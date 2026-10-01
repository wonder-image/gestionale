<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Le personalizzazioni di un articolo, nell'ordine in cui il cliente le vede.
 *
 * `is_required` obbliga a compilarla per mettere l'articolo nel carrello.
 * Una riga tolta dal form si cancella davvero.
 */
final class ProductModelCustomization extends Model
{
    public static string $table = 'gst_product_model_customizations';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-pencil-square';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_model_id')->int()->null(false)->foreign(ProductModel::$table),
            Column::key('customization_id')->int()->null(false)->foreign(Customization::$table),
            Column::key('is_required')->enum(['true', 'false'])->default('false'),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_model' => ['index' => 'product_model_id'],
            'ind_customization' => ['index' => 'customization_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('customization_id')->number()->decimals(0),
            Field::key('is_required')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
