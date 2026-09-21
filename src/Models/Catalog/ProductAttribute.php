<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Gli attributi di un prodotto: quello che distingue un prodotto dagli altri
 * della stessa variante (taglia, formato).
 *
 * Le tre tabelle dei collegamenti hanno le stesse colonne e cambiano solo nel
 * padre: il tipo dell'attributo decide quale delle tre si riempie, e le altre
 * restano vuote. La regola sta in `Support\Catalog\Attributes`.
 */
final class ProductAttribute extends Model
{
    public static string $table = 'gst_product_attributes';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-sliders';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['value_number']),
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Column::key('attribute_id')->int()->null(false)->foreign(Attribute::$table),
            Column::key('attribute_value_id')->int()->foreign(AttributeValue::$table),
            Column::key('value_text'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_parent' => ['index' => 'product_id'],
            'ind_attribute' => ['index' => 'attribute_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_id')->number()->decimals(0),
            Field::key('attribute_id')->number()->decimals(0),
            Field::key('attribute_value_id')->number()->decimals(0),
            Field::key('value_text')->text()->sanitizeFirst(),
            Field::key('value_number')->number()->decimals(3),
        ];
    }
}
