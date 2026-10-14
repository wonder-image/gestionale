<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Le revisioni inviate di un preventivo (G9 §3.2, architettura §4.7): una riga
 * per invio, con il PDF che il cliente ha ricevuto. Il PDF sta in
 * `storage/gestionale/documents` (`pdf_file` è il percorso relativo) e non si
 * riscrive: è quello che è partito.
 */
final class OrderQuoteRevision extends Model
{
    public static string $table = 'gst_order_quote_revisions';
    public static string $folder = 'gestionale/sales';
    public static string $icon = 'bi bi-file-earmark-pdf';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('order_id')->int()->null(false)->foreign(Order::$table),
            Column::key('revision')->int()->default(1),
            Column::key('pdf_file'),
            Column::key('sent_to')->type('TEXT'),
            Column::key('sent_at')->datetime(),
            Column::key('total')->type('DECIMAL')->schema('length', '10,2'),
            Column::key('user_id')->int()->default(0),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_order' => ['index' => 'order_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('order_id')->number()->decimals(0),
            Field::key('revision')->number()->decimals(0),
            Field::key('pdf_file')->text()->sanitize(false),
            Field::key('sent_to')->text()->sanitize(false),
            Field::key('sent_at')->date(),
            Field::key('total')->number()->decimals(2),
            Field::key('user_id')->number()->decimals(0),
        ];
    }
}
