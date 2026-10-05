<?php

namespace Wonder\Plugin\Gestionale\Models\Shipping;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Una zona di spedizione: un gruppo di aree (paese, provincia) con gli stessi
 * listini. Le aree stanno in `ShippingZoneArea`. A pari specificità, vince la
 * zona con la `position` minore.
 *
 * Non si sincronizza: è lavoro del commerciante.
 */
final class ShippingZone extends Model
{
    public static string $table = 'gst_shipping_zones';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-globe-europe-africa';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('name'),
            Column::key('position')->int(),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::SHIPPING_ZONE),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
