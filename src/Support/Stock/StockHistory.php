<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Models\Stock\StockThreshold;

/**
 * Chi ha una storia di magazzino e chi può dimenticarla.
 *
 * Giacenze e movimenti puntano al prodotto con una chiave esterna che
 * **impedisce** di cancellarlo: è la garanzia che la storia non resti a
 * parlare di righe scomparse. Chi elimina prodotti deve quindi chiedere prima
 * se si può (`hasMovements()`) o cancellare anche la storia (`purge()`).
 * Gli avvisi di scorta invece se ne vanno col prodotto (`dropAlerts()`).
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
     * Gli ultimi movimenti di una versione, dal più recente.
     *
     * Li mostra la scheda: è lì che si risponde a "perché qui c'è scritto 3?"
     * senza andare in Movimenti.
     *
     * @return list<array<string, mixed>>
     */
    public static function latest(int $productId, int $limit = 10): array
    {
        if ($productId <= 0) {
            return [];
        }

        try {
            $rows = StockMovement::find(
                ['product_id' => $productId, 'deleted' => 'false'],
                max(1, $limit),
                'id',
                'DESC'
            );
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Cancella giacenze, movimenti, prenotazioni e avvisi di certi prodotti.
     *
     * La usa **solo chi cancella quei prodotti davvero**: i dati di prova con
     * `--fresh`. Fuori di lì il magazzino non si dimentica: una quantità
     * sbagliata si corregge con una rettifica.
     *
     * @param list<int> $productIds
     * @return int quante righe se ne sono andate
     */
    public static function purge(array $productIds): int
    {
        $condition = self::condition($productIds);

        if ($condition === null) {
            return 0;
        }

        $removed = 0;

        foreach ([StockAlert::class, StockThreshold::class, StockReservation::class, StockMovement::class, StockRow::class] as $model) {
            try {
                // Contate prima: dopo non c'è più niente da contare, e il
                // comando dei dati di prova dice quante righe ha tolto.
                $removed += self::deleteWhere($model, $condition);
            } catch (Throwable) {
                // Tabella non ancora creata: non c'è niente da dimenticare.
            }
        }

        return $removed;
    }

    /**
     * Cancella gli avvisi di scorta di prodotti che stanno per sparire.
     *
     * Un avviso non è storia: dice "adesso questo prodotto è sotto scorta", e
     * di un prodotto eliminato non c'è più niente da dire. Ma punta al
     * prodotto con una chiave esterna: senza toglierlo prima, eliminare un
     * articolo con la soglia scritta e nessun movimento finirebbe in una
     * pagina di errore.
     *
     * @param list<int> $productIds
     * @return int quanti avvisi se ne sono andati
     */
    public static function dropAlerts(array $productIds): int
    {
        $condition = self::condition($productIds);

        if ($condition === null) {
            return 0;
        }

        try {
            return self::deleteWhere(StockAlert::class, $condition);
        } catch (Throwable) {
            // Tabella non ancora creata: non c'è niente da togliere.
            return 0;
        }
    }

    /**
     * La condizione `product_id IN (...)` sugli id validi, o null se non ne
     * resta nessuno.
     *
     * @param list<int> $productIds
     */
    private static function condition(array $productIds): ?string
    {
        $ids = array_values(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0));

        return $ids === [] ? null : 'product_id IN ('.implode(',', $ids).')';
    }

    /**
     * Conta le righe del modello che rispondono alla condizione, poi le
     * cancella. Un errore del database arriva a chi chiama: è lui a sapere
     * cosa vuol dire.
     *
     * @param class-string $model
     * @return int quante righe se ne sono andate
     */
    private static function deleteWhere(string $model, string $condition): int
    {
        $rows = $model::find($condition);
        $rows = isset($rows['id']) ? [$rows] : (array) $rows;
        $count = count(array_filter($rows, 'is_array'));

        $model::query()->Delete($model::$table, $condition);

        return $count;
    }
}
