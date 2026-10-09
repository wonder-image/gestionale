<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Throwable;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
use Wonder\Plugin\Gestionale\Support\Returns\Returns;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Sql\Transaction;

/**
 * **L'unico che scrive lo `status` di un ordine.**
 *
 * Confermare vuol dire tre cose insieme: il denaro risulta, la merce esce dal
 * magazzino, il cliente lo sa. Annullare vuol dire le stesse tre al contrario,
 * e quale contrario dipende da dov'era l'ordine: prima della conferma la merce
 * era solo impegnata e basta liberarla; dopo era già uscita e deve rientrare.
 *
 * L'evasione è l'eccezione che conferma la regola: `fulfill()` scrive
 * `fulfillment_status` e non tocca il magazzino, perché la merce è già uscita
 * alla conferma. Sta qui perché il cambio va nella storia dell'ordine e perché
 * può essere il pezzo che mancava per chiudere. Con la funzionalità `shipping`
 * accesa a chiamarla è `Shipments`, che deriva l'evasione dalle spedizioni.
 *
 * Tutto è idempotente. Un webhook che ripassa, un operatore che clicca due
 * volte, il giro dello scheduler che incontra un ordine già chiuso: la seconda
 * volta non scarica niente, non rimette niente e non manda una seconda email.
 * Chi chiama lo capisce da `changed`.
 */
final class Lifecycle
{
    /** Gli stati da cui si può ancora confermare. */
    private const CONFIRMABLE = ['draft', 'pending'];

    /** Quelli in cui la merce è già uscita di magazzino. */
    public const COMMITTED = ['confirmed', 'processing'];

    /**
     * Il pagamento risulta, la merce esce, il cliente lo sa.
     *
     * `merchant_notice` (bool): dopo l'email «confermato» al cliente manda anche `merchant_new` al commerciante.
     *
     * @param array{payment?: bool|null, provider?: string, provider_reference?: string, amount?: float, source?: string, user_id?: int, notify?: bool, merchant_notice?: bool, email_extra?: array<string, string>} $options
     * @return array{order_id: int, status: string, payment_status: string, committed: int, changed: bool}
     */
    public static function confirm(int $orderId, array $options = []): array
    {
        $result = Transaction::run(static function () use ($orderId, $options): array {
            $order = self::order($orderId);
            $status = (string) $order['status'];

            if ($status === 'cancelled') {
                throw UserError::make('order.cancelled_cannot_confirm');
            }

            if (!in_array($status, self::CONFIRMABLE, true)) {
                // Già confermato: la merce è fuori e il denaro è a posto.
                return [
                    'order_id' => $orderId,
                    'status' => $status,
                    'payment_status' => (string) $order['payment_status'],
                    'committed' => 0,
                    'changed' => false,
                ];
            }

            $timing = PaymentTiming::of((int) ($order['payment_method_id'] ?? 0));
            $wantsPayment = $options['payment'] ?? ($timing !== PaymentTiming::ON_DELIVERY);
            $paymentStatus = (string) $order['payment_status'];

            if ($wantsPayment === true && $paymentStatus !== 'paid') {
                $paymentStatus = Ledger::register([
                    'order_id' => $orderId,
                    'amount' => (float) ($options['amount'] ?? $order['total']),
                    'customer_id' => (int) ($order['customer_id'] ?? 0),
                    'payment_method_id' => (int) ($order['payment_method_id'] ?? 0),
                    'currency' => (string) ($order['currency'] ?? 'EUR'),
                    'provider' => (string) ($options['provider'] ?? ''),
                    'provider_reference' => (string) ($options['provider_reference'] ?? ''),
                    'source' => (string) ($options['source'] ?? 'system'),
                    'user_id' => (int) ($options['user_id'] ?? 0),
                ])['payment_status'];
            }

            $committed = 0;

            foreach (OrderLines::goods(self::items($orderId)) as $item) {
                Allocation::commit([
                    'product_id' => (int) $item['product_id'],
                    'quantity' => (float) $item['quantity'],
                    'location_id' => (int) ($order['location_id'] ?? 0),
                    'order_id' => $orderId,
                    'order_item_id' => (int) $item['id'],
                    // Col contrassegno la merce esce prima dell'incasso: è il
                    // patto di quel metodo, non una svista.
                    'payment_ok' => $paymentStatus === 'paid' || $timing === PaymentTiming::ON_DELIVERY,
                    'source' => (string) ($options['source'] ?? 'system'),
                    'user_id' => (int) ($options['user_id'] ?? 0),
                ]);
                ++$committed;
            }

            Order::update(['status' => 'confirmed'], $orderId);
            StatusLogger::record(
                OrderStatusLog::class,
                $orderId,
                'status',
                $status,
                'confirmed',
                (string) ($options['source'] ?? 'system'),
                (int) ($options['user_id'] ?? 0) ?: null
            );

            return [
                'order_id' => $orderId,
                'status' => 'confirmed',
                'payment_status' => $paymentStatus,
                'committed' => $committed,
                'changed' => true,
            ];
        });

        if ($result['changed'] && ($options['notify'] ?? true)) {
            // Fuori dalla transazione: la posta è lenta e non deve tenere
            // aperto un blocco sulle righe di magazzino.
            OrderNotifier::send('confirmed', $orderId, (array) ($options['email_extra'] ?? []));

            if (($options['merchant_notice'] ?? false) === true) {
                // L'ordine pagato online arriva al commerciante qui, non al checkout.
                OrderNotifier::send('merchant_new', $orderId);
            }
        }

        return $result;
    }

    /**
     * L'ordine si ferma: la merce torna disponibile e il denaro in attesa
     * decade.
     *
     * Quello già incassato non si rimborsa da qui — il rimborso lo fa il
     * gateway, e `Ledger::refund()` lo registra quando risponde. Qui si dice
     * soltanto quanto c'è da restituire, così chi annulla non se ne dimentica.
     *
     * @param array{reason?: string, source?: string, user_id?: int, notify?: bool, merchant_notice?: bool} $options
     * @return array{order_id: int, status: string, released: int, restored: int, refundable: string, changed: bool}
     */
    public static function cancel(int $orderId, array $options = []): array
    {
        $result = Transaction::run(static function () use ($orderId, $options): array {
            $order = self::order($orderId);
            $status = (string) $order['status'];

            if ($status === 'cancelled') {
                return [
                    'order_id' => $orderId,
                    'status' => 'cancelled',
                    'released' => 0,
                    'restored' => 0,
                    'refundable' => self::money(self::paid($orderId)),
                    'changed' => false,
                ];
            }

            if ($status === 'completed') {
                // Un ordine chiuso si disfa con un reso, che rimette la merce
                // riga per riga e lascia la storia in piedi.
                throw UserError::make('order.completed_cannot_cancel');
            }

            $released = 0;
            $restored = 0;
            $reason = trim((string) ($options['reason'] ?? ''));
            $source = (string) ($options['source'] ?? 'system');

            if (in_array($status, self::COMMITTED, true)) {
                foreach (OrderLines::goods(self::items($orderId)) as $item) {
                    // Quel che un reso ha già reso non si rimette due volte: se è
                    // rientrato lo ha fatto il reso, se era rotto non va a scaffale.
                    $daRimettere = round((float) $item['quantity'] - Returns::returned((int) $item['id']), 3);

                    if ($daRimettere <= 0) {
                        continue;
                    }

                    Allocation::restore([
                        'product_id' => (int) $item['product_id'],
                        'quantity' => $daRimettere,
                        'location_id' => (int) ($order['location_id'] ?? 0),
                        'order_id' => $orderId,
                        'order_item_id' => (int) $item['id'],
                        'source' => $source,
                        'user_id' => (int) ($options['user_id'] ?? 0),
                    ]);
                    ++$restored;
                }
            } else {
                $released = Allocation::release(['order_id' => $orderId]);
            }

            // Gli intenti ancora pagabili si annullano dopo, fuori dalla
            // transazione: Stripe non aspetta il nostro commit.
            $intents = [];

            foreach (self::payments($orderId) as $payment) {
                $paymentStatus = (string) $payment['status'];

                if ($paymentStatus === 'pending') {
                    Ledger::fail((int) $payment['id'], $reason !== '' ? $reason : 'Ordine annullato');
                }

                if (in_array($paymentStatus, ['pending', 'failed'], true) && ($intent = self::openIntent($payment)) !== null) {
                    $intents[] = $intent;
                }
            }

            Order::update(['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s')], $orderId);
            // L'ordine annullato non consuma più il coupon: l'uso torna disponibile.
            Coupons::release($orderId);
            StatusLogger::record(
                OrderStatusLog::class,
                $orderId,
                'status',
                $status,
                'cancelled',
                $source,
                (int) ($options['user_id'] ?? 0) ?: null,
                $reason
            );

            return [
                'order_id' => $orderId,
                'status' => 'cancelled',
                'released' => $released,
                'restored' => $restored,
                'refundable' => self::money(self::paid($orderId)),
                'changed' => true,
                'intents' => $intents,
            ];
        });

        $intents = $result['intents'] ?? [];
        unset($result['intents']);

        foreach ($intents as [$provider, $reference]) {
            try {
                PaymentProviders::get($provider)?->cancel($reference);
            } catch (Throwable $error) {
                // L'ordine è annullato comunque: se il cliente pagasse lo stesso,
                // il webhook registra l'incasso e avvisa il commerciante.
                Errors::internal($error, 'orders.cancel_intent', ['order' => $orderId, 'reference' => $reference]);
            }
        }

        if ($result['changed'] && ($options['notify'] ?? true)) {
            OrderNotifier::send('cancelled', $orderId);

            if (($options['merchant_notice'] ?? false) === true) {
                OrderNotifier::send('merchant_cancelled', $orderId);
            }
        }

        return $result;
    }

    /**
     * Il fornitore e l'intento della riga, se c'è un intento online ancora da
     * chiudere nell'ambiente in cui il fornitore gira adesso.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function openIntent(array $payment): ?array
    {
        $provider = (string) ($payment['provider'] ?? '');
        $reference = (string) ($payment['provider_reference'] ?? '');

        if ($provider === '' || $provider === 'manual' || $reference === '') {
            return null;
        }

        $gateway = PaymentProviders::get($provider);

        if ($gateway === null || (string) ($payment['environment'] ?? 'live') !== $gateway->environment()) {
            return null;
        }

        return [$provider, $reference];
    }

    /**
     * Segna a che punto è la merce che esce.
     *
     * L'evasione si muove a mano — la spunta «evaso» dopo aver spedito — e
     * **non tocca il magazzino**: la merce è già uscita alla conferma. Passa
     * di qui e non dalla pagina perché il cambio va scritto nella storia
     * dell'ordine, e perché «evaso» può essere l'ultimo pezzo che manca per
     * chiudere l'ordine: `refresh()` se ne accorge subito, senza che chi ha
     * spuntato la casella debba ricordarsi di un secondo passaggio.
     *
     * @param array{source?: string, user_id?: int, message?: string} $options
     * @return array{order_id: int, fulfillment_status: string, status: string, changed: bool}
     */
    public static function fulfill(int $orderId, string $fulfillment, array $options = []): array
    {
        if (!in_array($fulfillment, Order::FULFILLMENT_STATUSES, true)) {
            throw UserError::make('order.unknown_fulfillment', ['status' => $fulfillment]);
        }

        $result = Transaction::run(static function () use ($orderId, $fulfillment, $options): array {
            $order = self::order($orderId);
            $before = (string) $order['fulfillment_status'];

            if ($before === $fulfillment) {
                // Due clic sullo stesso pulsante non sono due evasioni.
                return [
                    'order_id' => $orderId,
                    'fulfillment_status' => $before,
                    'status' => (string) $order['status'],
                    'changed' => false,
                ];
            }

            Order::update(['fulfillment_status' => $fulfillment], $orderId);

            StatusLogger::record(
                OrderStatusLog::class,
                $orderId,
                'fulfillment_status',
                $before,
                $fulfillment,
                in_array((string) ($options['source'] ?? ''), StatusLogger::SOURCES, true)
                    ? (string) $options['source']
                    : 'user',
                (int) ($options['user_id'] ?? 0) ?: null,
                (string) ($options['message'] ?? '')
            );

            return [
                'order_id' => $orderId,
                'fulfillment_status' => $fulfillment,
                'status' => (string) $order['status'],
                'changed' => true,
            ];
        });

        if ($result['changed'] === true) {
            // Fuori dalla transazione di sopra solo per chiarezza: `refresh()`
            // apre la sua, e annidata diventa un savepoint.
            $result['status'] = self::refresh($orderId);
        }

        return $result;
    }

    /**
     * Chiude l'ordine quando è pagato per intero ed evaso per intero.
     *
     * `processing` resta una scelta di chi lavora gli ordini: non si entra e
     * non si esce da lì da soli.
     *
     * @return string il nuovo `status`
     */
    public static function refresh(int $orderId): string
    {
        return Transaction::run(static function () use ($orderId): string {
            $order = self::order($orderId);
            $status = (string) $order['status'];

            if (!in_array($status, self::COMMITTED, true)) {
                return $status;
            }

            if ((string) $order['payment_status'] !== 'paid'
                || (string) $order['fulfillment_status'] !== 'fulfilled') {
                return $status;
            }

            Order::update(['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')], $orderId);
            StatusLogger::record(
                OrderStatusLog::class,
                $orderId,
                'status',
                $status,
                'completed',
                'system'
            );

            return 'completed';
        });
    }

    /**
     * La riga dell'ordine, bloccata.
     *
     * @return array<string, mixed>
     */
    private static function order(int $orderId): array
    {
        $row = Order::findForUpdate(['id' => $orderId], 1);

        if (!is_array($row) || $row === []) {
            throw UserError::make('order.not_found');
        }

        if ((string) $row['stage'] !== 'order') {
            // Un carrello non ha stati da cambiare: quelli nascono al checkout.
            throw UserError::make('order.not_an_order');
        }

        return $row;
    }

    /** Quanto è stato incassato davvero, meno i rimborsi. */
    private static function paid(int $orderId): float
    {
        $total = 0.0;

        foreach (self::payments($orderId) as $payment) {
            if ((string) $payment['status'] !== 'paid') {
                continue;
            }

            $amount = (float) ($payment['amount'] ?? 0);
            $total += (string) $payment['type'] === 'refund' ? -$amount : $amount;
        }

        return round(max(0.0, $total), 2);
    }

    /** @return list<array<string, mixed>> */
    private static function payments(int $orderId): array
    {
        return self::rows(Payment::find(['order_id' => $orderId, 'deleted' => 'false']));
    }

    /** @return list<array<string, mixed>> */
    private static function items(int $orderId): array
    {
        return self::rows(OrderItem::find(['order_id' => $orderId, 'deleted' => 'false']));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }

        return isset($result['id']) ? [$result] : array_values(array_filter($result, 'is_array'));
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
