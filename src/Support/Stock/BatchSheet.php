<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Resources\Stock\BatchResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;
use Wonder\Plugin\Gestionale\Support\Contacts\SupplierNames;

/**
 * Quello che la scheda di un lotto mostra (G3.20): i dati, i pezzi per sede,
 * gli ultimi movimenti e i clienti che l'hanno ricevuto. I testi sono già
 * decodificati; l'HTML lo escapa la pagina, salvo `type` dei movimenti e
 * `expiry` che arrivano già pronti.
 */
final class BatchSheet
{
    /** Quanti movimenti la scheda mostra: gli altri stanno nei Movimenti. */
    public const MOVEMENTS = 50;

    /**
     * @param array<string, mixed> $batch una riga di `gst_batches`
     * @return array<string, mixed>
     */
    public static function for(array $batch): array
    {
        $id = (int) ($batch['id'] ?? 0);
        $productId = (int) ($batch['product_id'] ?? 0);
        $locations = self::locations($id);
        $total = round(array_sum(array_column($locations, 'quantity')), 3);
        $customers = BatchCustomers::of($id);

        return [
            'number' => self::text((string) ($batch['number'] ?? '')),
            'product' => StockMovementResource::productLabel($productId),
            'product_url' => ProductResource::editUrlFor($productId),
            'expiry' => BatchExpiry::badge((string) ($batch['expiry_date'] ?? ''), date('Y-m-d'), BatchExpiry::days()),
            'supplier' => Gestionale::feature('purchasing') ? SupplierNames::of((int) ($batch['supplier_id'] ?? 0)) : '',
            'note' => self::text((string) ($batch['note'] ?? '')),
            'total' => BatchResource::pieces($total),
            'locations' => count(Locations::shown()) >= 2 ? $locations : [],
            'movements' => self::movements($id),
            'movements_url' => StockMovementResource::listUrlFor($productId),
            'customers' => array_map(static fn (array $row): array => $row + [
                'url' => OrderResource::detailUrl($row['order_id']),
                'date' => OrderResource::date($row['ordered_at']),
                'pieces' => BatchResource::pieces($row['quantity']),
            ], $customers),
            'customers_url' => BatchResource::customersUrl($id),
        ];
    }

    /** @return list<array{label: string, quantity: float, pieces: string}> */
    private static function locations(int $batchId): array
    {
        $sum = [];

        foreach (self::rows(StockRow::find(['batch_id' => $batchId, 'deleted' => 'false'])) as $row) {
            $location = (int) $row['location_id'];
            $sum[$location] = round(($sum[$location] ?? 0.0) + (float) $row['quantity'], 3);
        }

        $out = [];

        foreach (Locations::shown() as $location) {
            if (isset($sum[$location['id']])) {
                $out[] = ['label' => $location['label'], 'quantity' => $sum[$location['id']], 'pieces' => BatchResource::pieces($sum[$location['id']])];
                unset($sum[$location['id']]);
            }
        }

        foreach ($sum as $id => $quantity) {
            $out[] = ['label' => 'Sede #'.$id, 'quantity' => $quantity, 'pieces' => BatchResource::pieces($quantity)];
        }

        return $out;
    }

    /** @return list<array{date: string, type: string, quantity: string, url: string}> */
    private static function movements(int $batchId): array
    {
        $out = [];

        foreach (self::rows(StockMovement::find(['batch_id' => $batchId, 'deleted' => 'false'], self::MOVEMENTS, 'id', 'DESC')) as $movement) {
            $quantity = (float) ($movement['quantity'] ?? 0);
            $out[] = [
                'date' => OrderResource::date((string) ($movement['creation'] ?? '')),
                'type' => StockMovementResource::typeCellOf($movement),
                'quantity' => ($quantity > 0 ? '+' : '').BatchResource::pieces($quantity),
                'url' => StockMovementResource::detailUrl((int) $movement['id']),
            ];
        }

        return $out;
    }

    private static function text(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
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
