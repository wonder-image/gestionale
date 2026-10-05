<?php

namespace Wonder\Plugin\Gestionale\Models\Promotions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Sql\TableSchema as Column;

/**
 * I marchi di un coupon: lo sconto vale per i prodotti di questi marchi.
 */
final class CouponBrand extends Model
{
    public static string $table = 'gst_coupon_brands';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-award';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('coupon_id')->int()->null(false)->foreign(Coupon::$table),
            Column::key('brand_id')->int()->null(false)->foreign(Brand::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_coupon' => ['index' => 'coupon_id'],
            'ind_brand' => ['index' => 'brand_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('coupon_id')->number()->decimals(0),
            Field::key('brand_id')->number()->decimals(0),
        ];
    }
}
