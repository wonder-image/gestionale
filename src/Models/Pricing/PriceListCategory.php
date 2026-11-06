<?php

namespace Wonder\Plugin\Gestionale\Models\Pricing;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Support\ForgetsRequestMemo;
use Wonder\Sql\TableSchema as Column;

/**
 * Le categorie di un listino: la regola vale per i prodotti di queste categorie (e sottocategorie).
 */
final class PriceListCategory extends Model
{
    use ForgetsRequestMemo;

    public const MEMO_GROUP = PriceList::MEMO_GROUP;

    public static string $table = 'gst_price_list_categories';
    public static string $folder = 'gestionale/pricing';
    public static string $icon = 'bi bi-diagram-3';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('price_list_id')->int()->null(false)->foreign(PriceList::$table),
            Column::key('category_id')->int()->null(false)->foreign(Category::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_price_list' => ['index' => 'price_list_id'],
            'ind_category' => ['index' => 'category_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('price_list_id')->number()->decimals(0),
            Field::key('category_id')->number()->decimals(0),
        ];
    }
}
