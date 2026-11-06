<?php

namespace Wonder\Plugin\Gestionale\Models\Pricing;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Support\ForgetsRequestMemo;
use Wonder\Sql\TableSchema as Column;

/**
 * I marchi di un listino: la regola vale per i prodotti di questi marchi.
 */
final class PriceListBrand extends Model
{
    use ForgetsRequestMemo;

    public const MEMO_GROUP = PriceList::MEMO_GROUP;

    public static string $table = 'gst_price_list_brands';
    public static string $folder = 'gestionale/pricing';
    public static string $icon = 'bi bi-award';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('price_list_id')->int()->null(false)->foreign(PriceList::$table),
            Column::key('brand_id')->int()->null(false)->foreign(Brand::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_price_list' => ['index' => 'price_list_id'],
            'ind_brand' => ['index' => 'brand_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('price_list_id')->number()->decimals(0),
            Field::key('brand_id')->number()->decimals(0),
        ];
    }
}
