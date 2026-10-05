<?php

namespace Wonder\Plugin\Gestionale\Models\Shipping;

use Wonder\Plugin\Gestionale\Models\System\StatusLog;

/** La storia degli stati di una spedizione (4.1). */
final class ShipmentStatusLog extends StatusLog
{
    public static string $table = 'gst_shipment_status_logs';
    public static string $folder = 'gestionale/models';

    public static function entityColumn(): string
    {
        return 'shipment_id';
    }

    public static function entityTable(): string
    {
        return Shipment::$table;
    }
}
