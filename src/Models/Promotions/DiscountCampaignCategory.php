<?php

namespace Wonder\Plugin\Gestionale\Models\Promotions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Sql\TableSchema as Column;

/**
 * Le categorie di una campagna. Vale anche per le loro sottocategorie.
 */
final class DiscountCampaignCategory extends Model
{
    public static string $table = 'gst_discount_campaign_categories';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-diagram-3';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('discount_campaign_id')->int()->null(false)->foreign(DiscountCampaign::$table),
            Column::key('category_id')->int()->null(false)->foreign(Category::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_campaign' => ['index' => 'discount_campaign_id'],
            'ind_category' => ['index' => 'category_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('discount_campaign_id')->number()->decimals(0),
            Field::key('category_id')->number()->decimals(0),
        ];
    }
}
