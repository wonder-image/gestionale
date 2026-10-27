<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\ForgetsRequestMemo;
use Wonder\Sql\TableSchema as Column;

/**
 * I marchi di una FAQ.
 */
final class FaqBrand extends Model
{
    use ForgetsRequestMemo;

    public const MEMO_GROUP = 'faqs';

    public static string $table = 'gst_faq_brands';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-award';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('faq_id')->int()->null(false)->foreign(Faq::$table),
            Column::key('brand_id')->int()->null(false)->foreign(Brand::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_faq' => ['index' => 'faq_id'],
            'ind_brand' => ['index' => 'brand_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('faq_id')->number()->decimals(0),
            Field::key('brand_id')->number()->decimals(0),
        ];
    }
}
