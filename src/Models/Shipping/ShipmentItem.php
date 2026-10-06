<?php

namespace Wonder\Plugin\Gestionale\Models\Shipping;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Le righe di una spedizione: quanto di ogni riga dell'ordine sta in quel
 * collo. La somma sulle spedizioni vive non supera mai la quantità ordinata
 * (lo garantisce `Shipments`, con le righe dell'ordine bloccate).
 */
final class ShipmentItem extends Model
{
    public static string $table = 'gst_shipment_items';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-box';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('shipment_id')->int()->null(false)->foreign(Shipment::$table),
            Column::key('order_item_id')->int()->null(false)->foreign(OrderItem::$table),
            Columns::decimal('quantity', '12,3'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_shipment' => ['index' => 'shipment_id'],
            'ind_order_item' => ['index' => 'order_item_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('shipment_id')->number()->decimals(0),
            Field::key('order_item_id')->number()->decimals(0),
            Field::key('quantity')->number()->decimals(3),
        ];
    }
}
