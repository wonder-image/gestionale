<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentSequences;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Locations\PickupPoints;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
use Wonder\Plugin\Gestionale\Support\Shipping\Shipping;
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
     * Il riepilogo del checkout mentre il cliente compila.
     *
     * Scrive sul carrello le scelte fatte fin qui — consegna, sede, metodo di
     * spedizione, pagamento, indirizzi — e toglie quelle che non valgono più;
     * poi ricalcola e dice cosa si può ancora scegliere. Non prenota, non
     * numera, non consuma il coupon e non chiede l'email: quello lo fa
     * `place()`, che riscrive tutto con il modulo inviato.
     *
     * Un campo che il modello rifiuta non ferma niente: torna in `invalid` e
     * gli altri si scrivono lo stesso.
     *
     * @param array<string, mixed> $data
     * @return array{
     *     order: array{products_total: string, discount_total: string, shipping_total: string, fees_total: string, total: string, currency: string},
     *     items: list<array<string, mixed>>,
     *     fulfillment: array{type: string, choices: list<string>},
     *     shipping_methods: array{options: list<array<string, mixed>>, selected: int},
     *     pickup_locations: array{options: list<array{id: int, name: string, address: string}>, selected: int},
     *     payment_methods: array{options: list<array{id: int, name: string, provider: string, manual: bool, instructions: string}>, selected: int},
     *     coupon: array{code: string, dropped: string},
     *     notices: list<string>,
     *     invalid: list<string>
     * }
     */
    public static function preview(int $cartId, array $data): array
    {
        return Transaction::run(static function () use ($cartId, $data): array {
            $cart = Order::findForUpdate(['id' => $cartId], 1);

            if (!is_array($cart) || $cart === [] || (string) $cart['stage'] !== 'cart') {
                throw UserError::make('cart.not_a_cart');
            }

            // Quello che il modulo non manda resta com'è sul carrello.
            $data += [
                'fulfillment_type' => (string) ($cart['fulfillment_type'] ?? 'shipping'),
                'shipping_method_id' => (int) ($cart['shipping_method_id'] ?? 0),
                'location_id' => (int) ($cart['location_id'] ?? 0),
                'payment_method_id' => (int) ($cart['payment_method_id'] ?? 0),
            ];

            $shipping = Gestionale::feature('shipping');
            $points = $shipping ? PickupPoints::all() : [];
            $type = (string) $data['fulfillment_type'];

            if (!in_array($type, ['shipping', 'pickup'], true) || ($type === 'pickup' && $points === [])) {
                $type = 'shipping';
            }

            $locationId = 0;

            if ($type === 'pickup') {
                $ids = array_column($points, 'id');
                $wanted = (int) $data['location_id'];
                $locationId = in_array($wanted, $ids, true) ? $wanted : (count($ids) === 1 ? $ids[0] : 0);
            }

            $payments = self::paymentMethods($type);
            $payment = null;

            foreach ($payments as $candidate) {
                if ((int) $candidate['id'] === (int) $data['payment_method_id']) {
                    $payment = $candidate;
                }
            }

            $invalid = self::writeLoosely($cartId, [
                'fulfillment_type' => $type,
                'location_id' => $locationId,
                'shipping_method_id' => $type === 'shipping' && $shipping ? (int) $data['shipping_method_id'] : 0,
                'payment_method_id' => $payment === null ? 0 : (int) $payment['id'],
                'last_activity_at' => date('Y-m-d H:i:s'),
            ] + self::addresses($data));

            // Il primo ricalcolo toglie il metodo che non copre più
            // l'indirizzo; poi, se ne resta uno solo, si sceglie da sé.
            $first = Cart::recalculate($cartId);
            $methodId = (int) ($first['order']['shipping_method_id'] ?? 0);
            $options = $type === 'shipping' ? Shipping::options($cartId) : [];

            if ($type === 'shipping') {
                $available = array_column($options, 'method_id');
                $chosen = in_array($methodId, $available, true) ? $methodId : (count($available) === 1 ? $available[0] : 0);

                if ($chosen !== $methodId) {
                    Order::update(['shipping_method_id' => $chosen], $cartId);
                    $methodId = $chosen;
                }
            }

            // La commissione dipende dal metodo di spedizione (contrassegno) e
            // dai prodotti: va dopo, e poi un secondo ricalcolo la somma.
            self::applyFee($cartId, $payment);
            $second = Cart::recalculate($cartId);
            $order = $second['order'];
            $dropped = $first['coupon_dropped'] !== '' ? $first['coupon_dropped'] : $second['coupon_dropped'];
            $notices = [];

            foreach ([$first['shipping_dropped'], $second['shipping_dropped'], $dropped !== '' ? UserError::make("coupon.{$dropped}")->getMessage() : ''] as $notice) {
                if ($notice !== '' && !in_array($notice, $notices, true)) {
                    $notices[] = $notice;
                }
            }

            return [
                'order' => [
                    'products_total' => (string) ($order['products_total'] ?? '0.00'),
                    'discount_total' => (string) ($order['discount_total'] ?? '0.00'),
                    'shipping_total' => (string) ($order['shipping_total'] ?? '0.00'),
                    'fees_total' => (string) ($order['fees_total'] ?? '0.00'),
                    'total' => (string) ($order['total'] ?? '0.00'),
                    'currency' => (string) ($order['currency'] ?? 'EUR'),
                ],
                'items' => $second['items'],
                'fulfillment' => ['type' => $type, 'choices' => $points === [] ? ['shipping'] : ['shipping', 'pickup']],
                'shipping_methods' => ['options' => $options, 'selected' => $type === 'shipping' ? $methodId : 0],
                'pickup_locations' => ['options' => $points, 'selected' => $locationId],
                'payment_methods' => [
                    'options' => array_map(self::paymentChoice(...), $payments),
                    'selected' => $payment === null ? 0 : (int) $payment['id'],
                ],
                'coupon' => ['code' => (string) ($order['coupon_code'] ?? ''), 'dropped' => $dropped],
                'notices' => $notices,
                'invalid' => $invalid,
            ];
        });
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

            self::checkDelivery($cartId, $type, $order, (string) ($data['source'] ?? 'user'));

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
                'provider' => PaymentMethod::ledgerProvider((string) ($method['provider'] ?? '')),
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
     * @return array<string, mixed>
     */
    private static function method(int $methodId, string $fulfillment): array
    {
        $method = $methodId > 0 ? PaymentMethod::findById($methodId) : null;

        if (!is_array($method) || $method === [] || !self::allowed($method, $fulfillment)) {
            throw UserError::make('order.payment_method_unavailable');
        }

        return $method;
    }

    /**
     * Deve essere attivo, offerto sul sito e ammesso per la consegna scelta:
     * il contrassegno di una spedizione non si usa per un ritiro, e un metodo
     * che il commerciante ha lasciato solo per il banco non compare online.
     *
     * @param array<string, mixed> $method
     */
    private static function allowed(array $method, string $fulfillment): bool
    {
        $for = (string) ($method['available_for'] ?? 'all');

        return ($method['active'] ?? 'false') === 'true'
            && ($method['deleted'] ?? 'false') !== 'true'
            && ($method['applies_online'] ?? 'true') === 'true'
            && ($for === 'all' || $fulfillment === 'none' || $for === $fulfillment);
    }

    /**
     * I metodi di pagamento che il cliente può scegliere, in ordine di posizione.
     *
     * @return list<array<string, mixed>>
     */
    private static function paymentMethods(string $fulfillment): array
    {
        $methods = array_values(array_filter(
            self::rows(PaymentMethod::find(['active' => 'true'])),
            static fn (array $method): bool => self::allowed($method, $fulfillment)
        ));

        usort($methods, static fn (array $a, array $b): int => [(int) ($a['position'] ?? 0), (int) $a['id']] <=> [(int) ($b['position'] ?? 0), (int) $b['id']]);

        return $methods;
    }

    /**
     * La voce del metodo per il modulo: `manual` dice al sito che non c'è un
     * gateway da aprire (bonifico, contanti).
     *
     * @param array<string, mixed> $method
     * @return array{id: int, name: string, provider: string, manual: bool, instructions: string}
     */
    private static function paymentChoice(array $method): array
    {
        $provider = (string) ($method['provider'] ?? '');

        return [
            'id' => (int) $method['id'],
            'name' => (string) ($method['name'] ?? ''),
            'provider' => $provider,
            'manual' => PaymentMethod::ledgerProvider($provider) === 'manual',
            'instructions' => (string) ($method['instructions'] ?? ''),
        ];
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
     * Il ritiro vuole una sede di ritiro; la spedizione dal sito vuole un
     * metodo che arrivi all'indirizzo. Si guarda l'ordine già ricalcolato,
     * cioè quello che il cliente ha appena inviato: un'anteprima aperta in
     * un'altra scheda non conta più.
     *
     * Con le spedizioni spente non si controlla niente. Il metodo si chiede
     * solo al cliente online e solo se c'è qualcosa da spedire: un ordine
     * fatto dal sistema o di soli servizi passa senza.
     *
     * @param array<string, mixed> $order
     */
    private static function checkDelivery(int $cartId, string $type, array $order, string $source): void
    {
        if (!Gestionale::feature('shipping')) {
            return;
        }

        if ($type === 'pickup') {
            if (PickupPoints::find((int) ($order['location_id'] ?? 0)) === null) {
                throw UserError::make('order.pickup_location_unavailable');
            }

            return;
        }

        // «Nessuna consegna» non è una via di fuga: con merce da spedire vale
        // quanto una spedizione. Gli ordini del sistema e delle importazioni
        // non hanno un cliente a cui chiedere il metodo.
        if (!in_array($type, ['shipping', 'none'], true)
            || (string) ($order['channel'] ?? 'online') !== 'online'
            || in_array($source, ['system', 'import'], true)
            || !Shipping::shippable($cartId)) {
            return;
        }

        $available = array_column(Shipping::options($cartId), 'method_id');

        if ($available === []) {
            throw UserError::make('order.shipping_unavailable');
        }

        if (!in_array((int) ($order['shipping_method_id'] ?? 0), $available, true)) {
            throw UserError::make('order.shipping_method_required');
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
     * Scrive quello che il modello accetta e restituisce i campi rifiutati:
     * in anteprima un dato sbagliato non deve buttare via gli altri.
     *
     * @param array<string, string|int> $fields
     * @return list<string>
     */
    private static function writeLoosely(int $orderId, array $fields): array
    {
        $result = Order::update($fields, $orderId);

        if (($result->success ?? false) === true) {
            return [];
        }

        $invalid = [];

        foreach (is_array($result->response ?? null) ? $result->response : [] as $field => $check) {
            if ((is_array($check) || is_object($check)) && (((array) $check)['valid'] ?? true) === false) {
                $invalid[] = (string) $field;
            }
        }

        $rest = array_diff_key($fields, array_flip($invalid));

        if ($rest !== []) {
            self::write($orderId, $rest);
        }

        return array_values($invalid);
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
            // La sede conta solo per il ritiro: una rimasta nel modulo non sposta la merce.
            'location_id' => $type === 'pickup' ? (int) ($data['location_id'] ?? 0) : 0,
            'customer_note' => (string) ($data['customer_note'] ?? ''),
            'last_activity_at' => date('Y-m-d H:i:s'),
        ];

        $fields['fulfillment_type'] = $type;

        // I campi dell'ordine vengono prima: un `shipping[method_id]` nel
        // modulo non deve poter riscrivere `shipping_method_id`.
        return $fields + self::addresses($data);
    }

    /**
     * Gli indirizzi del modulo come campi dell'ordine (`billing_city`, …).
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private static function addresses(array $data): array
    {
        $fields = [];

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
     * @param array<string, mixed>|null $method null quando non c'è ancora un metodo: si toglie e basta
     */
    private static function applyFee(int $orderId, ?array $method): void
    {
        foreach (self::rows(OrderItem::find(['order_id' => $orderId, 'type' => 'fee', 'deleted' => 'false'])) as $old) {
            OrderItem::delete((int) $old['id']);
        }

        if ($method === null) {
            return;
        }

        // Il contrassegno che si paga al corriere costa quanto dice il listino
        // di spedizione scelto: se ne ha uno, sostituisce la commissione del
        // metodo di pagamento.
        $cod = (string) ($method['timing'] ?? '') === PaymentTiming::ON_DELIVERY
            && (string) ($method['available_for'] ?? 'all') !== 'pickup'
            ? Shipping::codFee($orderId)
            : 0.0;

        $type = (string) ($method['fee_type'] ?? 'none');
        $fixed = in_array($type, ['amount', 'amount_percent'], true) ? round((float) ($method['fee_value'] ?? 0), 2) : 0.0;
        $percent = in_array($type, ['percent', 'amount_percent'], true) ? min(round((float) ($method['fee_percent'] ?? 0), 2), 100.0) : 0.0;

        if ($cod <= 0 && $fixed <= 0 && $percent <= 0) {
            return;
        }

        $order = Order::findById($orderId);
        $base = (float) (is_array($order) ? ($order['products_total'] ?? 0) : 0);
        $amount = $cod > 0 ? $cod : round($fixed + $base * $percent / 100, 2);

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
