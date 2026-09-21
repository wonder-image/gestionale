<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Le categorie di un modello.
 *
 * Un modello sta in più categorie, ma una sola è la principale: è quella che
 * la vetrina userà per il percorso della pagina. `is_main` vive qui e non
 * sulla categoria perché è una proprietà del legame, non dell'una né dell'altro.
 */
final class ProductModelCategory extends Model
{
    public static string $table = 'gst_product_model_categories';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-diagram-3';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_model_id')->int()->null(false)->foreign(ProductModel::$table),
            Column::key('category_id')->int()->null(false)->foreign(Category::$table),
            Column::key('is_main')->enum(['true', 'false'])->default('false'),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_model' => ['index' => 'product_model_id'],
            'ind_category' => ['index' => 'category_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('category_id')->number()->decimals(0),
            Field::key('is_main')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
