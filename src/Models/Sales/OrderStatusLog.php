<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\Plugin\Gestionale\Models\System\StatusLog;

/**
 * La storia degli stati di un ordine (4.1): chi ha cambiato cosa, quando e da
 * dove. Colonne, indice e campi arrivano tutti dalla base.
 */
final class OrderStatusLog extends StatusLog
{
    public static string $table = 'gst_order_status_logs';
    public static string $folder = 'gestionale/sales';

    public static function entityColumn(): string
    {
        return 'order_id';
    }

    public static function entityTable(): string
    {
        return Order::$table;
    }
}
