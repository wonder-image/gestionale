<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRate;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRateBracket;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZone;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZoneArea;

/**
 * Le spedizioni di prova: tre zone, un corriere e due metodi con i loro listini.
 *
 * - **Italia**: tutto il paese `IT`;
 * - **Isole**: le province sarde e siciliane più grandi (`CA SS NU OR PA CT ME`),
 *   un elenco breve di proposito: ne basta una per vedere la zona più
 *   specifica battere «Italia»;
 * - **Unione Europea**: cinque paesi (`FR DE ES AT BE`).
 *
 * **Standard** spedisce ovunque (in Italia con la commissione di 3,50 € per il
 * contrassegno, e una tariffa al kg oltre i 20 kg); **Espresso** solo in Italia
 * e nelle isole, con la maggiorazione carburante e gratis sopra i 100 € di
 * prodotti. Un paese fuori dalle zone non ha nessun metodo.
 *
 * Il segno di prova sta nel `code` di zone, corriere e metodi (`DemoCode`):
 * listini, scaglioni e aree non hanno un codice, e si riconoscono dal padre.
 * Se un articolo di prova da spedire non ha il peso, ne prende uno, perché il
 * prezzo dipende da lì; i pesi già scritti non si toccano.
 *
 * La pulizia toglie davvero tutto ciò che ha il segno, con i suoi figli. Un
 * metodo già scelto da un ordine, una zona con il listino di un metodo vero e
 * un corriere di un metodo vero restano, e il comando lo dice.
 */
final class ShippingDemo
{
    /** Chiave nel registro dei dati di prova. */
    public const KEY = 'spedizioni';

    /** Il peso, in kg, che prende un articolo di prova da spedire che non ne ha. */
    private const DEFAULT_WEIGHT = '0.300';

    public static function register(): void
    {
        DemoData::register(
            self::KEY,
            'Spedizioni: tre zone, un corriere e due metodi con i listini',
            static fn (): int => self::create(),
            static fn (): int => self::clear()
        );
    }

    /** @return int righe create */
    public static function create(): int
    {
        $created = 0;
        $zones = [];

        foreach (self::zones() as $ref => $zone) {
            [$id, $new] = self::ensure(ShippingZone::class, $ref, ['name' => $zone['name'], 'position' => $zone['position']]);
            $zones[$ref] = $id;
            $created += $new;

            if ($id > 0 && self::childrenOf(ShippingZoneArea::class, 'shipping_zone_id', $id) === []) {
                foreach ($zone['areas'] as [$country, $province]) {
                    ShippingZoneArea::create(['shipping_zone_id' => $id, 'country' => $country, 'province' => $province]);
                    $created++;
                }
            }
        }

        [$carrier, $new] = self::ensure(Carrier::class, 'corriere', [
            'name' => 'Corriere di prova',
            'tracking_url_template' => 'https://tracking.example.com/?codice={tracking}',
            'provider' => 'manual',
            'active' => 'true',
            'position' => 1,
        ]);
        $created += $new;

        foreach (self::methods() as $ref => $method) {
            [$id, $new] = self::ensure(ShippingMethod::class, $ref, [
                'name' => $method['name'],
                'description' => $method['description'],
                'carrier_id' => $carrier,
                'applies_online' => 'true',
                'applies_office' => 'true',
                'active' => 'true',
                'position' => $method['position'],
            ]);
            $created += $new;

            if ($id <= 0) {
                continue;
            }

            foreach ($method['rates'] as $zoneRef => $rate) {
                $zone = (int) ($zones[$zoneRef] ?? 0);

                if ($zone > 0 && self::rateOf($id, $zone) === null) {
                    $created += self::createRate($id, $zone, $rate);
                }
            }
        }

        return $created + self::weighArticles();
    }

    /** @return int righe tolte */
    public static function clear(): int
    {
        $removed = 0;
        $keptMethods = [];

        foreach (self::ours(ShippingMethod::class) as $method) {
            $id = (int) $method['id'];

            if (self::rowsOf(Order::class, 'shipping_method_id = '.$id) !== []) {
                $keptMethods[] = (string) ($method['name'] ?? '');
                continue;
            }

            foreach (self::childrenOf(ShippingRate::class, 'shipping_method_id', $id) as $rate) {
                foreach (self::childrenOf(ShippingRateBracket::class, 'shipping_rate_id', (int) $rate['id']) as $bracket) {
                    ShippingRateBracket::delete((int) $bracket['id']);
                    $removed++;
                }

                ShippingRate::delete((int) $rate['id']);
                $removed++;
            }

            ShippingMethod::delete($id);
            $removed++;
        }

        $keptZones = [];

        foreach (self::ours(ShippingZone::class) as $zone) {
            $id = (int) $zone['id'];

            if (self::childrenOf(ShippingRate::class, 'shipping_zone_id', $id) !== []) {
                $keptZones[] = (string) ($zone['name'] ?? '');
                continue;
            }

            foreach (self::childrenOf(ShippingZoneArea::class, 'shipping_zone_id', $id) as $area) {
                ShippingZoneArea::delete((int) $area['id']);
                $removed++;
            }

            ShippingZone::delete($id);
            $removed++;
        }

        $keptCarriers = [];

        foreach (self::ours(Carrier::class) as $carrier) {
            $id = (int) $carrier['id'];

            if (self::rowsOf(ShippingMethod::class, 'carrier_id = '.$id) !== []) {
                $keptCarriers[] = (string) ($carrier['name'] ?? '');
                continue;
            }

            Carrier::delete($id);
            $removed++;
        }

        foreach ([
            'Metodi di spedizione rimasti, perché già su un ordine' => $keptMethods,
            'Zone rimaste, perché hanno il listino di un metodo' => $keptZones,
            'Corrieri rimasti, perché hanno ancora dei metodi' => $keptCarriers,
        ] as $text => $names) {
            if ($names !== []) {
                DemoData::note($text.': '.implode(', ', $names).'.');
            }
        }

        return $removed;
    }

    /**
     * Le zone, per riferimento. Ogni area è [paese, provincia].
     *
     * @return array<string, array{name: string, position: int, areas: list<array{0: string, 1: string}>}>
     */
    private static function zones(): array
    {
        return [
            'italia' => ['name' => 'Italia', 'position' => 1, 'areas' => [['IT', '']]],
            'isole' => ['name' => 'Isole', 'position' => 2, 'areas' => [
                ['IT', 'CA'], ['IT', 'SS'], ['IT', 'NU'], ['IT', 'OR'], ['IT', 'PA'], ['IT', 'CT'], ['IT', 'ME'],
            ]],
            'ue' => ['name' => 'Unione Europea', 'position' => 3, 'areas' => [
                ['FR', ''], ['DE', ''], ['ES', ''], ['AT', ''], ['BE', ''],
            ]],
        ];
    }

    /**
     * I metodi con un listino per zona: scaglioni [peso massimo, importo], la
     * tariffa al kg oltre l'ultimo (`excess`, `null` se non c'è) e le colonne
     * del listino che non hanno il valore di partenza.
     *
     * @return array<string, array{name: string, description: string, position: int, rates: array<string, array{brackets: list<array{0: float, 1: float}>, excess: ?float, values: array<string, string>}>}>
     */
    private static function methods(): array
    {
        return [
            'standard' => [
                'name' => 'Standard',
                'description' => 'Da 3 a 5 giorni lavorativi',
                'position' => 1,
                'rates' => [
                    'italia' => ['brackets' => [[5.0, 6.90], [20.0, 9.90]], 'excess' => 0.90, 'values' => [
                        'excess_mode' => 'excess_only', 'volumetric_divisor' => '5000', 'cod_fee' => '3.50',
                    ]],
                    'isole' => ['brackets' => [[5.0, 9.90], [20.0, 14.90]], 'excess' => null, 'values' => []],
                    'ue' => ['brackets' => [[5.0, 14.90], [20.0, 24.90]], 'excess' => null, 'values' => []],
                ],
            ],
            'espresso' => [
                'name' => 'Espresso',
                'description' => 'Entro 24 ore lavorative',
                'position' => 2,
                'rates' => [
                    'italia' => ['brackets' => [[5.0, 9.90], [20.0, 14.90]], 'excess' => null, 'values' => [
                        'fuel_surcharge_percent' => '5.00', 'free_over_amount' => '100.00', 'volumetric_divisor' => '5000',
                    ]],
                    'isole' => ['brackets' => [[5.0, 12.90], [20.0, 19.90]], 'excess' => null, 'values' => [
                        'fuel_surcharge_percent' => '5.00',
                    ]],
                ],
            ],
        ];
    }

    /**
     * La riga col segno, rimessa al suo posto se il backend l'aveva cancellata
     * e creata se non c'era.
     *
     * @param class-string<\Wonder\App\Model> $model
     * @param array<string, mixed> $values
     * @return array{0: int, 1: int} id e righe create
     */
    private static function ensure(string $model, string $ref, array $values): array
    {
        $code = DemoCode::forModel($model, $ref);
        $row = $model::find(['code' => $code, 'deleted' => 'false'], 1);

        if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
            return [(int) $row['id'], 0];
        }

        $id = DemoCode::revive($model, $code);

        if ($id > 0) {
            return [$id, 1];
        }

        $result = $model::create($values + ['code' => $code]);
        $id = !empty($result->success) ? (int) ($result->insert_id ?? 0) : 0;

        return [$id, $id > 0 ? 1 : 0];
    }

    /**
     * @param array{brackets: list<array{0: float, 1: float}>, excess: ?float, values: array<string, string>} $rate
     * @return int righe create
     */
    private static function createRate(int $method, int $zone, array $rate): int
    {
        $result = ShippingRate::create($rate['values'] + [
            'shipping_method_id' => $method,
            'shipping_zone_id' => $zone,
            'excess_mode' => 'total_weight',
            'fuel_surcharge_percent' => '0.00',
            'markup_percent' => '0.00',
            'min_price' => '0.00',
            'cod_fee' => '0.00',
            'active' => 'true',
        ]);
        $id = !empty($result->success) ? (int) ($result->insert_id ?? 0) : 0;

        if ($id <= 0) {
            return 0;
        }

        $created = 1;

        foreach ($rate['brackets'] as [$weight, $amount]) {
            ShippingRateBracket::create([
                'shipping_rate_id' => $id,
                'type' => 'price',
                'max_weight' => number_format($weight, 3, '.', ''),
                'amount' => number_format($amount, 2, '.', ''),
            ]);
            $created++;
        }

        if ($rate['excess'] !== null) {
            ShippingRateBracket::create([
                'shipping_rate_id' => $id,
                'type' => 'excess',
                'max_weight' => null,
                'amount' => number_format($rate['excess'], 2, '.', ''),
            ]);
            $created++;
        }

        return $created;
    }

    /** @return array<string, mixed>|null il listino di quel metodo per quella zona, anche spento */
    private static function rateOf(int $method, int $zone): ?array
    {
        $rows = self::rowsOf(ShippingRate::class, 'shipping_method_id = '.$method.' AND shipping_zone_id = '.$zone);

        return $rows[0] ?? null;
    }

    /**
     * Il peso agli articoli di prova da spedire che non lo hanno.
     *
     * @return int righe aggiornate
     */
    private static function weighArticles(): int
    {
        $updated = 0;

        foreach (self::ours(ProductModel::class) as $model) {
            if ((string) ($model['requires_shipping'] ?? 'true') !== 'true') {
                continue;
            }

            foreach (self::rowsOf(Product::class, 'product_model_id = '.(int) $model['id']." AND (weight IS NULL OR weight = 0)") as $product) {
                Product::update(['weight' => self::DEFAULT_WEIGHT], (int) $product['id']);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Le righe col segno di prova, anche cancellate dal backend: il codice è
     * unico e una riga nascosta bloccherebbe comunque il ricrearla.
     *
     * @param class-string<\Wonder\App\Model> $model
     * @return list<array<string, mixed>>
     */
    private static function ours(string $model): array
    {
        $prefix = str_replace('_', '\\_', DemoCode::prefixOf($model).DemoCode::MARK);

        return array_values(array_filter(
            self::rowsOf($model, "code LIKE '".$prefix."%'"),
            static fn (array $row): bool => DemoCode::is((string) ($row['code'] ?? ''))
        ));
    }

    /**
     * @param class-string<\Wonder\App\Model> $model
     * @return list<array<string, mixed>>
     */
    private static function childrenOf(string $model, string $column, int $id): array
    {
        return self::rowsOf($model, $column.' = '.$id);
    }

    /**
     * Le righe che rispondono alla condizione, anche cancellate.
     *
     * @param class-string<\Wonder\App\Model> $model
     * @return list<array<string, mixed>>
     */
    private static function rowsOf(string $model, string $where): array
    {
        $rows = $model::find('('.$where.") AND (deleted = 'false' OR deleted = 'true')");

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return array_key_exists('id', $rows) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
