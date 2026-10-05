<?php

namespace Wonder\Plugin\Gestionale\Models\Promotions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Sql\TableSchema as Column;

/**
 * I marchi di una campagna.
 */
final class DiscountCampaignBrand extends Model
{
    public static string $table = 'gst_discount_campaign_brands';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-award';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('discount_campaign_id')->int()->null(false)->foreign(DiscountCampaign::$table),
            Column::key('brand_id')->int()->null(false)->foreign(Brand::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_campaign' => ['index' => 'discount_campaign_id'],
            'ind_brand' => ['index' => 'brand_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('discount_campaign_id')->number()->decimals(0),
            Field::key('brand_id')->number()->decimals(0),
        ];
    }
}
