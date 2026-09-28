<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;

/**
 * I prodotti sotto scorta minima, come li leggono l'email e la home.
 *
 * Gli avvisi dicono chi è sceso sotto la soglia, e in quale sede; le righe
 * dicono come stanno le cose adesso. `build()` tiene solo quello che è
 * ancora vero: un prodotto sparito, tolto dalla griglia o tornato sopra la
 * soglia non si segnala, e nemmeno una sede che non è più del magazzino.
 * Con una sede sola la sede non si nomina.
 */
final class LowStockReport
{
    /** Gli avvisi aperti che nessuno ha ancora mandato. @return list<array<string, mixed>> */
    public static function pending(): array
    {
        return self::rows(StockAlert::class, "deleted = 'false' AND resolved_at IS NULL AND notified_at IS NULL");
    }

    /** Tutti gli avvisi aperti, mandati o no. @return list<array<string, mixed>> */
    public static function open(): array
    {
        return self::rows(StockAlert::class, "deleted = 'false' AND resolved_at IS NULL");
    }

    /**
     * I prodotti di questi avvisi, per id.
     *
     * Il core aggiunge da sé `deleted = 'false'` a ogni lettura che non nomina
     * `deleted`: un prodotto tolto dalla griglia qui non c'è, e per
     * `orphans()` è sparito. Va bene così: il suo avviso si chiude.
     *
     * Un errore di lettura sale a chi chiama (l'attività segna il giro fallito,
     * il riquadro della home lo cattura): altrimenti ogni avviso aperto
     * sembrerebbe orfano.
     *
     * @param list<array<string, mixed>> $alerts
     * @return array<int, array<string, mixed>>
     */
    public static function products(array $alerts): array
    {
        $ids = self::productIds($alerts);

        if ($ids === []) {
            return [];
        }

        $products = [];

        foreach (self::read(Product::class, 'id IN ('.implode(',', $ids).')') as $row) {
            $products[(int) $row['id']] = $row;
        }

        return $products;
    }

    /**
     * @param list<array<string, mixed>> $alerts
     * @param array<int, array<string, mixed>> $products
     * @return list<array{product_id: int, location_id: int, location: string, article: string, option: string, sku: string, threshold: float, available: float}>
     */
    public static function items(array $alerts, array $products): array
    {
        $ids = array_keys($products);

        return self::build(
            $alerts,
            $products,
            ProductNames::models($products),
            Levels::byLocationForProducts($ids),
            Thresholds::forProducts($ids),
            Locations::shown()
        );
    }

    /**
     * Le righe da mostrare, pure: nessuna lettura qui dentro.
     *
     * Una riga per prodotto e sede. La sede si scrive solo quando le sedi da
     * mostrare sono almeno due: con una sola sarebbe rumore.
     *
     * @param list<array<string, mixed>> $alerts
     * @param array<int, array<string, mixed>> $products
     * @param array<int, string> $modelNames
     * @param array<int, array<int, array{available: float}>> $levels per prodotto, poi per sede
     * @param array<int, array<int, float>> $thresholds per prodotto, poi per sede
     * @param list<array{id: int, label: string}> $locations le sedi da mostrare, in ordine
     * @return list<array{product_id: int, location_id: int, location: string, article: string, option: string, sku: string, threshold: float, available: float}>
     */
    public static function build(
        array $alerts,
        array $products,
        array $modelNames,
        array $levels,
        array $thresholds,
        array $locations
    ): array {
        $labels = [];
        $positions = [];

        foreach ($locations as $position => $location) {
            $labels[(int) ($location['id'] ?? 0)] = count($locations) >= 2 ? (string) ($location['label'] ?? '') : '';
            $positions[(int) ($location['id'] ?? 0)] = $position;
        }

        $items = [];

        foreach ($alerts as $alert) {
            $id = (int) ($alert['product_id'] ?? 0);
            $locationId = (int) ($alert['location_id'] ?? 0);
            $key = $id.':'.$locationId;
            $product = $products[$id] ?? null;

            if (isset($items[$key]) || !isset($labels[$locationId])
                || !is_array($product) || ($product['deleted'] ?? 'false') === 'true') {
                continue;
            }

            $threshold = round((float) ($thresholds[$id][$locationId] ?? 0), 3);
            $available = round((float) ($levels[$id][$locationId]['available'] ?? 0), 3);

            // Tornato sopra la soglia, o soglia tolta: non è più una notizia.
            if (LowStock::decide($threshold, $available, false) !== LowStock::OPEN) {
                continue;
            }

            $names = ProductNames::of($product, $modelNames);

            $items[$key] = [
                'product_id' => $id,
                'location_id' => $locationId,
                'location' => $labels[$locationId],
                'article' => $names['article'],
                'option' => $names['option'],
                'sku' => (string) ($product['sku'] ?? ''),
                'threshold' => $threshold,
                'available' => $available,
            ];
        }

        usort($items, static fn (array $a, array $b): int =>
            [mb_strtolower($a['article'], 'UTF-8'), mb_strtolower($a['option'], 'UTF-8'), $a['sku'], $positions[$a['location_id']]]
            <=> [mb_strtolower($b['article'], 'UTF-8'), mb_strtolower($b['option'], 'UTF-8'), $b['sku'], $positions[$b['location_id']]]
        );

        return array_values($items);
    }

    /**
     * Gli avvisi di prodotti che non ci sono più, o che sono stati tolti dalla
     * griglia: nessuna email li deve nominare, e restano aperti per sempre se
     * nessuno li chiude.
     *
     * @param list<array<string, mixed>> $alerts
     * @param array<int, array<string, mixed>> $products
     * @return list<int>
     */
    public static function orphans(array $alerts, array $products): array
    {
        $ids = [];

        foreach ($alerts as $alert) {
            $product = $products[(int) ($alert['product_id'] ?? 0)] ?? null;

            if (!is_array($product) || ($product['deleted'] ?? 'false') === 'true') {
                $ids[] = (int) $alert['id'];
            }
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $alerts
     * @return list<int>
     */
    private static function productIds(array $alerts): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['product_id'] ?? 0),
            $alerts
        ))));
    }

    /**
     * @param class-string<\Wonder\App\Model> $modelClass
     * @return list<array<string, mixed>>
     */
    private static function rows(string $modelClass, string $condition): array
    {
        try {
            return self::read($modelClass, $condition);
        } catch (Throwable) {
            // Tabelle non ancora create: niente da segnalare.
            return [];
        }
    }

    /**
     * Le righe in lista, anche quando il core ne dà una sola: qui un errore di
     * lettura sale a chi chiama.
     *
     * @param class-string<\Wonder\App\Model> $modelClass
     * @return list<array<string, mixed>>
     */
    private static function read(string $modelClass, string $condition): array
    {
        $rows = $modelClass::find($condition);

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
