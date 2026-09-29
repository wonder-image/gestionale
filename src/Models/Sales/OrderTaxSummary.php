<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * I riepiloghi IVA dell'ordine: una riga per aliquota e natura, come li
 * produce `Support\Tax\TaxTotals` (4.5).
 *
 * Si salvano perché l'imposta si calcola sul totale imponibile di ogni
 * aliquota, non riga per riga: è la regola dei riepiloghi FatturaPA, e sono
 * questi numeri che finiranno in fattura senza essere ricalcolati.
 */
final class OrderTaxSummary extends Model
{
    public static string $table = 'gst_order_tax_summaries';
    public static string $folder = 'gestionale/sales';
    public static string $icon = 'bi bi-percent';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('order_id')->int()->null(false)->foreign(Order::$table),
            ...static::sqlColumnsFromDataSchema(['rate', 'taxable', 'tax', 'total']),
            Column::key('nature')->length(10),
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
            Field::key('rate')->number()->decimals(2),
            Field::key('nature')->text()->sanitize(false),
            Field::key('taxable')->number()->decimals(2),
            Field::key('tax')->number()->decimals(2),
            Field::key('total')->number()->decimals(2),
        ];
    }
}
