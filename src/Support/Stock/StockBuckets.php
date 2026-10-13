<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Stock\Batch;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;

/**
 * I secchi di un prodotto in una sede, letti con il lock (G3, spec §1.3).
 *
 * La lettura è la stessa di `Allocation` prima di G3: tutte le righe di
 * `gst_stock` di quel prodotto in quella sede, `FOR UPDATE`, prima delle
 * prenotazioni. Così due vendite sugli stessi pezzi si mettono in fila
 * nello stesso ordine di prima.
 *
 * Ogni secchio porta la scadenza del suo lotto (solo con «Lotti e scadenze»)
 * e il suo costo (solo con «Acquisti»): con le funzionalità spente scadenza
 * e costo restano vuoti, e niente scade.
 *
 * Va chiamata dentro `Transaction::run()`.
 */
final class StockBuckets
{
    /**
     * @return array{buckets: list<array{batch_id: int, supplier_id: int, quantity: float, expiry_date: ?string, cost: ?float}>, quantity: float, sellable: float, use_batches: bool, use_suppliers: bool}
     */
    public static function locked(int $productId, int $locationId, ?string $today = null): array
    {
        $rows = self::rows(StockRow::findForUpdate([
            'product_id' => $productId,
            'location_id' => $locationId,
            'deleted' => 'false',
        ]));

        return self::describe($rows, $productId, $today);
    }

    /**
     * Le righe lette, con scadenze e costi. Separata da `locked()` perché
     * `Levels::forSale()` fa la stessa cosa senza lock.
     *
     * @param list<array<string, mixed>> $rows righe di `gst_stock` di un prodotto e una sede
     * @return array{buckets: list<array{batch_id: int, supplier_id: int, quantity: float, expiry_date: ?string, cost: ?float}>, quantity: float, sellable: float, use_batches: bool, use_suppliers: bool}
     */
    public static function describe(array $rows, int $productId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $useBatches = Gestionale::feature('batch_tracking');
        $useSuppliers = Gestionale::feature('purchasing');
        $expiries = $useBatches ? self::expiries(array_map(static fn (array $r): int => (int) ($r['batch_id'] ?? 0), $rows)) : [];
        $costs = $useSuppliers ? StockCosts::forProduct($productId) : [];
        $buckets = [];
        $quantity = 0.0;
        $expired = 0.0;

        foreach ($rows as $row) {
            $batchId = (int) ($row['batch_id'] ?? 0);
            $supplierId = (int) ($row['supplier_id'] ?? 0);
            $pieces = round((float) ($row['quantity'] ?? 0), 3);
            $expiry = $expiries[$batchId] ?? null;
            $buckets[] = [
                'batch_id' => $batchId,
                'supplier_id' => $supplierId,
                'quantity' => $pieces,
                'expiry_date' => $expiry,
                'cost' => $useSuppliers ? StockCosts::of($supplierId, $costs) : null,
            ];
            $quantity += $pieces;

            if ($pieces > 0 && StockPicking::isExpired($expiry, $today)) {
                $expired += $pieces;
            }
        }

        return [
            'buckets' => $buckets,
            'quantity' => round($quantity, 3) + 0.0,
            'sellable' => round($quantity - $expired, 3) + 0.0,
            'use_batches' => $useBatches,
            'use_suppliers' => $useSuppliers,
        ];
    }

    /**
     * Le scadenze dei lotti, per id; un lotto senza scadenza non c'è.
     *
     * @param list<int> $batchIds
     * @return array<int, string>
     */
    public static function expiries(array $batchIds): array
    {
        $ids = array_values(array_unique(array_filter($batchIds, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (self::rows(Batch::find('`id` IN ('.implode(', ', $ids).") AND `deleted` IN ('true', 'false')")) as $batch) {
            $expiry = trim((string) ($batch['expiry_date'] ?? ''));

            if ($expiry !== '' && !str_starts_with($expiry, '0000-00-00')) {
                $out[(int) $batch['id']] = substr($expiry, 0, 10);
            }
        }

        return $out;
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
