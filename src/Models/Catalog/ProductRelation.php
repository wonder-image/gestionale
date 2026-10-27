<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Gli articoli che chi vende collega a un altro, nell'ordine in cui li ha
 * messi: `suggested` da comprare insieme («Abbinalo a»), `alternative` al
 * posto suo («Potrebbe interessarti»).
 *
 * Nessun indice unico sulla coppia: il repeater scrive prima le righe e
 * cancella dopo, e un indice fermerebbe chi scambia due righe. Doppioni e
 * articolo collegato a sé stesso si tolgono prima
 * ({@see \Wonder\Plugin\Gestionale\Support\Catalog\RelatedProducts::cleanRows()}).
 */
final class ProductRelation extends Model
{
    public static string $table = 'gst_product_relations';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-link-45deg';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_model_id')->int()->null(false)->foreign(ProductModel::$table),
            Column::key('related_model_id')->int()->null(false)->foreign(ProductModel::$table),
            Column::key('type')->enum(['suggested', 'alternative'])->default('suggested'),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_model_type' => ['index' => ['product_model_id', 'type']],
            'ind_related' => ['index' => 'related_model_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('related_model_id')->number()->decimals(0),
            Field::key('type')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
