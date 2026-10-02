<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Un prodotto che il cliente può scegliere in un gruppo, con il suo
 * sovrapprezzo (anche zero).
 */
final class BundleGroupOption extends Model
{
    public static string $table = 'gst_bundle_group_options';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-check2-square';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('bundle_group_id')->int()->null(false)->foreign(BundleGroup::$table),
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Columns::decimal('surcharge', '12,2'),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_group' => ['index' => 'bundle_group_id'],
            'ind_product' => ['index' => 'product_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('bundle_group_id')->number()->decimals(0),
            Field::key('product_id')->number()->decimals(0),
            Field::key('surcharge')->number()->decimals(2),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
