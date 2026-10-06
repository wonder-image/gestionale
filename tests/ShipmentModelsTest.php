<?php
/** php tests/ShipmentModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;
use Wonder\Plugin\Gestionale\Models\Shipping\ShipmentItem;
use Wonder\Plugin\Gestionale\Models\Shipping\ShipmentStatusLog;
use Wonder\Plugin\Gestionale\Models\System\StatusLog;
use Wonder\Plugin\Gestionale\Support\Codes;

$colonne = static function (string $model): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

$campo = static function (string $model, string $key): ?object {
    foreach ($model::dataSchema() as $field) {
        if ((string) $field->key === $key) {
            return $field;
        }
    }

    return null;
};

check('le tre tabelle delle spedizioni hanno il prefisso del gestionale', fn () =>
    Shipment::$table === 'gst_shipments'
    && ShipmentItem::$table === 'gst_shipment_items'
    && ShipmentStatusLog::$table === 'gst_shipment_status_logs'
);

check('le spedizioni sono la storia dell\'ambiente: non viaggiano con il deploy', fn () =>
    Shipment::syncSchema() === null
    && ShipmentItem::syncSchema() === null
    && ShipmentStatusLog::syncSchema() === null
);

check('la spedizione ha il suo prefisso shp_', function () use ($campo) {
    return ($campo(Shipment::class, 'code')?->getSchema('unique_code')['prefix'] ?? null) === Codes::SHIPMENT
        && Codes::SHIPMENT === 'shp_';
});

check('la testata tiene ordine, tipo, stato, vettore, tracking, sede, date e nota', function () use ($colonne) {
    $attese = [
        'code', 'order_id', 'type', 'status', 'carrier_id', 'tracking_number', 'tracking_url',
        'location_id', 'shipped_at', 'delivered_at', 'note',
    ];

    return array_diff($attese, array_keys($colonne(Shipment::class))) === [];
});

check('gli stati di consegna sono quelli del piano', fn () =>
    Shipment::DELIVERY_STATUSES === [
        'pending', 'label_created', 'in_transit', 'out_for_delivery', 'delivered',
        'failed_attempt', 'exception', 'returned', 'cancelled',
    ]
);

check('gli stati del ritiro sono quelli del piano', fn () =>
    Shipment::PICKUP_STATUSES === ['pending', 'ready_for_pickup', 'picked_up', 'cancelled']
);

check('l\'enum della colonna è l\'unione dei due elenchi, senza doppioni', function () use ($colonne) {
    $enum = $colonne(Shipment::class)['status']->getSchema('values')
        ?? $colonne(Shipment::class)['status']->getSchema('enum');
    $attesi = array_values(array_unique([...Shipment::DELIVERY_STATUSES, ...Shipment::PICKUP_STATUSES]));

    return is_array($enum) && array_diff($attesi, $enum) === [] && array_diff($enum, $attesi) === [];
});

check('il tipo è consegna o ritiro, e parte da consegna', function () use ($colonne) {
    return Shipment::TYPES === ['delivery', 'pickup']
        && $colonne(Shipment::class)['type']->getSchema('default') === 'delivery';
});

check('vettore e sede senza niente valgono zero, senza chiave esterna', function () use ($colonne) {
    $c = $colonne(Shipment::class);

    return $c['carrier_id']->getSchema('foreign_table') === null
        && $c['location_id']->getSchema('foreign_table') === null
        && (string) $c['carrier_id']->getSchema('default') === '0'
        && (string) $c['location_id']->getSchema('default') === '0';
});

check('la spedizione è legata all\'ordine, la riga alla spedizione e alla riga venduta', function () use ($colonne) {
    $s = $colonne(Shipment::class);
    $i = $colonne(ShipmentItem::class);

    return $s['order_id']->getSchema('foreign_table') === 'gst_orders'
        && $s['order_id']->getSchema('null') === false
        && $i['shipment_id']->getSchema('foreign_table') === 'gst_shipments'
        && $i['order_item_id']->getSchema('foreign_table') === 'gst_order_items'
        && $i['order_item_id']->getSchema('null') === false;
});

check('la quantità spedita tiene tre decimali', function () use ($colonne, $campo) {
    return $colonne(ShipmentItem::class)['quantity']->getSchema('length') === '12,3'
        && (int) ($campo(ShipmentItem::class, 'quantity')?->getSchema('decimals') ?? 0) === 3;
});

check('il log delle spedizioni punta alla spedizione e porta le colonne comuni', function () use ($colonne) {
    $c = $colonne(ShipmentStatusLog::class);

    return is_subclass_of(ShipmentStatusLog::class, StatusLog::class)
        && ShipmentStatusLog::entityColumn() === 'shipment_id'
        && ShipmentStatusLog::entityTable() === 'gst_shipments'
        && ($c['shipment_id'] ?? null)?->getSchema('foreign_table') === 'gst_shipments'
        && isset($c['field'], $c['from_value'], $c['to_value'], $c['source'], $c['user_id'], $c['message']);
});

summary();
