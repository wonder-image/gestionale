<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Backend\Support\SpreadsheetText;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;

/**
 * I clienti che hanno ricevuto un lotto (G3.20): la domanda di un richiamo.
 *
 * Si parte dai movimenti del lotto legati a una riga d'ordine
 * (`reference_item_id`): vendite, annulli e resi. Per ogni riga il netto è
 * quello che è uscito meno quello che è tornato, come in
 * `StockMovements::net()`; un ordine annullato o reso del tutto non c'è.
 * Le righe si sommano per ordine, e l'ordine dice chi è il cliente.
 *
 * `perItem()` e `perOrder()` sono pure; `of()` legge.
 */
final class BatchCustomers
{
    private const TYPES = ['sale', 'sale_cancel', 'return'];

    /**
     * Il netto uscito per riga d'ordine: la vendita è negativa, annulli e
     * resi positivi. Righe a zero o sotto non ci sono.
     *
     * @param list<array<string, mixed>> $movements movimenti di un lotto
     * @return array<int, float> riga d'ordine → pezzi
     */
    public static function perItem(array $movements): array
    {
        $net = [];

        foreach ($movements as $movement) {
            $item = (int) ($movement['reference_item_id'] ?? 0);

            if ($item <= 0 || !in_array((string) ($movement['type'] ?? ''), self::TYPES, true)) {
                continue;
            }

            $net[$item] = round(($net[$item] ?? 0.0) - (float) ($movement['quantity'] ?? 0), 3);
        }

        return array_filter($net, static fn (float $quantity): bool => $quantity > 0);
    }

    /**
     * @param array<int, float> $perItem riga d'ordine → pezzi
     * @param array<int, int> $itemOrders riga d'ordine → ordine
     * @return array<int, float> ordine → pezzi
     */
    public static function perOrder(array $perItem, array $itemOrders): array
    {
        $out = [];

        foreach ($perItem as $item => $quantity) {
            $order = (int) ($itemOrders[$item] ?? 0);

            if ($order > 0) {
                $out[$order] = round(($out[$order] ?? 0.0) + $quantity, 3);
            }
        }

        return $out;
    }

    /**
     * I clienti del lotto, dall'ordine più recente.
     *
     * @return list<array{order_id: int, order_number: string, ordered_at: string, customer: string, email: string, phone: string, quantity: float}>
     */
    public static function of(int $batchId): array
    {
        if ($batchId <= 0) {
            return [];
        }

        $movements = self::rows(StockMovement::find(
            '`batch_id` = '.$batchId." AND `type` IN ('".implode("', '", self::TYPES)."') AND `reference_item_id` > 0 AND `deleted` = 'false'"
        ));
        $perItem = self::perItem($movements);

        if ($perItem === []) {
            return [];
        }

        $itemOrders = [];

        foreach (self::rows(OrderItem::find('`id` IN ('.implode(', ', array_keys($perItem)).") AND `deleted` IN ('true', 'false')")) as $item) {
            $itemOrders[(int) $item['id']] = (int) $item['order_id'];
        }

        $perOrder = self::perOrder($perItem, $itemOrders);

        if ($perOrder === []) {
            return [];
        }

        $out = [];

        foreach (self::rows(Order::find('`id` IN ('.implode(', ', array_keys($perOrder)).") AND `deleted` IN ('true', 'false')")) as $order) {
            $id = (int) $order['id'];
            $out[] = [
                'order_id' => $id,
                'order_number' => (string) ($order['order_number'] ?? ''),
                'ordered_at' => (string) ($order['ordered_at'] ?? ''),
                'customer' => self::text(OrderResource::customerName($order)),
                'email' => self::text((string) ($order['email'] ?? '')),
                'phone' => self::text((string) ($order['phone'] ?? '')),
                'quantity' => $perOrder[$id],
            ];
        }

        usort($out, static fn (array $a, array $b): int => [$b['ordered_at'], $b['order_id']] <=> [$a['ordered_at'], $a['order_id']]);

        return $out;
    }

    /**
     * Il CSV del richiamo, col punto e virgola che Excel in italiano apre da
     * sé e il BOM per gli accenti.
     *
     * @param list<array{order_number: string, ordered_at: string, customer: string, email: string, phone: string, quantity: float}> $rows
     */
    public static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['Ordine', 'Data', 'Cliente', 'Email', 'Telefono', 'Pezzi'], ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, [
                SpreadsheetText::cell($row['order_number']),
                substr($row['ordered_at'], 0, 10),
                SpreadsheetText::cell($row['customer']),
                SpreadsheetText::cell($row['email']),
                SpreadsheetText::cell($row['phone']),
                str_replace('.', ',', (string) ($row['quantity'] + 0)),
            ], ';', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\u{FEFF}".$csv;
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
