<?php

namespace Wonder\Plugin\Gestionale\Models\Promotions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Sql\TableSchema as Column;

/**
 * I tag di un coupon: lo sconto vale per i prodotti con questi tag.
 */
final class CouponTag extends Model
{
    public static string $table = 'gst_coupon_tags';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-tag';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('coupon_id')->int()->null(false)->foreign(Coupon::$table),
            Column::key('tag_id')->int()->null(false)->foreign(Tag::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_coupon' => ['index' => 'coupon_id'],
            'ind_tag' => ['index' => 'tag_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('coupon_id')->number()->decimals(0),
            Field::key('tag_id')->number()->decimals(0),
        ];
    }
}
