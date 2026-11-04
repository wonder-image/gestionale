<?php

namespace Wonder\Plugin\Gestionale\Models\Subscriptions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Sql\TableSchema as Column;

/**
 * «Si lega a» (G10b): l'abbonamento `product_model_id` si compra solo
 * insieme a un abbonamento di `parent_product_model_id` (l'hosting al dominio). Un livello
 * solo: una madre non ha madri ({@see \Wonder\Plugin\Gestionale\Support\Subscriptions\PlanLinks::assert()}).
 * Un legame tolto dal form si cancella davvero.
 */
final class PlanLink extends Model
{
    public static string $table = 'gst_plan_links';
    public static string $folder = 'gestionale/subscriptions';
    public static string $icon = 'bi bi-link-45deg';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_model_id')->int()->null(false)->foreign(ProductModel::$table),
            Column::key('parent_product_model_id')->int()->null(false)->foreign(ProductModel::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_model' => ['index' => 'product_model_id'],
            'ind_parent' => ['index' => 'parent_product_model_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('parent_product_model_id')->number()->decimals(0),
        ];
    }
}
