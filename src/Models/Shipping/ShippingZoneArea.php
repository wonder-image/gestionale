<?php

namespace Wonder\Plugin\Gestionale\Models\Shipping;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Shipping\ShippingSync;
use Wonder\Sql\TableSchema as Column;

/**
 * Un'area di una zona: un paese (sigla di due lettere maiuscole) e, se serve,
 * una provincia (sigla maiuscola). `province` vuoto vuol dire tutto il paese.
 */
final class ShippingZoneArea extends Model
{
    public static string $table = 'gst_shipping_zone_areas';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-geo-alt';

    public static function syncSchema(): ?SyncSchema
    {
        return ShippingSync::schema();
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('shipping_zone_id')->int()->null(false)->foreign(ShippingZone::$table),
            Column::key('country')->length(2),
            Column::key('province')->length(10),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_zone' => ['index' => 'shipping_zone_id'],
            'ind_area' => ['index' => ['country', 'province']],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('shipping_zone_id')->number()->decimals(0),
            Field::key('country')->text()->sanitize(false),
            Field::key('province')->text()->sanitize(false),
        ];
    }
}
