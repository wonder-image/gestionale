<?php

namespace Wonder\Plugin\Gestionale\Models\Shipping;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Shipping\ShippingSync;
use Wonder\Sql\TableSchema as Column;

/**
 * Un corriere. Serve a dare un nome al vettore e a costruire il link di
 * tracking: `tracking_url_template` contiene `{tracking}`, che al momento del
 * link diventa il numero di spedizione. `provider` oggi è sempre `manual`:
 * i corrieri collegati sono una funzionalità futura.
 *
 * Viaggia col deploy solo con l'interruttore delle spedizioni (`ShippingSync`).
 */
final class Carrier extends Model
{
    public static string $table = 'gst_carriers';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-truck';

    public static function syncSchema(): ?SyncSchema
    {
        return ShippingSync::schema();
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('name'),
            Column::key('tracking_url_template')->length(500),
            Column::key('provider')->enum(['manual'])->default('manual'),
            Column::key('active')->enum(['true', 'false'])->default('true'),
            Column::key('position')->int(),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::CARRIER),
            Field::key('name')->text()->sanitize(false),
            Field::key('tracking_url_template')->text()->sanitize(false),
            Field::key('provider')->text()->sanitize(false),
            Field::key('active')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
