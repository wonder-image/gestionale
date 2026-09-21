<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Il prodotto: quello che si vende davvero e che sta a magazzino. "Blu / M".
 *
 * Punta sempre sia al modello sia alla variante, anche quando la variante è
 * una sola (G2a.2): senza variante un prodotto non esiste.
 *
 * Peso e misure vuoti valgono quelli del modello: la regola sta nella scheda,
 * non qui, perché è una scelta di lettura e non un dato.
 *
 * `min_stock_quantity`, `allow_backorder` e `backorder_lead_days` nascono ora
 * ma restano invisibili finché non arrivano il magazzino (G2b) e la vendita
 * senza giacenza (G4): stanno sul prodotto, e aggiungerle dopo vorrebbe dire
 * rifare la scheda.
 *
 * SKU ed EAN non hanno un indice unico: il framework scrive stringhe vuote e
 * non NULL, e due prodotti senza EAN si scontrerebbero. L'unicità la controlla
 * la Resource, che può spiegarla con una frase.
 */
final class Product extends Model
{
    public static string $table = 'gst_products';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-upc-scan';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema([
                'code', 'price', 'sale_price', 'min_stock_quantity',
                'weight', 'length', 'width', 'height',
            ]),
            Column::key('product_model_id')->int()->null(false)->foreign(ProductModel::$table),
            Column::key('product_variant_id')->int()->null(false)->foreign(ProductVariant::$table),
            Column::key('sku')->length(100),
            Column::key('ean')->length(13),
            Column::key('mpn')->length(100),
            Column::key('allow_backorder')->enum(['true', 'false'])->default('false'),
            Column::key('backorder_lead_days')->int(),
            Column::key('position')->int(),
            Column::key('active')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_model' => ['index' => 'product_model_id'],
            'ind_variant' => ['index' => 'product_variant_id'],
            'ind_sku' => ['index' => 'sku'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::PRODUCT),
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('product_variant_id')->number()->decimals(0),
            Field::key('sku')->text(),
            Field::key('ean')->text(),
            Field::key('mpn')->text(),
            Field::key('price')->number()->decimals(2),
            Field::key('sale_price')->number()->decimals(2),
            Field::key('min_stock_quantity')->number()->decimals(3),
            Field::key('allow_backorder')->text()->sanitize(false),
            Field::key('backorder_lead_days')->number()->decimals(0),
            Field::key('weight')->number()->decimals(3),
            Field::key('length')->number()->decimals(2),
            Field::key('width')->number()->decimals(2),
            Field::key('height')->number()->decimals(2),
            Field::key('position')->number()->decimals(0),
            Field::key('active')->text()->sanitize(false),
        ];
    }
}
