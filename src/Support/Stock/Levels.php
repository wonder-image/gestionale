<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;

/**
 * Quanti pezzi ci sono, quanti sono impegnati, quanti se ne possono vendere.
 *
 * Somma tutte le sedi: è il numero che interessa a chi guarda un articolo.
 * La regola di cosa conta come impegnato sta in `Availability`, che è pura;
 * qui c'è solo la lettura.
 *
 * `forProducts()` esiste per l'elenco delle giacenze, che altrimenti farebbe
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
