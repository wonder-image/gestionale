<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
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
        $stock = self::rows(StockRow::class, $ids);
        $expiries = self::expiries($stock);
        $expired = [];

        foreach ($stock as $row) {
            $id = (int) ($row['product_id'] ?? 0);

            if (isset($levels[$id])) {
                $levels[$id]['quantity'] = round(
                    $levels[$id]['quantity'] + (float) ($row['quantity'] ?? 0),
                    3
                );
                $expired[$id] = round(($expired[$id] ?? 0.0) + self::expiredPieces($row, $expiries, $now), 3);
            }
        }

        $reservations = [];

        foreach (self::rows(StockReservation::class, $ids) as $row) {
            $reservations[(int) ($row['product_id'] ?? 0)][] = $row;
        }

        foreach ($levels as $id => $level) {
            $rows = $reservations[$id] ?? [];
            $levels[$id]['reserved'] = Availability::reserved($rows, $now);
            // I lotti scaduti ci sono ma non si vendono (G3.11).
            $levels[$id]['available'] = Availability::of(round($level['quantity'] - ($expired[$id] ?? 0.0), 3), $rows, $now);
        }

        return $levels;
    }

    /**
     * Quello che si può vendere da una sede (G3, spec §2.2): giacenza della
     * sola sede, pezzi nei lotti scaduti (zero senza «Lotti e scadenze»),
     * impegnati della sede e disponibile = giacenza − scaduti − impegnati.
     *
     * È il numero del carrello e della vetrina, con la sede di
     * `Locations::forSale()`. A funzionalità spente la principale è l'unica
     * sede con merce e niente scade: è lo stesso numero di `forProducts()`.
     *
     * @param list<int> $productIds
     * @return array<int, array{quantity: float, expired: float, reserved: float, available: float}>
     */
    public static function forSale(array $productIds, int $locationId): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $levels = array_fill_keys($ids, ['quantity' => 0.0, 'expired' => 0.0, 'reserved' => 0.0, 'available' => 0.0]);

        if ($ids === []) {
            return $levels;
        }

        $now = date('Y-m-d H:i:s');
        $stock = self::rows(StockRow::class, $ids, $locationId);
        $expiries = Gestionale::feature('batch_tracking')
            ? StockBuckets::expiries(array_map(static fn (array $r): int => (int) ($r['batch_id'] ?? 0), $stock))
            : [];

        foreach ($stock as $row) {
            $id = (int) ($row['product_id'] ?? 0);
            $pieces = (float) ($row['quantity'] ?? 0);

            if (!isset($levels[$id])) {
                continue;
            }

            $levels[$id]['quantity'] = round($levels[$id]['quantity'] + $pieces, 3);

            if ($pieces > 0 && StockPicking::isExpired($expiries[(int) ($row['batch_id'] ?? 0)] ?? null, substr($now, 0, 10))) {
                $levels[$id]['expired'] = round($levels[$id]['expired'] + $pieces, 3);
            }
        }

        $reservations = [];

        foreach (self::rows(StockReservation::class, $ids, $locationId) as $row) {
            $reservations[(int) ($row['product_id'] ?? 0)][] = $row;
        }

        foreach ($levels as $id => $level) {
            $rows = $reservations[$id] ?? [];
            $levels[$id]['reserved'] = Availability::reserved($rows, $now);
            $levels[$id]['available'] = Availability::of(round($level['quantity'] - $level['expired'], 3), $rows, $now);
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

        $stock = self::rows(StockRow::class, $ids);
        $perLocation = self::perLocation(
            $stock,
            self::rows(StockReservation::class, $ids),
            date('Y-m-d H:i:s'),
            self::expiries($stock)
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
     * I pezzi dei lotti scaduti (G3.11) restano nella giacenza ma escono dal
     * disponibile: `$expiries` dà la scadenza di ogni lotto, per id.
     *
     * @param string|null $now `Y-m-d H:i:s`; null = adesso
     * @param array<int, string> $expiries lotto → scadenza `Y-m-d`
     * @return array<int, array<int, array{quantity: float, reserved: float, available: float}>>
     */
    public static function perLocation(array $stockRows, array $reservationRows, ?string $now = null, array $expiries = []): array
    {
        $now ??= date('Y-m-d H:i:s');
        $quantities = [];
        $expired = [];
        $reservations = [];

        foreach ($stockRows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $locationId = (int) ($row['location_id'] ?? 0);
            $quantities[$productId][$locationId] = round(
                ($quantities[$productId][$locationId] ?? 0.0) + (float) ($row['quantity'] ?? 0),
                3
            );
            $expired[$productId][$locationId] = round(
                ($expired[$productId][$locationId] ?? 0.0) + self::expiredPieces($row, $expiries, $now),
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
                    'available' => Availability::of(round($quantity - ($expired[$productId][$locationId] ?? 0.0), 3), $rows, $now),
                ];
            }
        }

        return $levels;
    }

    /**
     * Le scadenze dei lotti di queste righe, solo con «Lotti e scadenze»:
     * spenta, niente scade.
     *
     * @param list<array<string, mixed>> $stockRows
     * @return array<int, string>
     */
    private static function expiries(array $stockRows): array
    {
        if (!Gestionale::feature('batch_tracking')) {
            return [];
        }

        try {
            return StockBuckets::expiries(array_map(static fn (array $r): int => (int) ($r['batch_id'] ?? 0), $stockRows));
        } catch (Throwable) {
            return [];
        }
    }

    /** I pezzi di una riga se il suo lotto è scaduto; zero altrimenti. */
    private static function expiredPieces(array $row, array $expiries, string $now): float
    {
        $pieces = (float) ($row['quantity'] ?? 0);
        $expiry = $expiries[(int) ($row['batch_id'] ?? 0)] ?? null;

        return $pieces > 0 && StockPicking::isExpired($expiry, substr($now, 0, 10)) ? $pieces : 0.0;
    }

    /**
     * Le righe vive di un Model per un elenco di prodotti.
     *
     * Senza database (test degli schemi, comandi) torna vuoto invece di far
     * esplodere chi legge.
     *
     * @param class-string<\Wonder\App\Model> $modelClass
     * @param list<int> $ids
     * @param int $locationId 0 = tutte le sedi
     * @return list<array<string, mixed>>
     */
    private static function rows(string $modelClass, array $ids, int $locationId = 0): array
    {
        try {
            $rows = $modelClass::find(
                'product_id IN ('.implode(',', $ids).')'
                .($locationId > 0 ? ' AND location_id = '.$locationId : '')
                ." AND deleted = 'false'"
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
