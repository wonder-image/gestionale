<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\Plugin\Gestionale\Models\System\StatusLog;

/** La storia degli stati di un DDT (G9 §4.1). */
final class DeliveryNoteStatusLog extends StatusLog
{
    public static string $table = 'gst_delivery_note_status_logs';
    public static string $folder = 'gestionale/sales';

    public static function entityColumn(): string
    {
        return 'delivery_note_id';
    }

    public static function entityTable(): string
    {
        return DeliveryNote::$table;
    }
}
