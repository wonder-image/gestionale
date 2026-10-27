<?php

namespace Wonder\Plugin\Gestionale\Support\Mcp;

use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Resources\Sales\OfficeOrderResource;
use Wonder\Plugin\Gestionale\Resources\Sales\QuoteResource;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderRules;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders;
use Wonder\Plugin\Gestionale\Support\Tax\TaxCategories;

/**
 * Gli strumenti MCP dei preventivi: un preventivo è un ordine con `stage`
 * `quote`, scritto da `OfficeOrders::save()` come fa l'editor del backend.
 *
 * Da qui nasce solo la bozza: l'invio al cliente (PDF ed email) resta a chi
 * lo apre nel backend e lo controlla.
 */
final class QuoteTools
{
    use ToolSupport;

    /** Quello che ogni tipo di riga deve avere. */
    private const LINE_REQUIRED = [
        'product' => ['product_id', 'quantity'],
        'custom' => ['name', 'quantity', 'unit_price'],
        'text' => ['name'],
    ];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $money = ['type' => 'number', 'minimum' => 0];
        $text = ['type' => 'string', 'maxLength' => 2000];

        return [
            'quote_create' => [
                'description' => 'Crea un preventivo in bozza per un cliente (`customer_id`, un contatto del gestionale) con le sue righe. Righe: `product` (product_id della versione e quantity; unit_price vuoto = prezzo di listino), `custom` (name, quantity, unit_price, tax_category_id facoltativo, vuoto vale il tipo di IVA predefinito), `text` (solo name, una nota). Cliente, righe, quantità e prezzi li decide l\'utente: se mancano chiediglieli, non inventarli. Il preventivo non parte verso il cliente: si invia dal backend.',
                'schema' => self::schema([
                    'customer_id' => $id,
                    'lines' => ['type' => 'array', 'minItems' => 1, 'maxItems' => OfficeOrderRules::MAX_LINES, 'items' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'type' => ['type' => 'string', 'enum' => OfficeOrderRules::LINE_TYPES],
                            'product_id' => $id,
                            'name' => $text,
                            'quantity' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => OfficeOrderRules::MAX_QUANTITY],
                            'unit_price' => $money,
                            'discount_type' => ['type' => 'string', 'enum' => ['none', 'amount', 'percent']],
                            'discount_value' => $money,
                            'tax_category_id' => $id,
                        ],
                        'required' => ['type'],
                        'additionalProperties' => false,
                    ]],
                    'fulfillment_type' => ['type' => 'string', 'enum' => Order::FULFILLMENT_TYPES],
                    'shipping_method_id' => $id,
                    'shipping_amount' => $money,
                    'payment_method_id' => $id,
                    'payment_term_id' => $id,
                    'manual_discount_type' => ['type' => 'string', 'enum' => ['none', 'amount', 'percent']],
                    'manual_discount_value' => $money,
                    'quote_expires_at' => ['type' => 'string', 'format' => 'date', 'description' => 'AAAA-MM-GG; vuoto = la validità predefinita.'],
                    'customer_note' => $text,
                    'internal_note' => $text,
                    'document_note' => $text,
                ], ['customer_id', 'lines']),
                'permission' => [QuoteResource::class, 'store'],
                'write' => true,
                'run' => self::quoteCreate(...),
            ],
            'quote_get' => [
                'description' => 'Legge un preventivo: numero, stato, revisione, scadenza, cliente, totali e righe.',
                'schema' => self::schema(['id' => $id], ['id']),
                'permission' => [QuoteResource::class, 'edit'],
                'write' => false,
                'run' => self::quoteGet(...),
            ],
        ];
    }

    private static function quoteCreate(array $args, Caller $caller): array
    {
        self::quotesActive();

        $customerId = self::int($args, 'customer_id');
        $contact = Contact::findById($customerId);

        if (!is_array($contact) || $contact === [] || (string) ($contact['deleted'] ?? 'false') === 'true') {
            throw McpError::make('customer_not_found', 0, ['id' => $customerId]);
        }

        $data = ['lines' => self::lines($args['lines'] ?? null)];

        foreach ([
            'customer_id', 'fulfillment_type', 'shipping_method_id', 'shipping_amount', 'payment_method_id', 'payment_term_id',
            'manual_discount_type', 'manual_discount_value', 'quote_expires_at', 'customer_note', 'internal_note', 'document_note',
        ] as $key) {
            if (array_key_exists($key, $args) && $args[$key] !== null) {
                $data[$key] = is_bool($args[$key]) ? '' : (string) $args[$key];
            }
        }

        $id = OfficeOrders::save(0, $data, (int) $caller->userId, 'quote');
        $after = self::quote(self::quoteRow($id));

        return ['result' => $after, 'subject' => $id, 'after' => $after];
    }

    private static function quoteGet(array $args): array
    {
        self::quotesActive();

        return ['result' => self::quote(self::quoteRow(self::int($args, 'id')))];
    }

    /**
     * Le righe come le posta l'editor. Si controlla qui quello che manca, e lo
     * si dice tutto insieme: l'editor scarterebbe in silenzio una riga di
     * prodotto senza prodotto.
     *
     * @return list<array<string, string>>
     */
    private static function lines(mixed $input): array
    {
        if (!is_array($input) || $input === [] || !array_is_list($input)) {
            throw McpError::make('missing_fields', 0, ['fields' => 'lines']);
        }

        $missing = [];
        $lines = [];

        foreach ($input as $index => $line) {
            if (!is_array($line)) {
                throw McpError::make('object', 0, ['field' => 'lines['.$index.']']);
            }

            $type = (string) ($line['type'] ?? '');

            if (!isset(self::LINE_REQUIRED[$type])) {
                $missing[] = 'lines['.$index.'].type';

                continue;
            }

            foreach (self::LINE_REQUIRED[$type] as $key) {
                $value = $line[$key] ?? null;

                if ($value === null || (is_string($value) && trim($value) === '')) {
                    $missing[] = 'lines['.$index.'].'.$key;
                }
            }

            if ($type === 'product' && isset($line['product_id'])) {
                self::productRow(self::int($line, 'product_id'));
            }

            // Come la scheda del backend: senza tipo di IVA vale il predefinito.
            if ($type === 'custom' && !isset($line['tax_category_id'])) {
                $line['tax_category_id'] = TaxCategories::defaultId();
            }

            $lines[] = array_map(static fn (mixed $value): string => (string) $value, array_filter(
                $line,
                static fn (mixed $value): bool => is_scalar($value) && !is_bool($value)
            ));
        }

        if ($missing !== []) {
            throw McpError::make('missing_fields', 0, ['fields' => implode(', ', $missing)]);
        }

        return $lines;
    }

    /** Senza vendite da ufficio o senza preventivi la scheda non c'è: nemmeno qui. */
    private static function quotesActive(): void
    {
        if (!QuoteResource::featureActive() || !OfficeOrderResource::featureActive()) {
            throw McpError::make('quotes_disabled');
        }
    }

    /** @return array<string, mixed> */
    private static function quoteRow(int $id): array
    {
        $row = $id > 0 ? Order::findById($id) : null;

        if (!is_array($row) || $row === [] || (string) ($row['deleted'] ?? 'false') === 'true' || (string) ($row['stage'] ?? '') !== 'quote') {
            throw McpError::make('quote_not_found', 0, ['id' => $id]);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private static function quote(array $row): array
    {
        $id = (int) $row['id'];

        return [
            'id' => $id,
            'stage' => (string) ($row['stage'] ?? ''),
            'quote_number' => (string) ($row['quote_number'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'revision' => (int) ($row['quote_revision'] ?? 0),
            'expires_at' => (string) ($row['quote_expires_at'] ?? ''),
            'customer_id' => (int) ($row['customer_id'] ?? 0),
            'email' => (string) ($row['email'] ?? ''),
            'fulfillment_type' => (string) ($row['fulfillment_type'] ?? ''),
            'products_total' => (float) ($row['products_total'] ?? 0),
            'discount_total' => (float) ($row['discount_total'] ?? 0),
            'shipping_total' => (float) ($row['shipping_total'] ?? 0),
            'tax_total' => (float) ($row['tax_total'] ?? 0),
            'total' => (float) ($row['total'] ?? 0),
            'currency' => (string) ($row['currency'] ?? 'EUR'),
            'items' => array_map(static fn (array $item): array => [
                'id' => (int) $item['id'],
                'type' => (string) ($item['type'] ?? ''),
                'product_id' => (int) ($item['product_id'] ?? 0),
                'sku' => (string) ($item['sku'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => (float) ($item['quantity'] ?? 0),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'line_total' => (float) ($item['line_total'] ?? 0),
            ], self::rows(OrderItem::find(['order_id' => $id, 'deleted' => 'false'], null, 'position', 'ASC'))),
        ];
    }
}
