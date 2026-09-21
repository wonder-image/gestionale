<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * I tag di un modello: etichette trasversali alle categorie.
 *
 * Nessuna colonna in più oltre alle due chiavi: un tag o c'è o non c'è.
 */
final class ProductModelTag extends Model
{
    public static string $table = 'gst_product_model_tags';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-tag';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_model_id')->int()->null(false)->foreign(ProductModel::$table),
            Column::key('tag_id')->int()->null(false)->foreign(Tag::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_model' => ['index' => 'product_model_id'],
            'ind_tag' => ['index' => 'tag_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('tag_id')->number()->decimals(0),
        ];
    }
}
