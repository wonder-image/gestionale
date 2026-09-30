<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentSequences;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Plugin\Gestionale\Support\Tax\TaxTotals;
use Wonder\Sql\Transaction;

/**
 * Da carrello a ordine, in una transazione sola.
 *
 * È il punto più delicato del modulo: qui la merce smette di essere libera e
 * il denaro comincia ad avere un nome. Se una qualsiasi di queste cose non
 * riesce — la merce finita mentre il cliente scriveva l'indirizzo, il metodo
 * di pagamento spento dal commerciante un minuto fa — **non deve restare
 * niente a metà**: né un ordine numerato senza merce, né merce impegnata per
 * un ordine che non esiste. Per questo tutto sta dentro `Transaction::run()`,
 * e la prenotazione blocca le righe di magazzino con `FOR UPDATE` dentro
 * `Allocation`.
 *
 * L'ordine dei passi non è casuale: prima si scrive chi compra e come paga,
 * perché il paese di fatturazione cambia l'IVA e il metodo può aggiungere una
 * commissione; poi si ricalcola; poi si prenota; e solo alla fine si prende il
 * numero. Prendere il numero prima vorrebbe dire bruciarne uno a ogni
 * tentativo fallito, e la numerazione dei documenti non ammette buchi.
 */
final class Checkout
{
    /**
     * @param array<string, mixed> $data
     * @return array{order_id: int, order_number: string, payment_id: int, total: string, reserved: int, status: string}
     */
    public static function place(int $cartId, array $data): array
    {
        $result = Transaction::run(static function () use ($cartId, $data): array {
            $cart = Order::findForUpdate(['id' => $cartId], 1);

            if (!is_array($cart) || $cart === [] || (string) $cart['stage'] !== 'cart') {
                throw UserError::make('cart.not_a_cart');
            }

            $method = self::method((int) ($data['payment_method_id'] ?? 0));
            Order::update(self::details($data, $method), $cartId);

            self::applyFee($cartId, $method);
            $recalculated = Cart::recalculate($cartId);
            $order = $recalculated['order'];
            $items = $recalculated['items'];

            if (self::goods($items) === []) {
                throw UserError::make('order.empty_cart');
            }

            $timing = PaymentTiming::of((int) $method['id']);
            $expires = PaymentTiming::reservationExpiry($timing);
            $reserved = 0;

            foreach (self::goods($items) as $item) {
                Allocation::reserve([
                    'product_id' => (int) $item['product_id'],
                    'quantity' => (float) $item['quantity'],
                    'location_id' => (int) ($order['location_id'] ?? 0),
                    'order_id' => $cartId,
                    'order_item_id' => (int) $item['id'],
                    'expires_at' => $expires ?? '',
                ]);
                ++$reserved;
            }

            $number = DocumentSequences::next('order');
            Order::update([
                'stage' => 'order',
                'status' => 'pending',
                'order_number' => $number,
                'ordered_at' => date('Y-m-d H:i:s'),
            ], $cartId);

            StatusLogger::record(
                OrderStatusLog::class,
                $cartId,
                'status',
                (string) $cart['status'],
                'pending',
                (string) ($data['source'] ?? 'user'),
                (int) ($data['user_id'] ?? 0) ?: null
            );

            $payment = Ledger::open([
                'order_id' => $cartId,
                'amount' => (float) $order['total'],
                'customer_id' => (int) ($order['customer_id'] ?? 0),
                'payment_method_id' => (int) $method['id'],
                'payment_account_id' => (int) ($method['payment_account_id'] ?? 0),
                'currency' => (string) ($order['currency'] ?? 'EUR'),
                'provider' => (string) ($method['provider'] ?? 'manual'),
                'source' => (string) ($data['source'] ?? 'user'),
                'user_id' => (int) ($data['user_id'] ?? 0),
            ]);

            self::writeTaxSummaries($cartId, $recalculated);

            return [
                'order_id' => $cartId,
                'order_number' => $number,
                'payment_id' => (int) $payment['payment_id'],
                'total' => (string) $order['total'],
                'reserved' => $reserved,
                'timing' => $timing,
                'status' => 'pending',
            ];
        });

        // Il contrassegno e il ritiro si pagano alla consegna: l'ordine è
        // buono così com'è e la merce può uscire subito. `confirm()` manda la
        // sua email di conferma, quindi qui basta avvisare il commerciante.
        if ($result['timing'] === PaymentTiming::ON_DELIVERY) {
            $confirmed = Lifecycle::confirm($result['order_id'], [
                'payment' => false,
                'source' => (string) ($data['source'] ?? 'user'),
                'user_id' => (int) ($data['user_id'] ?? 0),
            ]);
            $result['status'] = $confirmed['status'];
        } else {
            OrderNotifier::send('received', $result['order_id']);
        }

        OrderNotifier::send('merchant_new', $result['order_id']);
        unset($result['timing']);

        return $result;
    }

    /**
     * Il metodo di pagamento, se si può ancora usare.
     *
     * @return array<string, mixed>
     */
    private static function method(int $methodId): array
    {
        $method = $methodId > 0 ? PaymentMethod::findById($methodId) : null;

        if (!is_array($method) || $method === [] || ($method['active'] ?? 'false') !== 'true') {
            throw UserError::make('order.payment_method_unavailable');
        }

        return $method;
    }

    /**
     * I dati che il cliente ha scritto, pronti per la riga dell'ordine.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $method
     * @return array<string, string|int>
     */
    private static function details(array $data, array $method): array
    {
        $fields = [
            'email' => (string) ($data['email'] ?? ''),
            'phone' => (string) ($data['phone'] ?? ''),
            'customer_id' => (int) ($data['customer_id'] ?? 0),
            'payment_method_id' => (int) $method['id'],
            'shipping_method_id' => (int) ($data['shipping_method_id'] ?? 0),
            'location_id' => (int) ($data['location_id'] ?? 0),
            'customer_note' => (string) ($data['customer_note'] ?? ''),
            'last_activity_at' => date('Y-m-d H:i:s'),
        ];

        $type = (string) ($data['fulfillment_type'] ?? 'shipping');
        $fields['fulfillment_type'] = in_array($type, Order::FULFILLMENT_TYPES, true) ? $type : 'shipping';

        foreach (['billing', 'shipping'] as $group) {
            $address = $data[$group] ?? [];

            if (!is_array($address)) {
                continue;
            }

            foreach ($address as $key => $value) {
                if (is_scalar($value)) {
                    $fields[$group.'_'.$key] = (string) $value;
                }
            }
        }

        return $fields;
    }

    /**
     * La commissione del metodo, come riga dell'ordine.
     *
     * Una sola: se il cliente torna indietro e cambia metodo, quella di prima
     * se ne va invece di sommarsi.
     *
     * @param array<string, mixed> $method
     */
    private static function applyFee(int $orderId, array $method): void
    {
        foreach (self::rows(OrderItem::find(['order_id' => $orderId, 'type' => 'fee', 'deleted' => 'false'])) as $old) {
            OrderItem::delete((int) $old['id']);
        }

        $type = (string) ($method['fee_type'] ?? 'none');
        $value = round((float) ($method['fee_value'] ?? 0), 2);

        if ($type === 'none' || $value <= 0) {
            return;
        }

        $order = Order::findById($orderId);
        $base = (float) (is_array($order) ? ($order['products_total'] ?? 0) : 0);
        $amount = $type === 'percent' ? round($base * min($value, 100.0) / 100, 2) : $value;

        if ($amount <= 0) {
            return;
        }

        OrderItem::create([
            'order_id' => $orderId,
            'type' => 'fee',
            'position' => 900,
            'name' => (string) ($method['name'] ?? 'Commissione'),
            'quantity' => '1.000',
            'list_price' => number_format($amount, 2, '.', ''),
            'unit_price' => number_format($amount, 2, '.', ''),
            'price_source' => 'manual',
            'line_total' => number_format($amount, 2, '.', ''),
            // La commissione non ha un tipo fiscale suo: prende l'aliquota di
            // ripiego delle impostazioni, come la spedizione.
            'tax_category_id' => 0,
        ]);
    }

    /**
     * Riscrive i riepiloghi IVA dell'ordine.
     *
     * @param array{order: array<string, mixed>, items: list<array<string, mixed>>} $recalculated
     */
    private static function writeTaxSummaries(int $orderId, array $recalculated): void
    {
        sqlDelete(OrderTaxSummary::$table, 'order_id = '.$orderId);

        $order = $recalculated['order'];
        $lines = [];

        foreach ($recalculated['items'] as $item) {
            $lines[] = [
                'total' => round((float) $item['line_total'] - (float) ($item['order_discount_amount'] ?? 0), 2),
                'rate' => (float) ($item['tax_rate'] ?? 0),
                'nature' => (string) ($item['tax_nature'] ?? ''),
            ];
        }

        $includeTax = (string) ($order['prices_include_tax'] ?? 'true') === 'true';

        foreach (TaxTotals::summaries($lines, $includeTax) as $summary) {
            OrderTaxSummary::create([
                'order_id' => $orderId,
                'rate' => number_format($summary['rate'], 2, '.', ''),
                'taxable' => number_format($summary['taxable'], 2, '.', ''),
                'tax' => number_format($summary['tax'], 2, '.', ''),
                'total' => number_format($summary['total'], 2, '.', ''),
                'nature' => $summary['nature'],
            ]);
        }
    }

    /**
     * Le righe che hanno merce dietro: quelle da prenotare.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function goods(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (array $item): bool => (string) $item['type'] === 'product'
                && (int) ($item['product_id'] ?? 0) > 0
                && (float) $item['quantity'] > 0
        ));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }

        return isset($result['id']) ? [$result] : array_values(array_filter($result, 'is_array'));
    }
}
