<?php

namespace Wonder\Plugin\Gestionale\Support\Shipping;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRate;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRateBracket;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZone;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Sql\Transaction;

/**
 * I listini di un metodo, come li scrive il form: un riquadro per zona, con un
 * interruttore `rate_<zona>_on`, i campi `rate_<zona>_<campo>` e la tabella
 * degli scaglioni `rate_<zona>_brackets`.
 *
 * Il core non annida i repeater, quindi il form del metodo è piatto: questa
 * classe lo legge dalla richiesta (`readRates`), lo controlla (`validate`),
 * lo scrive (`saveRates`) e lo rimette nei campi (`loadRates`). Un listino
 * spento non si cancella: resta salvato con `active = false`, e riaccendendolo
 * ricompaiono i suoi valori.
 */
final class RateForm
{
    /** Le colonne decimali facoltative: vuote restano vuote. */
    private const OPTIONAL = ['volumetric_divisor', 'rounding_step', 'free_over_amount', 'free_under_weight'];

    /** Le colonne decimali con un valore di partenza: vuote valgono zero. */
    private const DEFAULTED = ['fuel_surcharge_percent', 'markup_percent', 'min_price', 'cod_fee'];

    /** I valori che non possono essere negativi. */
    private const NON_NEGATIVE = [
        'volumetric_divisor', 'fuel_surcharge_percent', 'rounding_step',
        'min_price', 'free_over_amount', 'free_under_weight', 'cod_fee',
    ];

    /**
     * I listini accesi nella richiesta, per id di zona.
     *
     * Una richiesta storta (un valore che non è una lista, una riga di
     * testo, un numero che non lo è) non rompe niente: il valore vale vuoto e
     * la riga si scarta.
     *
     * @param array<string, mixed> $post
     * @return array<int, array<string, mixed>>
     */
    public static function readRates(array $post): array
    {
        $rates = [];

        foreach ($post as $key => $value) {
            if (preg_match('/^rate_(\d+)_on$/', (string) $key, $m) !== 1
                || (int) $m[1] <= 0
                || $value !== 'true') {
                continue;
            }

            $zone = (int) $m[1];
            $field = static fn (string $name): mixed => $post['rate_'.$zone.'_'.$name] ?? null;
            $rate = ['excess_mode' => $field('excess_mode') === 'excess_only' ? 'excess_only' : 'total_weight'];

            foreach (self::OPTIONAL as $name) {
                $rate[$name] = Numbers::fromForm($field($name));
            }

            foreach (self::DEFAULTED as $name) {
                $rate[$name] = Numbers::fromForm($field($name)) ?? '0';
            }

            $rate['brackets'] = static::readBrackets($field('brackets'));
            $rates[$zone] = $rate;
        }

        ksort($rates);

        return $rates;
    }

    /**
     * Gli scaglioni di una zona: le righe vuote si tolgono, quelli di prezzo
     * si ordinano per peso e la tariffa al kg va in fondo.
     *
     * @return list<array{type: string, max_weight: ?string, amount: ?string}>
     */
    private static function readBrackets(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $prices = [];
        $excess = [];

        foreach ($rows as $row) {
            if (!is_array($row)
                || !is_string($row['type'] ?? 'price')
                || is_array($row['max_weight'] ?? null)
                || is_array($row['amount'] ?? null)) {
                continue;
            }

            $isExcess = ($row['type'] ?? 'price') === 'excess';
            $weight = $isExcess ? null : Numbers::fromForm($row['max_weight'] ?? null);
            $amount = Numbers::fromForm($row['amount'] ?? null);

            if ($amount === null && $weight === null) {
                continue;
            }

            $bracket = ['type' => $isExcess ? 'excess' : 'price', 'max_weight' => $weight, 'amount' => $amount];

            if ($isExcess) {
                $excess[] = $bracket;
            } else {
                $prices[] = $bracket;
            }
        }

        usort($prices, static fn (array $a, array $b): int => (float) ($a['max_weight'] ?? INF) <=> (float) ($b['max_weight'] ?? INF));

        return [...$prices, ...$excess];
    }

    /**
     * Si ferma, con la frase per il commerciante, al primo listino che non sta
     * in piedi. Il margine può essere negativo (uno sconto sul listino) ma non
     * sotto −100 %, che porterebbe il prezzo sotto zero.
     *
     * @param array<int, array<string, mixed>> $rates
     * @param array<int, string> $zoneNames id di zona => nome, per dire di quale si parla
     * @throws UserError
     */
    public static function validate(array $rates, array $zoneNames = []): void
    {
        foreach ($rates as $zone => $rate) {
            $with = ['zone' => $zoneNames[$zone] ?? '#'.$zone];

            foreach (self::NON_NEGATIVE as $name) {
                if (($rate[$name] ?? null) !== null && (float) $rate[$name] < 0) {
                    throw UserError::make('shipping.rate_negative', $with);
                }
            }

            if ((float) ($rate['markup_percent'] ?? 0) < -100) {
                throw UserError::make('shipping.markup_out_of_range', $with);
            }

            $weights = [];
            $excess = 0;

            foreach ((array) ($rate['brackets'] ?? []) as $bracket) {
                $amount = $bracket['amount'] ?? null;

                if ($amount === null || (float) $amount < 0) {
                    throw UserError::make('shipping.bracket_amount', $with);
                }

                if (($bracket['type'] ?? 'price') === 'excess') {
                    if (++$excess > 1) {
                        throw UserError::make('shipping.excess_duplicate', $with);
                    }

                    continue;
                }

                $weight = $bracket['max_weight'] ?? null;

                if ($weight === null || (float) $weight <= 0) {
                    throw UserError::make('shipping.bracket_weight', $with);
                }

                // Il confronto è sul numero: 5 e 5.000 sono lo stesso scaglione.
                if (in_array(round((float) $weight, 3), $weights, true)) {
                    throw UserError::make('shipping.bracket_duplicate', $with);
                }

                $weights[] = round((float) $weight, 3);
            }

            if ($weights === []) {
                throw UserError::make('shipping.rate_no_brackets', $with);
            }
        }
    }

    /**
     * Scrive i listini del metodo, in una transazione. Una zona che non esiste
     * (più) si ignora; un listino che prima c'era e ora non è tra i listini
     * accesi resta salvato, spento. Gli scaglioni si riscrivono per intero.
     *
     * @param array<int, array<string, mixed>> $rates
     */
    public static function saveRates(int $methodId, array $rates): void
    {
        $zones = array_flip(array_map('intval', array_column(static::rows(ShippingZone::find(['deleted' => 'false'])), 'id')));

        Transaction::run(static function () use ($methodId, $rates, $zones): void {
            $saved = [];

            foreach (static::rows(ShippingRate::find(['shipping_method_id' => $methodId])) as $row) {
                $saved[(int) $row['shipping_zone_id']] = $row;
            }

            foreach ($saved as $zone => $row) {
                if (!isset($rates[$zone]) && ($row['active'] ?? 'true') === 'true') {
                    ShippingRate::update(['active' => 'false'], (int) $row['id']);
                }
            }

            foreach ($rates as $zone => $rate) {
                if (!isset($zones[$zone])) {
                    continue;
                }

                $values = [
                    'excess_mode' => $rate['excess_mode'],
                    'active' => 'true',
                ];

                foreach ([...self::OPTIONAL, ...self::DEFAULTED] as $name) {
                    // Una casella vuota svuota la colonna: `null` e non zero.
                    $values[$name] = $rate[$name] ?? null;
                }

                if (isset($saved[$zone])) {
                    $rateId = (int) $saved[$zone]['id'];
                    ShippingRate::update($values, $rateId);
                } else {
                    $rateId = (int) ShippingRate::create($values + [
                        'shipping_method_id' => $methodId,
                        'shipping_zone_id' => $zone,
                    ])->insert_id;
                }

                foreach (static::rows(ShippingRateBracket::find(['shipping_rate_id' => $rateId])) as $old) {
                    ShippingRateBracket::delete((int) $old['id']);
                }

                foreach ((array) $rate['brackets'] as $bracket) {
                    ShippingRateBracket::create([
                        'shipping_rate_id' => $rateId,
                        'type' => $bracket['type'],
                        'max_weight' => $bracket['max_weight'],
                        'amount' => $bracket['amount'],
                    ]);
                }
            }
        });
    }

    /**
     * I listini salvati del metodo, nella forma dei campi del form
     * (`rate_<zona>_<campo>`), per le zone che esistono.
     *
     * @return array<string, mixed>
     */
    public static function loadRates(int $methodId): array
    {
        if ($methodId <= 0) {
            return [];
        }

        $values = [];

        foreach (static::rows(ShippingRate::find(['shipping_method_id' => $methodId])) as $row) {
            $zone = (int) $row['shipping_zone_id'];
            $values['rate_'.$zone.'_on'] = ($row['active'] ?? 'true') === 'true' ? 'true' : 'false';
            $values['rate_'.$zone.'_excess_mode'] = (string) ($row['excess_mode'] ?? 'total_weight');

            foreach ([...self::OPTIONAL, ...self::DEFAULTED] as $name) {
                $values['rate_'.$zone.'_'.$name] = $row[$name] ?? null;
            }

            $brackets = static::rows(ShippingRateBracket::find(['shipping_rate_id' => (int) $row['id']]));
            usort($brackets, static fn (array $a, array $b): int => [($a['type'] ?? '') === 'excess', (float) ($a['max_weight'] ?? 0)]
                <=> [($b['type'] ?? '') === 'excess', (float) ($b['max_weight'] ?? 0)]);

            $values['rate_'.$zone.'_brackets'] = array_map(static fn (array $b): array => [
                'type' => (string) ($b['type'] ?? 'price'),
                'max_weight' => $b['max_weight'] ?? null,
                'amount' => $b['amount'] ?? null,
            ], $brackets);
        }

        return $values;
    }

    /**
     * Gli articoli da spedire che non hanno il peso: su di loro il prezzo è
     * quello dello scaglione più basso. Serve solo a avvisarne il
     * commerciante.
     */
    public static function unweighted(): int
    {
        try {
            return (int) Product::query()->Count(
                Product::$table,
                "WHERE deleted = 'false' AND (weight IS NULL OR weight = 0) AND product_model_id IN ("
                .'SELECT id FROM '.ProductModel::$table
                ." WHERE deleted = 'false' AND requires_shipping = 'true' AND type = 'simple')"
            );
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return array_key_exists('id', $found) ? [$found] : array_values(array_filter($found, 'is_array'));
    }
}
