<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;

/**
 * Quanti pezzi ci sono, quanti sono impegnati, quanti se ne possono vendere.
 *
 * `of()` e `forProducts()` sommano tutte le sedi: è il numero che interessa
 * a chi guarda un articolo. `byLocation()` tiene le sedi separate: serve
 * alla scheda con più sedi e agli avvisi, che ragionano per sede. La regola
 * di cosa conta come impegnato sta in `Availability`, che è pura; qui c'è
 * solo la lettura, e il conto per sede sta in `perLocation()`, pura anche lei.
 *
 * Le versioni al plurale esistono per gli elenchi, che altrimenti farebbero
 * due query per riga.
 */
final class Levels
{
    private const EMPTY = ['quantity' => 0.0, 'reserved' => 0.0, 'available' => 0.0];

    /** @return array{quantity: float, reserved: float, available: float} */
    public static function of(int $productId): array
    {
        return self::forProducts([$productId])[$productId] ?? self::EMPTY;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, array{quantity: float, reserved: float, available: float}>
     */
    public static function forProducts(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $levels = [];

        foreach ($ids as $id) {
            $levels[$id] = self::EMPTY;
        }

        if ($ids === []) {
            return $levels;
        }

        $now = date('Y-m-d H:i:s');

        foreach (self::rows(StockRow::class, $ids) as $row) {
            $id = (int) ($row['product_id'] ?? 0);

            if (isset($levels[$id])) {
                $levels[$id]['quantity'] = round(
                    $levels[$id]['quantity'] + (float) ($row['quantity'] ?? 0),
                    3
                );
            }
        }

        $reservations = [];

        foreach (self::rows(StockReservation::class, $ids) as $row) {
            $reservations[(int) ($row['product_id'] ?? 0)][] = $row;
        }

        foreach ($levels as $id => $level) {
            $rows = $reservations[$id] ?? [];
            $levels[$id]['reserved'] = Availability::reserved($rows, $now);
            $levels[$id]['available'] = Availability::of($level['quantity'], $rows, $now);
        }

        return $levels;
    }

    /** @return array<int, array{quantity: float, reserved: float, available: float}> per sede, solo le sedi con righe */
    public static function byLocation(int $productId): array
    {
        return self::byLocationForProducts([$productId])[$productId] ?? [];
    }

    /**
     * @param list<int> $productIds
     * @return array<int, array<int, array{quantity: float, reserved: float, available: float}>> per prodotto, poi per sede
     */
    public static function byLocationForProducts(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $levels = array_fill_keys($ids, []);

        if ($ids === []) {
            return $levels;
        }

        $perLocation = self::perLocation(
            self::rows(StockRow::class, $ids),
            self::rows(StockReservation::class, $ids),
            date('Y-m-d H:i:s')
        );

        foreach ($ids as $id) {
            $levels[$id] = $perLocation[$id] ?? [];
        }

        return $levels;
    }

    /**
     * Il conto per prodotto e sede, puro: pezzi, impegnati, disponibili.
     *
     * Le righe di `gst_stock` si sommano per prodotto e sede (lotti e
     * fornitori diversi sono righe diverse della stessa sede); le prenotazioni
     * contano solo se vive adesso. Un prodotto senza righe non compare; le
     * sedi stanno in ordine di id.
     *
     * @param list<array<string, mixed>> $stockRows righe di `gst_stock`
     * @param list<array<string, mixed>> $reservationRows righe di `gst_stock_reservations`
     * @param string|null $now `Y-m-d H:i:s`; null = adesso
     * @return array<int, array<int, array{quantity: float, reserved: float, available: float}>>
     */
    public static function perLocation(array $stockRows, array $reservationRows, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $quantities = [];
        $reservations = [];

        foreach ($stockRows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $locationId = (int) ($row['location_id'] ?? 0);
            $quantities[$productId][$locationId] = round(
                ($quantities[$productId][$locationId] ?? 0.0) + (float) ($row['quantity'] ?? 0),
                3
            );
        }

        foreach ($reservationRows as $row) {
            $reservations[(int) ($row['product_id'] ?? 0)][(int) ($row['location_id'] ?? 0)][] = $row;
        }

        $levels = [];

        foreach (array_keys($quantities + $reservations) as $productId) {
            $locationIds = array_keys(($quantities[$productId] ?? []) + ($reservations[$productId] ?? []));
            sort($locationIds);

            foreach ($locationIds as $locationId) {
                $rows = $reservations[$productId][$locationId] ?? [];
                $quantity = $quantities[$productId][$locationId] ?? 0.0;
                $levels[$productId][$locationId] = [
                    'quantity' => $quantity,
                    'reserved' => Availability::reserved($rows, $now),
                    'available' => Availability::of($quantity, $rows, $now),
                ];
            }
        }

        return $levels;
    }

    /**
     * Le righe vive di un Model per un elenco di prodotti.
     *
     * Senza database (test degli schemi, comandi) torna vuoto invece di far
     * esplodere chi legge.
     *
     * @param class-string<\Wonder\App\Model> $modelClass
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private static function rows(string $modelClass, array $ids): array
    {
        try {
            $rows = $modelClass::find(
                'product_id IN ('.implode(',', $ids).") AND deleted = 'false'"
            );
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
