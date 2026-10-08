<?php

namespace Wonder\Plugin\Gestionale\Models\Shipping;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Shipping\ShippingSync;
use Wonder\Sql\TableSchema as Column;

/**
 * Un metodo di spedizione (Standard, Espresso…): il nome e i tempi che il
 * cliente vede. Il prezzo non sta qui ma nei listini, uno per zona
 * (`ShippingRate`).
 *
 * `carrier_id` vale 0 quando il metodo non ha un corriere: per questo non ha
 * una chiave esterna. `applies_online` e `applies_office` dicono per quali
 * canali il metodo è offerto.
 *
 * Viaggia col deploy solo con l'interruttore delle spedizioni (`ShippingSync`).
 */
final class ShippingMethod extends Model
{
    public static string $table = 'gst_shipping_methods';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-box-seam';

    public static function syncSchema(): ?SyncSchema
    {
        return ShippingSync::schema();
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('name'),
            Column::key('description')->length(255),
            Column::key('carrier_id')->int()->null(false)->default(0),
            Column::key('provider_service_code')->length(100),
            Column::key('applies_online')->enum(['true', 'false'])->default('true'),
            Column::key('applies_office')->enum(['true', 'false'])->default('true'),
            Column::key('active')->enum(['true', 'false'])->default('true'),
            Column::key('position')->int(),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::SHIPPING_METHOD),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('description')->text(),
            Field::key('carrier_id')->number()->decimals(0),
            Field::key('provider_service_code')->text()->sanitize(false),
            Field::key('applies_online')->text()->sanitize(false),
            Field::key('applies_office')->text()->sanitize(false),
            Field::key('active')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
