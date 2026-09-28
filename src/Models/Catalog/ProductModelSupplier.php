<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Da chi si compra un articolo, con quale codice e a quanto.
 *
 * I fornitori si scrivono sull'articolo, una riga per fornitore: valgono per
 * tutte le sue opzioni. Un'opzione che si compra a un prezzo diverso, o da
 * un fornitore suo, ha la sua riga in `gst_product_suppliers`, che per quel
 * fornitore vince (vedi `Support\Purchasing\ProductSuppliers::effective()`).
 *
 * `cost` è il costo **di oggi**: lo storico sta nel costo dei movimenti. Per
 * questo il legame si cancella davvero, quando sparisce l'articolo o la
 * riga, e non resta niente in `deleted = 'true'` a tenere ferma la chiave
 * esterna. Vuoto vuol dire «non lo so» (`NULL`), non zero: uno zero farebbe
 * del fornitore il più conveniente e abbasserebbe il valore del magazzino.
 *
 * Nessun indice unico su articolo e fornitore: il repeater prima scrive e
 * poi toglie, e uno scambio di righe inciamperebbe a metà salvataggio. I
 * doppioni li rifiuta la scheda, che può spiegarlo con una frase.
 */
final class ProductModelSupplier extends Model
{
    public static string $table = 'gst_product_model_suppliers';
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
            Column::key('product_model_id')->int()->null(false)->foreign(ProductModel::$table),
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
            'ind_model' => ['index' => 'product_model_id'],
            'ind_supplier' => ['index' => 'supplier_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('supplier_id')->number()->decimals(0),
            Field::key('supplier_sku')->text(),
            Field::key('cost')->number()->decimals(4),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
