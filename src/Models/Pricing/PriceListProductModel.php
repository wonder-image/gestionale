<?php

namespace Wonder\Plugin\Gestionale\Models\Pricing;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Support\ForgetsRequestMemo;
use Wonder\Sql\TableSchema as Column;

/**
 * Gli articoli di un listino: scelti uno per uno (`is_excluded` falso) oppure tolti dalla selezione (`is_excluded` vero).
 */
final class PriceListProductModel extends Model
{
    use ForgetsRequestMemo;

    public const MEMO_GROUP = PriceList::MEMO_GROUP;

    public static string $table = 'gst_price_list_product_models';
    public static string $folder = 'gestionale/pricing';
    public static string $icon = 'bi bi-box';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('price_list_id')->int()->null(false)->foreign(PriceList::$table),
            Column::key('product_model_id')->int()->null(false)->foreign(ProductModel::$table),
            Column::key('is_excluded')->enum(['true', 'false'])->default('false'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_price_list' => ['index' => 'price_list_id'],
            'ind_model' => ['index' => 'product_model_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('price_list_id')->number()->decimals(0),
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('is_excluded')->text()->sanitize(false),
        ];
    }
}
