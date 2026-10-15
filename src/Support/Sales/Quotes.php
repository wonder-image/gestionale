<?php

namespace Wonder\Plugin\Gestionale\Support\Sales;

use DateTimeImmutable;
use RuntimeException;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderQuoteRevision;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentData;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentFiles;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentPdfs;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentSequences;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\OrderNotifier;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Sql\Transaction;

/**
 * Il preventivo dopo la bozza (G9 §3.1): invio con revisione e PDF
 * archiviato, accettazione (diventa una bozza d'ordine, lo stesso documento),
 * rifiuto e scadenza. La bozza la scrive `OfficeOrders::save()` con lo
 * `stage` `quote`.
 *
 * Un preventivo non tocca mai il magazzino: non prenota e non scarica.
 */
final class Quotes
{
    /**
     * Invia il preventivo. Da bozza: la revisione +1, il PDF archiviato,
     * `sent`; il numero l'ha già dal primo salvataggio. Già inviato e non cambiato: si rimanda
     * l'ultima revisione, senza farne un'altra. Con `notify` (predefinito)
     * parte l'email al cliente con il PDF.
     *
     * @param array{user_id?: int, source?: string, notify?: bool} $options
     * @return array{number: string, revision: int, file: string, email: string}
     */
    public static function send(int $orderId, array $options = []): array
    {
        $userId = (int) ($options['user_id'] ?? 0);
        $source = (string) ($options['source'] ?? 'user');

        $result = Transaction::run(static function () use ($orderId, $userId, $source): array {
            $order = self::lock($orderId);
            $status = (string) $order['status'];

            if ($status === 'sent') {
                $last = self::revisions($orderId)[0] ?? null;

                if ($last !== null) {
                    return [
                        'number' => (string) $order['quote_number'],
                        'revision' => (int) $last['revision'],
                        'file' => (string) $last['pdf_file'],
                        'revision_id' => (int) $last['id'],
                    ];
                }
            } elseif ($status !== 'draft') {
                throw UserError::make('quote.cannot_send');
            }

            self::assertSendable($order, self::items($orderId));

            // Il numero c'è dal primo salvataggio (`OfficeOrders::save()`); un
            // preventivo di prima lo prende qui, dalla sequenza degli ordini.
            $number = trim((string) ($order['quote_number'] ?? '')) ?: trim((string) ($order['order_number'] ?? ''));
            $number = $number !== '' ? $number : DocumentSequences::next('order');
            $revision = (int) ($order['quote_revision'] ?? 0) + 1;
            $expires = trim((string) ($order['quote_expires_at'] ?? ''));

            Order::update([
                'quote_number' => $number,
                'order_number' => trim((string) ($order['order_number'] ?? '')) ?: $number,
                'quote_revision' => $revision,
                'quote_sent_at' => date('Y-m-d H:i:s'),
                'quote_expires_at' => $expires !== '' && !str_starts_with($expires, '0000') ? $expires : OfficeOrders::defaultExpiry(),
                'status' => 'sent',
            ], $orderId);
            StatusLogger::record(OrderStatusLog::class, $orderId, 'status', $status, 'sent', $source, $userId ?: null, 'Revisione '.$revision);

            $file = DocumentFiles::save(
                'quotes/'.$orderId.'-'.$revision.'.pdf',
                DocumentPdfs::render('quote', DocumentData::forOrder($orderId))
            );
            $created = OrderQuoteRevision::create([
                'order_id' => $orderId,
                'revision' => $revision,
                'pdf_file' => $file,
                'sent_to' => '',
                'sent_at' => date('Y-m-d H:i:s'),
                'total' => number_format((float) ($order['total'] ?? 0), 2, '.', ''),
                'user_id' => $userId,
            ]);

            if (($created->success ?? false) !== true) {
                throw new RuntimeException('Revisione del preventivo non scritta.');
            }

            return ['number' => $number, 'revision' => $revision, 'file' => $file, 'revision_id' => (int) ($created->insert_id ?? 0)];
        });

        $email = 'skipped';

        if (($options['notify'] ?? true) === true) {
            // Fuori dalla transazione: la posta è lenta e un invio fallito non
            // disfa il preventivo, che resta inviato con il suo PDF.
            $path = DocumentFiles::path($result['file']);
            $sent = OrderNotifier::send('quote_sent', $orderId, [], $path === null ? [] : [
                $path => 'Preventivo-'.preg_replace('/[^A-Za-z0-9-]+/', '-', $result['number']).($result['revision'] > 1 ? '-rev'.$result['revision'] : '').'.pdf',
            ]);
            $email = $sent['status'];

            if ($sent['sent'] !== []) {
                OrderQuoteRevision::update(['sent_to' => implode(', ', $sent['sent'])], $result['revision_id']);
            }
        }

        return ['number' => $result['number'], 'revision' => $result['revision'], 'file' => $result['file'], 'email' => $email];
    }

    /**
     * Il cliente ha detto sì: lo stesso documento diventa una bozza d'ordine
     * da ufficio, con le stesse righe e il numero di preventivo. Si conferma
     * dalla sua pagina (G9.10).
     *
     * @param array{user_id?: int, source?: string} $options
     */
    public static function accept(int $orderId, array $options = []): int
    {
        return Transaction::run(static function () use ($orderId, $options): int {
            $order = self::lock($orderId);

            if (!in_array((string) $order['status'], ['draft', 'sent'], true)) {
                throw UserError::make('quote.cannot_accept');
            }

            Order::update(['stage' => 'order', 'status' => 'draft'], $orderId);
            // Nello storico il passaggio da preventivo a ordine; lo stato, solo se cambia.
            StatusLogger::record(
                OrderStatusLog::class,
                $orderId,
                'stage',
                'quote',
                'order',
                (string) ($options['source'] ?? 'user'),
                (int) ($options['user_id'] ?? 0) ?: null,
                'Preventivo accettato'
            );

            if ((string) $order['status'] !== 'draft') {
                StatusLogger::record(OrderStatusLog::class, $orderId, 'status', (string) $order['status'], 'draft', (string) ($options['source'] ?? 'user'), (int) ($options['user_id'] ?? 0) ?: null);
            }

            return $orderId;
        });
    }

    /** @param array{user_id?: int, source?: string} $options */
    public static function reject(int $orderId, array $options = []): void
    {
        self::move($orderId, ['sent'], 'rejected', 'quote.cannot_reject', $options, 'Preventivo rifiutato');
    }

    /**
     * Un preventivo mai inviato si elimina, e il suo numero resta un buco
     * nella sequenza; uno inviato no: il cliente ha il suo PDF (si rifiuta o
     * si lascia scadere).
     */
    public static function delete(int $orderId): void
    {
        Transaction::run(static function () use ($orderId): void {
            $order = self::lock($orderId);

            if ((string) $order['status'] !== 'draft' || (int) ($order['quote_revision'] ?? 0) > 0) {
                throw UserError::make('quote.cannot_delete');
            }

            sqlDelete(OrderItem::$table, 'order_id = '.$orderId);
            sqlDelete(OrderTaxSummary::$table, 'order_id = '.$orderId);
            sqlDelete(OrderStatusLog::$table, 'order_id = '.$orderId);
            sqlDelete(Order::$table, 'id = '.$orderId);
        });
    }

    /**
     * I preventivi inviati oltre la validità passano a `expired`: lo fa
     * `QuoteExpiryTask`, una volta al giorno. Il giorno stesso vale ancora.
     *
     * @return int quanti
     */
    public static function expireDue(?DateTimeImmutable $now = null): int
    {
        $today = ($now ?? new DateTimeImmutable())->format('Y-m-d');
        $rows = Order::find(
            "stage = 'quote' AND status = 'sent' AND deleted = 'false' AND quote_expires_at IS NOT NULL"
            ." AND quote_expires_at > '1000-01-01' AND quote_expires_at < '".$today." 00:00:00'"
        );
        $rows = is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'))) : [];
        $count = 0;

        foreach ($rows as $row) {
            try {
                self::move((int) $row['id'], ['sent'], 'expired', 'quote.cannot_reject', ['source' => 'cron'], 'Validità scaduta');
                $count++;
            } catch (UserError) {
                // Cambiato nel frattempo da qualcuno: non è più da far scadere.
            }
        }

        return $count;
    }

    /**
     * Le revisioni inviate, dalla più recente.
     *
     * @return list<array<string, mixed>>
     */
    public static function revisions(int $orderId): array
    {
        $rows = OrderQuoteRevision::find(['order_id' => $orderId, 'deleted' => 'false'], null, 'revision', 'DESC');

        return is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'))) : [];
    }

    /**
     * Quello che l'invio chiede: un cliente con un indirizzo, almeno una riga
     * che si vende. L'indirizzo di consegna no: si chiede alla conferma.
     *
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $items
     */
    public static function assertSendable(array $order, array $items): void
    {
        if ((int) ($order['customer_id'] ?? 0) <= 0) {
            throw UserError::make('office_order.customer_required');
        }

        $sold = array_filter($items, static fn (array $i): bool => in_array((string) ($i['type'] ?? ''), ['product', 'custom'], true)
            && (int) ($i['parent_item_id'] ?? 0) === 0
            && (float) ($i['quantity'] ?? 0) > 0);

        if ($sold === []) {
            throw UserError::make('office_order.no_rows');
        }
    }

    /**
     * @param list<string> $from
     * @param array{user_id?: int, source?: string} $options
     */
    private static function move(int $orderId, array $from, string $to, string $refusal, array $options, string $message): void
    {
        Transaction::run(static function () use ($orderId, $from, $to, $refusal, $options, $message): void {
            $order = self::lock($orderId);
            $status = (string) $order['status'];

            if (!in_array($status, $from, true)) {
                throw UserError::make($refusal);
            }

            Order::update(['status' => $to], $orderId);
            StatusLogger::record(
                OrderStatusLog::class,
                $orderId,
                'status',
                $status,
                $to,
                (string) ($options['source'] ?? 'user'),
                (int) ($options['user_id'] ?? 0) ?: null,
                $message
            );
        });
    }

    /** @return array<string, mixed> */
    private static function lock(int $orderId): array
    {
        $order = $orderId > 0 ? Order::findForUpdate(['id' => $orderId], 1) : null;

        if (!is_array($order) || $order === [] || (string) ($order['deleted'] ?? 'false') === 'true' || (string) ($order['stage'] ?? '') !== 'quote') {
            throw UserError::make('quote.not_found');
        }

        return $order;
    }

    /** @return list<array<string, mixed>> */
    private static function items(int $orderId): array
    {
        $rows = OrderItem::find(['order_id' => $orderId, 'deleted' => 'false']);

        return is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'))) : [];
    }
}
