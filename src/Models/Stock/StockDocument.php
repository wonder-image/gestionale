<?php

namespace Wonder\Plugin\Gestionale\Models\Stock;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Il documento di magazzino: carico, scarico, inventario (G2b-bis 4.1).
 *
 * L'enum dei tipi nasce con `transfer`, che arriva con G3: così G3 non tocca
 * una tabella piena. Anche le colonne di G3 nascono subito e restano vuote —
 * sede d'arrivo, fornitore, documento del fornitore, costo totale.
 *
 * `number` e `date` arrivano alla conferma: una bozza eliminata non lascia
 * buchi nella numerazione. `user_id` è chi ha creato il documento; chi lo
 * conferma e chi lo annulla stanno nel log degli stati.
 */
final class StockDocument extends Model
{
    public static string $table = 'gst_stock_documents';
    public static string $folder = 'gestionale/stock';
    public static string $icon = 'bi bi-file-earmark-text';

    public const TYPES = ['receipt', 'issue', 'stocktake', 'transfer'];

    /** `in_transit` è il trasferimento partito e non ancora arrivato (G3.16). */
    public const STATUSES = ['draft', 'in_transit', 'completed', 'cancelled'];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('type')->enum(static::TYPES)->default('receipt'),
            Column::key('number')->length(20),
            Column::key('date')->date(),
            Column::key('location_id')->int()->null(false)->foreign(Location::$table),
            // G3: sede d'arrivo del trasferimento e fornitore del carico, senza chiave esterna.
            Column::key('to_location_id')->int()->default(0),
            Column::key('supplier_id')->int()->default(0),
            Column::key('supplier_document_number')->length(50),
            Column::key('supplier_document_date')->date(),
            Column::key('reason')->length(30),
            Column::key('status')->enum(static::STATUSES)->default('draft'),
            Columns::decimal('total_cost', '10,2'),
            // G3, Acquisti: i costi del carico scritti con l'IVA; il totale resta senza.
            Column::key('prices_include_tax')->enum(['true', 'false'])->default('false'),
            Column::key('note')->type('TEXT'),
            Column::key('user_id')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_type' => ['index' => 'type'],
            'ind_status' => ['index' => 'status'],
            'ind_number' => ['index' => 'number'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::STOCK_DOCUMENT),
            Field::key('type')->text()->sanitize(false),
            Field::key('number')->text()->sanitize(false),
            Field::key('date')->date(),
            Field::key('location_id')->number()->decimals(0),
            Field::key('to_location_id')->number()->decimals(0),
            Field::key('supplier_id')->number()->decimals(0),
            Field::key('supplier_document_number')->text(),
            Field::key('supplier_document_date')->date(),
            Field::key('reason')->text()->sanitize(false),
            Field::key('status')->text()->sanitize(false),
            Field::key('total_cost')->number()->decimals(2),
            Field::key('prices_include_tax')->text()->sanitize(false),
            Field::key('note')->text(),
            Field::key('user_id')->number()->decimals(0),
        ];
    }
}
