<?php

namespace Wonder\Plugin\Gestionale\Models\Payments;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Una rata di una condizione di pagamento: dopo quanti giorni, se a fine mese,
 * quale percentuale del totale.
 *
 * «RiBa 30/60 gg fine mese» ne ha due: 30 giorni a fine mese al 50%, 60 giorni
 * a fine mese al 50%. Le percentuali di una condizione sommano a 100
 * (`PaymentTermRules`); l'ultima rata prende il resto dell'arrotondamento
 * (`PaymentSchedule`).
 */
final class PaymentTermInstallment extends Model
{
    public static string $table = 'gst_payment_term_installments';
    public static string $folder = 'gestionale/payments';
    public static string $icon = 'bi bi-calendar3';

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('payment_term_id')->int()->null(false)->foreign(PaymentTerm::$table),
            Column::key('days')->int()->default(0),
            Column::key('end_of_month')->enum(['true', 'false'])->default('false'),
            Columns::decimal('percentage', '5,2'),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_payment_term' => ['index' => 'payment_term_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('payment_term_id')->number()->decimals(0),
            Field::key('days')->number()->decimals(0),
            Field::key('end_of_month')->text()->sanitize(false),
            Field::key('percentage')->number()->decimals(2),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
