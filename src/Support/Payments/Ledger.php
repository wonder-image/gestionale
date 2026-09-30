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

            self::move($row, 'failed', $message);

            return self::sync((int) $row['order_id']);
        });
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

            return $status;
        });
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

        return Transaction::run(static function () use ($data, $type, $status, $orderId, $amount): array {
            $provider = self::provider($data);
            $reference = trim((string) ($data['provider_reference'] ?? ''));
            $existing = $reference === '' ? null : self::findByReference($provider, $reference);

            if (is_array($existing)) {
                self::move($existing, $status, (string) ($data['note'] ?? ''));

                return [
                    'payment_id' => (int) $existing['id'],
                    'created' => false,
                    'payment_status' => self::sync((int) $existing['order_id']),
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
                // Senza riferimento del gateway ci si firma con il proprio
                // codice: l'indice unico vuole un valore diverso per riga.
                'provider_reference' => $reference !== '' ? $reference : $code,
                'paid_at' => $status === 'paid' ? date('Y-m-d H:i:s') : '',
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
    private static function move(array $row, string $status, string $message = ''): void
    {
        $before = (string) $row['status'];

        if ($before === $status) {
            return;
        }

        $changes = ['status' => $status];

        if ($status === 'paid' && trim((string) ($row['paid_at'] ?? '')) === '') {
            $changes['paid_at'] = date('Y-m-d H:i:s');
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
     * La riga di quel gateway con quel riferimento, bloccata.
     *
     * La condizione è sull'indice unico: se la riga non c'è, il blocco resta
     * sullo spazio dove andrebbe, e la notifica gemella aspetta qui.
     *
     * @return array<string, mixed>|null
     */
    private static function findByReference(string $provider, string $reference): ?array
    {
        $row = Payment::findForUpdate([
            'provider' => $provider,
            'provider_reference' => $reference,
        ], 1);

        return is_array($row) && $row !== [] ? $row : null;
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

    /** @param array<string, mixed> $data */
    private static function provider(array $data): string
    {
        $provider = trim((string) ($data['provider'] ?? ''));

        return in_array($provider, Payment::PROVIDERS, true) ? $provider : 'manual';
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
