<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;

/**
 * Apre e chiude gli avvisi di scorta minima, sede per sede.
 *
 * Un avviso è di un prodotto in una sede: la soglia sta in `Thresholds`, i
 * pezzi in `Levels::byLocation()`, e la decisione è di `LowStock`, che è
 * pura e si prova con gli array; qui c'è solo la scrittura. Gira dentro la
 * transazione di `Stock::apply()`: un avviso aperto da un movimento
 * annullato deve sparire con lui.
 *
 * Contano solo le sedi che il magazzino mostra (`Locations::shown()`): un
 * avviso di una sede che non c'è più, o senza sede (le righe di prima del
 * tredicesimo giro, con `location_id` 0), si chiude al primo giro.
 *
 * L'email non parte da qui. La riga nasce con `notified_at` vuoto e la manda
 * l'attività dello scheduler, raggruppata: dieci rettifiche di fila non devono
 * fare dieci email, e il salvataggio non deve dipendere dal server di posta.
 */
final class Alerts
{
    /**
     * Riallinea gli avvisi di un prodotto a com'è adesso, in ogni sede.
     *
     * @return LowStock::OPEN|LowStock::CLOSE|LowStock::NONE la notizia più
     *     forte del giro: aperto batte chiuso, chiuso batte niente
     */
    public static function refresh(int $productId): string
    {
        if ($productId <= 0) {
            return LowStock::NONE;
        }

        $shown = array_column(Locations::shown(), 'id');
        $thresholds = array_intersect_key(Thresholds::forProduct($productId), array_flip($shown));
        $levels = Levels::byLocation($productId);
        $open = [];

        foreach (self::openRows($productId) as $row) {
            $open[(int) ($row['location_id'] ?? 0)][] = (int) $row['id'];
        }

        $now = date('Y-m-d H:i:s');
        $outcome = LowStock::NONE;

        foreach ($thresholds as $locationId => $threshold) {
            $available = (float) ($levels[$locationId]['available'] ?? 0);
            $decision = LowStock::decide($threshold, $available, isset($open[$locationId]));

            if ($decision === LowStock::OPEN) {
                StockAlert::create([
                    'product_id' => $productId,
                    'location_id' => $locationId,
                    'threshold' => number_format($threshold, 3, '.', ''),
                    'quantity_at_alert' => number_format($available, 3, '.', ''),
                ]);
                $outcome = LowStock::OPEN;
            }

            if ($decision === LowStock::CLOSE) {
                self::close($open[$locationId] ?? [], $now);
                $outcome = $outcome === LowStock::OPEN ? $outcome : LowStock::CLOSE;
            }

            unset($open[$locationId]);
        }

        // Quello che resta aperto non ha più una soglia dietro: sede tolta,
        // soglia tolta, o le righe senza sede di prima. Si chiude.
        foreach ($open as $ids) {
            self::close($ids, $now);
            $outcome = $outcome === LowStock::NONE ? LowStock::CLOSE : $outcome;
        }

        return $outcome;
    }

    /**
     * L'avviso ancora aperto di un prodotto, `[]` se non ce n'è: di una sede,
     * o di qualunque sede (l'ultimo aperto).
     *
     * @return array<string, mixed>
     */
    public static function openRow(int $productId, ?int $locationId = null): array
    {
        // "Non ancora risolto" è `NULL`: la colonna la scrive solo chi chiude
        // l'avviso. E deve restare `IS NULL` e basta — confrontare un DATETIME
        // con la stringa vuota fa fallire la query ("Incorrect DATETIME
        // value"), e il `catch` qui sotto lo nasconderebbe.
        $condition = self::condition($productId)
            .($locationId === null ? '' : ' AND location_id = '.$locationId);

        try {
            $row = StockAlert::find($condition, 1, 'id', 'DESC');
        } catch (Throwable) {
            return [];
        }

        return is_array($row) ? $row : [];
    }

    /** Tutti gli avvisi aperti di un prodotto, in ogni sede. @return list<array<string, mixed>> */
    public static function openRows(int $productId): array
    {
        try {
            $rows = StockAlert::find(self::condition($productId), null, 'id', 'ASC');
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    private static function condition(int $productId): string
    {
        return 'product_id = '.$productId
            ." AND deleted = 'false'"
            .' AND resolved_at IS NULL';
    }

    /** @param list<int> $ids */
    private static function close(array $ids, string $now): void
    {
        foreach ($ids as $id) {
            StockAlert::update(['resolved_at' => $now], $id);
        }
    }
}
