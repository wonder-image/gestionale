<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Il documento di trasporto (G9 §4, architettura §4.9).
 *
 * Nasce da un ordine (`reason` `sale`); `transfer` e `stock_document_id`
 * esistono già per il DDT da trasferimento fra sedi, che arriva dopo (G9.14).
 * Gli indirizzi e il vettore sono **copiati**: il DDT emesso dice dove è
 * andata la merce anche se l'ordine o il corriere cambiano dopo.
 *
 * Il DDT non muove il magazzino: la merce è uscita alla conferma dell'ordine.
 */
final class DeliveryNote extends Model
{
    public static string $table = 'gst_delivery_notes';
    public static string $folder = 'gestionale/sales';
    public static string $icon = 'bi bi-truck';

    public const STATUSES = ['draft', 'issued', 'cancelled'];
    public const REASONS = ['sale', 'transfer'];
    public const TRANSPORT_BY = ['sender', 'recipient', 'carrier'];
    public const FREIGHT_TERMS = ['prepaid', 'collect'];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code', 'gross_weight', 'net_weight']),
            Column::key('number')->length(20),
            Column::key('date')->date(),
            Column::key('status')->enum(static::STATUSES)->default('draft'),
            Column::key('reason')->enum(static::REASONS)->default('sale'),
            Column::key('order_id')->int()->default(0),
            Column::key('stock_document_id')->int()->default(0),
            Column::key('customer_id')->int()->default(0),
            Column::key('from_location_id')->int()->default(0),
            ...Order::billingAddress()->tableSchema(),
            ...Order::shippingAddress()->tableSchema(),
            Column::key('transport_by')->enum(static::TRANSPORT_BY)->default('sender'),
            Column::key('carrier_id')->int()->default(0),
            Column::key('carrier_name'),
            Column::key('carrier_vat')->length(30),
            Column::key('carrier_address'),
            Column::key('transport_started_at')->datetime(),
            Column::key('goods_appearance')->length(100),
            Column::key('packages_count')->int()->default(0),
            Column::key('freight_terms')->enum(static::FREIGHT_TERMS)->default('prepaid'),
            Column::key('document_note')->type('TEXT'),
            Column::key('pdf_file'),
            Column::key('user_id')->int()->default(0),
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
            Field::key('code')->text()->uniqueCode(Codes::DELIVERY_NOTE),
            Field::key('number')->text()->sanitize(false),
            Field::key('date')->date(),
            Field::key('status')->text()->sanitize(false),
            Field::key('reason')->text()->sanitize(false),
            Field::key('order_id')->number()->decimals(0),
            Field::key('stock_document_id')->number()->decimals(0),
            Field::key('customer_id')->number()->decimals(0),
            Field::key('from_location_id')->number()->decimals(0),
            ...Order::billingAddress()->dataSchema(),
            ...Order::shippingAddress()->dataSchema(),
            Field::key('transport_by')->text()->sanitize(false),
            Field::key('carrier_id')->number()->decimals(0),
            Field::key('carrier_name')->text(),
            Field::key('carrier_vat')->text()->sanitize(false),
            Field::key('carrier_address')->text(),
            Field::key('transport_started_at')->date(),
            Field::key('goods_appearance')->text(),
            Field::key('packages_count')->number()->decimals(0),
            Field::key('gross_weight')->number()->decimals(3),
            Field::key('net_weight')->number()->decimals(3),
            Field::key('freight_terms')->text()->sanitize(false),
            Field::key('document_note')->text(),
            Field::key('pdf_file')->text()->sanitize(false),
            Field::key('user_id')->number()->decimals(0),
        ];
    }
}
