<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentSequences;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Plugin\Gestionale\Support\Tax\TaxTotals;
use Throwable;
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
        try {
            $result = self::create($cartId, $data);
        } catch (UserError $error) {
            // Un coupon che non regge più al checkout non deve restare sul
            // carrello: l'ordine non è nato (la transazione è tornata indietro,
            // anche il distacco fatto dal ricalcolo) e il cliente riprova senza.
            if (str_starts_with($error->key(), 'coupon.')) {
                self::dropCoupon($cartId);
            }

            throw $error;
        }

        // Da qui l'ordine esiste ed è a posto: quello che segue non lo deve
        // disfare. Se la conferma cade, l'ordine resta in attesa e si può
        // confermare da capo; il commerciante lo deve sapere comunque.
        //
        // Il contrassegno e il ritiro si pagano alla consegna: l'ordine è
        // buono così com'è e la merce può uscire subito. `confirm()` manda la
        // sua email di conferma, quindi qui basta avvisare il commerciante.
        try {
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
        } catch (Throwable $error) {
            Errors::internal($error, 'checkout.after_place', ['order_id' => $result['order_id']]);
        }

        try {
            OrderNotifier::send('merchant_new', $result['order_id']);
        } catch (Throwable $error) {
            Errors::internal($error, 'checkout.merchant_notice', ['order_id' => $result['order_id']]);
        }

        unset($result['timing']);

        return $result;
    }

    /**
     * La transazione che fa nascere l'ordine.
     *
     * @param array<string, mixed> $data
     * @return array{order_id: int, order_number: string, payment_id: int, total: string, reserved: int, timing: string, status: string}
     */
    private static function create(int $cartId, array $data): array
    {
        return Transaction::run(static function () use ($cartId, $data): array {
            $cart = Order::findForUpdate(['id' => $cartId], 1);

            if (!is_array($cart) || $cart === [] || (string) $cart['stage'] !== 'cart') {
                throw UserError::make('cart.not_a_cart');
            }

            $type = self::fulfillment($data);
            $method = self::method((int) ($data['payment_method_id'] ?? 0), $type);
            self::checkAddress($data, $type);
            self::write($cartId, self::details($data, $method, $type, (string) ($cart['email'] ?? '')));

            self::applyFee($cartId, $method);
            $recalculated = Cart::recalculate($cartId);
            $order = $recalculated['order'];
            $items = $recalculated['items'];

            if (OrderLines::goods($items) === []) {
                throw UserError::make('order.empty_cart');
            }

            // L'utilizzo del coupon si prende qui, a dati di checkout scritti e
            // con la riga del coupon bloccata: chi arriva secondo conta gli usi
            // dopo il primo.
            Coupons::redeem($cartId, $recalculated);

            $timing = PaymentTiming::of((int) $method['id']);
            $expires = PaymentTiming::reservationExpiry($timing);
            $reserved = 0;

            foreach (OrderLines::goods($items) as $item) {
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
    }

    /** Toglie il coupon dal carrello, fuori dalla transazione che è fallita; un guasto qui non copre l'errore vero. */
    private static function dropCoupon(int $cartId): void
    {
        try {
            Coupons::remove($cartId);
        } catch (Throwable $error) {
            Errors::internal($error, 'checkout.coupon_remove', ['order_id' => $cartId]);
        }
    }

    /**
     * Il metodo di pagamento, se si può ancora usare per questa consegna.
     *
     * Deve essere attivo, offerto sul sito e ammesso per la consegna scelta:
     * il contrassegno di una spedizione non si usa per un ritiro, e un metodo
     * che il commerciante ha lasciato solo per il banco non compare online.
     *
     * @return array<string, mixed>
     */
    private static function method(int $methodId, string $fulfillment): array
    {
        $method = $methodId > 0 ? PaymentMethod::findById($methodId) : null;

        if (!is_array($method) || $method === []
            || ($method['active'] ?? 'false') !== 'true'
            || ($method['applies_online'] ?? 'true') !== 'true') {
            throw UserError::make('order.payment_method_unavailable');
        }

        $for = (string) ($method['available_for'] ?? 'all');

        if ($for !== 'all' && $fulfillment !== 'none' && $for !== $fulfillment) {
            throw UserError::make('order.payment_method_unavailable');
        }

        return $method;
    }

    /** @param array<string, mixed> $data */
    private static function fulfillment(array $data): string
    {
        $type = (string) ($data['fulfillment_type'] ?? 'shipping');

        return in_array($type, Order::FULFILLMENT_TYPES, true) ? $type : 'shipping';
    }

    /**
     * Una spedizione senza dove spedire non è un ordine: il corriere non
     * saprebbe dove andare. Conta l'indirizzo di consegna se il cliente l'ha
     * scritto, altrimenti quello di fatturazione. Il paese ha un ripiego e non
     * si controlla.
     *
     * @param array<string, mixed> $data
     */
    private static function checkAddress(array $data, string $fulfillment): void
    {
        if ($fulfillment !== 'shipping') {
            return;
        }

        $address = [];

        foreach (['shipping', 'billing'] as $group) {
            $candidate = is_array($data[$group] ?? null) ? $data[$group] : [];

            if (array_filter($candidate, static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '') !== []) {
                $address = $candidate;

                break;
            }
        }

        foreach (['city', 'street'] as $key) {
            if (trim((string) ($address[$key] ?? '')) === '') {
                throw UserError::make('order.address_incomplete');
            }
        }
    }

    /**
     * Scrive i dati del cliente sull'ordine. Se il modello li rifiuta — un
     * indirizzo email che non è tale — ci si ferma qui: proseguire vorrebbe
     * dire numerare e impegnare la merce di un ordine senza chi lo riceve.
     *
     * @param array<string, string|int> $fields
     */
    private static function write(int $orderId, array $fields): void
    {
        $result = Order::update($fields, $orderId);

        if (($result->success ?? false) !== true) {
            $invalid = is_array($result->response ?? null) ? array_keys($result->response) : [];

            throw UserError::make('order.invalid_details', ['fields' => implode(', ', $invalid)]);
        }
    }

    /**
     * I dati che il cliente ha scritto, pronti per la riga dell'ordine.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $method
     * @return array<string, string|int>
     */
    private static function details(array $data, array $method, string $type, string $current): array
    {
        $email = trim((string) ($data['email'] ?? ''));

        // Senza indirizzo non si scrive niente: un valore vuoto farebbe
        // rifiutare tutto all'ordine, e uno già buono va tenuto.
        if ($email === '' && trim($current) === '') {
            throw UserError::make('order.invalid_details', ['fields' => 'email']);
        }

        $fields = [
            'email' => $email !== '' ? $email : $current,
            'phone' => (string) ($data['phone'] ?? ''),
            'customer_id' => (int) ($data['customer_id'] ?? 0),
            'payment_method_id' => (int) $method['id'],
            'shipping_method_id' => (int) ($data['shipping_method_id'] ?? 0),
            'location_id' => (int) ($data['location_id'] ?? 0),
            'customer_note' => (string) ($data['customer_note'] ?? ''),
            'last_activity_at' => date('Y-m-d H:i:s'),
        ];

        $fields['fulfillment_type'] = $type;

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

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }

        return isset($result['id']) ? [$result] : array_values(array_filter($result, 'is_array'));
    }
}
