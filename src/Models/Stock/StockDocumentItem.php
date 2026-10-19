<?php

namespace Wonder\Plugin\Gestionale\Models\Stock;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Le righe del documento di magazzino (G2b-bis 4.1).
 *
 * `quantity` è il numero scritto sulla riga — pezzi caricati, scaricati o
 * contati — senza segno: il verso lo dà il tipo di documento. In un
 * inventario una riga non ancora contata ha `quantity` vuota, e
 * `expected_quantity` pure: casella vuota non vuol dire zero.
 *
 * Lotto, fornitore, costo e totale di riga restano vuoti finché G3 non li usa.
 */
final class StockDocumentItem extends Model
{
    public static string $table = 'gst_stock_document_items';
    public static string $folder = 'gestionale/stock';
    public static string $icon = 'bi bi-list-ul';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('stock_document_id')->int()->null(false)->foreign(StockDocument::$table),
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Column::key('batch_id')->int()->default(0),
            Column::key('supplier_id')->int()->default(0),
            // G3: lotto e scadenza scritti nella bozza del carico, prima che il lotto esista.
            Column::key('batch_number')->length(50),
            Column::key('batch_expiry')->date()->null(true),
            Columns::decimal('quantity')->null(true),
            Columns::decimal('expected_quantity')->null(true),
            // Sempre senza IVA: a magazzino e nel costo del fornitore va il netto.
            Columns::decimal('unit_cost', '10,4'),
            // Con il carico «IVA inclusa»: il costo scritto e l'aliquota che lo
            // ha scorporato, fermata al salvataggio. NULL nelle righe senza IVA.
            Columns::decimal('entered_cost', '10,4')->null(true),
            Columns::decimal('tax_rate', '5,2')->null(true),
            Columns::decimal('line_total', '10,2'),
            Column::key('position')->int()->default(0),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_document' => ['index' => 'stock_document_id'],
            'ind_product' => ['index' => 'product_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('stock_document_id')->number()->decimals(0),
            Field::key('product_id')->number()->decimals(0),
            Field::key('batch_id')->number()->decimals(0),
            Field::key('supplier_id')->number()->decimals(0),
            Field::key('batch_number')->text(),
            Field::key('batch_expiry')->date(),
            Field::key('quantity')->number()->decimals(3),
            Field::key('expected_quantity')->number()->decimals(3),
            Field::key('unit_cost')->number()->decimals(4),
            Field::key('entered_cost')->number()->decimals(4),
            Field::key('tax_rate')->number()->decimals(2),
            Field::key('line_total')->number()->decimals(2),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
