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
