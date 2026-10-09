<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use RuntimeException;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Sql\Transaction;

/**
 * **L'unico che tocca il `payment_status` di un ordine.**
 *
 * Ogni movimento di denaro è una riga di `gst_payments`, rimborsi compresi, e
 * lo stato dell'ordine non è un campo che si imposta: è la conseguenza della
 * somma delle righe riuscite, ricalcolata da `PaymentStatus::of()` ogni volta
 * che una riga nasce o cambia.
 *
 * Contro la notifica doppia del gateway ci sono due difese. La prima è qui:
 * prima di scrivere si cerca la riga con quel `provider` e quel
 * `provider_reference` **con `FOR UPDATE`**, e siccome la condizione è
 * sull'indice unico, quando la riga non c'è InnoDB blocca lo spazio dove
 * andrebbe — la seconda notifica aspetta la prima, poi la trova e non scrive.
 * La seconda difesa è l'indice unico stesso, che regge comunque.
 *
 * Un pagamento senza riferimento — il bonifico a mano, i contanti al banco —
 * non ha niente da confrontare, e due incassi manuali uguali sono due incassi
 * veri: per non farli collidere sul riferimento vuoto si firmano con il
 * proprio codice (`pay_…`), che si conosce già prima di scrivere.
 */
final class Ledger
{
    /**
     * Apre un pagamento in attesa: la carta è stata avviata, il bonifico è
     * stato chiesto. L'ordine risulta «in attesa», non ancora pagato.
     *
     * @param array<string, mixed> $data
     * @return array{payment_id: int, created: bool, payment_status: string}
     */
    public static function open(array $data): array
    {
        return self::write($data, 'payment', 'pending');
    }

    /**
     * Incassa. Se il pagamento era già aperto con lo stesso riferimento, non
     * ne nasce un altro: quello si chiude.
     *
     * @param array<string, mixed> $data
     * @return array{payment_id: int, created: bool, payment_status: string}
     */
    public static function register(array $data): array
    {
        return self::write($data, 'payment', 'paid');
    }

    /**
     * Rimborsa. È una riga come le altre, di tipo `refund`: il denaro uscito
     * si racconta come quello entrato, e lo stato lo sa.
     *
     * @param array<string, mixed> $data
     * @return array{payment_id: int, created: bool, payment_status: string}
     */
    public static function refund(array $data): array
    {
        return self::write($data, 'refund', 'paid');
    }

    /**
     * Segna fallito un pagamento e ricalcola l'ordine: una carta rifiutata non
     * lascia l'ordine «in attesa» per sempre.
     *
     * @return string il nuovo `payment_status` dell'ordine
     */
    public static function fail(int $paymentId, string $message = ''): string
    {
        return Transaction::run(static function () use ($paymentId, $message): string {
            $row = Payment::findForUpdate(['id' => $paymentId], 1);

            if (!is_array($row) || $row === []) {
                throw new RuntimeException("Pagamento {$paymentId} non trovato.");
            }

            if ((string) $row['status'] === 'paid') {
                // La notifica di fallimento che arriva dopo l'incasso, in
                // ritardo o ripetuta: dichiararlo fallito rimetterebbe a «non
                // pagato» un ordine che i soldi li ha già portati.
                throw UserError::make('payment.already_paid');
            }

            self::move($row, 'failed', $message);

            return self::sync((int) $row['order_id']);
        });
    }

    /**
     * Lega la riga aperta al checkout all'intento del gateway.
     *
     * Da qui in poi la conferma, da qualunque parte arrivi, trova questa riga
     * con il riferimento del gateway e la chiude: non ne nasce una seconda.
     * Vale anche per una riga fallita, perché una carta rifiutata lascia
     * l'intento riusabile e il cliente riprova sullo stesso.
     */
    public static function attach(int $paymentId, string $provider, string $reference, string $environment): void
    {
        Transaction::run(static function () use ($paymentId, $provider, $reference, $environment): void {
            $row = Payment::findForUpdate(['id' => $paymentId], 1);

            if (!is_array($row) || $row === []) {
                throw new RuntimeException("Pagamento {$paymentId} non trovato.");
            }

            if (!in_array((string) $row['status'], ['pending', 'failed'], true)) {
                throw new RuntimeException("Pagamento {$paymentId} già chiuso.");
            }

            Payment::update([
                'provider' => $provider,
                'provider_reference' => trim($reference),
                'environment' => in_array($environment, Payment::ENVIRONMENTS, true) ? $environment : 'live',
            ], $paymentId);
        });
    }

    /**
     * La riga con quel riferimento del gateway, senza bloccarla: per chi
     * legge e poi passa da `register()`, `fail()` o `refund()`, che bloccano.
     *
     * @return array<string, mixed>|null
     */
    public static function byReference(string $provider, string $reference, string $type = 'payment'): ?array
    {
        $row = Payment::find([
            'provider' => $provider,
            'provider_reference' => trim($reference),
            'type' => $type,
            'deleted' => 'false',
        ], 1);

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * Ricalcola il `payment_status` dell'ordine dalle sue righe e lo salva.
     *
     * È l'unico punto in cui quella colonna si scrive. Se lo stato non cambia
     * non si scrive niente e non si logga niente: un webhook che ripassa non
     * deve riempire la storia di righe uguali.
     */
    public static function sync(int $orderId): string
    {
        return Transaction::run(static function () use ($orderId): string {
            $order = Order::findForUpdate(['id' => $orderId], 1);

            if (!is_array($order) || $order === []) {
                throw new RuntimeException("Ordine {$orderId} non trovato.");
            }

            $status = PaymentStatus::of((float) $order['total'], self::paymentsOf($orderId));
            $before = (string) $order['payment_status'];

            if ($status === $before) {
                return $status;
            }

            Order::update(['payment_status' => $status], $orderId);

            StatusLogger::record(
                OrderStatusLog::class,
                $orderId,
                'payment_status',
                $before,
                $status,
                'system'
            );

            // Pagato dopo l'evasione — il bonifico che arriva a merce già
            // partita — è l'ultimo pezzo che mancava: l'ordine si chiude qui,
            // senza aspettare che qualcuno lanci `refresh()`. Un carrello non
            // ha stati da chiudere: `refresh()` lì lancerebbe.
            if ((string) $order['stage'] === 'order') {
                Lifecycle::refresh($orderId);
            }

            return $status;
        });
    }

    /**
     * Quando è arrivato il denaro: `paid_at` se c'è, altrimenti adesso.
     *
     * Chi registra un bonifico a mano lo fa spesso dopo l'arrivo: la data
     * vera è quella dell'estratto conto. Una data senza ora vale la
     * mezzanotte di quel giorno; nel futuro non si incassa.
     *
     * @param array<string, mixed> $data
     */
    private static function paidAt(array $data): string
    {
        $raw = trim((string) ($data['paid_at'] ?? ''));

        if ($raw === '') {
            return date('Y-m-d H:i:s');
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?$/', $raw, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw UserError::make('payment.invalid_date');
        }

        $moment = sprintf('%s-%s-%s %02d:%02d:%02d', $m[1], $m[2], $m[3], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0));

        if (strtotime($moment) > time() + 60) {
            throw UserError::make('payment.future_date');
        }

        return $moment;
    }

    /**
     * La riga nuova, o quella che c'era già.
     *
     * @param array<string, mixed> $data
     * @return array{payment_id: int, created: bool, payment_status: string}
     */
    private static function write(array $data, string $type, string $status): array
    {
        $orderId = (int) ($data['order_id'] ?? 0);
        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw UserError::make('payment.zero_amount');
        }

        // La data si controlla prima di aprire la transazione: un rifiuto non
        // deve lasciare niente scritto.
        $paidAt = $status === 'paid' ? self::paidAt($data) : '';

        return Transaction::run(static function () use ($data, $type, $status, $orderId, $amount, $paidAt): array {
            $provider = self::provider($data);
            $reference = trim((string) ($data['provider_reference'] ?? ''));
            $existing = $reference === '' ? null : self::findByReference($provider, $reference, $type);

            if ($existing === null && $status === 'paid') {
                $existing = self::adoptOpen($orderId, $provider, $reference, $type);
            }

            if (is_array($existing)) {
                if ((int) $existing['order_id'] !== $orderId) {
                    // Quel riferimento è già di un altro ordine: preso per
                    // buono qui, pagherebbe quello e lascerebbe questo senza
                    // una riga di denaro.
                    throw UserError::make('payment.reference_other_order');
                }

                self::reconcile($existing, $amount);
                self::move($existing, $status, (string) ($data['note'] ?? ''), $paidAt);

                return [
                    'payment_id' => (int) $existing['id'],
                    'created' => false,
                    'payment_status' => self::sync($orderId),
                ];
            }

            $code = Code::make(Payment::class, Codes::PAYMENT);
            $created = Payment::create([
                'code' => $code,
                'type' => $type,
                'order_id' => $orderId,
                'customer_id' => (int) ($data['customer_id'] ?? 0),
                'payment_method_id' => (int) ($data['payment_method_id'] ?? 0),
                'payment_account_id' => (int) ($data['payment_account_id'] ?? 0),
                'amount' => self::money($amount),
                'currency' => (string) ($data['currency'] ?? 'EUR'),
                'status' => $status,
                'provider' => $provider,
                'provider_method' => (string) ($data['provider_method'] ?? ''),
                // Senza riferimento del gateway ci si firma con il proprio
                // codice: l'indice unico vuole un valore diverso per riga.
                'provider_reference' => $reference !== '' ? $reference : $code,
                'paid_at' => $paidAt,
                'note' => (string) ($data['note'] ?? ''),
            ]);

            if (($created->success ?? false) !== true) {
                throw new RuntimeException('Pagamento non scritto.');
            }

            $paymentId = (int) ($created->insert_id ?? 0);

            StatusLogger::record(
                PaymentStatusLog::class,
                $paymentId,
                'status',
                '',
                $status,
                self::source($data),
                (int) ($data['user_id'] ?? 0) ?: null
            );

            return [
                'payment_id' => $paymentId,
                'created' => true,
                'payment_status' => self::sync($orderId),
            ];
        });
    }

    /**
     * Porta una riga già scritta a un altro stato, e lo annota.
     *
     * Chi è già in quello stato non si tocca: è la notifica ripetuta.
     *
     * @param array<string, mixed> $row
     */
    private static function move(array $row, string $status, string $message = '', string $paidAt = ''): void
    {
        $before = (string) $row['status'];

        if ($before === $status) {
            return;
        }

        $changes = ['status' => $status];

        if ($status === 'paid' && trim((string) ($row['paid_at'] ?? '')) === '') {
            $changes['paid_at'] = $paidAt !== '' ? $paidAt : date('Y-m-d H:i:s');
        }

        Payment::update($changes, (int) $row['id']);

        StatusLogger::record(
            PaymentStatusLog::class,
            (int) $row['id'],
            'status',
            $before,
            $status,
            'system',
            null,
            $message
        );
    }

    /**
     * La riga di quel gateway con quel riferimento, dello stesso tipo,
     * bloccata.
     *
     * La condizione è sull'indice unico: se la riga non c'è, il blocco resta
     * sullo spazio dove andrebbe, e la notifica gemella aspetta qui.
     *
     * Il tipo fa parte della chiave perché certi gateway rimandano il
     * riferimento dell'incasso anche quando restituiscono i soldi: senza,
     * il rimborso si confonderebbe con l'incasso e il denaro uscito non
     * risulterebbe da nessuna parte.
     *
     * @return array<string, mixed>|null
     */
    private static function findByReference(string $provider, string $reference, string $type): ?array
    {
        $row = Payment::findForUpdate([
            'provider' => $provider,
            'provider_reference' => $reference,
            'type' => $type,
        ], 1);

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * Il pagamento aperto al checkout, ancora firmato col nostro codice, che
     * l'incasso col riferimento del gateway deve chiudere.
     *
     * Al checkout il gateway non ha ancora un riferimento e la riga si firma
     * da sola. Quando l'incasso arriva con il suo, cercarlo non trova niente
     * e ne nascerebbe una seconda: la prima resterebbe «in attesa» per sempre,
     * e con lei un ordine che non risulta mai del tutto saldato. Vale anche
     * per l'incasso a mano, che un riferimento non ce l'ha. La riga si
     * riconosce perché è dello stesso ordine, dello stesso gateway, dello
     * stesso tipo, ancora aperta e mai passata dal gateway.
     *
     * @return array<string, mixed>|null
     */
    private static function adoptOpen(int $orderId, string $provider, string $reference, string $type): ?array
    {
        foreach (self::paymentsOf($orderId) as $row) {
            if ((string) $row['type'] !== $type
                || (string) $row['status'] !== 'pending'
                || (string) $row['provider'] !== $provider
                || (string) $row['provider_reference'] !== (string) $row['code']) {
                continue;
            }

            if ($reference !== '') {
                Payment::update(['provider_reference' => $reference], (int) $row['id']);
            }

            return Payment::findForUpdate(['id' => (int) $row['id']], 1) ?: null;
        }

        return null;
    }

    /**
     * Rimette sulla riga l'importo che è arrivato davvero.
     *
     * Si autorizzano cento e se ne incassano sessanta: quella riga vale
     * sessanta, e l'ordine resta pagato in parte. Tenendo l'importo della
     * prima notizia, l'ordine risulterebbe saldato con quaranta euro che
     * nessuno ha mai versato.
     *
     * @param array<string, mixed> $row
     */
    private static function reconcile(array $row, float $amount): void
    {
        $before = round((float) ($row['amount'] ?? 0), 2);

        if ($before === $amount) {
            return;
        }

        Payment::update(['amount' => self::money($amount)], (int) $row['id']);

        StatusLogger::record(
            PaymentStatusLog::class,
            (int) $row['id'],
            'amount',
            self::money($before),
            self::money($amount),
            'system'
        );
    }

    /**
     * Le righe di denaro dell'ordine, nella forma che `PaymentStatus` vuole.
     *
     * @return list<array<string, mixed>>
     */
    private static function paymentsOf(int $orderId): array
    {
        $rows = Payment::find(['order_id' => $orderId, 'deleted' => 'false']);

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Il gateway, scritto come lo scriviamo noi.
     *
     * Il caso non conta: un webhook che si firma «Stripe» e la conferma che
     * si firma «stripe» sono lo stesso gateway, e la riga aperta si deve
     * ritrovare. Un nome che non conosciamo invece non diventa un incasso a
     * mano — la riga resterebbe firmata da un altro, la notifica buona non la
     * ritroverebbe e l'ordine finirebbe incassato due volte.
     *
     * @param array<string, mixed> $data
     */
    private static function provider(array $data): string
    {
        $provider = strtolower(trim((string) ($data['provider'] ?? '')));

        if ($provider === '') {
            return 'manual';
        }

        if (!in_array($provider, Payment::PROVIDERS, true)) {
            throw UserError::make('payment.unknown_provider', [
                'provider' => trim((string) ($data['provider'] ?? '')),
            ]);
        }

        return $provider;
    }

    /** @param array<string, mixed> $data */
    private static function source(array $data): string
    {
        $source = trim((string) ($data['source'] ?? ''));

        return in_array($source, StatusLogger::SOURCES, true) ? $source : 'system';
    }

    /** Il denaro si scrive con il punto e due decimali, mai con la virgola. */
    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
