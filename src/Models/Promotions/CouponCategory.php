<?php

namespace Wonder\Plugin\Gestionale\Models\Promotions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Sql\TableSchema as Column;

/**
 * Le categorie di un coupon: lo sconto vale per i prodotti di queste categorie (e delle loro sotto-categorie).
 */
final class CouponCategory extends Model
{
    public static string $table = 'gst_coupon_categories';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-folder';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('coupon_id')->int()->null(false)->foreign(Coupon::$table),
            Column::key('category_id')->int()->null(false)->foreign(Category::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_coupon' => ['index' => 'coupon_id'],
            'ind_category' => ['index' => 'category_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('coupon_id')->number()->decimals(0),
            Field::key('category_id')->number()->decimals(0),
        ];
    }
}
