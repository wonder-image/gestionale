<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Da chi si compra un'opzione, con quale codice e a quanto.
 *
 * I fornitori sono del prodotto, una riga per fornitore: l'articolo senza
 * varianti li ha sul suo unico prodotto, quello con le varianti su ogni
 * opzione (vedi `Support\Purchasing\ProductSuppliers`).
 *
 * `cost` è il costo **di oggi**: lo storico sta nel costo dei movimenti. Per
 * questo il legame si cancella davvero, quando sparisce l'opzione o la riga,
 * e non resta niente in `deleted = 'true'` a tenere ferma la chiave esterna.
 * Vuoto vuol dire «non lo so» (`NULL`), non zero: uno zero farebbe del
 * fornitore il più conveniente e abbasserebbe il valore del magazzino.
 *
 * Nessun indice unico su opzione e fornitore: il salvataggio prima scrive e
 * poi toglie, e uno scambio di righe inciamperebbe a metà. I doppioni li
 * rifiuta la scheda, che può spiegarlo con una frase.
 */
final class ProductSupplier extends Model
{
    public static string $table = 'gst_product_suppliers';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-truck';

    /** I costi d'acquisto sono lavoro di chi vende, non configurazione. */
    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Column::key('supplier_id')->int()->null(false)->foreign(Contact::$table),
            Column::key('supplier_sku')->length(100),
            // Quattro decimali come il costo dei movimenti; nella scheda se ne
            // scrivono due.
            Columns::decimal('cost', '12,4')->null(true),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_product' => ['index' => 'product_id'],
            'ind_supplier' => ['index' => 'supplier_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_id')->number()->decimals(0),
            Field::key('supplier_id')->number()->decimals(0),
            Field::key('supplier_sku')->text(),
            Field::key('cost')->number()->decimals(4),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
