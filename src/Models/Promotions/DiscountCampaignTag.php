<?php

namespace Wonder\Plugin\Gestionale\Models\Promotions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Sql\TableSchema as Column;

/**
 * I tag di una campagna.
 */
final class DiscountCampaignTag extends Model
{
    public static string $table = 'gst_discount_campaign_tags';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-tag';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('discount_campaign_id')->int()->null(false)->foreign(DiscountCampaign::$table),
            Column::key('tag_id')->int()->null(false)->foreign(Tag::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_campaign' => ['index' => 'discount_campaign_id'],
            'ind_tag' => ['index' => 'tag_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('discount_campaign_id')->number()->decimals(0),
            Field::key('tag_id')->number()->decimals(0),
        ];
    }
}
