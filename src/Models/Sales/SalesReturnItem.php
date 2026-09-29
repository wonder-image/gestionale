<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Le righe del reso (4.10).
 *
 * Ogni riga punta alla riga venduta, non al prodotto: è da lì che arrivano
 * prezzo, aliquota e — quando ci sarà G3 — lotto e fornitore con cui la merce
 * era uscita, perché deve rientrare sugli stessi.
 *
 * `restock` decide se la merce torna in magazzino: il servizio dei resi lo
 * propone spento per `damaged` e `defective`, ma resta una scelta di chi
 * controlla la merce.
 */
final class SalesReturnItem extends Model
{
    public static string $table = 'gst_sales_return_items';
    public static string $folder = 'gestionale/sales';
    public static string $icon = 'bi bi-box-arrow-in-left';

    public const REASONS = [
        'damaged', 'defective', 'wrong_item', 'not_as_described',
        'changed_mind', 'wrong_size', 'other',
    ];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('sales_return_id')->int()->null(false)->foreign(SalesReturn::$table),
            Column::key('order_item_id')->int()->null(false)->foreign(OrderItem::$table),
            Columns::decimal('quantity'),
            Column::key('reason')->enum(static::REASONS)->default('other'),
            Column::key('restock')->enum(['true', 'false'])->default('true'),
            Column::key('note')->type('TEXT'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_return' => ['index' => 'sales_return_id'],
            'ind_order_item' => ['index' => 'order_item_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('sales_return_id')->number()->decimals(0),
            Field::key('order_item_id')->number()->decimals(0),
            Field::key('quantity')->number()->decimals(3),
            Field::key('reason')->text()->sanitize(false),
            Field::key('restock')->text()->sanitize(false),
            Field::key('note')->text(),
        ];
    }
}
