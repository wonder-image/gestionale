<?php

namespace Wonder\Plugin\Gestionale\Support\Shipping;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;
use Wonder\Plugin\Gestionale\Models\Shipping\ShipmentItem;
use Wonder\Plugin\Gestionale\Models\Shipping\ShipmentStatusLog;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Sql\Transaction;

/**
 * Le spedizioni vere di un ordine: cosa esce, in quante tranche, con che stato.
 *
 * Questa classe è l'unica porta che scrive `Shipment` e `ShipmentItem`. Una
 * riga d'ordine si può spedire in più tranche, ma mai più di quanto è stato
 * ordinato: il conto delle quantità già assegnate si fa **con le righe
 * dell'ordine bloccate** (`SELECT … FOR UPDATE`), così due operatori che
 * creano una spedizione nello stesso istante si mettono in fila invece di
 * assegnare entrambi l'ultimo pezzo.
 */
final class Shipments
{
    /** Gli stati di una spedizione che non occupano più la merce. */
    public const RELEASED = ['cancelled', 'returned'];

    /**
     * Quanto resta da spedire di ogni riga spedibile dell'ordine.
     *
     * @return array<int, float> riga d'ordine => quantità
     */
    public static function remaining(int $orderId): array
    {
        $items = self::items($orderId);

        return ShippableLines::remaining(self::describe($items), self::assigned($orderId));
    }

    /**
     * Crea una spedizione di consegna in attesa (`pending`) con le righe date.
     *
     * @param array<int, mixed> $quantities riga d'ordine => quantità (vuota o zero = la riga non parte)
     * @param array{carrier_id?: int, tracking_number?: string, note?: string, source?: string, user_id?: int} $options
     * @return int l'id della spedizione
     * @throws UserError
     */
    public static function create(int $orderId, array $quantities, array $options = []): int
    {
        if (!Gestionale::feature('shipping')) {
            throw UserError::make('shipment.feature_off');
        }

        return Transaction::run(static function () use ($orderId, $quantities, $options): int {
            $order = Order::findForUpdate(['id' => $orderId], 1);

            if (!is_array($order) || $order === []) {
                throw UserError::make('order.not_found');
            }

            if ((string) $order['stage'] !== 'order'
                || !in_array((string) $order['status'], Lifecycle::COMMITTED, true)
                || (string) $order['fulfillment_type'] !== 'shipping') {
                throw UserError::make('shipment.order_not_open');
            }

            $wanted = self::wanted($quantities);

            if ($wanted === []) {
                throw UserError::make('shipment.nothing_to_ship');
            }

            // Prima il blocco delle righe, poi il conto di quanto è già assegnato.
            $items = self::items($orderId, true);
            $remaining = ShippableLines::remaining(self::describe($items), self::assigned($orderId, true));

            foreach ($wanted as $itemId => $quantity) {
                if (!isset($remaining[$itemId]) || $quantity > $remaining[$itemId] + 0.0005) {
                    throw UserError::make('shipment.over_quantity');
                }
            }

            $shipmentId = (int) (Shipment::create([
                'code' => Code::make(Shipment::class, Codes::SHIPMENT),
                'order_id' => $orderId,
                'type' => 'delivery',
                'status' => 'pending',
                'carrier_id' => (int) ($options['carrier_id'] ?? 0),
                'tracking_number' => trim((string) ($options['tracking_number'] ?? '')),
                'note' => trim((string) ($options['note'] ?? '')),
            ])->insert_id ?? 0);

            foreach ($wanted as $itemId => $quantity) {
                ShipmentItem::create([
                    'shipment_id' => $shipmentId,
                    'order_item_id' => $itemId,
                    'quantity' => number_format($quantity, 3, '.', ''),
                ]);
            }

            StatusLogger::record(
                ShipmentStatusLog::class,
                $shipmentId,
                'status',
                '',
                'pending',
                (string) ($options['source'] ?? 'user'),
                (int) ($options['user_id'] ?? 0) ?: null
            );

            return $shipmentId;
        });
    }

    /**
     * Le quantità chieste, pulite: le righe vuote o a zero si tolgono.
     *
     * @param array<int, mixed> $quantities
     * @return array<int, float>
     */
    private static function wanted(array $quantities): array
    {
        $wanted = [];

        foreach ($quantities as $itemId => $quantity) {
            $number = Numbers::fromForm($quantity);

            if ((int) $itemId <= 0 || $number === null || (float) $number <= 0) {
                continue;
            }

            $wanted[(int) $itemId] = round((float) $number, 3);
        }

        return $wanted;
    }

    /**
     * Le righe dell'ordine con il dato che manca per decidere se si spediscono.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function describe(array $items): array
    {
        $products = [];
        $models = [];

        foreach ($items as &$item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $products[$productId] ??= $productId > 0 ? Product::findById($productId) : null;
            $modelId = is_array($products[$productId]) ? (int) ($products[$productId]['product_model_id'] ?? 0) : 0;
            $models[$modelId] ??= $modelId > 0 ? ProductModel::findById($modelId) : null;

            // Un articolo che non si trova più resta spedibile: la riga dell'ordine è una copia.
            $item['requires_shipping'] = !is_array($models[$modelId])
                || (string) ($models[$modelId]['requires_shipping'] ?? 'true') === 'true';
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private static function items(int $orderId, bool $lock = false): array
    {
        $where = ['order_id' => $orderId, 'deleted' => 'false'];
        $rows = $lock ? OrderItem::findForUpdate($where) : OrderItem::find($where);

        return self::rows($rows);
    }

    /**
     * Le quantità già in spedizioni che occupano la merce, per riga d'ordine.
     *
     * @return array<int, float>
     */
    private static function assigned(int $orderId, bool $lock = false): array
    {
        $where = ['order_id' => $orderId, 'deleted' => 'false'];
        $shipments = self::rows($lock ? Shipment::findForUpdate($where) : Shipment::find($where));
        $assigned = [];

        foreach ($shipments as $shipment) {
            if (in_array((string) $shipment['status'], self::RELEASED, true)) {
                continue;
            }

            $where = ['shipment_id' => (int) $shipment['id'], 'deleted' => 'false'];

            foreach (self::rows($lock ? ShipmentItem::findForUpdate($where) : ShipmentItem::find($where)) as $row) {
                $itemId = (int) $row['order_item_id'];
                $assigned[$itemId] = round(($assigned[$itemId] ?? 0.0) + (float) $row['quantity'], 3);
            }
        }

        return $assigned;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        $rows = array_key_exists('id', $found) ? [$found] : array_values(array_filter($found, 'is_array'));
        usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return $rows;
    }
}
