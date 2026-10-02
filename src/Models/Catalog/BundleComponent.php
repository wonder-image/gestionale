<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Un prodotto che sta sempre dentro un multiprodotto, nella quantità indicata
 * per ogni confezione. Una riga tolta dal form si cancella davvero.
 */
final class BundleComponent extends Model
{
    public static string $table = 'gst_bundle_components';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-box-seam';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_model_id')->int()->null(false)->foreign(ProductModel::$table),
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Columns::decimal('quantity', '12,3'),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_model' => ['index' => 'product_model_id'],
            'ind_product' => ['index' => 'product_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('product_id')->number()->decimals(0),
            Field::key('quantity')->number()->decimals(3),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
