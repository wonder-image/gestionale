<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Le righe del DDT (G9 §4.1): copia della riga d'ordine, la quantità
 * consegnata e il lotto da cui è uscita. Una riga d'ordine uscita da due lotti
 * sono due righe del DDT.
 */
final class DeliveryNoteItem extends Model
{
    public static string $table = 'gst_delivery_note_items';
    public static string $folder = 'gestionale/sales';
    public static string $icon = 'bi bi-list-ul';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('delivery_note_id')->int()->null(false)->foreign(DeliveryNote::$table),
            Column::key('order_item_id')->int()->default(0),
            Column::key('product_id')->int()->default(0),
            Column::key('sku')->length(100),
            Column::key('description'),
            Columns::decimal('quantity'),
            Column::key('unit')->length(10),
            Column::key('batch_id')->int()->default(0),
            Column::key('batch_number')->length(100),
            Column::key('supplier_id')->int()->default(0),
            Column::key('position')->int()->default(0),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_note' => ['index' => 'delivery_note_id'],
            'ind_order_item' => ['index' => 'order_item_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('delivery_note_id')->number()->decimals(0),
            Field::key('order_item_id')->number()->decimals(0),
            Field::key('product_id')->number()->decimals(0),
            Field::key('sku')->text(),
            Field::key('description')->text(),
            Field::key('quantity')->number()->decimals(3),
            Field::key('unit')->text()->sanitize(false),
            Field::key('batch_id')->number()->decimals(0),
            Field::key('batch_number')->text(),
            Field::key('supplier_id')->number()->decimals(0),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
