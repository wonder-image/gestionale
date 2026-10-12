<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;

/**
 * Il costo di un secchio (G3, spec §1.4), IVA esclusa (G3.21).
 *
 * Fornitore noto: il suo costo per quella versione in `gst_product_suppliers`.
 * Fornitore 0 (la merce di prima, o entrata senza fornitore): il costo più
 * basso fra i fornitori della versione (G3.6). Nessun costo: null.
 *
 * `of()` è pura; `forProduct()` legge i costi di una versione.
 */
final class StockCosts
{
    /** @param array<int, float> $supplierCosts fornitore → costo, solo quelli noti */
    public static function of(int $supplierId, array $supplierCosts): ?float
    {
        if ($supplierId > 0) {
            return array_key_exists($supplierId, $supplierCosts) ? (float) $supplierCosts[$supplierId] : null;
        }

        return $supplierCosts === [] ? null : (float) min($supplierCosts);
    }

    /**
     * I costi d'acquisto di una versione, per fornitore. Un costo vuoto non
     * è uno zero: non entra.
     *
     * @return array<int, float>
     */
    public static function forProduct(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        try {
            $rows = ProductSupplier::find(['product_id' => $productId, 'deleted' => 'false']);
        } catch (Throwable) {
            return [];
        }

        $rows = !is_array($rows) || $rows === [] ? [] : (isset($rows['id']) ? [$rows] : array_filter($rows, 'is_array'));
        $costs = [];

        foreach ($rows as $row) {
            $cost = $row['cost'] ?? null;

            if ($cost === null || trim((string) $cost) === '') {
                continue;
            }

            $costs[(int) $row['supplier_id']] = round((float) $cost, 4);
        }

        return $costs;
    }
}
