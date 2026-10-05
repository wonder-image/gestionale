<?php

namespace Wonder\Plugin\Gestionale\Support\Shipping;

/**
 * Il prezzo di una spedizione da un listino: i passi 2-5 di §4.11.
 *
 * 1. lo scaglione che contiene il peso (il primo con `max_weight` ≥ peso),
 *    oppure, oltre l'ultimo, la tariffa al kg `excess` su tutto il peso
 *    (`total_weight`) o sulla sola parte oltre l'ultimo scaglione
 *    (`excess_only`, e allora si somma l'ultimo scaglione);
 * 2. × (1 + carburante %), arrotondato al centesimo;
 * 3. × (1 + margine %), arrotondato al centesimo (il margine può essere negativo);
 * 4. per eccesso al gradino `rounding_step`, mai sotto `min_price`, mai sotto zero;
 * 5. gratuita se il totale dei prodotti **supera** `free_over_amount` e/o il peso
 *    è **sotto** `free_under_weight`; con tutte e due impostate valgono tutte e due.
 *
 * Si arrotonda alla fine di ogni passo, mai a catena su float grezzi. Classe
 * pura: niente database, niente `date()`, niente eccezioni; un listino che non
 * copre il peso dà `null`. I valori possono arrivare come stringhe dal
 * database; `null` e stringa vuota vogliono dire «non impostato», che non è 0.
 */
final class ShippingRates
{
    /**
     * @param array<string, mixed> $rate Le colonne di `ShippingRate`.
     * @param list<array{type: string, max_weight: float|int|string, amount: float|int|string}> $brackets
     * @param float $weight Il peso tassabile.
     * @param float $productsTotal Il totale dei prodotti dopo gli sconti.
     * @return array{amount: float, free: bool}|null
     */
    public static function price(array $rate, array $brackets, float $weight, float $productsTotal): ?array
    {
        $amount = static::base($rate, $brackets, round($weight, 3));

        if ($amount === null) {
            return null;
        }

        $amount = round($amount * (1 + static::number($rate['fuel_surcharge_percent'] ?? null) / 100), 2);
        $amount = round($amount * (1 + static::number($rate['markup_percent'] ?? null) / 100), 2);

        $step = static::optional($rate['rounding_step'] ?? null);

        if ($step !== null && $step > 0.0) {
            $amount = round(ceil(round($amount / $step, 6)) * $step, 2);
        }

        $amount = max($amount, round(static::number($rate['min_price'] ?? null), 2), 0.0);

        if (static::isFree($rate, $weight, $productsTotal)) {
            return ['amount' => 0.0, 'free' => true];
        }

        return ['amount' => $amount, 'free' => false];
    }

    /** L'importo prima di carburante e margine, o `null` se il listino non copre il peso. */
    private static function base(array $rate, array $brackets, float $weight): ?float
    {
        $prices = [];
        $excess = null;

        foreach ($brackets as $bracket) {
            if (($bracket['type'] ?? 'price') === 'excess') {
                $excess ??= (float) $bracket['amount'];

                continue;
            }

            $prices[] = ['max' => round((float) $bracket['max_weight'], 3), 'amount' => (float) $bracket['amount']];
        }

        if ($prices === []) {
            return null;
        }

        usort($prices, static fn (array $a, array $b): int => $a['max'] <=> $b['max']);

        foreach ($prices as $price) {
            if ($weight <= $price['max']) {
                return round($price['amount'], 2);
            }
        }

        if ($excess === null) {
            return null;
        }

        $last = $prices[array_key_last($prices)];

        if (($rate['excess_mode'] ?? 'total_weight') === 'excess_only') {
            return round($last['amount'] + ($weight - $last['max']) * $excess, 2);
        }

        return round($weight * $excess, 2);
    }

    private static function isFree(array $rate, float $weight, float $productsTotal): bool
    {
        $over = static::optional($rate['free_over_amount'] ?? null);
        $under = static::optional($rate['free_under_weight'] ?? null);

        if ($over === null && $under === null) {
            return false;
        }

        if ($over !== null && !(round($productsTotal, 2) > round($over, 2))) {
            return false;
        }

        if ($under !== null && !(round($weight, 3) < round($under, 3))) {
            return false;
        }

        return true;
    }

    private static function number(mixed $value): float
    {
        return static::optional($value) ?? 0.0;
    }

    /** Un numero, o `null` se il valore non è impostato (assente o stringa vuota). */
    private static function optional(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
