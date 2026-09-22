<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;

/**
 * Chi ha una storia di magazzino e chi può dimenticarla.
 *
 * Giacenze e movimenti puntano al prodotto con una chiave esterna che
 * **impedisce** di cancellarlo: è la garanzia che la storia non resti a
 * parlare di righe scomparse. Chi elimina prodotti deve quindi chiedere prima
 * se si può (`hasMovements()`) o cancellare anche la storia (`purge()`).
 */
final class StockHistory
{
    /** Vero se il prodotto ha almeno un movimento: allora non si elimina. */
    public static function hasMovements(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }

        try {
            $row = StockMovement::find(['product_id' => $productId], 1);
        } catch (Throwable) {
            return false;
        }

        return is_array($row) && $row !== [];
    }

    /**
     * Cancella giacenze, movimenti, prenotazioni e avvisi di certi prodotti.
     *
     * La usa **solo chi cancella quei prodotti davvero**: i dati di prova con
     * `--fresh`. Fuori di lì il magazzino non si dimentica: una quantità
     * sbagliata si corregge con una rettifica.
     *
     * @param list<int> $productIds
     */
    public static function purge(array $productIds): void
    {
        $ids = array_values(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return;
        }

        $condition = 'product_id IN ('.implode(',', $ids).')';

        foreach ([StockAlert::class, StockReservation::class, StockMovement::class, StockRow::class] as $model) {
            try {
                $model::query()->Delete($model::$table, $condition);
            } catch (Throwable) {
                // Tabella non ancora creata: non c'è niente da dimenticare.
            }
        }
    }
}
