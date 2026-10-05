<?php

namespace Wonder\Plugin\Gestionale\Models\Promotions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Sql\TableSchema as Column;

/**
 * Gli articoli di una campagna: scelti uno per uno (`is_excluded` falso) oppure tolti dalla selezione (`is_excluded` vero).
 */
final class DiscountCampaignProductModel extends Model
{
    public static string $table = 'gst_discount_campaign_product_models';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-box';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('discount_campaign_id')->int()->null(false)->foreign(DiscountCampaign::$table),
            Column::key('product_model_id')->int()->null(false)->foreign(ProductModel::$table),
            Column::key('is_excluded')->enum(['true', 'false'])->default('false'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_campaign' => ['index' => 'discount_campaign_id'],
            'ind_model' => ['index' => 'product_model_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('discount_campaign_id')->number()->decimals(0),
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('is_excluded')->text()->sanitize(false),
        ];
    }
}
