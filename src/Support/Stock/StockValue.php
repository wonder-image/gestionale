<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;

/**
 * Il valore del magazzino (G3.19, spec §3.4): pezzi × costo del secchio,
 * IVA esclusa, solo i secchi sopra zero. I pezzi senza nessun costo si
 * contano a parte, così il riquadro può dire «N pezzi senza costo» invece di
 * farli valere zero in silenzio.
 *
 * `of()` è pura; `byLocation()` legge giacenze e costi una volta sola.
 */
final class StockValue
{
    /**
     * @param list<array{quantity: float, cost: ?float}> $buckets
     * @return array{value: float, pieces: float, uncosted: float}
     */
    public static function of(array $buckets): array
    {
        $value = 0.0;
        $pieces = 0.0;
        $uncosted = 0.0;

        foreach ($buckets as $bucket) {
            $quantity = round((float) ($bucket['quantity'] ?? 0), 3);

            if ($quantity <= 0) {
                continue;
            }

            $pieces += $quantity;

            if (($bucket['cost'] ?? null) === null) {
                $uncosted += $quantity;

                continue;
            }

            $value += $quantity * (float) $bucket['cost'];
        }

        return ['value' => round($value, 2) + 0.0, 'pieces' => round($pieces, 3) + 0.0, 'uncosted' => round($uncosted, 3) + 0.0];
    }

    /**
     * Il valore per sede, con il totale sotto la chiave 0.
     *
     * @return array<int, array{value: float, pieces: float, uncosted: float}>
     */
    public static function byLocation(): array
    {
        try {
            $rows = self::rows(StockRow::find("`quantity` > 0 AND `deleted` = 'false'"));
            $productIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['product_id'], $rows)));
            $links = $productIds === [] ? [] : self::rows(ProductSupplier::find('`product_id` IN ('.implode(', ', $productIds).") AND `deleted` = 'false'"));
        } catch (Throwable) {
            return [0 => ['value' => 0.0, 'pieces' => 0.0, 'uncosted' => 0.0]];
        }

        $costs = [];

        foreach ($links as $link) {
            if ($link['cost'] !== null && trim((string) $link['cost']) !== '') {
                $costs[(int) $link['product_id']][(int) $link['supplier_id']] = (float) $link['cost'];
            }
        }

        $buckets = [0 => []];

        foreach ($rows as $row) {
            $bucket = [
                'quantity' => (float) $row['quantity'],
                'cost' => StockCosts::of((int) $row['supplier_id'], $costs[(int) $row['product_id']] ?? []),
            ];
            $buckets[(int) $row['location_id']][] = $bucket;
            $buckets[0][] = $bucket;
        }

        return array_map(self::of(...), $buckets);
    }

    /** «1.234,50 €», come gli importi della scheda ordine. */
    public static function money(float $value): string
    {
        return number_format($value, 2, ',', '.').' €';
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
