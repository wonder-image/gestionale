<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Sql\Transaction;

/**
 * Un'uscita di magazzino che non è una vendita — scarico, inventario in meno,
 * rettifica in meno — divisa fra i secchi (G3.13, G3.14).
 *
 * `StockPicking` sceglie i secchi della sede, ogni parte è un movimento con il
 * costo del suo secchio (zero senza «Acquisti»); quello che manca va nel
 * secchio 0, dove `Stock::apply()` decide come sempre: sotto zero solo con la
 * vendita scoperta, altrimenti `stock.insufficient`. Con il solo secchio 0 è
 * un movimento solo, come prima di G3.
 */
final class StockOut
{
    /**
     * @param array<string, mixed> $movement come per `Stock::apply()`, con `product_id`, `location_id` e `quantity` negativa
     * @return array{movement_id: int, parts: list<array{batch_id: int, supplier_id: int, quantity: float}>}
     */
    public static function take(array $movement, bool $withExpired = false): array
    {
        $quantity = -round((float) ($movement['quantity'] ?? 0), 3);
        $locationId = (int) ($movement['location_id'] ?? 0) ?: Locations::mainId();

        if ($quantity <= 0) {
            throw new \InvalidArgumentException('StockOut vuole una quantità negativa.');
        }

        return Transaction::run(static function () use ($movement, $quantity, $locationId, $withExpired): array {
            $stock = StockBuckets::locked((int) ($movement['product_id'] ?? 0), $locationId);
            $split = StockPicking::split($stock['buckets'], $quantity, [
                'use_batches' => $stock['use_batches'],
                'use_suppliers' => $stock['use_suppliers'],
                'include_expired' => $withExpired,
                'batch_id' => (int) ($movement['batch_id'] ?? 0),
                'supplier_id' => (int) ($movement['supplier_id'] ?? 0),
            ]);
            $parts = $split['missing'] > 0 ? StockMovements::addToZero($split['parts'], $split['missing']) : $split['parts'];
            $movementId = 0;

            foreach ($parts as $part) {
                $movementId = Stock::apply([
                    'location_id' => $locationId,
                    'quantity' => -$part['quantity'],
                    'batch_id' => $part['batch_id'],
                    'supplier_id' => $part['supplier_id'],
                    'unit_cost' => self::cost($stock, $part),
                ] + $movement)['movement_id'];
            }

            return ['movement_id' => $movementId, 'parts' => $parts];
        });
    }

    /**
     * Il costo di un secchio sul movimento: zero senza «Acquisti».
     *
     * @param array{buckets: list<array<string, mixed>>, use_suppliers: bool} $stock
     * @param array{batch_id: int, supplier_id: int} $part
     */
    public static function cost(array $stock, array $part): float
    {
        if (!$stock['use_suppliers']) {
            return 0.0;
        }

        foreach ($stock['buckets'] as $bucket) {
            if ($bucket['batch_id'] === $part['batch_id'] && $bucket['supplier_id'] === $part['supplier_id']) {
                return (float) ($bucket['cost'] ?? 0.0);
            }
        }

        return 0.0;
    }
}
