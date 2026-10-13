<?php

namespace Wonder\Plugin\Gestionale\Models\Subscriptions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Un prezzo di un piano (G10 §1.1): «Mensile» a 19 €, «Annuale» a 190 €.
 *
 * `term_months` dice quanti mesi copre un addebito (1 per il mensile, 12 per
 * l'annuale); `commitment_months` a zero vuol dire nessun vincolo;
 * `trial_days` i giorni di prova gratuita. A fine vincolo, con
 * `renewal_plan_price_id`, l'abbonamento passa a quel prezzo.
 *
 * Il prezzo è IVA inclusa o esclusa come il listino base (G10.4). Un prezzo
 * usato da un abbonamento, anche chiuso, non si elimina: si spegne con
 * `active`. Il codice (`ppr_`) è il riferimento del prezzo verso il sito e il
 * dataLayer.
 */
final class PlanPrice extends Model
{
    public static string $table = 'gst_plan_prices';
    public static string $folder = 'gestionale/subscriptions';
    public static string $icon = 'bi bi-tag';

    public const INTERVALS = ['month', 'year'];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code', 'price', 'sale_price']),
            Column::key('plan_id')->int()->null(false)->foreign(Plan::$table),
            Column::key('name'),
            Column::key('billing_interval')->enum(static::INTERVALS)->default('month'),
            Column::key('term_months')->int()->default(1),
            Column::key('commitment_months')->int()->default(0),
            Column::key('trial_days')->int()->default(0),
            Column::key('renewal_plan_price_id')->int()->default(0),
            Column::key('active')->enum(['true', 'false'])->default('true'),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_plan' => ['index' => 'plan_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::PLAN_PRICE),
            Field::key('plan_id')->number()->decimals(0),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('billing_interval')->text()->sanitize(false),
            Field::key('term_months')->number()->decimals(0),
            Field::key('commitment_months')->number()->decimals(0),
            Field::key('trial_days')->number()->decimals(0),
            Field::key('price')->number()->decimals(2),
            Field::key('sale_price')->number()->decimals(2),
            Field::key('renewal_plan_price_id')->number()->decimals(0),
            Field::key('active')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
