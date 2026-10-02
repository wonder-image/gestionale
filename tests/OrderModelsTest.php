<?php
/** php tests/OrderModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Support\Codes;

$modelli = [Order::class, OrderItem::class, OrderTaxSummary::class];

$colonne = static function (string $model): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

$campo = static function (string $model, string $key): ?object {
    foreach ($model::dataSchema() as $field) {
        if ((string) $field->key === $key) {
            return $field;
        }
    }

    return null;
};

check('le tre tabelle dell\'ordine hanno il prefisso del gestionale', fn () =>
    Order::$table === 'gst_orders'
    && OrderItem::$table === 'gst_order_items'
    && OrderTaxSummary::$table === 'gst_order_tax_summaries'
);

check('gli ordini non viaggiano con il deploy', function () use ($modelli) {
    foreach ($modelli as $modello) {
        if ($modello::syncSchema() !== null) {
            return false;
        }
    }

    return true;
});

check('l\'ordine ha il suo prefisso', function () use ($campo) {
    return ($campo(Order::class, 'code')?->getSchema('unique_code')['prefix'] ?? null)
        === Codes::ORDER;
});

check('un enum solo tiene preventivo e ordine', fn () =>
    Order::STATUSES === [
        'draft', 'sent', 'rejected', 'expired',
        'pending', 'confirmed', 'processing', 'completed', 'cancelled',
    ]
    && Order::PAYMENT_STATUSES === [
        'unpaid', 'pending', 'partially_paid', 'paid', 'partially_refunded', 'refunded',
    ]
    && Order::FULFILLMENT_STATUSES === [
        'unfulfilled', 'ready_for_pickup', 'partially_fulfilled', 'fulfilled',
    ]
    && Order::STAGES === ['cart', 'quote', 'order']
);

check('la testata tiene tutte le colonne di 4.7', function () use ($colonne) {
    $attese = [
        'code', 'stage', 'channel', 'quote_number', 'quote_revision', 'quote_sent_at',
        'quote_expires_at', 'order_number', 'ordered_at', 'completed_at', 'cancelled_at',
        'user_id', 'status', 'payment_status', 'fulfillment_status', 'customer_id',
        'email', 'phone', 'price_list_id', 'prices_include_tax', 'fulfillment_type',
        'location_id', 'shipping_method_id', 'payment_method_id', 'payment_term_id',
        'coupon_id', 'coupon_code', 'manual_discount_type', 'manual_discount_value',
        'currency', 'products_total', 'discount_total', 'shipping_total', 'fees_total',
        'taxable_total', 'tax_total', 'total', 'total_weight', 'customer_note',
        'internal_note', 'document_note', 'cart_token', 'last_activity_at', 'custom_data',
    ];

    return array_diff($attese, array_keys($colonne(Order::class))) === [];
});

check('i due indirizzi hanno il loro prefisso e solo la fatturazione ha i dati fiscali', function () use ($colonne) {
    $c = array_keys($colonne(Order::class));

    return in_array('billing_city', $c, true)
        && in_array('billing_pi', $c, true)
        && in_array('billing_type', $c, true)
        && in_array('shipping_city', $c, true)
        && in_array('shipping_name', $c, true)
        && in_array('shipping_phone', $c, true)
        && !in_array('shipping_pi', $c, true)
        && !in_array('shipping_link', $c, true);
});

check('la riga tiene tutte le colonne di 4.7', function () use ($colonne) {
    $attese = [
        'order_id', 'type', 'product_id', 'parent_item_id', 'position',
        'sku', 'name', 'description', 'unit',
        'quantity', 'list_price', 'unit_price', 'price_source', 'discount_type',
        'discount_value', 'order_discount_amount', 'price_list_id', 'discount_campaign_id',
        'tax_category_id', 'tax_id', 'tax_rate', 'tax_nature',
        'customization', 'customization_surcharge', 'line_total',
    ];

    return array_diff($attese, array_keys($colonne(OrderItem::class))) === [];
});

check('la riga figlia ricorda l\'opzione scelta e vale zero se è un componente fisso', function () use ($colonne) {
    $colonna = $colonne(OrderItem::class)['bundle_option_id'] ?? null;

    return $colonna !== null
        && (string) $colonna->getSchema('default') === '0'
        && empty($colonna->getSchema('foreign_table'));
});

check('i tipi di riga e le sorgenti del prezzo sono quelli della spec', fn () =>
    OrderItem::TYPES === ['product', 'custom', 'text', 'shipping', 'fee']
    && OrderItem::PRICE_SOURCES === ['price_list', 'campaign', 'sale_price', 'base', 'manual']
    && OrderItem::DISCOUNT_TYPES === ['none', 'amount', 'percent']
);

check('riga e riepilogo sono legati al loro ordine', function () use ($colonne) {
    return $colonne(OrderItem::class)['order_id']->getSchema('foreign_table') === 'gst_orders'
        && $colonne(OrderTaxSummary::class)['order_id']->getSchema('foreign_table') === 'gst_orders';
});

check('le colonne che valgono zero valgono zero e non hanno chiave esterna', function () use ($colonne) {
    // Un carrello di un ospite non ha cliente, un carrello non ha ancora sede
    // né metodo di pagamento, una riga di spedizione non ha prodotto: MySQL
    // rifiuterebbe lo zero. Listino, coupon, spedizione e condizione puntano a
    // tabelle che nasceranno con G6, G7 e G9.
    foreach ([
        [Order::class, 'customer_id'], [Order::class, 'location_id'],
        [Order::class, 'payment_method_id'], [Order::class, 'price_list_id'],
        [Order::class, 'coupon_id'], [Order::class, 'shipping_method_id'],
        [Order::class, 'payment_term_id'], [Order::class, 'user_id'],
        [OrderItem::class, 'product_id'], [OrderItem::class, 'parent_item_id'],
        [OrderItem::class, 'tax_id'], [OrderItem::class, 'tax_category_id'],
        [OrderItem::class, 'price_list_id'], [OrderItem::class, 'discount_campaign_id'],
    ] as [$modello, $nome]) {
        $colonna = $colonne($modello)[$nome] ?? null;

        // Senza predefinito la colonna nasce NULL, e in MySQL NULL non è zero:
        // `where('customer_id', 0)` non troverebbe più i carrelli degli ospiti.
        if ($colonna?->getSchema('foreign_table') !== null
            || (string) $colonna?->getSchema('default') !== '0') {
            return false;
        }
    }

    return true;
});

check('la posizione della riga parte da zero, non da NULL', function () use ($colonne) {
    // Il servizio che aggiunge una riga fa MAX(position) + 1: su NULL resta
    // NULL, e le righe escono in fattura in ordine sparso.
    return (string) ($colonne(OrderItem::class)['position'] ?? null)?->getSchema('default') === '0';
});

check('quantità e peso tengono tre decimali, il denaro due', function () use ($colonne, $campo) {
    $riga = $colonne(OrderItem::class);
    $testata = $colonne(Order::class);

    return $riga['quantity']->getSchema('length') === '10,3'
        && $testata['total_weight']->getSchema('length') === '10,3'
        && (int) ($campo(OrderItem::class, 'quantity')?->getSchema('decimals') ?? 0) === 3
        && (int) ($campo(OrderItem::class, 'line_total')?->getSchema('decimals') ?? 0) === 2
        && (int) ($campo(Order::class, 'total')?->getSchema('decimals') ?? 0) === 2;
});

check('il riepilogo IVA ha aliquota, natura, imponibile e imposta', function () use ($colonne) {
    $attese = ['order_id', 'rate', 'nature', 'taxable', 'tax', 'total'];

    return array_diff($attese, array_keys($colonne(OrderTaxSummary::class))) === [];
});

check('l\'ordine si ritrova per numero, carrello, cliente e stato', function () {
    $pseudo = Order::tablePseudos();

    return isset(
        $pseudo['ind_order_number'], $pseudo['ind_cart_token'],
        $pseudo['ind_customer'], $pseudo['ind_status'], $pseudo['ind_last_activity']
    );
});

check('nessuna colonna usa una parola riservata di MySQL', function () use ($modelli, $colonne) {
    $riservate = ['key', 'group', 'order', 'index', 'default', 'status_'];

    foreach ($modelli as $modello) {
        foreach (array_keys($colonne($modello)) as $nome) {
            if (in_array(strtolower((string) $nome), $riservate, true)) {
                return false;
            }
        }
    }

    return true;
});

summary();
