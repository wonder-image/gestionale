<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use RuntimeException;
use Throwable;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentStart;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Mail\Recipients;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Orders\OrderNotifier;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Sql\Transaction;

/**
 * Il denaro che passa da un gateway: l'avvio dell'intento e quello che il
 * gateway racconta dopo. Webhook, pagina di ritorno e riallineamento passano
 * tutti da qui, così i controlli sono gli stessi da qualunque parte arrivi
 * la notizia.
 */
final class OnlinePayments
{
    /** @var array<int, true>|null gli ordini con un pagamento di prova, letti una volta per richiesta */
    private static ?array $testOrders = null;

    /** L'ultima riga di pagamento online dell'ordine. @return array<string, mixed>|null */
    public static function payment(int $orderId): ?array
    {
        $found = Payment::find(['order_id' => $orderId, 'type' => 'payment', 'deleted' => 'false']);
        $rows = is_array($found) && isset($found['id']) ? [$found] : array_values(array_filter((array) $found, 'is_array'));
        $online = array_values(array_filter($rows, static fn (array $row): bool => (string) $row['provider'] !== 'manual'));

        return $online === [] ? null : end($online);
    }

    /** Se l'ordine ha un pagamento fatto con le chiavi di prova: soldi non veri. */
    public static function inTest(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        if (self::$testOrders === null) {
            $rows = Payment::find(['environment' => 'test', 'deleted' => 'false'], null, null, null, 'order_id');
            $rows = is_array($rows) && isset($rows['order_id']) ? [$rows] : array_filter((array) $rows, 'is_array');
            self::$testOrders = array_fill_keys(array_map(static fn (array $row): int => (int) $row['order_id'], $rows), true);
        }

        return isset(self::$testOrders[$orderId]);
    }

    /**
     * Crea o riusa l'intento dell'ordine e lo lega alla riga aperta al
     * checkout. Il riuso lo decide il fornitore, che vede lo stato dell'intento.
     */
    public static function start(int $orderId): PaymentStart
    {
        $order = Order::findById($orderId);

        if (!is_array($order) || (string) ($order['status'] ?? '') !== 'awaiting_payment') {
            throw new RuntimeException("Ordine {$orderId} non più da pagare.");
        }

        $payment = self::payment($orderId);

        if ($payment === null || !in_array((string) $payment['status'], ['pending', 'failed'], true)) {
            throw new RuntimeException("Ordine {$orderId} senza un pagamento online aperto.");
        }

        $provider = PaymentProviders::get((string) $payment['provider']);

        if ($provider === null || !$provider->connected()) {
            throw new RuntimeException('Pagamento online non collegato.');
        }

        $start = $provider->start($order, $payment);
        Ledger::attach((int) $payment['id'], $provider->code(), $start->reference, $start->environment);
        self::$testOrders = null;

        return $start;
    }

    /**
     * Il gateway ha incassato: si controlla che il denaro sia proprio
     * dell'ordine, poi si registra.
     *
     * Non si conferma: l'ordine pagato resta «in attesa» finché il
     * commerciante non controlla la merce e lo conferma dal backend
     * (`Lifecycle::confirm`, che trova il pagamento già registrato). Chi
     * ripassa (rinvio del webhook, riallineamento, pagina di ritorno) trova la
     * riga già pagata e non riavvisa.
     *
     * @return array{order_id: int, status: string, payment_status?: string, changed: bool}
     */
    public static function succeeded(string $provider, string $reference, int $amount, string $currency, int $orderId, string $source): array
    {
        // Letto fuori dalla transazione (`order_id` non cambia): dentro, la
        // prima lettura è il lock e la vista nasce dopo l'attesa.
        $payee = (int) (Ledger::byReference($provider, $reference)['order_id'] ?? 0);

        // Lock sulla riga, poi tutto nella stessa transazione: webhook e pagina
        // di ritorno insieme, il secondo aspetta e trova la riga già pagata.
        $outcome = Transaction::run(static function () use ($provider, $reference, $amount, $currency, $orderId, $source, $payee): array {
            // Prima l'ordine, poi il pagamento, come `Lifecycle::abandon()` e
            // `cancel()`: con l'ordine inverso i due si bloccano a vicenda.
            if ($payee > 0) {
                Order::findForUpdate(['id' => $payee], 1);
            }

            $locked = Payment::findForUpdate([
                'provider' => $provider,
                'provider_reference' => trim($reference),
                'type' => 'payment',
                'deleted' => 'false',
            ], 1);

            [$row, $rowOrder, $order, $expected] = self::matching($provider, $reference, $amount, $currency, $orderId);

            // Letto sotto lock prima di scrivere: chi ripassa su una riga già pagata non riavvisa.
            $alreadyPaid = (string) ((is_array($locked) && $locked !== [] ? $locked : $row)['status'] ?? '') === 'paid';

            // Il cliente ha pagato: l'ordine «In pagamento» diventa del commerciante,
            // con numero e data. Chi arriva secondo lo trova già ricevuto.
            Lifecycle::receive($rowOrder, ['source' => $source, 'reason' => 'Pagamento riuscito']);

            Ledger::register([
                'order_id' => $rowOrder,
                'amount' => $amount / 100,
                'currency' => $expected,
                'provider' => $provider,
                'provider_reference' => $reference,
                'source' => $source,
            ]);

            $current = Order::findById($rowOrder);
            $current = is_array($current) ? $current : $order;
            $status = (string) ($current['status'] ?? '');

            if (in_array($status, ['cancelled', 'abandoned'], true)) {
                return [
                    'result' => ['order_id' => $rowOrder, 'status' => $status, 'changed' => false],
                    'notify' => false,
                    // Il cliente ha pagato un ordine già annullato o abbandonato: il
                    // denaro va restituito dalla dashboard del gateway.
                    'alert' => !$alreadyPaid,
                ];
            }

            // L'ordine resta «in attesa»: lo conferma il commerciante dal backend,
            // dopo aver visto che la merce c'è. Intanto la merce messa da parte non
            // scade più.
            $notify = !$alreadyPaid && $status === 'pending';

            if ($notify) {
                Allocation::keep($rowOrder);
            }

            return [
                'result' => [
                    'order_id' => $rowOrder,
                    'status' => $status,
                    'payment_status' => (string) ($current['payment_status'] ?? ''),
                    'changed' => !$alreadyPaid,
                ],
                'notify' => $notify,
                'alert' => false,
            ];
        });

        // Email e avvisi solo dopo il commit: un rollback non lascia mail partite.
        $rowOrder = (int) $outcome['result']['order_id'];

        if ($outcome['alert']) {
            self::alert($provider, 'payment.cancelled_order', "Incasso {$reference} sull'ordine annullato {$rowOrder}: va rimborsato.", ['order_id' => $rowOrder]);
        }

        if ($outcome['notify']) {
            OrderNotifier::send('paid', $rowOrder);
            OrderNotifier::send('merchant_paid', $rowOrder);
        }

        return $outcome['result'];
    }

    /**
     * Il gateway ha preso in carico il pagamento ma l'esito arriva dopo
     * (SEPA): l'ordine diventa del commerciante, con numero e data, e cliente
     * e commerciante lo sanno. Il pagamento resta da incassare; a `succeeded`
     * partono le email del pagamento.
     *
     * @return array{order_id: int, status: string, changed: bool}
     */
    public static function processing(string $provider, string $reference, int $amount, string $currency, int $orderId, string $source): array
    {
        [, $rowOrder] = self::matching($provider, $reference, $amount, $currency, $orderId);
        $received = Lifecycle::receive($rowOrder, ['source' => $source, 'reason' => 'Pagamento in elaborazione']);

        if ($received) {
            // Il cliente ha pagato, la banca deve solo confermare: la merce
            // non scade mentre aspetta (un SEPA impiega giorni).
            Allocation::keep($rowOrder);
            OrderNotifier::send('processing', $rowOrder);
            OrderNotifier::send('merchant_new', $rowOrder);
        }

        return [
            'order_id' => $rowOrder,
            'status' => (string) (Order::findById($rowOrder)['status'] ?? ''),
            'changed' => $received,
        ];
    }

    /**
     * La riga e l'ordine dell'intento, dopo aver controllato che importo,
     * valuta e ordine siano quelli del gestionale.
     *
     * @return array{0: array<string, mixed>, 1: int, 2: array<string, mixed>, 3: string}
     */
    private static function matching(string $provider, string $reference, int $amount, string $currency, int $orderId): array
    {
        $row = Ledger::byReference($provider, $reference);

        if ($row === null) {
            throw new PaymentMismatch("Intento {$reference} senza un pagamento nel gestionale.");
        }

        $rowOrder = (int) $row['order_id'];
        $order = Order::findById($rowOrder);

        if ($orderId !== $rowOrder || !is_array($order)) {
            throw new PaymentMismatch("Intento {$reference}: ordine {$orderId} al posto di {$rowOrder}.");
        }

        if ($amount !== (int) round((float) $row['amount'] * 100)) {
            throw new PaymentMismatch("Intento {$reference}: {$amount} centesimi al posto di ".(int) round((float) $row['amount'] * 100).'.');
        }

        // La riga può essere vecchia: l'incasso deve coprire ancora quanto resta dell'ordine.
        $due = self::dueCents($order, (int) $row['id']);

        if ($amount !== $due) {
            throw new PaymentMismatch("Intento {$reference}: {$amount} centesimi, all'ordine ne restano {$due}.");
        }

        $expected = (string) ($order['currency'] ?? '') ?: 'EUR';

        if (strtolower($currency) !== strtolower($expected)) {
            throw new PaymentMismatch("Intento {$reference}: valuta {$currency} al posto di {$expected}.");
        }

        if ((string) ($order['stage'] ?? '') !== 'order') {
            // Tornato carrello (il cliente ha cambiato il checkout): `Lifecycle::reopen`
            // annulla l'intento prima di riaprire, quindi qui arriva solo un
            // incasso che il gateway ha preso lo stesso. Niente denaro su un
            // carrello: il commerciante lo rimborsa, e riprovare non serve.
            throw new PaymentMismatch("Incasso {$reference} sull'ordine {$rowOrder}, tornato carrello: va rimborsato.");
        }

        return [$row, $rowOrder, $order, $expected];
    }

    /**
     * Qualcosa nel denaro va guardato a mano dal commerciante (di solito un
     * rimborso dalla dashboard del gateway). Resta la traccia tecnica e parte
     * un'email ai destinatari delle notifiche del negozio. Non lancia mai:
     * l'avviso non deve far cadere l'incasso che lo ha causato.
     *
     * @param array<string, mixed> $context
     */
    public static function alert(string $provider, string $action, string $message, array $context = []): void
    {
        try {
            Errors::report($provider, $action, $message, $context);

            $to = Recipients::parse((string) (Setting::current()['merchant_notification_emails'] ?? ''))['valid'];

            if ($to === []) {
                return;
            }

            Mailer::send('payment.review', $to, 'Pagamento da controllare', sprintf(
                '<p>%s</p><p>Controlla il pagamento nella dashboard di Stripe e, se serve, rimborsalo da lì.</p>',
                htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            ));
        } catch (Throwable $error) {
            Errors::internal($error, 'payments.alert', ['action' => $action] + $context);
        }
    }

    /**
     * Il tentativo non è andato: la riga si segna fallita e l'ordine resta in
     * attesa. L'intento resta riusabile: il cliente riprova sullo stesso.
     * Con `$attempt` ogni tentativo rifiutato resta nella storia del
     * pagamento (`Ledger::fail`).
     */
    public static function fail(string $provider, string $reference, string $message, string $attempt = ''): void
    {
        $row = Ledger::byReference($provider, $reference);

        if ($row === null || (string) $row['status'] === 'paid') {
            // Un fallimento in ritardo dopo l'incasso non toglie il denaro.
            return;
        }

        Ledger::fail((int) $row['id'], $message, $attempt);

        if ((string) (Order::findById((int) $row['order_id'])['status'] ?? '') === 'pending') {
            // L'ordine era già del commerciante (pagamento in elaborazione):
            // resta suo, e il commerciante deve sapere che il denaro non arriva.
            self::alert($provider, 'payment.failed_after_processing', "Pagamento {$reference} dell'ordine {$row['order_id']} non riuscito dopo l'elaborazione: l'ordine resta da incassare.", ['order_id' => (int) $row['order_id']]);
        }
    }

    /**
     * I rimborsi fatti dalla dashboard del gateway. Il riferimento di ognuno
     * (`re_…`) rende la scrittura idempotente.
     *
     * @param list<array{id: string, amount: int, status: string}> $refunds
     */
    public static function refunded(string $provider, string $reference, array $refunds, string $currency, string $source): int
    {
        $row = Ledger::byReference($provider, $reference);

        if ($row === null) {
            throw new PaymentMismatch("Rimborso sull'intento {$reference}, che il gestionale non conosce.");
        }

        $written = 0;

        foreach ($refunds as $refund) {
            if (in_array((string) $refund['status'], ['failed', 'canceled'], true) || (int) $refund['amount'] <= 0) {
                continue;
            }

            Ledger::refund([
                'order_id' => (int) $row['order_id'],
                'amount' => (int) $refund['amount'] / 100,
                'currency' => strtoupper($currency) ?: 'EUR',
                'provider' => $provider,
                'provider_reference' => (string) $refund['id'],
                'source' => $source,
            ]);
            ++$written;
        }

        return $written;
    }

    /** Il totale dell'ordine meno gli altri incassi già arrivati, al netto dei rimborsi, in centesimi. */
    private static function dueCents(array $order, int $paymentId): int
    {
        $rows = Payment::find(['order_id' => (int) $order['id'], 'deleted' => 'false']);
        $rows = isset($rows['id']) ? [$rows] : array_values(array_filter((array) $rows, 'is_array'));
        $others = array_filter($rows, static fn (array $row): bool => (int) $row['id'] !== $paymentId);
        $sums = PaymentStatus::sums($others);

        return (int) round(((float) $order['total'] - $sums['paid'] + $sums['refunded']) * 100);
    }
}
