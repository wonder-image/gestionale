<?php

namespace Wonder\Plugin\Gestionale\Models\Stock;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * La scorta minima: sotto quanti pezzi un prodotto, in una sede, va avvisato.
 *
 * Una riga per prodotto e sede, con l'indice unico che lo garantisce. Niente
 * riga vuol dire «nessuna soglia»: chi la svuota o la mette a zero la toglie,
 * non la scrive a zero. Con una sede sola la riga sta sulla sede principale.
 *
 * Nessun codice scrive qui direttamente: la porta è
 * `Support\Stock\Thresholds`, che tiene insieme scheda e avvisi.
 */
final class StockThreshold extends Model
{
    public static string $table = 'gst_stock_thresholds';
    public static string $folder = 'gestionale/stock';
    public static string $icon = 'bi bi-speedometer2';

    /** Le soglie sono lavoro di chi vende, non configurazione. */
    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Column::key('location_id')->int()->null(false)->foreign(Location::$table),
            // Tre decimali dichiarati a mano: il core ne darebbe due (vedi
            // `Support\Columns`), e i pezzi si contano anche a etti.
            Columns::decimal('quantity', '12,3'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'uni_threshold' => ['unique' => ['product_id', 'location_id']],
            'ind_product' => ['index' => 'product_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_id')->number()->decimals(0),
            Field::key('location_id')->number()->decimals(0),
            Field::key('quantity')->number()->decimals(3),
        ];
    }
}
