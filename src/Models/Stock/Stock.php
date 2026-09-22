<?php

namespace Wonder\Plugin\Gestionale\Models\Stock;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Sql\TableSchema as Column;

/**
 * La giacenza: quanti pezzi ci sono di una versione in vendita, in una sede.
 *
 * Una riga per combinazione prodotto/sede/lotto/fornitore, con l'indice unico
 * che lo garantisce. `batch_id` e `supplier_id` restano a zero fino a G3, ma
 * stanno nell'indice da subito: aggiungerli dopo vorrebbe dire rifare l'indice
 * e tutte le letture che ci passano.
 *
 * Nessun codice scrive qui direttamente: la porta è
 * `Support\Stock\Stock::apply()`, che tiene insieme giacenza e movimenti.
 */
final class Stock extends Model
{
    public static string $table = 'gst_stock';
    public static string $folder = 'gestionale/stock';
    public static string $icon = 'bi bi-boxes';

    /** La giacenza è la storia di questo ambiente, non configurazione. */
    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Column::key('location_id')->int()->null(false)->foreign(Location::$table),
            // Niente chiave esterna: zero vuol dire "nessun lotto", "nessun
            // fornitore", e MySQL non accetta uno zero che punta a niente.
            Column::key('batch_id')->int()->default(0),
            Column::key('supplier_id')->int()->default(0),
            ...static::sqlColumnsFromDataSchema(['quantity']),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'uni_stock' => ['unique' => ['product_id', 'location_id', 'batch_id', 'supplier_id']],
            'ind_product' => ['index' => 'product_id'],
            'ind_location' => ['index' => 'location_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_id')->number()->decimals(0),
            Field::key('location_id')->number()->decimals(0),
            Field::key('batch_id')->number()->decimals(0),
            Field::key('supplier_id')->number()->decimals(0),
            Field::key('quantity')->number()->decimals(3),
        ];
    }
}
