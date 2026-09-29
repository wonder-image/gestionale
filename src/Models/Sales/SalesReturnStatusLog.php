<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\Plugin\Gestionale\Models\System\StatusLog;

/** La storia degli stati di un reso (4.1, 4.10). */
final class SalesReturnStatusLog extends StatusLog
{
    public static string $table = 'gst_sales_return_status_logs';
    public static string $folder = 'gestionale/sales';

    public static function entityColumn(): string
    {
        return 'sales_return_id';
    }

    public static function entityTable(): string
    {
        return SalesReturn::$table;
    }
}
