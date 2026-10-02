<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Un gruppo di scelta di un multiprodotto: il cliente sceglie fra `min_choices`
 * e `max_choices` opzioni fra quelle del gruppo (`BundleGroupOption`).
 */
final class BundleGroup extends Model
{
    public static string $table = 'gst_bundle_groups';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-ui-checks-grid';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_model_id')->int()->null(false)->foreign(ProductModel::$table),
            Column::key('name'),
            Column::key('min_choices')->int()->default(0),
            Column::key('max_choices')->int()->default(1),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_model' => ['index' => 'product_model_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('min_choices')->number()->decimals(0),
            Field::key('max_choices')->number()->decimals(0),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
