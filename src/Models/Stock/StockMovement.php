<?php

namespace Wonder\Plugin\Gestionale\Models\Stock;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * La storia del magazzino: una riga per ogni pezzo entrato o uscito.
 *
 * Non si modifica e non si cancella mai: una rettifica sbagliata si corregge
 * con un'altra rettifica. È l'unica risposta possibile alla domanda "perché
 * qui c'è scritto 3?".
 *
 * L'enum dei tipi nasce completo anche se in G2b l'unico prodotto è
 * `adjustment`: cambiare un enum con dentro dei dati è la migrazione che si
 * rimanda sempre. Vendite e resi arrivano con G4, carichi e trasferimenti con
 * G3.
 *
 * `reference_type` + `reference_id` legano il movimento al documento che l'ha
 * causato; in G2b restano vuoti.
 */
final class StockMovement extends Model
{
    public static string $table = 'gst_stock_movements';
    public static string $folder = 'gestionale/stock';
    public static string $icon = 'bi bi-arrow-left-right';

    public const TYPES = [
        'sale', 'sale_cancel', 'return', 'purchase',
        'adjustment', 'transfer_in', 'transfer_out',
    ];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    /** @return array<string, string> */
    public static function typeLabels(): array
    {
        return [
            'sale' => 'Vendita',
            'sale_cancel' => 'Vendita annullata',
            'return' => 'Reso',
            'purchase' => 'Carico',
            'adjustment' => 'Rettifica',
            'transfer_in' => 'Trasferimento in entrata',
            'transfer_out' => 'Trasferimento in uscita',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema([
                'code', 'quantity', 'quantity_before', 'quantity_after', 'unit_cost',
            ]),
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Column::key('location_id')->int()->null(false)->foreign(Location::$table),
            Column::key('batch_id')->int()->default(0),
            Column::key('supplier_id')->int()->default(0),
            Column::key('type')->enum(self::TYPES)->default('adjustment'),
            Column::key('reason')->length(30),
            Column::key('reference_type')->length(30),
            Column::key('reference_id')->int(),
            Column::key('source')->length(20),
            Column::key('user_id')->int(),
            Column::key('note')->type('TEXT'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_product' => ['index' => 'product_id'],
            'ind_location' => ['index' => 'location_id'],
            'ind_reference' => ['index' => 'reference_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::STOCK_MOVEMENT),
            Field::key('product_id')->number()->decimals(0),
            Field::key('location_id')->number()->decimals(0),
            Field::key('batch_id')->number()->decimals(0),
            Field::key('supplier_id')->number()->decimals(0),
            Field::key('type')->text()->sanitize(false),
            Field::key('reason')->text()->sanitize(false),
            Field::key('quantity')->number()->decimals(3),
            Field::key('quantity_before')->number()->decimals(3),
            Field::key('quantity_after')->number()->decimals(3),
            // Quattro decimali: un imballo da mille pezzi ha un costo unitario
            // con le frazioni, e arrotondarlo qui falserebbe il valore del
            // magazzino di G3.
            Field::key('unit_cost')->number()->decimals(4),
            Field::key('reference_type')->text()->sanitize(false),
            Field::key('reference_id')->number()->decimals(0),
            Field::key('source')->text()->sanitize(false),
            Field::key('user_id')->number()->decimals(0),
            Field::key('note')->text(),
        ];
    }
}
