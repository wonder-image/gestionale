<?php

namespace Wonder\Plugin\Gestionale\Models\Stock;

use Wonder\Plugin\Gestionale\Models\System\StatusLog;

/** La storia degli stati di un documento di magazzino (4.1). */
final class StockDocumentStatusLog extends StatusLog
{
    public static string $table = 'gst_stock_document_status_logs';
    public static string $folder = 'gestionale/stock';

    public static function entityColumn(): string
    {
        return 'stock_document_id';
    }

    public static function entityTable(): string
    {
        return StockDocument::$table;
    }
}
