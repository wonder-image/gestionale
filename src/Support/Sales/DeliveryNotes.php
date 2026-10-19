<?php

namespace Wonder\Plugin\Gestionale\Support\Sales;

use RuntimeException;
use Throwable;
use Wonder\Plugin\Gestionale\Models\Sales\DeliveryNote;
use Wonder\Plugin\Gestionale\Models\Sales\DeliveryNoteItem;
use Wonder\Plugin\Gestionale\Models\Sales\DeliveryNoteStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Stock\Batch;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentData;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentFiles;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentPdfs;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentSequences;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Orders\OrderLines;
use Wonder\Plugin\Gestionale\Support\Orders\OrderNotifier;
use Wonder\Plugin\Gestionale\Support\Shipping\Shipments;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Plugin\Gestionale\Support\Stock\StockMovements;
use Wonder\Sql\Transaction;

/**
 * Il DDT di un ordine (G9 §4): **l'unica porta** che scrive un documento di
 * trasporto.
 *
 * La bozza nasce dall'ordine con quello che non è ancora in un DDT emesso, una
 * riga per lotto uscito; si cambiano le quantità e il trasporto. All'emissione
 * prende numero e data, il PDF si archivia e l'evasione dell'ordine si rifà
 * (`Shipments::syncOrder()`, con `FulfilledLines`). Non muove il magazzino: la
 * merce è uscita alla conferma.
 */
final class DeliveryNotes
{
    /** Gli stati dell'ordine da cui si fa un DDT: la merce è uscita. */
    public const ORDER_STATUSES = ['confirmed', 'processing', 'completed'];

    /**
     * La bozza dall'ordine: per riga di merce il resto da consegnare, diviso
     * per lotto; testata e trasporto copiati.
     *
     * @param array{user_id?: int, source?: string} $options
     * @return int l'id della bozza
     */
    public static function createFromOrder(int $orderId, array $options = []): int
    {
        return Transaction::run(static function () use ($orderId, $options): int {
            $order = Order::findForUpdate(['id' => $orderId], 1);

            if (!is_array($order) || $order === [] || (string) ($order['stage'] ?? '') !== 'order'
                || !in_array((string) ($order['status'] ?? ''), self::ORDER_STATUSES, true)) {
                throw UserError::make('delivery_note.order_not_ready');
            }

            $lines = self::proposal($orderId);

            if ($lines === []) {
                throw UserError::make('delivery_note.nothing_to_deliver');
            }

            $settings = self::settings();
            $values = [
                'code' => Code::make(DeliveryNote::class, Codes::DELIVERY_NOTE),
                'status' => 'draft',
                'reason' => 'sale',
                'order_id' => $orderId,
                'customer_id' => (int) ($order['customer_id'] ?? 0),
                'from_location_id' => (int) ($order['location_id'] ?? 0),
                'transport_by' => in_array((string) ($settings['delivery_transport_by'] ?? ''), DeliveryNote::TRANSPORT_BY, true) ? (string) $settings['delivery_transport_by'] : 'sender',
                'goods_appearance' => html_entity_decode((string) ($settings['delivery_goods_appearance'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'freight_terms' => in_array((string) ($settings['delivery_freight_terms'] ?? ''), DeliveryNote::FREIGHT_TERMS, true) ? (string) $settings['delivery_freight_terms'] : 'prepaid',
                'document_note' => '',
                'user_id' => (int) ($options['user_id'] ?? 0),
            ] + self::carrierCopy((int) ($settings['delivery_carrier_id'] ?? 0));

            // Solo gli indirizzi: `shipping_method_id` e `shipping_total` sono dell'ordine.
            foreach (self::addressKeys() as $key) {
                if (array_key_exists($key, $order)) {
                    $values[$key] = $order[$key];
                }
            }

            $created = DeliveryNote::create($values);
            $id = (int) ($created->insert_id ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('DDT non scritto.');
            }

            foreach ($lines as $i => $line) {
                DeliveryNoteItem::create($line + ['delivery_note_id' => $id, 'position' => ($i + 1) * 10]);
            }

            StatusLogger::record(DeliveryNoteStatusLog::class, $id, 'status', '', 'draft', (string) ($options['source'] ?? 'user'), (int) ($options['user_id'] ?? 0) ?: null);

            return $id;
        });
    }

    /**
     * Salva la bozza: il trasporto e le quantità delle righe (una quantità
     * vuota o zero toglie la riga).
     *
     * @param array<string, mixed> $header
     * @param array<int, mixed> $quantities riga del DDT => quantità
     */
    public static function saveDraft(int $id, array $header, array $quantities): void
    {
        Transaction::run(static function () use ($id, $header, $quantities): void {
            self::draft($id);
            // Solo le quantità, senza testata: un UPDATE senza campi è un errore SQL.
            $values = self::headerValues($header);

            if ($values !== []) {
                DeliveryNote::update($values, $id);
            }

            foreach (self::items($id) as $item) {
                $itemId = (int) $item['id'];

                if (!array_key_exists($itemId, $quantities)) {
                    continue;
                }

                $number = Numbers::fromForm($quantities[$itemId]);

                if ($number === null || (is_numeric($number) && (float) $number == 0.0)) {
                    DeliveryNoteItem::delete($itemId);

                    continue;
                }

                if (!is_numeric($number) || (float) $number < 0 || (float) $number > 9999999.999) {
                    throw UserError::make('delivery_note.invalid_quantity', ['name' => (string) $item['description']]);
                }

                DeliveryNoteItem::update(['quantity' => number_format(round((float) $number, 3), 3, '.', '')], $itemId);
            }
        });
    }

    /**
     * Emette: controlla che non si consegni più di quanto ordinato, dà numero
     * e data, archivia il PDF, rifà l'evasione dell'ordine.
     *
     * @param array{user_id?: int, source?: string} $options
     * @return array{number: string, file: string}
     */
    public static function issue(int $id, array $options = []): array
    {
        $result = Transaction::run(static function () use ($id, $options): array {
            $note = self::draft($id);
            $items = self::items($id);
            $orderId = (int) $note['order_id'];

            if (array_filter($items, static fn (array $i): bool => (float) $i['quantity'] > 0) === []) {
                throw UserError::make('delivery_note.no_rows');
            }

            $ordered = self::orderedGoods($orderId);
            $already = self::inNotes($orderId);
            $wanted = [];
            $names = [];

            foreach ($items as $item) {
                $line = (int) $item['order_item_id'];
                $wanted[$line] = round(($wanted[$line] ?? 0.0) + (float) $item['quantity'], 3);
                $names[$line] = (string) $item['description'];
            }

            foreach ($wanted as $line => $quantity) {
                $max = round((float) ($ordered[$line] ?? 0) - (float) ($already[$line] ?? 0), 3);

                if ($quantity > $max + 0.0005) {
                    throw UserError::make('delivery_note.too_much', ['name' => $names[$line], 'max' => OfficeOrderRules::plain(max(0.0, $max))]);
                }
            }

            $number = DocumentSequences::next('delivery_note');
            $started = trim((string) ($note['transport_started_at'] ?? ''));

            DeliveryNote::update([
                'number' => $number,
                'date' => date('Y-m-d'),
                'status' => 'issued',
                'transport_started_at' => $started !== '' && !str_starts_with($started, '0000') ? $started : date('Y-m-d H:i:s'),
            ], $id);
            StatusLogger::record(DeliveryNoteStatusLog::class, $id, 'status', 'draft', 'issued', (string) ($options['source'] ?? 'user'), (int) ($options['user_id'] ?? 0) ?: null);

            $file = DocumentFiles::save('delivery-notes/'.$id.'.pdf', DocumentPdfs::render('delivery_note', DocumentData::forDeliveryNote($id)));
            DeliveryNote::update(['pdf_file' => $file], $id);
            Shipments::syncOrder($orderId, $options);

            return ['number' => $number, 'file' => $file];
        });

        return $result;
    }

    /**
     * Annulla un DDT emesso: le sue righe non contano più come consegnate,
     * e l'evasione dell'ordine torna indietro.
     *
     * @param array{user_id?: int, source?: string, reason?: string} $options
     */
    public static function cancel(int $id, array $options = []): void
    {
        Transaction::run(static function () use ($id, $options): void {
            $note = self::lock($id);

            if ((string) $note['status'] !== 'issued') {
                throw UserError::make('delivery_note.cannot_cancel');
            }

            DeliveryNote::update(['status' => 'cancelled'], $id);
            StatusLogger::record(DeliveryNoteStatusLog::class, $id, 'status', 'issued', 'cancelled', (string) ($options['source'] ?? 'user'), (int) ($options['user_id'] ?? 0) ?: null, (string) ($options['reason'] ?? ''));
            Shipments::syncOrder((int) $note['order_id'], $options);
        });
    }

    /** La bozza si elimina; un DDT emesso si annulla. */
    public static function delete(int $id): void
    {
        Transaction::run(static function () use ($id): void {
            self::draft($id);
            sqlDelete(DeliveryNoteItem::$table, 'delivery_note_id = '.$id);
            sqlDelete(DeliveryNoteStatusLog::$table, 'delivery_note_id = '.$id);
            sqlDelete(DeliveryNote::$table, 'id = '.$id);
        });
    }

    /**
     * Manda il DDT emesso al cliente, con il PDF.
     *
     * @return string l'esito della posta (`sent`, `no_recipients`, `failed`…)
     */
    public static function send(int $id): string
    {
        $note = self::lock($id);

        if ((string) $note['status'] !== 'issued') {
            throw UserError::make('delivery_note.not_issued');
        }

        $path = DocumentFiles::path((string) ($note['pdf_file'] ?? ''));
        $name = 'DDT-'.preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $note['number']).'.pdf';

        return OrderNotifier::send('delivery_note_sent', (int) $note['order_id'], ['ddt' => (string) $note['number']], $path === null ? [] : [$path => $name])['status'];
    }

    /**
     * Quanto di ogni riga d'ordine sta in DDT emessi.
     *
     * @return array<int, float>
     */
    public static function inNotes(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        try {
            $notes = self::rows(DeliveryNote::find(['order_id' => $orderId, 'status' => 'issued', 'deleted' => 'false']));
        } catch (Throwable) {
            // La tabella non c'è ancora (prima di `forge update`): nessun DDT.
            return [];
        }

        if ($notes === []) {
            return [];
        }

        $ids = implode(', ', array_map(static fn (array $n): int => (int) $n['id'], $notes));
        $out = [];

        foreach (self::rows(DeliveryNoteItem::find('`delivery_note_id` IN ('.$ids.") AND `deleted` = 'false'")) as $item) {
            $line = (int) $item['order_item_id'];
            $out[$line] = round(($out[$line] ?? 0.0) + (float) $item['quantity'], 3);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function items(int $id): array
    {
        return self::rows(DeliveryNoteItem::find(['delivery_note_id' => $id, 'deleted' => 'false'], null, 'position', 'ASC'));
    }

    /**
     * Le righe proposte: per ogni riga di merce il resto da consegnare,
     * diviso sui lotti da cui è uscita (al netto dei rientri). Un lotto già
     * consegnato in un altro DDT non si ripropone.
     *
     * @return list<array<string, mixed>>
     */
    public static function proposal(int $orderId): array
    {
        $items = self::rows(OrderItem::find(['order_id' => $orderId, 'deleted' => 'false'], null, 'position', 'ASC'));
        $already = self::inNotes($orderId);
        $byBatch = self::deliveredByBatch($orderId);
        $lines = [];

        foreach (OrderLines::goods($items) as $item) {
            $itemId = (int) $item['id'];
            $left = round((float) $item['quantity'] - (float) ($already[$itemId] ?? 0), 3);

            if ($left <= 0) {
                continue;
            }

            $batches = [];

            foreach (StockMovements::netOut($itemId) as $bucket) {
                $batches[(int) $bucket['batch_id']] = round(($batches[(int) $bucket['batch_id']] ?? 0.0) + (float) $bucket['quantity'], 3);
            }

            // Senza movimenti (o senza lotti) una riga sola, senza lotto.
            if ($batches === [] || array_keys($batches) === [0]) {
                $lines[] = self::line($item, $left, 0);

                continue;
            }

            foreach ($batches as $batchId => $out) {
                $free = round($out - (float) ($byBatch[$itemId][$batchId] ?? 0), 3);
                $take = round(min($left, $free), 3);

                if ($take <= 0) {
                    continue;
                }

                $lines[] = self::line($item, $take, $batchId);
                $left = round($left - $take, 3);
            }

            if ($left > 0) {
                $lines[] = self::line($item, $left, 0);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function line(array $item, float $quantity, int $batchId): array
    {
        $batch = $batchId > 0 ? Batch::findById($batchId) : null;

        return [
            'order_item_id' => (int) $item['id'],
            'product_id' => (int) ($item['product_id'] ?? 0),
            'sku' => (string) ($item['sku'] ?? ''),
            'description' => html_entity_decode((string) ($item['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'quantity' => number_format($quantity, 3, '.', ''),
            'unit' => (string) (($item['unit'] ?? '') ?: 'pz'),
            'batch_id' => $batchId,
            'batch_number' => is_array($batch) ? html_entity_decode((string) ($batch['number'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
            'supplier_id' => 0,
        ];
    }

    /**
     * La merce ordinata, per riga (le figlie dei multiprodotti, non le madri).
     *
     * @return array<int, float>
     */
    public static function orderedGoods(int $orderId): array
    {
        $out = [];

        foreach (OrderLines::goods(self::rows(OrderItem::find(['order_id' => $orderId, 'deleted' => 'false']))) as $item) {
            $out[(int) $item['id']] = round((float) $item['quantity'], 3);
        }

        return $out;
    }

    /**
     * Quanto di ogni lotto di ogni riga è già in DDT emessi.
     *
     * @return array<int, array<int, float>>
     */
    private static function deliveredByBatch(int $orderId): array
    {
        $notes = self::rows(DeliveryNote::find(['order_id' => $orderId, 'status' => 'issued', 'deleted' => 'false']));
        $out = [];

        foreach ($notes as $note) {
            foreach (self::items((int) $note['id']) as $item) {
                $line = (int) $item['order_item_id'];
                $batch = (int) $item['batch_id'];
                $out[$line][$batch] = round(($out[$line][$batch] ?? 0.0) + (float) $item['quantity'], 3);
            }
        }

        return $out;
    }

    /**
     * Il trasporto dalla pagina, pulito. Il vettore si copia dalla tabella
     * dei corrieri.
     *
     * @param array<string, mixed> $header
     * @return array<string, mixed>
     */
    private static function headerValues(array $header): array
    {
        $values = [];

        if (array_key_exists('transport_by', $header)) {
            $values['transport_by'] = in_array((string) $header['transport_by'], DeliveryNote::TRANSPORT_BY, true) ? (string) $header['transport_by'] : 'sender';
        }

        if (array_key_exists('freight_terms', $header)) {
            $values['freight_terms'] = in_array((string) $header['freight_terms'], DeliveryNote::FREIGHT_TERMS, true) ? (string) $header['freight_terms'] : 'prepaid';
        }

        if (array_key_exists('carrier_id', $header)) {
            $values += self::carrierCopy((int) $header['carrier_id']);
        }

        foreach (['goods_appearance', 'document_note'] as $key) {
            if (array_key_exists($key, $header)) {
                $values[$key] = mb_substr(trim((string) $header[$key]), 0, $key === 'goods_appearance' ? 100 : 2000);
            }
        }

        if (array_key_exists('packages_count', $header)) {
            $values['packages_count'] = max(0, min(99999, (int) $header['packages_count']));
        }

        foreach (['gross_weight', 'net_weight'] as $key) {
            if (array_key_exists($key, $header)) {
                $number = Numbers::fromForm($header[$key]);

                if ($number !== null && (!is_numeric($number) || (float) $number < 0)) {
                    throw UserError::make('delivery_note.invalid_weight');
                }

                $values[$key] = $number === null ? null : number_format(round((float) $number, 3), 3, '.', '');
            }
        }

        if (array_key_exists('transport_started_at', $header)) {
            $text = trim((string) $header['transport_started_at']);
            $values['transport_started_at'] = $text === '' ? null : self::dateTime($text);
        }

        foreach ($header as $key => $value) {
            if (in_array((string) $key, self::addressKeys(), true) && str_starts_with((string) $key, 'shipping_')
                && is_scalar($value) && !in_array((string) $key, ['shipping_lat', 'shipping_lng'], true)) {
                $values[(string) $key] = trim((string) $value);
            }
        }

        return $values;
    }

    /** @return list<string> le colonne degli indirizzi copiati dall'ordine */
    private static function addressKeys(): array
    {
        return array_map(
            static fn (object $column): string => (string) $column->name,
            [...Order::billingAddress()->tableSchema(), ...Order::shippingTableSchema()]
        );
    }

    /** @return array{carrier_id: int, carrier_name: string, carrier_vat: string, carrier_address: string} */
    private static function carrierCopy(int $carrierId): array
    {
        $carrier = $carrierId > 0 ? Carrier::findById($carrierId) : null;

        return [
            'carrier_id' => is_array($carrier) && $carrier !== [] ? $carrierId : 0,
            'carrier_name' => is_array($carrier) ? html_entity_decode((string) ($carrier['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
            'carrier_vat' => is_array($carrier) ? (string) ($carrier['vat_number'] ?? $carrier['pi'] ?? '') : '',
            'carrier_address' => is_array($carrier) ? html_entity_decode((string) ($carrier['address'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
        ];
    }

    /** «2026-10-13T09:30» (campo datetime-local) o «2026-10-13 09:30». */
    private static function dateTime(string $text): string
    {
        $text = str_replace('T', ' ', $text);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/', $text, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw UserError::make('delivery_note.invalid_date');
        }

        return sprintf('%s-%s-%s %02d:%02d:00', $m[1], $m[2], $m[3], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0));
    }

    /** @return array<string, mixed> */
    private static function draft(int $id): array
    {
        $note = self::lock($id);

        if ((string) $note['status'] !== 'draft') {
            throw UserError::make('delivery_note.not_draft');
        }

        return $note;
    }

    /** @return array<string, mixed> */
    private static function lock(int $id): array
    {
        $note = $id > 0 ? DeliveryNote::findForUpdate(['id' => $id], 1) : null;

        if (!is_array($note) || $note === [] || (string) ($note['deleted'] ?? 'false') === 'true') {
            throw UserError::make('delivery_note.not_found');
        }

        return $note;
    }

    /** @return array<string, mixed> */
    private static function settings(): array
    {
        try {
            return Setting::current();
        } catch (Throwable) {
            return [];
        }
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
