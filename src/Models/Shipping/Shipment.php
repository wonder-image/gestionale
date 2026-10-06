<?php

namespace Wonder\Plugin\Gestionale\Models\Shipping;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Una spedizione: un collo con il suo tracking, oppure il ritiro in sede di un
 * ordine. Un ordine può averne più d'una (spedizioni parziali).
 *
 * Gli stati sono due elenchi — consegna e ritiro — e l'enum della colonna è la
 * loro unione: chi può muoverli e in che ordine lo dice `ShipmentFlow`.
 *
 * `carrier_id` e `location_id` valgono zero quando non c'è niente (ritiro senza
 * vettore, consegna senza sede) e per questo non hanno chiave esterna.
 * `tracking_url` si calcola alla spedizione e si conserva: un link già mandato
 * al cliente non cambia se poi il vettore viene modificato.
 *
 * Non si sincronizza: è la storia di quell'ambiente, come gli ordini.
 */
final class Shipment extends Model
{
    public static string $table = 'gst_shipments';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-box-seam';

    public const TYPES = ['delivery', 'pickup'];

    public const DELIVERY_STATUSES = [
        'pending', 'label_created', 'in_transit', 'out_for_delivery', 'delivered',
        'failed_attempt', 'exception', 'returned', 'cancelled',
    ];

    public const PICKUP_STATUSES = ['pending', 'ready_for_pickup', 'picked_up', 'cancelled'];

    /** Tutti gli stati possibili, ognuno una volta sola. @return list<string> */
    public static function statuses(): array
    {
        return array_values(array_unique([...static::DELIVERY_STATUSES, ...static::PICKUP_STATUSES]));
    }

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('order_id')->int()->null(false)->foreign(Order::$table),
            Column::key('type')->enum(static::TYPES)->default('delivery'),
            Column::key('status')->enum(static::statuses())->default('pending'),
            Column::key('carrier_id')->int()->default(0),
            Column::key('tracking_number')->length(100),
            Column::key('tracking_url')->length(500),
            Column::key('location_id')->int()->default(0),
            Column::key('shipped_at')->datetime(),
            Column::key('delivered_at')->datetime(),
            Column::key('note')->type('TEXT'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_order' => ['index' => 'order_id'],
            'ind_status' => ['index' => 'status'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::SHIPMENT),
            Field::key('order_id')->number()->decimals(0),
            Field::key('type')->text()->sanitize(false),
            Field::key('status')->text()->sanitize(false),
            Field::key('carrier_id')->number()->decimals(0),
            Field::key('tracking_number')->text()->sanitize(false),
            Field::key('tracking_url')->text()->sanitize(false),
            Field::key('location_id')->number()->decimals(0),
            Field::key('shipped_at')->date(),
            Field::key('delivered_at')->date(),
            Field::key('note')->text(),
        ];
    }
}
