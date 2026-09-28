<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Stock\StockThreshold;

/**
 * Le scorte minime, per prodotto e sede.
 *
 * Una soglia è una riga di `gst_stock_thresholds`; niente riga vuol dire
 * «non avvisarmi». Chi salva passa la mappa completa delle sedi che vuole
 * (`save()`): le sedi che mancano perdono la soglia, così la scheda scrive
 * quello che mostra e non lascia soglie nascoste.
 *
 * Le letture non esplodono senza database (test degli schemi, comandi): come
 * `Levels`, tornano vuote. Le scritture no: un errore lì è un guasto vero.
 */
final class Thresholds
{
    /** @return array<int, float> la soglia di ogni sede che ne ha una */
    public static function forProduct(int $productId): array
    {
        return self::forProducts([$productId])[$productId] ?? [];
    }

    /**
     * @param list<int> $productIds
     * @return array<int, array<int, float>> per prodotto, poi per sede
     */
    public static function forProducts(array $productIds): array
    {
        $ids = self::ids($productIds);
        $map = array_fill_keys($ids, []);

        if ($ids === []) {
            return $map;
        }

        foreach (self::rows('product_id IN ('.implode(',', $ids).") AND deleted = 'false'") as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $locationId = (int) ($row['location_id'] ?? 0);
            $quantity = round((float) ($row['quantity'] ?? 0), 3);

            if (isset($map[$productId]) && $locationId > 0 && $quantity > 0) {
                $map[$productId][$locationId] = $quantity;
            }
        }

        return $map;
    }

    /**
     * Scrive le soglie di un prodotto: la mappa è tutta la verità.
     *
     * @param array<int, mixed> $byLocation `[sede => soglia]`; vuota, zero o sotto zero = niente soglia
     * @return int quante righe sono cambiate
     */
    public static function save(int $productId, array $byLocation): int
    {
        if ($productId <= 0) {
            return 0;
        }

        $stored = [];

        foreach (self::rows('product_id = '.$productId." AND deleted = 'false'") as $row) {
            $stored[(int) ($row['location_id'] ?? 0)] = $row;
        }

        $plan = self::plan($stored, $byLocation);

        foreach ($plan['insert'] as $locationId => $quantity) {
            StockThreshold::create([
                'product_id' => $productId,
                'location_id' => $locationId,
                'quantity' => number_format($quantity, 3, '.', ''),
            ]);
        }

        foreach ($plan['update'] as $id => $quantity) {
            StockThreshold::update(['quantity' => number_format($quantity, 3, '.', '')], $id);
        }

        if ($plan['delete'] !== []) {
            // Cancellazione vera: l'indice unico prodotto×sede non ammette
            // una riga «cancellata» accanto a quella nuova.
            StockThreshold::query()->Delete(
                StockThreshold::$table,
                'WHERE id IN ('.implode(',', $plan['delete']).')'
            );
        }

        return count($plan['insert']) + count($plan['update']) + count($plan['delete']);
    }

    /**
     * Cosa scrivere, pura: da quello che c'è a quello che si vuole.
     *
     * @param array<int, array<string, mixed>> $stored righe di adesso, per sede (`id`, `quantity`)
     * @param array<int, mixed> $wanted `[sede => soglia]`
     * @return array{insert: array<int, float>, update: array<int, float>, delete: list<int>}
     */
    public static function plan(array $stored, array $wanted): array
    {
        $plan = ['insert' => [], 'update' => [], 'delete' => []];
        $keep = [];

        foreach ($wanted as $locationId => $raw) {
            $locationId = (int) $locationId;
            $quantity = Stocktake::quantity($raw);

            if ($locationId <= 0 || $quantity === null || $quantity <= 0) {
                continue;
            }

            $keep[$locationId] = true;

            if (!isset($stored[$locationId])) {
                $plan['insert'][$locationId] = $quantity;
            } elseif (round((float) ($stored[$locationId]['quantity'] ?? 0), 3) !== $quantity) {
                $plan['update'][(int) $stored[$locationId]['id']] = $quantity;
            }
        }

        foreach ($stored as $locationId => $row) {
            if (!isset($keep[(int) $locationId])) {
                $plan['delete'][] = (int) ($row['id'] ?? 0);
            }
        }

        return $plan;
    }

    /**
     * Toglie le soglie di prodotti che stanno per sparire: puntano al
     * prodotto con una chiave esterna, e senza questo passaggio eliminarlo
     * finirebbe in errore.
     *
     * @param list<int> $productIds
     * @return int quante righe se ne sono andate
     */
    public static function dropFor(array $productIds): int
    {
        $ids = self::ids($productIds);

        if ($ids === []) {
            return 0;
        }

        $condition = 'product_id IN ('.implode(',', $ids).')';

        try {
            $count = count(self::rows($condition));
            StockThreshold::query()->Delete(StockThreshold::$table, 'WHERE '.$condition);
        } catch (Throwable) {
            // Tabella non ancora creata: non c'è niente da togliere.
            return 0;
        }

        return $count;
    }

    /**
     * @param list<int> $productIds
     * @return list<int>
     */
    private static function ids(array $productIds): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn (int $id): bool => $id > 0
        )));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(string $condition): array
    {
        try {
            $rows = StockThreshold::find($condition);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return array_is_list($rows) ? array_values(array_filter($rows, 'is_array')) : [$rows];
    }
}
