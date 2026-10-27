<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\ForgetsRequestMemo;
use Wonder\Sql\TableSchema as Column;

/**
 * Gli articoli di una FAQ: scelti uno per uno (`is_excluded` falso) oppure tolti dalla selezione (`is_excluded` vero).
 */
final class FaqProductModel extends Model
{
    use ForgetsRequestMemo;

    public const MEMO_GROUP = 'faqs';

    public static string $table = 'gst_faq_product_models';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-box';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('faq_id')->int()->null(false)->foreign(Faq::$table),
            Column::key('product_model_id')->int()->null(false)->foreign(ProductModel::$table),
            Column::key('is_excluded')->enum(['true', 'false'])->default('false'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_faq' => ['index' => 'faq_id'],
            'ind_model' => ['index' => 'product_model_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('faq_id')->number()->decimals(0),
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('is_excluded')->text()->sanitize(false),
        ];
    }
}
