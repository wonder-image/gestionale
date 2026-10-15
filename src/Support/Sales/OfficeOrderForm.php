<?php

namespace Wonder\Plugin\Gestionale\Support\Sales;

use JsonException;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * L'invio della pagina *Nuovo ordine* (G9 §2.2), senza HTML: legge il POST,
 * chiama `OfficeOrders` e dice com'è andata. Come `StockDocumentForm`: le
 * righe in un solo campo JSON (`lines`), e un ordine nuovo nasce bozza anche
 * quando la conferma poi si ferma, così la pagina riapre quella bozza con
 * l'errore.
 */
final class OfficeOrderForm
{
    /** Chiave di sessione di una bozza nuova che non è nata (`remember()`). */
    public const RESTORE_KEY = 'gst_office_order_restore';

    private const RESTORE_TTL = 600;

    /** I campi della testata che la pagina manda. */
    private const HEADER_KEYS = [
        'customer_id', 'email', 'phone', 'location_id', 'shipping_method_id', 'payment_method_id', 'payment_term_id',
        'customer_note', 'internal_note', 'document_note', 'fulfillment_type', 'manual_discount_type',
        'manual_discount_value', 'shipping_amount', 'quote_expires_at',
    ];

    /**
     * Salva la bozza; con `intent = confirm` la conferma, con `delete` la
     * elimina. Per un preventivo (`$stage` `quote`, piano 3) anche `send`,
     * `accept` e `reject`. Con `$save` falso (il menu ⋯ dell'elenco) agisce
     * sulla bozza com'è, senza salvarla prima.
     *
     * @param array<string, mixed> $post
     * @return array{id: int, number: string, deleted: bool, error: string, sent: string, accepted: bool, rejected: bool, email: string}
     */
    public static function submit(int $id, array $post, int $userId, string $stage = 'order', bool $save = true): array
    {
        $result = ['id' => $id, 'number' => '', 'deleted' => false, 'error' => '', 'sent' => '', 'accepted' => false, 'rejected' => false, 'email' => ''];
        $intent = (string) ($post['intent'] ?? 'save');
        $quote = $stage === 'quote';
        $notify = (string) ($post['notify'] ?? '') === 'true';

        try {
            if ($intent === 'delete') {
                $quote ? Quotes::delete($id) : OfficeOrders::delete($id);
                $result['deleted'] = true;

                return $result;
            }

            if ($quote && $intent === 'reject') {
                Quotes::reject($id, ['user_id' => $userId]);
                $result['rejected'] = true;

                return $result;
            }

            if ($save) {
                $data = self::header($post);
                $data['lines'] = self::decode($post['lines'] ?? null);
                $result['id'] = OfficeOrders::save($id, $data, $userId, $stage);
            }

            if ($quote) {
                if ($intent === 'send') {
                    $sent = Quotes::send($result['id'], ['user_id' => $userId, 'notify' => $notify]);
                    $result['sent'] = $sent['number'].($sent['revision'] > 1 ? ' rev. '.$sent['revision'] : '');
                    $result['email'] = $sent['email'];
                } elseif ($intent === 'accept') {
                    Quotes::accept($result['id'], ['user_id' => $userId]);
                    $result['accepted'] = true;
                }

                return $result;
            }

            if ($intent === 'confirm') {
                $result['number'] = OfficeOrders::confirm($result['id'], [
                    'user_id' => $userId,
                    'source' => 'user',
                    'notify' => $notify,
                ])['order_number'];
            }
        } catch (UserError $error) {
            $result['error'] = $error->getMessage();
        }

        return $result;
    }

    /**
     * Il campo `lines`: una lista JSON. Tutto il resto è un rifiuto: una
     * pagina rotta non deve cancellare le righe salvate.
     *
     * @return list<array<string, mixed>>
     */
    public static function decode(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            throw UserError::make('office_order.invalid_lines');
        }

        try {
            $lines = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw UserError::make('office_order.invalid_lines');
        }

        if (!is_array($lines) || !array_is_list($lines)) {
            throw UserError::make('office_order.invalid_lines');
        }

        return $lines;
    }

    /**
     * La testata inviata: i campi noti e gli indirizzi, solo se scalari.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function header(array $post): array
    {
        $out = [];

        foreach ($post as $key => $value) {
            $key = (string) $key;

            if (!is_scalar($value)) {
                continue;
            }

            if (in_array($key, self::HEADER_KEYS, true) || str_starts_with($key, 'billing_') || str_starts_with($key, 'shipping_')) {
                $out[$key] = (string) $value;
            }
        }

        // Col ritiro la sede è quella dove passa il cliente.
        if (($out['fulfillment_type'] ?? '') === 'pickup' && is_scalar($post['pickup_location_id'] ?? null)) {
            $out['location_id'] = (string) $post['pickup_location_id'];
        }

        return $out;
    }

    /**
     * Una bozza nuova che non nasce (pagina scaduta, riga sbagliata): testata
     * e righe restano in sessione, e la prossima *Nuovo ordine* le ridisegna.
     *
     * @param array<string, mixed> $post
     */
    public static function remember(array $post, string $stage = 'order'): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        try {
            $lines = self::decode($post['lines'] ?? null);
        } catch (UserError) {
            $lines = [];
        }

        $_SESSION[self::RESTORE_KEY] = [
            'at' => time(),
            'stage' => $stage,
            'header' => self::header($post),
            'lines' => array_slice(array_values(array_filter($lines, 'is_array')), 0, OfficeOrderRules::MAX_LINES),
        ];
    }

    /**
     * Quello che `remember()` ha messo da parte, una volta sola.
     *
     * @return array{header: array<string, string>, lines: list<array<string, mixed>>}|null
     */
    public static function recall(string $stage = 'order'): ?array
    {
        $saved = $_SESSION[self::RESTORE_KEY] ?? null;

        // Quello di un preventivo non torna in un ordine nuovo, e viceversa.
        if (!is_array($saved) || (string) ($saved['stage'] ?? 'order') !== $stage) {
            return null;
        }

        unset($_SESSION[self::RESTORE_KEY]);

        if (time() - (int) ($saved['at'] ?? 0) > self::RESTORE_TTL) {
            return null;
        }

        return ['header' => (array) ($saved['header'] ?? []), 'lines' => array_values((array) ($saved['lines'] ?? []))];
    }

    /**
     * Le righe per l'editor, dalle righe salvate o da quelle rimandate dalla
     * pagina: le versioni rilette con prezzo e disponibile della sede. Una
     * versione che non c'è più non si disegna e si conta in `dropped`.
     * Spedizione e righe figlie non sono dell'editor.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{lines: list<array<string, mixed>>, dropped: int}
     */
    public static function editorLines(array $rows, int $locationId): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if ((string) ($row['type'] ?? 'product') === 'product' && (int) ($row['product_id'] ?? 0) > 0) {
                $ids[] = (int) $row['product_id'];
            }
        }

        $versions = [];

        foreach ($ids === [] ? [] : OfficeSearch::describe($ids, $locationId) as $version) {
            $versions[(int) $version['id']] = $version;
        }

        $lines = [];
        $dropped = 0;

        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? 'product');

            if ((int) ($row['parent_item_id'] ?? 0) > 0 || !in_array($type, OfficeOrderRules::LINE_TYPES, true)) {
                continue;
            }

            if ($type === 'text') {
                $lines[] = ['type' => 'text', 'name' => html_entity_decode((string) ($row['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')];

                continue;
            }

            $price = array_key_exists('manual_unit_price', $row) ? $row['manual_unit_price'] : ($row['unit_price'] ?? '');
            $line = [
                'type' => $type,
                'quantity' => self::number($row['quantity'] ?? 1),
                'unit_price' => $price === null || trim((string) $price) === '' ? '' : (string) $price,
                'discount_type' => (string) ($row['discount_type'] ?? 'none'),
                'discount_value' => (string) ($row['discount_value'] ?? ''),
                'batch_id' => (int) ($row['batch_id'] ?? 0),
                'supplier_id' => (int) ($row['supplier_id'] ?? 0),
            ];

            if ($type === 'custom') {
                $lines[] = $line + [
                    'name' => html_entity_decode((string) ($row['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'tax_category_id' => (int) ($row['tax_category_id'] ?? 0),
                ];

                continue;
            }

            $version = $versions[(int) ($row['product_id'] ?? 0)] ?? null;

            if ($version === null) {
                $dropped++;

                continue;
            }

            $lines[] = $line + ['version' => $version];
        }

        return ['lines' => $lines, 'dropped' => $dropped];
    }

    /** @return list<array<string, mixed>> le righe salvate della bozza */
    public static function items(int $orderId): array
    {
        $rows = OrderItem::find(['order_id' => $orderId, 'deleted' => 'false'], null, 'position', 'ASC');

        return is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'))) : [];
    }

    /** @return array<int, string> le categorie IVA visibili, per le righe libere */
    public static function taxCategories(): array
    {
        $rows = TaxCategory::find(['deleted' => 'false', 'visible' => 'true'], null, 'position', 'ASC');
        $rows = is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'))) : [];
        $out = [];

        // La predefinita per prima: è quella che la riga libera propone.
        usort($rows, static fn (array $a, array $b): int => [(string) ($b['is_default'] ?? '') === 'true', (int) ($a['position'] ?? 0)]
            <=> [(string) ($a['is_default'] ?? '') === 'true', (int) ($b['position'] ?? 0)]);

        foreach ($rows as $row) {
            $out[(int) $row['id']] = (string) ($row['name'] ?? '');
        }

        return $out;
    }

    private static function number(mixed $value): float|string
    {
        return is_numeric($value) ? round((float) $value, 3) : (string) $value;
    }
}
