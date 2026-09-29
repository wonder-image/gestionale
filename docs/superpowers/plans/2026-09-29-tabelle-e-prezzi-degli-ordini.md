# Tabelle e prezzi degli ordini — Piano 1 di 5 di G4

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** dare a G4 le sue undici tabelle — ordini, righe, riepiloghi IVA,
pagamenti, metodi, conti, resi e i tre log degli stati — e le due classi pure
dei prezzi (`LinePrice`, `OrderTotals`) su cui tutti i piani successivi
contano.

**Architecture:** i Model dichiarano le tabelle e basta, come nel magazzino:
nessuna logica, nessuna scrittura. I conti stanno in due classi pure di
`Support\Pricing\`: `LinePrice` decide il prezzo di **una riga** (quale prezzo
vince, lo sconto della riga, il sovrapprezzo) e `OrderTotals` porta le righe ai
**totali dell'ordine**, ripartendo lo sconto sul totale e passando le righe a
`Tax\TaxTotals` per i riepiloghi IVA. Nessuna delle due tocca il database: si
provano con gli array, e i servizi dei piani 2 e 3 si limiteranno a
chiamarle.

**Tech Stack:** PHP 8.2, `wonder-image/app` ^2.4.0-beta.1 (`Model`,
`TableSchema`, `UploadSchema`, `AddressExtension`, `FormField`), harness di
test del modulo (`tests/harness.php`), MySQL del sito di prova
`/Users/andreamarinoni/Developer/boilerplates/ecommerce-site`.

**Spec:** [G4 — Ordini e pagamenti](../specs/2026-09-29-ordini-e-pagamenti-design.md)

## Global Constraints

- Lingua: **italiano** nei commenti, nei testi e nei nomi dei test; **inglese**
  per classi, tabelle e colonne.
- Prefisso delle tabelle: **`gst_`** (lo controlla `tests/ConventionsTest.php`).
- **Documenti e storia non si sincronizzano:** `syncSchema()` torna **`null`**
  per ordini, righe, riepiloghi, pagamenti, resi e log. **Configurazione sì:**
  `gst_payment_methods` e `gst_payment_accounts` tornano
  `SyncSchema::multiRow()->keepIds()->localOnly()`, come `gst_taxes`.
- `code` con prefisso da `Support\Codes`, generato con
  `Field::key('code')->text()->uniqueCode(Codes::ORDER)` — qui `Codes::ORDER`
  (`ord_`), `Codes::PAYMENT` (`pay_`), `Codes::SALES_RETURN` (`ret_`) — le
  tre costanti **esistono già** in `src/Support/Codes.php`, non si riaggiungono. Le
  tabelle di configurazione hanno invece il **codice parlante**
  (`Field::key('code')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate()`),
  come `Tax`.
- **Chiave esterna solo dove la colonna è sempre valorizzata.** MySQL rifiuta
  uno zero che punta a niente, e in G4 valgono zero molte colonne: un carrello
  di un ospite non ha `customer_id`, un carrello non ha ancora `location_id` né
  `payment_method_id`, una riga `shipping` non ha `product_id`, un pagamento di
  un abbonamento non ha `order_id`, e `price_list_id`, `coupon_id`,
  `shipping_method_id`, `payment_term_id`, `subscription_id` puntano a tabelle
  che ancora non esistono. Tutte queste sono `Column::key('x')->int()` (o
  `->default(0)`), **senza** `foreign()`. La chiave esterna resta su
  `order_items.order_id`, `order_tax_summaries.order_id`,
  `sales_returns.order_id`, `sales_returns.location_id`,
  `sales_return_items.sales_return_id`, `sales_return_items.order_item_id` e
  sulla colonna dell'entità dei tre log.
- Ogni tabella nasce già con `id`, `deleted`, `creation` e `last_modified` dal
  core: non si dichiarano.
- **Decimali.** Il core genera `DECIMAL(10,2)` per qualsiasi campo numerico:
  va bene per il denaro (`unit_price`, `line_total`, i totali, `amount`,
  `tax_rate`), che quindi passa da `sqlColumnsFromDataSchema()`. Le quantità e
  i pesi hanno bisogno di tre decimali e si dichiarano a mano con
  `Columns::decimal($nome)` (predefinito `10,3`): `order_items.quantity`,
  `orders.total_weight`, `sales_return_items.quantity`.
- Le quantità si scrivono sempre come stringa canonica
  (`number_format($v, 3, '.', '')`); il denaro con
  `number_format($v, 2, '.', '')`.
- Le classi pure di `Support\Pricing\` **non leggono il database, non chiamano
  `Model::find()`, non usano `date()`**: prendono array e tornano array.
- Un rifiuto che deve leggere una persona è **`UserError::make('chiave')`**,
  con il testo in `lang/it/gestionale.json`. In questo piano non ce ne sono:
  le classi pure normalizzano gli input invece di rifiutarli (un carrello non
  deve andare in pagina 500 perché arriva una quantità negativa).
- Ogni task finisce con un commit sul ramo `feature/ordini-tabelle-e-prezzi`
  di `packages/gestionale`.
- Test: `php tests/run.php` deve restare verde.

## Review Focus

- **Quantità zero, negativa o non numerica in `LinePrice`:** il totale di riga
  è `0.00`, mai negativo. Test nel Task 5.
- **Sconto della riga più grande del prezzo** (importo oltre il prezzo,
  percentuale oltre 100): il prezzo unitario si ferma a `0.00`, non diventa
  negativo. Test nel Task 5.
- **`sale_price` vuota, zero o non inferiore al prezzo base:** vince il prezzo
  base e `price_source` resta `base` — un prezzo scontato più alto non è uno
  sconto. Test nel Task 5.
- **Sconto sul totale maggiore dell'imponibile scontabile, o nessuna riga
  scontabile:** lo sconto si ferma alla base e con base zero non si riparte
  niente (nessuna divisione per zero). Test nel Task 6.
- **Il resto dell'ultimo centesimo:** la somma degli `order_discount_amount`
  deve fare **esattamente** lo sconto, anche su tre righe da 33,33 €. Test nel
  Task 6.

---

## File Structure

**Da creare**

| File | Responsabilità |
|---|---|
| `src/Models/Sales/Order.php` | tabella `gst_orders`: testata di carrello, preventivo e ordine |
| `src/Models/Sales/OrderItem.php` | tabella `gst_order_items`: le righe |
| `src/Models/Sales/OrderTaxSummary.php` | tabella `gst_order_tax_summaries`: imponibile e imposta per aliquota |
| `src/Models/Sales/SalesReturn.php` | tabella `gst_sales_returns`: testata del reso |
| `src/Models/Sales/SalesReturnItem.php` | tabella `gst_sales_return_items`: righe del reso |
| `src/Models/Sales/OrderStatusLog.php` | tabella `gst_order_status_logs` |
| `src/Models/Sales/SalesReturnStatusLog.php` | tabella `gst_sales_return_status_logs` |
| `src/Models/Payments/Payment.php` | tabella `gst_payments`: ogni movimento di denaro |
| `src/Models/Payments/PaymentMethod.php` | tabella `gst_payment_methods` (configurazione) |
| `src/Models/Payments/PaymentAccount.php` | tabella `gst_payment_accounts` (configurazione) |
| `src/Models/Payments/PaymentStatusLog.php` | tabella `gst_payment_status_logs` |
| `src/Support/Pricing/LinePrice.php` | **pura** — il prezzo di una riga |
| `src/Support/Pricing/OrderTotals.php` | **pura** — dalle righe ai totali dell'ordine |
| `tests/OrderModelsTest.php` | le tre tabelle dell'ordine |
| `tests/PaymentModelsTest.php` | le quattro tabelle dei pagamenti |
| `tests/SalesReturnModelsTest.php` | resi e log degli stati |
| `tests/LinePriceTest.php` | il prezzo di una riga |
| `tests/OrderTotalsTest.php` | i totali dell'ordine |

**Da modificare**

| File | Modifica |
|---|---|
| `src/Models/System/Setting.php` | due colonne: `order_reservation_minutes`, `order_payment_wait_days` |
| `src/Resources/System/SettingResource.php` | riquadro *Vendite* con i due campi |
| `tests/SettingsTest.php` | le due impostazioni nuove |

---

## Task 1: Le tabelle dell'ordine

**Files:**
- Create: `src/Models/Sales/Order.php`
- Create: `src/Models/Sales/OrderItem.php`
- Create: `src/Models/Sales/OrderTaxSummary.php`
- Test: `tests/OrderModelsTest.php`

**Interfaces:**
- Consumes: `Wonder\Plugin\Gestionale\Support\Codes::ORDER` (`ord_`),
  `Support\Columns::decimal(string $name, string $length = '10,3'): Column`,
  `Models\Catalog\Product::$table`, `Models\Contacts\Contact::$table`,
  `Models\Locations\Location::$table`, `Models\Tax\Tax::$table`,
  `Models\Tax\TaxCategory::$table`.
- Produces: `Models\Sales\Order` con `$table = 'gst_orders'` e le costanti
  `Order::STAGES`, `Order::CHANNELS`, `Order::STATUSES`,
  `Order::PAYMENT_STATUSES`, `Order::FULFILLMENT_STATUSES`,
  `Order::FULFILLMENT_TYPES`, `Order::DISCOUNT_TYPES`, più
  `Order::billingAddress(): AddressExtension` e
  `Order::shippingAddress(): AddressExtension`;
  `Models\Sales\OrderItem` con `$table = 'gst_order_items'` e
  `OrderItem::TYPES` (`product`, `custom`, `text`, `shipping`, `fee`),
  `OrderItem::PRICE_SOURCES` (`price_list`, `campaign`, `sale_price`, `base`,
  `manual`), `OrderItem::DISCOUNT_TYPES` (`none`, `amount`, `percent`);
  `Models\Sales\OrderTaxSummary` con `$table = 'gst_order_tax_summaries'`.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/OrderModelsTest.php`:

```php
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

check('i tipi di riga e le sorgenti del prezzo sono quelli della spec', fn () =>
    OrderItem::TYPES === ['product', 'custom', 'text', 'shipping', 'fee']
    && OrderItem::PRICE_SOURCES === ['price_list', 'campaign', 'sale_price', 'base', 'manual']
    && OrderItem::DISCOUNT_TYPES === ['none', 'amount', 'percent']
);

check('riga e riepilogo sono legati al loro ordine', function () use ($colonne) {
    return $colonne(OrderItem::class)['order_id']->getSchema('foreign_table') === 'gst_orders'
        && $colonne(OrderTaxSummary::class)['order_id']->getSchema('foreign_table') === 'gst_orders';
});

check('le colonne che valgono zero non hanno chiave esterna', function () use ($colonne) {
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
        if (($colonne($modello)[$nome] ?? null)?->getSchema('foreign_table') !== null) {
            return false;
        }
    }

    return true;
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
```

- [ ] **Step 2: Lancia il test e guardalo fallire**

Run: `php tests/OrderModelsTest.php`
Atteso: errore fatale, `Class "Wonder\Plugin\Gestionale\Models\Sales\Order" not found`.

- [ ] **Step 3: Scrivi `src/Models/Sales/Order.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\App\Model;
use Wonder\App\Schema\Extensions\AddressExtension;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * La testata di 4.7: carrello, preventivo e ordine nella stessa tabella,
 * distinti da `stage`.
 *
 * Gli stati stanno su tre assi — `status` (dove sta il documento),
 * `payment_status` (quanto è stato pagato), `fulfillment_status` (quanto è
 * partito) — invece dell'elenco unico che li mescolava. `status` ha un enum
 * solo con i valori del preventivo e dell'ordine: un preventivo accettato
 * diventa un ordine senza cambiare riga.
 *
 * Nessuna colonna che può valere zero ha una chiave esterna: un carrello di un
 * ospite non ha cliente e non ha ancora scelto sede né metodo di pagamento, e
 * listino, coupon, spedizione e condizione di pagamento puntano a tabelle che
 * nasceranno con G6, G7 e G9.
 *
 * I totali qui sono una copia per elenchi e statistiche: la verità la
 * ricalcolano sempre `Support\Pricing\LinePrice` e `Support\Pricing\OrderTotals`.
 */
final class Order extends Model
{
    public static string $table = 'gst_orders';
    public static string $folder = 'gestionale/sales';
    public static string $icon = 'bi bi-receipt';

    public const STAGES = ['cart', 'quote', 'order'];
    public const CHANNELS = ['office', 'online', 'b2b_portal', 'pos'];

    /** Preventivo e ordine in un enum solo (4.7). */
    public const STATUSES = [
        'draft', 'sent', 'rejected', 'expired',
        'pending', 'confirmed', 'processing', 'completed', 'cancelled',
    ];

    public const PAYMENT_STATUSES = [
        'unpaid', 'pending', 'partially_paid', 'paid', 'partially_refunded', 'refunded',
    ];

    public const FULFILLMENT_STATUSES = [
        'unfulfilled', 'ready_for_pickup', 'partially_fulfilled', 'fulfilled',
    ];

    public const FULFILLMENT_TYPES = ['shipping', 'pickup', 'none'];
    public const DISCOUNT_TYPES = ['none', 'amount', 'percent'];

    /** La fatturazione: gli stessi campi della scheda cliente, senza link. */
    public static function billingAddress(): AddressExtension
    {
        return AddressExtension::billing('billing_', countryDefault: 'IT')->withLink(false);
    }

    /** La consegna: destinatario e telefono, nessun dato fiscale o aziendale. */
    public static function shippingAddress(): AddressExtension
    {
        return AddressExtension::simple('shipping_', countryDefault: 'IT')
            ->withLink(false)
            ->withContactName()
            ->withPhone();
    }

    /** Gli ordini sono la storia di questo ambiente, non configurazione. */
    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema([
                'code', 'manual_discount_value',
                'products_total', 'discount_total', 'shipping_total', 'fees_total',
                'taxable_total', 'tax_total', 'total',
            ]),
            // Documento
            Column::key('stage')->enum(static::STAGES)->default('order'),
            Column::key('channel')->enum(static::CHANNELS)->default('online'),
            Column::key('quote_number')->length(20),
            Column::key('quote_revision')->int(),
            Column::key('quote_sent_at')->datetime(),
            Column::key('quote_expires_at')->datetime(),
            Column::key('order_number')->length(20),
            Column::key('ordered_at')->datetime(),
            Column::key('completed_at')->datetime(),
            Column::key('cancelled_at')->datetime(),
            Column::key('user_id')->int(),
            // Stati
            Column::key('status')->enum(static::STATUSES)->default('pending'),
            Column::key('payment_status')->enum(static::PAYMENT_STATUSES)->default('unpaid'),
            Column::key('fulfillment_status')->enum(static::FULFILLMENT_STATUSES)->default('unfulfilled'),
            // Cliente
            Column::key('customer_id')->int(),
            Column::key('email')->length(150),
            Column::key('phone')->length(50),
            ...static::billingAddress()->tableSchema(),
            ...static::shippingAddress()->tableSchema(),
            Column::key('price_list_id')->int()->default(0),
            Column::key('prices_include_tax')->enum(['true', 'false'])->default('true'),
            // Consegna
            Column::key('fulfillment_type')->enum(static::FULFILLMENT_TYPES)->default('shipping'),
            Column::key('location_id')->int(),
            Column::key('shipping_method_id')->int()->default(0),
            // Pagamento
            Column::key('payment_method_id')->int(),
            Column::key('payment_term_id')->int()->default(0),
            // Sconti sul totale
            Column::key('coupon_id')->int()->default(0),
            Column::key('coupon_code')->length(50),
            Column::key('manual_discount_type')->enum(static::DISCOUNT_TYPES)->default('none'),
            // Totali
            Column::key('currency')->length(3)->default('EUR'),
            // Tre decimali dichiarati a mano: il core ne darebbe due (vedi
            // `Support\Columns`) e mezzo etto cambia lo scaglione di spedizione.
            Columns::decimal('total_weight'),
            // Note
            Column::key('customer_note')->type('TEXT'),
            Column::key('internal_note')->type('TEXT'),
            Column::key('document_note')->type('TEXT'),
            // Carrello
            Column::key('cart_token')->length(64),
            Column::key('last_activity_at')->datetime(),
            // Sito
            Column::key('custom_data')->json(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_order_number' => ['index' => 'order_number'],
            'ind_cart_token' => ['index' => 'cart_token'],
            'ind_customer' => ['index' => 'customer_id'],
            'ind_status' => ['index' => 'status'],
            'ind_last_activity' => ['index' => 'last_activity_at'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::ORDER),
            Field::key('stage')->text()->sanitize(false),
            Field::key('channel')->text()->sanitize(false),
            Field::key('quote_number')->text()->sanitize(false),
            Field::key('quote_revision')->number()->decimals(0),
            Field::key('quote_sent_at')->date(),
            Field::key('quote_expires_at')->date(),
            Field::key('order_number')->text()->sanitize(false),
            Field::key('ordered_at')->date(),
            Field::key('completed_at')->date(),
            Field::key('cancelled_at')->date(),
            Field::key('user_id')->number()->decimals(0),
            Field::key('status')->text()->sanitize(false),
            Field::key('payment_status')->text()->sanitize(false),
            Field::key('fulfillment_status')->text()->sanitize(false),
            Field::key('customer_id')->number()->decimals(0),
            Field::key('email')->email(),
            Field::key('phone')->text()->sanitize(false),
            ...static::billingAddress()->dataSchema(),
            ...static::shippingAddress()->dataSchema(),
            Field::key('price_list_id')->number()->decimals(0),
            Field::key('prices_include_tax')->text()->sanitize(false),
            Field::key('fulfillment_type')->text()->sanitize(false),
            Field::key('location_id')->number()->decimals(0),
            Field::key('shipping_method_id')->number()->decimals(0),
            Field::key('payment_method_id')->number()->decimals(0),
            Field::key('payment_term_id')->number()->decimals(0),
            Field::key('coupon_id')->number()->decimals(0),
            Field::key('coupon_code')->text()->sanitize(false),
            Field::key('manual_discount_type')->text()->sanitize(false),
            Field::key('manual_discount_value')->number()->decimals(2),
            Field::key('currency')->text()->sanitize(false),
            Field::key('products_total')->number()->decimals(2),
            Field::key('discount_total')->number()->decimals(2),
            Field::key('shipping_total')->number()->decimals(2),
            Field::key('fees_total')->number()->decimals(2),
            Field::key('taxable_total')->number()->decimals(2),
            Field::key('tax_total')->number()->decimals(2),
            Field::key('total')->number()->decimals(2),
            Field::key('total_weight')->number()->decimals(3),
            Field::key('customer_note')->text(),
            Field::key('internal_note')->text(),
            Field::key('document_note')->text(),
            Field::key('cart_token')->text()->sanitize(false),
            Field::key('last_activity_at')->date(),
            Field::key('custom_data')->json(),
        ];
    }
}
```

- [ ] **Step 4: Scrivi `src/Models/Sales/OrderItem.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Le righe dell'ordine (4.7).
 *
 * Nome, SKU, descrizione e unità sono **copiati** al momento dell'aggiunta: un
 * ordine di marzo deve continuare a dire cosa è stato venduto anche se il
 * prodotto oggi si chiama diversamente o non esiste più. Per la stessa ragione
 * `product_id` è un intero semplice, senza chiave esterna: le righe `text`,
 * `shipping` e `fee` non hanno prodotto, e un prodotto venduto non si elimina
 * comunque — lo impedisce già il controllo dei movimenti di magazzino.
 *
 * Componenti e opzioni di un prodotto composto sono righe figlie
 * (`parent_item_id`) a prezzo zero: servono allo scarico del magazzino e al DDT.
 */
final class OrderItem extends Model
{
    public static string $table = 'gst_order_items';
    public static string $folder = 'gestionale/sales';
    public static string $icon = 'bi bi-list-ul';

    public const TYPES = ['product', 'custom', 'text', 'shipping', 'fee'];
    public const PRICE_SOURCES = ['price_list', 'campaign', 'sale_price', 'base', 'manual'];
    public const DISCOUNT_TYPES = ['none', 'amount', 'percent'];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('order_id')->int()->null(false)->foreign(Order::$table),
            ...static::sqlColumnsFromDataSchema([
                'list_price', 'unit_price', 'discount_value', 'order_discount_amount',
                'tax_rate', 'customization_surcharge', 'line_total',
            ]),
            Column::key('type')->enum(static::TYPES)->default('product'),
            Column::key('product_id')->int()->default(0),
            Column::key('parent_item_id')->int()->default(0),
            Column::key('position')->int(),
            // Copia del prodotto
            Column::key('sku')->length(100),
            Column::key('name'),
            Column::key('description')->type('TEXT'),
            Column::key('unit')->length(10),
            // Prezzo
            Columns::decimal('quantity'),
            Column::key('price_source')->enum(static::PRICE_SOURCES)->default('base'),
            Column::key('discount_type')->enum(static::DISCOUNT_TYPES)->default('none'),
            Column::key('price_list_id')->int()->default(0),
            Column::key('discount_campaign_id')->int()->default(0),
            // IVA
            Column::key('tax_category_id')->int()->default(0),
            Column::key('tax_id')->int()->default(0),
            Column::key('tax_nature')->length(10),
            // Personalizzazione (G5): campo, etichetta, valore, file, sovrapprezzo
            Column::key('customization')->json(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_order' => ['index' => 'order_id'],
            'ind_product' => ['index' => 'product_id'],
            'ind_parent' => ['index' => 'parent_item_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('order_id')->number()->decimals(0),
            Field::key('type')->text()->sanitize(false),
            Field::key('product_id')->number()->decimals(0),
            Field::key('parent_item_id')->number()->decimals(0),
            Field::key('position')->number()->decimals(0),
            Field::key('sku')->text(),
            // Niente `sanitizeFirst()`: "XL" deve restare "XL".
            Field::key('name')->text(),
            Field::key('description')->text(),
            Field::key('unit')->text()->sanitize(false),
            Field::key('quantity')->number()->decimals(3),
            Field::key('list_price')->number()->decimals(2),
            Field::key('unit_price')->number()->decimals(2),
            Field::key('price_source')->text()->sanitize(false),
            Field::key('discount_type')->text()->sanitize(false),
            Field::key('discount_value')->number()->decimals(2),
            Field::key('order_discount_amount')->number()->decimals(2),
            Field::key('price_list_id')->number()->decimals(0),
            Field::key('discount_campaign_id')->number()->decimals(0),
            Field::key('tax_category_id')->number()->decimals(0),
            Field::key('tax_id')->number()->decimals(0),
            Field::key('tax_rate')->number()->decimals(2),
            Field::key('tax_nature')->text()->sanitize(false),
            Field::key('customization')->json(),
            Field::key('customization_surcharge')->number()->decimals(2),
            Field::key('line_total')->number()->decimals(2),
        ];
    }
}
```

- [ ] **Step 5: Scrivi `src/Models/Sales/OrderTaxSummary.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * I riepiloghi IVA dell'ordine: una riga per aliquota e natura, come li
 * produce `Support\Tax\TaxTotals` (4.5).
 *
 * Si salvano perché l'imposta si calcola sul totale imponibile di ogni
 * aliquota, non riga per riga: è la regola dei riepiloghi FatturaPA, e sono
 * questi numeri che finiranno in fattura senza essere ricalcolati.
 */
final class OrderTaxSummary extends Model
{
    public static string $table = 'gst_order_tax_summaries';
    public static string $folder = 'gestionale/sales';
    public static string $icon = 'bi bi-percent';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('order_id')->int()->null(false)->foreign(Order::$table),
            ...static::sqlColumnsFromDataSchema(['rate', 'taxable', 'tax', 'total']),
            Column::key('nature')->length(10),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_order' => ['index' => 'order_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('order_id')->number()->decimals(0),
            Field::key('rate')->number()->decimals(2),
            Field::key('nature')->text()->sanitize(false),
            Field::key('taxable')->number()->decimals(2),
            Field::key('tax')->number()->decimals(2),
            Field::key('total')->number()->decimals(2),
        ];
    }
}
```

- [ ] **Step 6: Lancia il test e guardalo passare**

Run: `php tests/OrderModelsTest.php`
Atteso: tutti i check verdi.

- [ ] **Step 7: Commit**

```bash
git add src/Models/Sales tests/OrderModelsTest.php && git commit -m "Aggiunge le tabelle dell'ordine"
```

---

## Task 2: Le tabelle dei pagamenti

**Files:**
- Create: `src/Models/Payments/Payment.php`
- Create: `src/Models/Payments/PaymentMethod.php`
- Create: `src/Models/Payments/PaymentAccount.php`
- Test: `tests/PaymentModelsTest.php`

**Interfaces:**
- Consumes: `Support\Codes::PAYMENT` (`pay_`), `Wonder\App\Support\SyncSchema`.
- Produces: `Models\Payments\Payment` con `$table = 'gst_payments'` e le
  costanti `Payment::TYPES` (`payment`, `refund`), `Payment::STATUSES`
  (`pending`, `paid`, `failed`, `cancelled`), `Payment::PROVIDERS` (`stripe`,
  `paypal`, `nexi`, `manual`); `Models\Payments\PaymentMethod` con
  `$table = 'gst_payment_methods'` e `PaymentMethod::FEE_TYPES` (`none`,
  `amount`, `percent`), `PaymentMethod::AVAILABLE_FOR` (`all`, `shipping`,
  `pickup`); `Models\Payments\PaymentAccount` con
  `$table = 'gst_payment_accounts'`.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/PaymentModelsTest.php`:

```php
<?php
/** php tests/PaymentModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentAccount;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Support\Codes;

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

check('le tre tabelle dei pagamenti hanno il prefisso del gestionale', fn () =>
    Payment::$table === 'gst_payments'
    && PaymentMethod::$table === 'gst_payment_methods'
    && PaymentAccount::$table === 'gst_payment_accounts'
);

check('i pagamenti restano nel loro ambiente, metodi e conti viaggiano', function () {
    $metodi = PaymentMethod::syncSchema();
    $conti = PaymentAccount::syncSchema();

    return Payment::syncSchema() === null
        && $metodi !== null && $metodi->keepIds && $metodi->localOnly
        && $conti !== null && $conti->keepIds && $conti->localOnly;
});

check('il pagamento ha il suo prefisso, il metodo il codice parlante', function () use ($campo, $colonne) {
    return ($campo(Payment::class, 'code')?->getSchema('unique_code')['prefix'] ?? null) === Codes::PAYMENT
        && ($campo(PaymentMethod::class, 'code')?->getSchema('unique_code') ?? null) === null
        && $colonne(PaymentMethod::class)['code']->getSchema('unique') === true;
});

check('il pagamento tiene tutte le colonne di 4.8', function () use ($colonne) {
    $attese = [
        'code', 'type', 'order_id', 'subscription_id', 'customer_id',
        'payment_method_id', 'payment_account_id', 'amount', 'currency',
        'status', 'provider', 'provider_reference', 'due_date', 'paid_at', 'note',
    ];

    return array_diff($attese, array_keys($colonne(Payment::class))) === [];
});

check('gli enum del pagamento sono quelli della spec', fn () =>
    Payment::TYPES === ['payment', 'refund']
    && Payment::STATUSES === ['pending', 'paid', 'failed', 'cancelled']
    && Payment::PROVIDERS === ['stripe', 'paypal', 'nexi', 'manual']
);

check('la notifica doppia del gateway non passa due volte', function () {
    $unico = Payment::tablePseudos()['uni_provider_reference']['unique'] ?? [];

    return $unico === ['provider', 'provider_reference'];
});

check('il metodo di pagamento tiene tutte le colonne di 4.8', function () use ($colonne) {
    $attese = [
        'code', 'name', 'sdi_code', 'provider', 'payment_account_id',
        'fee_type', 'fee_value', 'available_for', 'applies_online', 'applies_office',
        'applies_pos', 'instructions', 'stripe_payment_method_types', 'active', 'position',
    ];

    return array_diff($attese, array_keys($colonne(PaymentMethod::class))) === [];
});

check('il conto tiene banca e IBAN', function () use ($colonne) {
    $attese = ['code', 'name', 'bank_name', 'iban', 'bic', 'active'];

    return array_diff($attese, array_keys($colonne(PaymentAccount::class))) === [];
});

check('le colonne che valgono zero non hanno chiave esterna', function () use ($colonne) {
    // Un pagamento di un abbonamento non ha ordine, un pagamento al banco non
    // ha cliente, e un metodo può non avere un conto: sempre zero.
    foreach ([
        [Payment::class, 'order_id'], [Payment::class, 'subscription_id'],
        [Payment::class, 'customer_id'], [Payment::class, 'payment_method_id'],
        [Payment::class, 'payment_account_id'],
        [PaymentMethod::class, 'payment_account_id'],
    ] as [$modello, $nome]) {
        if (($colonne($modello)[$nome] ?? null)?->getSchema('foreign_table') !== null) {
            return false;
        }
    }

    return true;
});

check('gli importi tengono due decimali', function () use ($campo) {
    return (int) ($campo(Payment::class, 'amount')?->getSchema('decimals') ?? 0) === 2
        && (int) ($campo(PaymentMethod::class, 'fee_value')?->getSchema('decimals') ?? 0) === 2;
});

check('il pagamento si ritrova per ordine e per scadenza', function () {
    $pseudo = Payment::tablePseudos();

    return isset($pseudo['ind_order'], $pseudo['ind_due_date'], $pseudo['ind_status']);
});

summary();
```

- [ ] **Step 2: Lancia il test e guardalo fallire**

Run: `php tests/PaymentModelsTest.php`
Atteso: errore fatale, `Class "Wonder\Plugin\Gestionale\Models\Payments\Payment" not found`.

- [ ] **Step 3: Scrivi `src/Models/Payments/PaymentAccount.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Payments;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Il conto su cui arrivano i soldi: banca e IBAN da stampare su preventivi,
 * fatture e fattura elettronica (4.8).
 *
 * Configurazione di `admin`, con il codice parlante (`banca-principale`) e il
 * deploy che la porta in produzione, come le aliquote.
 */
final class PaymentAccount extends Model
{
    public static string $table = 'gst_payment_accounts';
    public static string $folder = 'gestionale/payments';
    public static string $icon = 'bi bi-bank';

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('code')->length(100)->null(false)->unique(),
            Column::key('name'),
            Column::key('bank_name'),
            Column::key('iban')->length(34),
            Column::key('bic')->length(11),
            Column::key('active')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('bank_name')->text()->sanitizeFirst(),
            Field::key('iban')->text()->sanitize(false),
            Field::key('bic')->text()->sanitize(false),
            Field::key('active')->text()->sanitize(false),
        ];
    }
}
```

- [ ] **Step 4: Scrivi `src/Models/Payments/PaymentMethod.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Payments;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Come si paga: bonifico, carta, contrassegno, pagamento al ritiro (4.8).
 *
 * `fee_type` e `fee_value` generano la riga `fee` dell'ordine, con l'aliquota
 * di spedizione e commissioni; per il contrassegno il `cod_fee` del listino di
 * spedizione prevarrà su questo, ma arriva con G7.
 *
 * `available_for` limita il metodo alla consegna scelta: il contrassegno solo
 * con spedizione, il pagamento al ritiro solo con ritiro. `sdi_code` è il
 * codice della fattura elettronica (MP01–MP23).
 *
 * Configurazione di `admin` con il codice parlante (`bank-transfer`), portata
 * in produzione dal deploy.
 */
final class PaymentMethod extends Model
{
    public static string $table = 'gst_payment_methods';
    public static string $folder = 'gestionale/payments';
    public static string $icon = 'bi bi-credit-card';

    public const FEE_TYPES = ['none', 'amount', 'percent'];
    public const AVAILABLE_FOR = ['all', 'shipping', 'pickup'];
    public const PROVIDERS = ['manual', 'stripe', 'paypal', 'nexi'];

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('code')->length(100)->null(false)->unique(),
            ...static::sqlColumnsFromDataSchema(['fee_value']),
            Column::key('name'),
            Column::key('sdi_code')->length(10),
            Column::key('provider')->enum(static::PROVIDERS)->default('manual'),
            // Zero vuol dire "nessun conto": niente chiave esterna.
            Column::key('payment_account_id')->int()->default(0),
            Column::key('fee_type')->enum(static::FEE_TYPES)->default('none'),
            Column::key('available_for')->enum(static::AVAILABLE_FOR)->default('all'),
            Column::key('applies_online')->enum(['true', 'false'])->default('true'),
            Column::key('applies_office')->enum(['true', 'false'])->default('true'),
            Column::key('applies_pos')->enum(['true', 'false'])->default('false'),
            Column::key('instructions')->type('TEXT'),
            Column::key('stripe_payment_method_types')->length(255),
            Column::key('active')->enum(['true', 'false'])->default('true'),
            Column::key('position')->int(),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('sdi_code')->text()->sanitize(false),
            Field::key('provider')->text()->sanitize(false),
            Field::key('payment_account_id')->number()->decimals(0),
            Field::key('fee_type')->text()->sanitize(false),
            Field::key('fee_value')->number()->decimals(2),
            Field::key('available_for')->text()->sanitize(false),
            Field::key('applies_online')->text()->sanitize(false),
            Field::key('applies_office')->text()->sanitize(false),
            Field::key('applies_pos')->text()->sanitize(false),
            Field::key('instructions')->text(),
            Field::key('stripe_payment_method_types')->text()->sanitize(false),
            Field::key('active')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
```

- [ ] **Step 5: Scrivi `src/Models/Payments/Payment.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Payments;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Ogni movimento di denaro è una riga, rimborsi compresi (4.8): il pagamento
 * del checkout, il bonifico registrato a mano, i contanti al banco, le rate.
 *
 * Il `payment_status` dell'ordine non si scrive mai a mano: si ricalcola dalla
 * somma delle righe riuscite, ed è `Support\Payments\Ledger` (piano 2) l'unico
 * che lo tocca.
 *
 * `provider` e `provider_reference` hanno un indice unico insieme: è la difesa
 * contro la notifica doppia del gateway, che arriva anche due volte per lo
 * stesso incasso. `order_id`, `subscription_id` e `customer_id` sono interi
 * semplici: un pagamento di un abbonamento non ha ordine e uno al banco può
 * non avere cliente.
 */
final class Payment extends Model
{
    public static string $table = 'gst_payments';
    public static string $folder = 'gestionale/payments';
    public static string $icon = 'bi bi-cash-coin';

    public const TYPES = ['payment', 'refund'];
    public const STATUSES = ['pending', 'paid', 'failed', 'cancelled'];
    public const PROVIDERS = ['stripe', 'paypal', 'nexi', 'manual'];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code', 'amount']),
            Column::key('type')->enum(static::TYPES)->default('payment'),
            Column::key('order_id')->int()->default(0),
            Column::key('subscription_id')->int()->default(0),
            Column::key('customer_id')->int()->default(0),
            Column::key('payment_method_id')->int()->default(0),
            Column::key('payment_account_id')->int()->default(0),
            Column::key('currency')->length(3)->default('EUR'),
            Column::key('status')->enum(static::STATUSES)->default('pending'),
            Column::key('provider')->enum(static::PROVIDERS)->default('manual'),
            Column::key('provider_reference')->length(191),
            Column::key('due_date')->date(),
            Column::key('paid_at')->datetime(),
            Column::key('note')->type('TEXT'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            // La stessa notifica del gateway non entra due volte.
            'uni_provider_reference' => ['unique' => ['provider', 'provider_reference']],
            'ind_order' => ['index' => 'order_id'],
            'ind_due_date' => ['index' => 'due_date'],
            'ind_status' => ['index' => 'status'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::PAYMENT),
            Field::key('type')->text()->sanitize(false),
            Field::key('order_id')->number()->decimals(0),
            Field::key('subscription_id')->number()->decimals(0),
            Field::key('customer_id')->number()->decimals(0),
            Field::key('payment_method_id')->number()->decimals(0),
            Field::key('payment_account_id')->number()->decimals(0),
            Field::key('amount')->number()->decimals(2),
            Field::key('currency')->text()->sanitize(false),
            Field::key('status')->text()->sanitize(false),
            Field::key('provider')->text()->sanitize(false),
            Field::key('provider_reference')->text()->sanitize(false),
            Field::key('due_date')->date(),
            Field::key('paid_at')->date(),
            Field::key('note')->text(),
        ];
    }
}
```

- [ ] **Step 6: Lancia il test e guardalo passare**

Run: `php tests/PaymentModelsTest.php`
Atteso: tutti i check verdi.

Se `Column::key('due_date')->date()` non esiste nel core installato, usa
`->datetime()` e cambia il test di conseguenza: in G4 la scadenza si confronta
sempre con un giorno intero.

- [ ] **Step 7: Commit**

```bash
git add src/Models/Payments tests/PaymentModelsTest.php && git commit -m "Aggiunge le tabelle dei pagamenti"
```

---

## Task 3: I resi e i tre log degli stati

**Files:**
- Create: `src/Models/Sales/SalesReturn.php`
- Create: `src/Models/Sales/SalesReturnItem.php`
- Create: `src/Models/Sales/OrderStatusLog.php`
- Create: `src/Models/Sales/SalesReturnStatusLog.php`
- Create: `src/Models/Payments/PaymentStatusLog.php`
- Test: `tests/SalesReturnModelsTest.php`

**Interfaces:**
- Consumes: `Support\Codes::SALES_RETURN` (`ret_`),
  `Models\System\StatusLog` (base astratta: `entityColumn(): string`,
  `entityTable(): string`, `commonColumns()`, `commonFields()`, e
  `tableSchema()`/`tablePseudos()`/`dataSchema()` già pronti),
  `Models\Sales\Order::$table`, `Models\Sales\OrderItem::$table`,
  `Models\Payments\Payment::$table`, `Models\Locations\Location::$table`.
- Produces: `Models\Sales\SalesReturn` con `$table = 'gst_sales_returns'` e
  `SalesReturn::STATUSES` (`requested`, `approved`, `rejected`, `received`,
  `completed`, `cancelled`), `SalesReturn::CHANNELS` (`online`, `office`,
  `pos`); `Models\Sales\SalesReturnItem` con
  `$table = 'gst_sales_return_items'` e `SalesReturnItem::REASONS`;
  le tre sottoclassi di `StatusLog`.

Questi sono i **primi** log degli stati del gestionale: `StatusLog` esiste da
G1 con la base astratta, ma nessuna sottoclasse è mai stata scritta. Una
sottoclasse dichiara tre cose e basta — `$table`, `entityColumn()`,
`entityTable()` — e non ripete nessuna colonna.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/SalesReturnModelsTest.php`:

```php
<?php
/** php tests/SalesReturnModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Payments\PaymentStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnItem;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnStatusLog;
use Wonder\Plugin\Gestionale\Models\System\StatusLog;
use Wonder\Plugin\Gestionale\Support\Codes;

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

check('le tabelle dei resi e i tre log hanno il prefisso del gestionale', fn () =>
    SalesReturn::$table === 'gst_sales_returns'
    && SalesReturnItem::$table === 'gst_sales_return_items'
    && OrderStatusLog::$table === 'gst_order_status_logs'
    && PaymentStatusLog::$table === 'gst_payment_status_logs'
    && SalesReturnStatusLog::$table === 'gst_sales_return_status_logs'
);

check('resi e log non viaggiano con il deploy', fn () =>
    SalesReturn::syncSchema() === null
    && SalesReturnItem::syncSchema() === null
    && OrderStatusLog::syncSchema() === null
    && PaymentStatusLog::syncSchema() === null
    && SalesReturnStatusLog::syncSchema() === null
);

check('il reso ha il suo prefisso', function () use ($campo) {
    return ($campo(SalesReturn::class, 'code')?->getSchema('unique_code')['prefix'] ?? null)
        === Codes::SALES_RETURN;
});

check('la testata del reso tiene tutte le colonne di 4.10', function () use ($colonne) {
    $attese = [
        'code', 'number', 'order_id', 'customer_id', 'channel', 'status',
        'location_id', 'requested_at', 'approved_at', 'received_at', 'completed_at',
        'customer_note', 'internal_note', 'user_id',
    ];

    return array_diff($attese, array_keys($colonne(SalesReturn::class))) === [];
});

check('gli stati del reso nascono completi, anche quelli del reso online', fn () =>
    SalesReturn::STATUSES === [
        'requested', 'approved', 'rejected', 'received', 'completed', 'cancelled',
    ]
    && SalesReturn::CHANNELS === ['online', 'office', 'pos']
);

check('la riga del reso tiene quantità, motivo, rientro e nota', function () use ($colonne) {
    $attese = ['sales_return_id', 'order_item_id', 'quantity', 'reason', 'restock', 'note'];

    return array_diff($attese, array_keys($colonne(SalesReturnItem::class))) === [];
});

check('i motivi del reso sono i sette di 4.10', fn () =>
    SalesReturnItem::REASONS === [
        'damaged', 'defective', 'wrong_item', 'not_as_described',
        'changed_mind', 'wrong_size', 'other',
    ]
);

check('la quantità resa tiene tre decimali', function () use ($colonne, $campo) {
    return $colonne(SalesReturnItem::class)['quantity']->getSchema('length') === '10,3'
        && (int) ($campo(SalesReturnItem::class, 'quantity')?->getSchema('decimals') ?? 0) === 3;
});

check('il reso è legato all\'ordine e alla sede che riceve la merce', function () use ($colonne) {
    $c = $colonne(SalesReturn::class);

    return $c['order_id']->getSchema('foreign_table') === 'gst_orders'
        && $c['order_id']->getSchema('null') === false
        && $c['location_id']->getSchema('foreign_table') === 'gst_locations'
        && $c['location_id']->getSchema('null') === false;
});

check('la riga del reso è legata al reso e alla riga venduta', function () use ($colonne) {
    $c = $colonne(SalesReturnItem::class);

    return $c['sales_return_id']->getSchema('foreign_table') === 'gst_sales_returns'
        && $c['order_item_id']->getSchema('foreign_table') === 'gst_order_items';
});

check('un reso da ufficio non ha cliente: la colonna resta senza chiave esterna', function () use ($colonne) {
    return $colonne(SalesReturn::class)['customer_id']->getSchema('foreign_table') === null
        && $colonne(SalesReturn::class)['user_id']->getSchema('foreign_table') === null;
});

check('i tre log puntano al loro documento e portano le colonne comuni', function () use ($colonne) {
    foreach ([
        [OrderStatusLog::class, 'order_id', 'gst_orders'],
        [PaymentStatusLog::class, 'payment_id', 'gst_payments'],
        [SalesReturnStatusLog::class, 'sales_return_id', 'gst_sales_returns'],
    ] as [$modello, $colonna, $tabella]) {
        $c = $colonne($modello);

        if ($modello::entityColumn() !== $colonna
            || $modello::entityTable() !== $tabella
            || ($c[$colonna] ?? null)?->getSchema('foreign_table') !== $tabella
            || !isset($c['field'], $c['from_value'], $c['to_value'], $c['source'], $c['response'])) {
            return false;
        }
    }

    return true;
});

check('i log non riscrivono le colonne comuni: le prendono dalla base', function () {
    $comuni = array_map(
        static fn (object $column): string => (string) $column->name,
        StatusLog::commonColumns()
    );
    $log = array_map(
        static fn (object $column): string => (string) $column->name,
        OrderStatusLog::tableSchema()
    );

    // La colonna del documento più le comuni, niente altro.
    return count($log) === count($comuni) + 1 && $log[0] === 'order_id';
});

summary();
```

- [ ] **Step 2: Lancia il test e guardalo fallire**

Run: `php tests/SalesReturnModelsTest.php`
Atteso: errore fatale, `Class "Wonder\Plugin\Gestionale\Models\Sales\SalesReturn" not found`.

- [ ] **Step 3: Scrivi `src/Models/Sales/SalesReturn.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * La testata del reso (4.10).
 *
 * L'enum degli stati nasce completo: in G4 il commerciante registra un reso
 * già arrivato e parte da `received`, ma `requested`, `approved` e `rejected`
 * esistono da subito perché li accenderà il reso online dall'area cliente,
 * senza toccare una tabella piena.
 *
 * `location_id` è la sede che **riceve** la merce, ed è obbligatoria: senza
 * sapere dove rientra la merce non si può scrivere il movimento `return`.
 * `customer_id` invece può valere zero — un reso registrato al banco non ha per
 * forza un cliente in anagrafica — e resta senza chiave esterna.
 */
final class SalesReturn extends Model
{
    public static string $table = 'gst_sales_returns';
    public static string $folder = 'gestionale/sales';
    public static string $icon = 'bi bi-arrow-return-left';

    public const CHANNELS = ['online', 'office', 'pos'];

    public const STATUSES = [
        'requested', 'approved', 'rejected', 'received', 'completed', 'cancelled',
    ];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('number')->length(20),
            Column::key('order_id')->int()->null(false)->foreign(Order::$table),
            Column::key('customer_id')->int()->default(0),
            Column::key('channel')->enum(static::CHANNELS)->default('office'),
            Column::key('status')->enum(static::STATUSES)->default('received'),
            // Dove rientra la merce: serve al movimento `return`, sempre valorizzata.
            Column::key('location_id')->int()->null(false)->foreign(Location::$table),
            Column::key('requested_at')->datetime(),
            Column::key('approved_at')->datetime(),
            Column::key('received_at')->datetime(),
            Column::key('completed_at')->datetime(),
            Column::key('customer_note')->type('TEXT'),
            Column::key('internal_note')->type('TEXT'),
            Column::key('user_id')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_order' => ['index' => 'order_id'],
            'ind_status' => ['index' => 'status'],
            'ind_number' => ['index' => 'number'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::SALES_RETURN),
            Field::key('number')->text()->sanitize(false),
            Field::key('order_id')->number()->decimals(0),
            Field::key('customer_id')->number()->decimals(0),
            Field::key('channel')->text()->sanitize(false),
            Field::key('status')->text()->sanitize(false),
            Field::key('location_id')->number()->decimals(0),
            Field::key('requested_at')->date(),
            Field::key('approved_at')->date(),
            Field::key('received_at')->date(),
            Field::key('completed_at')->date(),
            Field::key('customer_note')->text(),
            Field::key('internal_note')->text(),
            Field::key('user_id')->number()->decimals(0),
        ];
    }
}
```

- [ ] **Step 4: Scrivi `src/Models/Sales/SalesReturnItem.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Le righe del reso (4.10).
 *
 * Ogni riga punta alla riga venduta, non al prodotto: è da lì che arrivano
 * prezzo, aliquota e — quando ci sarà G3 — lotto e fornitore con cui la merce
 * era uscita, perché deve rientrare sugli stessi.
 *
 * `restock` decide se la merce torna in magazzino: il servizio dei resi lo
 * propone spento per `damaged` e `defective`, ma resta una scelta di chi
 * controlla la merce.
 */
final class SalesReturnItem extends Model
{
    public static string $table = 'gst_sales_return_items';
    public static string $folder = 'gestionale/sales';
    public static string $icon = 'bi bi-box-arrow-in-left';

    public const REASONS = [
        'damaged', 'defective', 'wrong_item', 'not_as_described',
        'changed_mind', 'wrong_size', 'other',
    ];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('sales_return_id')->int()->null(false)->foreign(SalesReturn::$table),
            Column::key('order_item_id')->int()->null(false)->foreign(OrderItem::$table),
            Columns::decimal('quantity'),
            Column::key('reason')->enum(static::REASONS)->default('other'),
            Column::key('restock')->enum(['true', 'false'])->default('true'),
            Column::key('note')->type('TEXT'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_return' => ['index' => 'sales_return_id'],
            'ind_order_item' => ['index' => 'order_item_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('sales_return_id')->number()->decimals(0),
            Field::key('order_item_id')->number()->decimals(0),
            Field::key('quantity')->number()->decimals(3),
            Field::key('reason')->text()->sanitize(false),
            Field::key('restock')->text()->sanitize(false),
            Field::key('note')->text(),
        ];
    }
}
```

- [ ] **Step 5: Scrivi i tre log**

`src/Models/Sales/OrderStatusLog.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\Plugin\Gestionale\Models\System\StatusLog;

/**
 * La storia degli stati di un ordine (4.1): chi ha cambiato cosa, quando e da
 * dove. Colonne, indice e campi arrivano tutti dalla base.
 */
final class OrderStatusLog extends StatusLog
{
    public static string $table = 'gst_order_status_logs';
    public static string $folder = 'gestionale/sales';

    public static function entityColumn(): string
    {
        return 'order_id';
    }

    public static function entityTable(): string
    {
        return Order::$table;
    }
}
```

`src/Models/Sales/SalesReturnStatusLog.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\Plugin\Gestionale\Models\System\StatusLog;

/** La storia degli stati di un reso (4.1, 4.10). */
final class SalesReturnStatusLog extends StatusLog
{
    public static string $table = 'gst_sales_return_status_logs';
    public static string $folder = 'gestionale/sales';

    public static function entityColumn(): string
    {
        return 'sales_return_id';
    }

    public static function entityTable(): string
    {
        return SalesReturn::$table;
    }
}
```

`src/Models/Payments/PaymentStatusLog.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Payments;

use Wonder\Plugin\Gestionale\Models\System\StatusLog;

/**
 * La storia degli stati di un pagamento (4.1).
 *
 * Qui finisce anche la risposta del gateway: `response` è la colonna JSON
 * della base, ed è quella che si guarda quando un incasso non torna.
 */
final class PaymentStatusLog extends StatusLog
{
    public static string $table = 'gst_payment_status_logs';
    public static string $folder = 'gestionale/payments';

    public static function entityColumn(): string
    {
        return 'payment_id';
    }

    public static function entityTable(): string
    {
        return Payment::$table;
    }
}
```

- [ ] **Step 6: Lancia il test e guardalo passare**

Run: `php tests/SalesReturnModelsTest.php`
Atteso: tutti i check verdi.

- [ ] **Step 7: Crea le tabelle davvero, sul sito di prova**

Gli undici Model nuovi non sono mai passati da MySQL: i test leggono gli
schemi, non li creano. Le chiavi esterne, gli enum e i decimali si vedono solo
qui, e l'ordine di creazione conta — `gst_order_items` non nasce se
`gst_orders` non c'è.

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update --local
```

Atteso: nessun errore. Poi controlla che le undici tabelle ci siano davvero e
che le colonne critiche siano del tipo giusto:

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php -r '$c=require "config/database.php"; $p=new PDO("mysql:host={$c["host"]};dbname={$c["database"]}",$c["username"],$c["password"]); foreach(["gst_orders","gst_order_items","gst_order_tax_summaries","gst_payments","gst_payment_methods","gst_payment_accounts","gst_sales_returns","gst_sales_return_items","gst_order_status_logs","gst_payment_status_logs","gst_sales_return_status_logs"] as $t){ $n=$p->query("SHOW TABLES LIKE ".$p->quote($t))->fetchColumn(); echo ($n?"ok  ":"MANCA "), $t, "\n"; } foreach([["gst_order_items","quantity"],["gst_orders","total_weight"],["gst_sales_return_items","quantity"]] as [$t,$col]){ $r=$p->query("SHOW COLUMNS FROM `$t` LIKE ".$p->quote($col))->fetch(PDO::FETCH_ASSOC); echo $t,".",$col," = ",$r["Type"] ?? "MANCA","\n"; }'
```

Atteso: undici `ok`, e le tre colonne `decimal(10,3)`. Se il percorso della
configurazione del sito di prova è diverso, leggi le credenziali da dove le
tiene quel boilerplate e rifai il controllo: quello che conta è vedere le
tabelle e i tre decimali.

- [ ] **Step 8: Commit**

```bash
git add src/Models tests/SalesReturnModelsTest.php && git commit -m "Aggiunge le tabelle dei resi e i log degli stati"
```

---

## Task 4: Le due impostazioni della vendita

**Files:**
- Modify: `src/Models/System/Setting.php`
- Modify: `src/Resources/System/SettingResource.php`
- Test: `tests/SettingsTest.php` (esistente: aggiungi i check in fondo, prima
  di `$forza(null);` e `summary();`)

**Interfaces:**
- Consumes: `Models\System\Setting::current(): array`,
  `Resources\System\SettingResource::formSchema()`,
  `Resources\System\SettingResource::formLayoutSchema()`.
- Produces: due colonne su `gst_settings` — `order_reservation_minutes`
  (predefinito `30`) e `order_payment_wait_days` (predefinito `7`) — e i due
  campi corrispondenti nel form delle *Impostazioni*.

I piani 2 e 3 le leggono così:
`(int) (Setting::current()['order_reservation_minutes'] ?? 30)` e
`(int) (Setting::current()['order_payment_wait_days'] ?? 7)`.

- [ ] **Step 1: Scrivi i check che falliscono**

In fondo a `tests/SettingsTest.php`, **prima** di `$forza(null);`:

```php
check('le due impostazioni della vendita ci sono, con i predefiniti di 5.2', function () {
    $colonne = [];

    foreach (Setting::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return isset($colonne['order_reservation_minutes'], $colonne['order_payment_wait_days'])
        && (string) $colonne['order_reservation_minutes']->getSchema('default') === '30'
        && (string) $colonne['order_payment_wait_days']->getSchema('default') === '7';
});

check('le due impostazioni si possono cambiare dal form', function () {
    $campi = array_map(static fn ($field): string => (string) $field->name, SettingResource::formSchema());

    return in_array('order_reservation_minutes', $campi, true)
        && in_array('order_payment_wait_days', $campi, true);
});

check('le due impostazioni hanno un\'etichetta in italiano', function () {
    $etichette = SettingResource::labelSchema();

    return trim((string) ($etichette['order_reservation_minutes'] ?? '')) !== ''
        && trim((string) ($etichette['order_payment_wait_days'] ?? '')) !== '';
});
```

- [ ] **Step 2: Lancia il test e guardalo fallire**

Run: `php tests/SettingsTest.php`
Atteso: tre `✗` in fondo, gli altri check verdi.

- [ ] **Step 3: Aggiungi le due colonne a `Setting`**

In `tableSchema()`, dopo `Column::key('stamp_duty_auto')...`:

```php
            // Vendita (5.2): quanto resta impegnata la merce di un ordine non
            // ancora pagato e quanti giorni si aspetta il bonifico.
            Column::key('order_reservation_minutes')->int()->default(30),
            Column::key('order_payment_wait_days')->int()->default(7),
```

In `dataSchema()`, dopo `Field::key('stamp_duty_auto')...`:

```php
            Field::key('order_reservation_minutes')->number()->decimals(0),
            Field::key('order_payment_wait_days')->number()->decimals(0),
```

- [ ] **Step 4: Aggiungi i due campi al form**

In `SettingResource::labelSchema()`, in fondo all'array:

```php
            'order_reservation_minutes' => 'Minuti di prenotazione',
            'order_payment_wait_days' => 'Giorni di attesa del pagamento',
```

In `SettingResource::formSchema()`, in fondo all'array:

```php
            FormField::key('order_reservation_minutes')->number()->decimal(0)->value(30)->label('Minuti di prenotazione')->required(),
            FormField::key('order_payment_wait_days')->number()->decimal(0)->value(7)->label('Giorni di attesa del pagamento')->required(),
```

In `formLayoutSchema()`, un riquadro nuovo tra *Documenti* ed *Errori*. I due
campi stanno vicini perché rispondono alla stessa domanda — quanto si aspetta
un ordine non pagato — e la spiegazione sta nel tooltip del titolo, non sotto i
campi:

```php
                (new Card)->components([
                    SectionTitle::make('Vendite')
                        ->tooltip('Quanto resta impegnata la merce di un ordine non ancora pagato, e per quanti giorni si aspetta il bonifico prima di annullare.')
                        ->columnSpan(12),
                    static::getInput('order_reservation_minutes')->columnSpan(6),
                    static::getInput('order_payment_wait_days')->columnSpan(6),
                ])->columns(12)->columnSpan(12),
```

- [ ] **Step 5: Lancia i test e guardali passare**

Run: `php tests/SettingsTest.php`
Atteso: tutti i check verdi.

- [ ] **Step 6: Aggiorna la tabella e guarda la pagina**

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update --local
```

Poi apri `app/gestionale/impostazioni`: il riquadro *Vendite* deve mostrare i
due campi con 30 e 7, e il salvataggio non deve toccare gli altri riquadri.

- [ ] **Step 7: Commit**

```bash
git add src/Models/System/Setting.php src/Resources/System/SettingResource.php tests/SettingsTest.php && git commit -m "Aggiunge minuti di prenotazione e giorni di attesa del pagamento"
```

---

## Task 5: Il prezzo di una riga

**Files:**
- Create: `src/Support/Pricing/LinePrice.php`
- Test: `tests/LinePriceTest.php`

**Interfaces:**
- Consumes: niente. È una classe pura: nessun database, nessun `Model::find()`,
  nessun `date()`.
- Produces: `Support\Pricing\LinePrice::of(array $input): array`.

  **Entra** (tutte le chiavi sono facoltative):

  | Chiave | Significato |
  |---|---|
  | `quantity` | quantità, anche con tre decimali (predefinito 1) |
  | `price` | prezzo base del prodotto |
  | `sale_price` | prezzo scontato del prodotto (0 o vuoto = nessuno) |
  | `price_list_price` | prezzo del listino (G6; in G4 arriva sempre 0) |
  | `campaign_price` | prezzo della campagna (G6; in G4 arriva sempre 0) |
  | `manual_unit_price` | prezzo scritto a mano (`''` = nessuno) |
  | `discount_type` | `none`, `amount`, `percent` |
  | `discount_value` | valore dello sconto di riga |
  | `customization_surcharge` | sovrapprezzo unitario della personalizzazione (G5; in G4 sempre 0) |

  **Esce** un array di **stringhe canoniche**, pronte per le colonne di
  `gst_order_items`: `list_price`, `unit_price`, `price_source`,
  `discount_type`, `discount_value`, `customization_surcharge`, `quantity`,
  `line_total`. Stringhe e non float perché è così che vanno scritte in
  tabella, e perché due stringhe si confrontano esattamente: un test su
  `'10.50'` non ha il problema di `0.1 + 0.2`.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/LinePriceTest.php`:

```php
<?php
/** php tests/LinePriceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Pricing\LinePrice;

check('senza altro vince il prezzo base', function () {
    $riga = LinePrice::of(['price' => 10, 'quantity' => 2]);

    return $riga['price_source'] === 'base'
        && $riga['list_price'] === '10.00'
        && $riga['unit_price'] === '10.00'
        && $riga['line_total'] === '20.00';
});

check('il prezzo scontato del prodotto batte il base', function () {
    $riga = LinePrice::of(['price' => 10, 'sale_price' => 7.5, 'quantity' => 2]);

    return $riga['price_source'] === 'sale_price'
        && $riga['list_price'] === '10.00'
        && $riga['unit_price'] === '7.50'
        && $riga['line_total'] === '15.00';
});

check('un prezzo scontato più alto del base non è uno sconto', function () {
    // Succede quando il listino sale e nessuno svuota la colonna.
    $riga = LinePrice::of(['price' => 10, 'sale_price' => 12]);

    return $riga['price_source'] === 'base' && $riga['unit_price'] === '10.00';
});

check('un prezzo scontato uguale al base non vince', fn () =>
    LinePrice::of(['price' => 10, 'sale_price' => 10])['price_source'] === 'base'
);

check('un prezzo scontato vuoto o a zero non vince', fn () =>
    LinePrice::of(['price' => 10, 'sale_price' => ''])['price_source'] === 'base'
    && LinePrice::of(['price' => 10, 'sale_price' => 0])['price_source'] === 'base'
);

check('il listino batte tutto il resto', function () {
    $riga = LinePrice::of(['price' => 10, 'sale_price' => 7.5, 'price_list_price' => 6]);

    return $riga['price_source'] === 'price_list' && $riga['unit_price'] === '6.00';
});

check('la campagna batte il prezzo scontato ma non il listino', function () {
    $conCampagna = LinePrice::of(['price' => 10, 'sale_price' => 7.5, 'campaign_price' => 7]);
    $conListino = LinePrice::of(['price' => 10, 'campaign_price' => 7, 'price_list_price' => 6]);

    return $conCampagna['price_source'] === 'campaign'
        && $conCampagna['unit_price'] === '7.00'
        && $conListino['price_source'] === 'price_list';
});

check('un prezzo scritto a mano vince su tutti', function () {
    $riga = LinePrice::of(['price' => 10, 'price_list_price' => 6, 'manual_unit_price' => '4.20']);

    return $riga['price_source'] === 'manual' && $riga['unit_price'] === '4.20';
});

check('un prezzo a mano a zero è un prezzo, non un campo vuoto', function () {
    // Un omaggio si scrive così: zero euro, scelto da una persona.
    $riga = LinePrice::of(['price' => 10, 'manual_unit_price' => '0']);

    return $riga['price_source'] === 'manual' && $riga['unit_price'] === '0.00';
});

check('lo sconto a importo si toglie dal prezzo unitario', function () {
    $riga = LinePrice::of([
        'price' => 10, 'quantity' => 3,
        'discount_type' => 'amount', 'discount_value' => 2,
    ]);

    return $riga['unit_price'] === '8.00'
        && $riga['line_total'] === '24.00'
        && $riga['discount_type'] === 'amount'
        && $riga['discount_value'] === '2.00';
});

check('lo sconto a percentuale si calcola sul prezzo che ha vinto', function () {
    $riga = LinePrice::of([
        'price' => 10, 'sale_price' => 8,
        'discount_type' => 'percent', 'discount_value' => 25,
    ]);

    return $riga['unit_price'] === '6.00';
});

check('uno sconto di riga fa diventare manuale la sorgente', fn () =>
    LinePrice::of([
        'price' => 10, 'sale_price' => 8,
        'discount_type' => 'percent', 'discount_value' => 25,
    ])['price_source'] === 'manual'
);

check('uno sconto più grande del prezzo si ferma a zero', function () {
    // Mai un prezzo negativo: un ordine con un totale sotto zero è un rimborso
    // che nessuno ha chiesto.
    $importo = LinePrice::of([
        'price' => 10, 'quantity' => 2,
        'discount_type' => 'amount', 'discount_value' => 30,
    ]);
    $percentuale = LinePrice::of([
        'price' => 10,
        'discount_type' => 'percent', 'discount_value' => 150,
    ]);

    return $importo['unit_price'] === '0.00'
        && $importo['line_total'] === '0.00'
        && $percentuale['unit_price'] === '0.00';
});

check('uno sconto a zero o negativo non è uno sconto', function () {
    $zero = LinePrice::of(['price' => 10, 'discount_type' => 'amount', 'discount_value' => 0]);
    $negativo = LinePrice::of(['price' => 10, 'discount_type' => 'amount', 'discount_value' => -5]);

    return $zero['discount_type'] === 'none'
        && $zero['unit_price'] === '10.00'
        && $zero['price_source'] === 'base'
        && $negativo['discount_type'] === 'none'
        && $negativo['unit_price'] === '10.00';
});

check('uno sconto di tipo sconosciuto non tocca il prezzo', fn () =>
    LinePrice::of([
        'price' => 10, 'discount_type' => 'marziano', 'discount_value' => 5,
    ])['unit_price'] === '10.00'
);

check('quantità zero, negativa o scritta male fa una riga da zero', function () {
    // Un carrello non deve andare in pagina 500 perché arriva una quantità
    // storta: la riga vale zero e il commerciante la vede.
    foreach ([0, -3, '', 'due'] as $quantità) {
        $riga = LinePrice::of(['price' => 10, 'quantity' => $quantità]);

        if ($riga['line_total'] !== '0.00' || $riga['quantity'] !== '0.000') {
            return false;
        }
    }

    return true;
});

check('senza quantità se ne vende uno', fn () =>
    LinePrice::of(['price' => 10])['line_total'] === '10.00'
);

check('la quantità tiene tre decimali', function () {
    $riga = LinePrice::of(['price' => 10, 'quantity' => 0.75]);

    return $riga['quantity'] === '0.750' && $riga['line_total'] === '7.50';
});

check('il sovrapprezzo della personalizzazione si somma al prezzo unitario', function () {
    $riga = LinePrice::of([
        'price' => 10, 'quantity' => 2, 'customization_surcharge' => 1.5,
    ]);

    return $riga['unit_price'] === '11.50'
        && $riga['customization_surcharge'] === '1.50'
        && $riga['line_total'] === '23.00';
});

check('il sovrapprezzo si somma dopo lo sconto, non prima', function () {
    $riga = LinePrice::of([
        'price' => 10, 'customization_surcharge' => 2,
        'discount_type' => 'percent', 'discount_value' => 50,
    ]);

    // 50% di 10 fa 5, più 2 di personalizzazione: 7,00 e non 6,00.
    return $riga['unit_price'] === '7.00';
});

check('un prezzo negativo si legge come zero', fn () =>
    LinePrice::of(['price' => -10])['unit_price'] === '0.00'
);

check('i centesimi si arrotondano una volta sola', function () {
    // 3 × 3,335 farebbe 10,005: la riga costa 10,01, non 10,00 né 10,02.
    $riga = LinePrice::of(['price' => 3.335, 'quantity' => 3]);

    return $riga['unit_price'] === '3.34' && $riga['line_total'] === '10.02';
});

summary();
```

- [ ] **Step 2: Lancia il test e guardalo fallire**

Run: `php tests/LinePriceTest.php`
Atteso: errore fatale, `Class "Wonder\Plugin\Gestionale\Support\Pricing\LinePrice" not found`.

- [ ] **Step 3: Scrivi `src/Support/Pricing/LinePrice.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Pricing;

/**
 * Il prezzo di **una** riga: quale prezzo vince, lo sconto della riga, il
 * sovrapprezzo della personalizzazione (§1 di G4, §4.5 e §4.6).
 *
 * La priorità è **listino → campagna → prezzo scontato → prezzo base**, e la
 * sorgente che ha vinto resta scritta in `price_source`: senza quella colonna,
 * sei mesi dopo, nessuno sa più perché quella riga costava così. Listino e
 * campagna arrivano con G6: in G4 la classe li riceve vuoti e li salta.
 *
 * Classe pura: niente database, niente `date()`, niente eccezioni. Le quantità
 * storte e gli sconti impossibili si normalizzano invece di essere rifiutati,
 * perché questa classe gira anche sul carrello di un cliente e un carrello non
 * deve andare in pagina 500 per una quantità negativa. I rifiuti che una
 * persona deve leggere li fa il servizio che chiama, con `UserError`.
 *
 * Restituisce **stringhe canoniche**, pronte per le colonne di
 * `gst_order_items`: due decimali per il denaro, tre per la quantità.
 */
final class LinePrice
{
    public const SOURCES = ['price_list', 'campaign', 'sale_price', 'base', 'manual'];
    public const DISCOUNT_TYPES = ['none', 'amount', 'percent'];

    /**
     * @param array<string, mixed> $input
     * @return array{list_price: string, unit_price: string, price_source: string, discount_type: string, discount_value: string, customization_surcharge: string, quantity: string, line_total: string}
     */
    public static function of(array $input): array
    {
        $quantity = static::quantity($input['quantity'] ?? 1);
        $listPrice = static::money($input['price'] ?? 0);

        [$source, $gross] = static::source($input, $listPrice);

        $discountType = in_array((string) ($input['discount_type'] ?? 'none'), static::DISCOUNT_TYPES, true)
            ? (string) $input['discount_type']
            : 'none';
        $discountValue = static::money($input['discount_value'] ?? 0);

        if ($discountValue <= 0.0) {
            $discountType = 'none';
            $discountValue = 0.0;
        }

        $discount = match ($discountType) {
            // Uno sconto più grande del prezzo si ferma al prezzo: un prezzo
            // negativo è un rimborso che nessuno ha chiesto.
            'amount' => min($discountValue, $gross),
            'percent' => round($gross * min($discountValue, 100.0) / 100, 2),
            default => 0.0,
        };

        if ($discount > 0.0) {
            // Lo sconto scritto a mano vince su qualsiasi prezzo automatico.
            $source = 'manual';
        }

        $surcharge = static::money($input['customization_surcharge'] ?? 0);
        // Il sovrapprezzo si somma **dopo** lo sconto: non si sconta una
        // personalizzazione che il cliente ha chiesto in più.
        $unitPrice = round(max(0.0, round($gross - $discount, 2)) + $surcharge, 2);

        return [
            'list_price' => static::asMoney($listPrice),
            'unit_price' => static::asMoney($unitPrice),
            'price_source' => $source,
            'discount_type' => $discountType,
            'discount_value' => static::asMoney($discountValue),
            'customization_surcharge' => static::asMoney($surcharge),
            'quantity' => number_format($quantity, 3, '.', ''),
            'line_total' => static::asMoney(round($unitPrice * $quantity, 2)),
        ];
    }

    /**
     * Il prezzo che vince e da dove viene.
     *
     * @param array<string, mixed> $input
     * @return array{0: string, 1: float}
     */
    private static function source(array $input, float $listPrice): array
    {
        $manual = trim((string) ($input['manual_unit_price'] ?? ''));

        // Un prezzo a mano a zero è una scelta — l'omaggio — non un campo vuoto.
        if ($manual !== '' && is_numeric($manual)) {
            return ['manual', static::money($manual)];
        }

        $priceList = static::money($input['price_list_price'] ?? 0);

        if ($priceList > 0.0) {
            return ['price_list', $priceList];
        }

        $campaign = static::money($input['campaign_price'] ?? 0);

        if ($campaign > 0.0) {
            return ['campaign', $campaign];
        }

        $sale = static::money($input['sale_price'] ?? 0);

        // Un prezzo scontato che non è più basso del base non è uno sconto:
        // succede quando il listino sale e nessuno svuota la colonna.
        if ($sale > 0.0 && $sale < $listPrice) {
            return ['sale_price', $sale];
        }

        return ['base', $listPrice];
    }

    /** Denaro: mai sotto zero, sempre due decimali. */
    private static function money(mixed $value): float
    {
        return round(max(0.0, (float) $value), 2);
    }

    /** Quantità: mai sotto zero, sempre tre decimali. */
    private static function quantity(mixed $value): float
    {
        return round(max(0.0, (float) $value), 3);
    }

    private static function asMoney(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
```

- [ ] **Step 4: Lancia il test e guardalo passare**

Run: `php tests/LinePriceTest.php`
Atteso: tutti i check verdi.

- [ ] **Step 5: Commit**

```bash
git add src/Support/Pricing/LinePrice.php tests/LinePriceTest.php && git commit -m "Aggiunge il calcolo del prezzo di una riga"
```

---

## Task 6: I totali dell'ordine

**Files:**
- Create: `src/Support/Pricing/OrderTotals.php`
- Test: `tests/OrderTotalsTest.php`

**Interfaces:**
- Consumes: `Support\Tax\TaxTotals::summaries(array $lines, bool $pricesIncludeTax): array`,
  che restituisce
  `list<array{rate: float, nature: string, taxable: float, tax: float, total: float}>`
  ordinata per aliquota decrescente.
- Produces: `Support\Pricing\OrderTotals::of(array $lines, array $context = []): array`.

  **Entra** un elenco di righe, ognuna come la produce `LinePrice` più il
  contesto della riga:

  | Chiave della riga | Significato |
  |---|---|
  | `type` | `product`, `custom`, `text`, `shipping`, `fee` (predefinito `product`) |
  | `quantity`, `line_total` | dalla riga, già calcolati da `LinePrice` |
  | `tax_rate`, `tax_nature` | l'aliquota già risolta da `Tax\TaxResolver` |
  | `weight` | peso unitario del prodotto (predefinito 0) |
  | `discountable` | facoltativo: forza se la riga entra nello sconto sul totale |

  **Contesto:** `prices_include_tax` (predefinito `true`), `discount_type`
  (`none`, `amount`, `percent`), `discount_value` — il coupon o lo sconto
  scritto a mano sulla testata.

  **Esce:** `products_total`, `discount_total`, `shipping_total`, `fees_total`,
  `taxable_total`, `tax_total`, `total`, `total_weight` (stringhe canoniche,
  pronte per `gst_orders`), `tax_summaries` (le righe di
  `gst_order_tax_summaries`, come le dà `TaxTotals`) e `lines` — le righe di
  partenza con `order_discount_amount` scritto dentro, pronte per
  `gst_order_items`.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/OrderTotalsTest.php`:

```php
<?php
/** php tests/OrderTotalsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Pricing\OrderTotals;

/** Una riga di prodotto, con quello che serve ai totali. */
$riga = static function (float $totale, float $aliquota = 22, array $extra = []): array {
    return $extra + [
        'type' => 'product',
        'quantity' => '1.000',
        'line_total' => number_format($totale, 2, '.', ''),
        'tax_rate' => $aliquota,
        'tax_nature' => '',
    ];
};

check('con i prezzi IVA inclusa il cliente paga la cifra esposta', function () use ($riga) {
    $totali = OrderTotals::of([$riga(100)], ['prices_include_tax' => true]);

    return $totali['products_total'] === '100.00'
        && $totali['taxable_total'] === '81.97'
        && $totali['tax_total'] === '18.03'
        && $totali['total'] === '100.00';
});

check('con i prezzi IVA esclusa l\'imposta si aggiunge', function () use ($riga) {
    $totali = OrderTotals::of([$riga(100)], ['prices_include_tax' => false]);

    return $totali['taxable_total'] === '100.00'
        && $totali['tax_total'] === '22.00'
        && $totali['total'] === '122.00';
});

check('senza righe i totali sono tutti a zero', function () {
    $totali = OrderTotals::of([]);

    return $totali['products_total'] === '0.00'
        && $totali['taxable_total'] === '0.00'
        && $totali['tax_total'] === '0.00'
        && $totali['total'] === '0.00'
        && $totali['total_weight'] === '0.000'
        && $totali['tax_summaries'] === [];
});

check('spedizione e commissioni hanno il loro totale', function () use ($riga) {
    $totali = OrderTotals::of([
        $riga(100),
        ['type' => 'shipping', 'line_total' => '7.90', 'tax_rate' => 22, 'quantity' => '1.000'],
        ['type' => 'fee', 'line_total' => '3.00', 'tax_rate' => 22, 'quantity' => '1.000'],
    ]);

    return $totali['products_total'] === '100.00'
        && $totali['shipping_total'] === '7.90'
        && $totali['fees_total'] === '3.00'
        && $totali['total'] === '110.90';
});

check('lo sconto sul totale si riparte in proporzione sulle righe', function () use ($riga) {
    $totali = OrderTotals::of(
        [$riga(75), $riga(25)],
        ['discount_type' => 'amount', 'discount_value' => 20]
    );

    return $totali['lines'][0]['order_discount_amount'] === '15.00'
        && $totali['lines'][1]['order_discount_amount'] === '5.00'
        && $totali['discount_total'] === '20.00'
        && $totali['total'] === '80.00';
});

check('lo sconto a percentuale vale sulla merce scontabile', function () use ($riga) {
    $totali = OrderTotals::of(
        [$riga(100), ['type' => 'shipping', 'line_total' => '10.00', 'tax_rate' => 22]],
        ['discount_type' => 'percent', 'discount_value' => 10]
    );

    // Il 10% si calcola su 100, non su 110: la spedizione non si sconta.
    return $totali['discount_total'] === '10.00' && $totali['total'] === '100.00';
});

check('l\'ultimo centesimo del resto va alla riga più alta', function () use ($riga) {
    // Tre righe da 33,33 e dieci euro di sconto: 3,33 a testa fa 9,99. Il
    // centesimo che manca deve finire da qualche parte, o la somma delle righe
    // non fa più il totale e i riepiloghi IVA sballano.
    $totali = OrderTotals::of(
        [$riga(33.33), $riga(33.33), $riga(33.33)],
        ['discount_type' => 'amount', 'discount_value' => 10]
    );

    $ripartito = 0.0;

    foreach ($totali['lines'] as $linea) {
        $ripartito += (float) $linea['order_discount_amount'];
    }

    return round($ripartito, 2) === 10.00
        && $totali['discount_total'] === '10.00'
        && $totali['lines'][0]['order_discount_amount'] === '3.34';
});

check('il resto va alla riga più alta anche quando non è la prima', function () use ($riga) {
    // 0,83 + 8,33 + 0,83 fa 9,99: il centesimo va alla riga da cento euro.
    $totali = OrderTotals::of(
        [$riga(10), $riga(100), $riga(10)],
        ['discount_type' => 'amount', 'discount_value' => 10]
    );

    return $totali['lines'][0]['order_discount_amount'] === '0.83'
        && $totali['lines'][1]['order_discount_amount'] === '8.34'
        && $totali['lines'][2]['order_discount_amount'] === '0.83'
        && $totali['discount_total'] === '10.00';
});

check('uno sconto più grande della merce si ferma alla merce', function () use ($riga) {
    // Un coupon da 500 € su un ordine da 100 € non regala la spedizione e non
    // porta il totale sotto zero.
    $totali = OrderTotals::of(
        [$riga(100), ['type' => 'shipping', 'line_total' => '7.90', 'tax_rate' => 22]],
        ['discount_type' => 'amount', 'discount_value' => 500]
    );

    return $totali['discount_total'] === '100.00' && $totali['total'] === '7.90';
});

check('senza righe scontabili lo sconto non si riparte e non divide per zero', function () {
    $totali = OrderTotals::of(
        [['type' => 'shipping', 'line_total' => '7.90', 'tax_rate' => 22]],
        ['discount_type' => 'percent', 'discount_value' => 50]
    );

    return $totali['discount_total'] === '0.00' && $totali['total'] === '7.90';
});

check('righe a zero non prendono sconto e non dividono per zero', function () use ($riga) {
    $totali = OrderTotals::of(
        [$riga(0), $riga(0)],
        ['discount_type' => 'amount', 'discount_value' => 10]
    );

    return $totali['discount_total'] === '0.00'
        && $totali['lines'][0]['order_discount_amount'] === '0.00';
});

check('uno sconto a zero o negativo non tocca niente', function () use ($riga) {
    foreach ([0, -5] as $valore) {
        $totali = OrderTotals::of([$riga(100)], ['discount_type' => 'amount', 'discount_value' => $valore]);

        if ($totali['discount_total'] !== '0.00' || $totali['total'] !== '100.00') {
            return false;
        }
    }

    return true;
});

check('una riga di testo non si sconta e non pesa sui totali', function () use ($riga) {
    $totali = OrderTotals::of(
        [$riga(100), ['type' => 'text', 'line_total' => '0.00', 'tax_rate' => 0]],
        ['discount_type' => 'amount', 'discount_value' => 10]
    );

    return $totali['lines'][1]['order_discount_amount'] === '0.00'
        && $totali['discount_total'] === '10.00';
});

check('una riga può essere esclusa dallo sconto a mano', function () use ($riga) {
    $totali = OrderTotals::of(
        [$riga(50), $riga(50, 22, ['discountable' => 'false'])],
        ['discount_type' => 'amount', 'discount_value' => 10]
    );

    return $totali['lines'][0]['order_discount_amount'] === '10.00'
        && $totali['lines'][1]['order_discount_amount'] === '0.00';
});

check('i riepiloghi IVA arrivano uno per aliquota, dalla più alta', function () use ($riga) {
    $totali = OrderTotals::of([$riga(100, 10), $riga(122, 22), $riga(50, 10)]);

    return count($totali['tax_summaries']) === 2
        && $totali['tax_summaries'][0]['rate'] === 22.0
        && $totali['tax_summaries'][1]['rate'] === 10.0
        && $totali['tax_summaries'][1]['total'] === 150.0;
});

check('lo sconto entra nei riepiloghi IVA, non resta fuori', function () use ($riga) {
    $totali = OrderTotals::of(
        [$riga(100)],
        ['discount_type' => 'percent', 'discount_value' => 50]
    );

    // Imponibile e imposta si calcolano su 50, non su 100.
    return $totali['tax_summaries'][0]['total'] === 50.0
        && $totali['taxable_total'] === '40.98'
        && $totali['total'] === '50.00';
});

check('due nature esenti diverse restano due riepiloghi', function () use ($riga) {
    $totali = OrderTotals::of([
        $riga(100, 0, ['tax_nature' => 'N2.2']),
        $riga(50, 0, ['tax_nature' => 'N3.2']),
    ]);

    return count($totali['tax_summaries']) === 2 && $totali['tax_total'] === '0.00';
});

check('il peso totale tiene tre decimali', function () {
    $totali = OrderTotals::of([
        ['type' => 'product', 'quantity' => '3.000', 'line_total' => '30.00', 'tax_rate' => 22, 'weight' => 0.25],
        ['type' => 'product', 'quantity' => '2.000', 'line_total' => '20.00', 'tax_rate' => 22, 'weight' => 1.5],
    ]);

    return $totali['total_weight'] === '3.750';
});

check('la spedizione non ha peso e non lo inventa', function () use ($riga) {
    $totali = OrderTotals::of([
        $riga(100, 22, ['weight' => 2]),
        ['type' => 'shipping', 'line_total' => '7.90', 'tax_rate' => 22, 'quantity' => '1.000'],
    ]);

    return $totali['total_weight'] === '2.000';
});

check('le righe tornano indietro tutte, nello stesso ordine', function () use ($riga) {
    $totali = OrderTotals::of([$riga(10), $riga(20), $riga(30)]);

    return count($totali['lines']) === 3
        && $totali['lines'][0]['line_total'] === '10.00'
        && $totali['lines'][2]['line_total'] === '30.00';
});

summary();
```

- [ ] **Step 2: Lancia il test e guardalo fallire**

Run: `php tests/OrderTotalsTest.php`
Atteso: errore fatale, `Class "Wonder\Plugin\Gestionale\Support\Pricing\OrderTotals" not found`.

- [ ] **Step 3: Scrivi `src/Support/Pricing/OrderTotals.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Pricing;

use Wonder\Plugin\Gestionale\Support\Tax\TaxTotals;

/**
 * Dalle righe ai totali dell'ordine (§1 di G4, §4.5 e §4.7).
 *
 * Lo sconto sul totale — coupon o sconto scritto a mano sulla testata — non
 * resta appeso alla testata: si **riparte in proporzione** sulle righe a cui si
 * applica e si scrive riga per riga in `order_discount_amount`. Se restasse
 * fuori, i riepiloghi IVA verrebbero calcolati su un imponibile che il cliente
 * non ha pagato, e la fattura non tornerebbe con l'incasso.
 *
 * L'ultimo centesimo di resto va alla riga più alta: tre righe da 33,33 con
 * dieci euro di sconto fanno 3,33 a testa, cioè 9,99, e quel centesimo deve
 * finire da qualche parte perché la somma delle righe faccia esattamente il
 * totale.
 *
 * Spedizione, commissioni e righe di testo non si scontano: il coupon vale
 * sulla merce. Entrano però nei riepiloghi IVA, ognuna con la sua aliquota.
 *
 * Classe pura: l'unica cosa che chiama è `Tax\TaxTotals`, che è pura anche lei.
 * Le aliquote arrivano già risolte da `Tax\TaxResolver`.
 */
final class OrderTotals
{
    /** Le righe che lo sconto sul totale può toccare. */
    private const DISCOUNTABLE_TYPES = ['product', 'custom'];

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $context
     * @return array{products_total: string, discount_total: string, shipping_total: string, fees_total: string, taxable_total: string, tax_total: string, total: string, total_weight: string, tax_summaries: list<array{rate: float, nature: string, taxable: float, tax: float, total: float}>, lines: list<array<string, mixed>>}
     */
    public static function of(array $lines, array $context = []): array
    {
        $lines = array_values(array_filter($lines, 'is_array'));
        $pricesIncludeTax = !array_key_exists('prices_include_tax', $context)
            || filter_var($context['prices_include_tax'], FILTER_VALIDATE_BOOL);

        $products = 0.0;
        $shipping = 0.0;
        $fees = 0.0;
        $weight = 0.0;
        $base = 0.0;

        foreach ($lines as $line) {
            $total = static::money($line['line_total'] ?? 0);
            $type = static::type($line);

            if ($type === 'shipping') {
                $shipping += $total;
            } elseif ($type === 'fee') {
                $fees += $total;
            } else {
                $products += $total;
            }

            $weight += (float) ($line['weight'] ?? 0) * (float) ($line['quantity'] ?? 0);

            if ($total > 0.0 && static::isDiscountable($line)) {
                $base += $total;
            }
        }

        $base = round($base, 2);
        $discount = static::discount($context, $base);
        $shares = static::shares($lines, $discount, $base);

        $taxLines = [];
        $result = [];

        foreach ($lines as $index => $line) {
            $share = $shares[$index] ?? 0.0;
            $line['order_discount_amount'] = static::asMoney($share);
            $result[] = $line;

            $taxLines[] = [
                // L'imposta si calcola su quello che il cliente paga davvero.
                'total' => round(static::money($line['line_total'] ?? 0) - $share, 2),
                'rate' => (float) ($line['tax_rate'] ?? 0),
                'nature' => (string) ($line['tax_nature'] ?? ''),
            ];
        }

        $summaries = TaxTotals::summaries($taxLines, $pricesIncludeTax);
        $taxable = 0.0;
        $tax = 0.0;

        foreach ($summaries as $summary) {
            $taxable += $summary['taxable'];
            $tax += $summary['tax'];
        }

        $taxable = round($taxable, 2);
        $tax = round($tax, 2);

        return [
            'products_total' => static::asMoney(round($products, 2)),
            'discount_total' => static::asMoney(round(array_sum($shares), 2)),
            'shipping_total' => static::asMoney(round($shipping, 2)),
            'fees_total' => static::asMoney(round($fees, 2)),
            'taxable_total' => static::asMoney($taxable),
            'tax_total' => static::asMoney($tax),
            'total' => static::asMoney(round($taxable + $tax, 2)),
            'total_weight' => number_format(round(max(0.0, $weight), 3), 3, '.', ''),
            'tax_summaries' => $summaries,
            'lines' => $result,
        ];
    }

    /**
     * Lo sconto sul totale, mai più grande della merce che può scontare.
     *
     * @param array<string, mixed> $context
     */
    private static function discount(array $context, float $base): float
    {
        $value = round((float) ($context['discount_value'] ?? 0), 2);

        // Base zero: niente da scontare e nessuna divisione per zero dopo.
        if ($base <= 0.0 || $value <= 0.0) {
            return 0.0;
        }

        $discount = match ((string) ($context['discount_type'] ?? 'none')) {
            'amount' => $value,
            'percent' => round($base * min($value, 100.0) / 100, 2),
            default => 0.0,
        };

        // Un coupon da 500 € su un ordine da 100 € sconta 100 €: il resto non
        // si prende dalla spedizione né dalle commissioni.
        return min($discount, $base);
    }

    /**
     * La quota di sconto di ogni riga, indicizzata come le righe.
     *
     * @param list<array<string, mixed>> $lines
     * @return array<int, float>
     */
    private static function shares(array $lines, float $discount, float $base): array
    {
        if ($discount <= 0.0 || $base <= 0.0) {
            return [];
        }

        $shares = [];
        $assigned = 0.0;
        $tallest = null;
        $tallestTotal = 0.0;

        foreach ($lines as $index => $line) {
            $total = static::money($line['line_total'] ?? 0);

            if ($total <= 0.0 || !static::isDiscountable($line)) {
                continue;
            }

            $share = round($discount * $total / $base, 2);
            $shares[$index] = $share;
            $assigned = round($assigned + $share, 2);

            if ($total > $tallestTotal) {
                $tallest = $index;
                $tallestTotal = $total;
            }
        }

        // Il centesimo che manca (o che avanza) alla riga più alta: la somma
        // delle quote deve fare **esattamente** lo sconto.
        if ($tallest !== null) {
            $shares[$tallest] = round($shares[$tallest] + ($discount - $assigned), 2);
        }

        return $shares;
    }

    /** @param array<string, mixed> $line */
    private static function type(array $line): string
    {
        $type = trim((string) ($line['type'] ?? 'product'));

        return $type === '' ? 'product' : $type;
    }

    /** @param array<string, mixed> $line */
    private static function isDiscountable(array $line): bool
    {
        // Chi chiama può forzare la scelta riga per riga: serve ai coupon di G6
        // che valgono solo su certi prodotti.
        if (array_key_exists('discountable', $line)) {
            return filter_var($line['discountable'], FILTER_VALIDATE_BOOL);
        }

        return in_array(static::type($line), self::DISCOUNTABLE_TYPES, true);
    }

    private static function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private static function asMoney(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
```

- [ ] **Step 4: Lancia il test e guardalo passare**

Run: `php tests/OrderTotalsTest.php`
Atteso: tutti i check verdi.

- [ ] **Step 5: Lancia tutti i test del modulo**

Run: `php tests/run.php`
Atteso: verde, compresi `ConventionsTest` e `ManifestTest`, che ora vedono
undici tabelle in più.

- [ ] **Step 6: Commit**

```bash
git add src/Support/Pricing/OrderTotals.php tests/OrderTotalsTest.php && git commit -m "Aggiunge il calcolo dei totali dell'ordine"
```
