<?php

namespace Wonder\Plugin\Gestionale\Models\Pricing;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Support\ForgetsRequestMemo;
use Wonder\Sql\TableSchema as Column;

/**
 * I tag di un listino: la regola vale per i prodotti con questi tag.
 */
final class PriceListTag extends Model
{
    use ForgetsRequestMemo;

    public const MEMO_GROUP = PriceList::MEMO_GROUP;

    public static string $table = 'gst_price_list_tags';
    public static string $folder = 'gestionale/pricing';
    public static string $icon = 'bi bi-tag';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('price_list_id')->int()->null(false)->foreign(PriceList::$table),
            Column::key('tag_id')->int()->null(false)->foreign(Tag::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_price_list' => ['index' => 'price_list_id'],
            'ind_tag' => ['index' => 'tag_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('price_list_id')->number()->decimals(0),
            Field::key('tag_id')->number()->decimals(0),
        ];
    }
}
