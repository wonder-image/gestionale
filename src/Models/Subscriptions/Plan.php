<?php

namespace Wonder\Plugin\Gestionale\Models\Subscriptions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Un piano di abbonamento (G10 §1): nome, descrizione, tipo fiscale e se si
 * vede nella pagina pubblica dei piani. I prezzi stanno in `PlanPrice`, i
 * limiti in `PlanFeature`.
 *
 * Non si sincronizza: come i coupon, è lavoro del commerciante, e gli ID dei
 * prodotti in Stripe sono di un ambiente solo.
 */
final class Plan extends Model
{
    public static string $table = 'gst_plans';
    public static string $folder = 'gestionale/subscriptions';
    public static string $icon = 'bi bi-arrow-repeat';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('slug')->length(150)->unique(),
            Column::key('name'),
            Column::key('description')->type('TEXT'),
            Column::key('tax_category_id')->int()->foreign(TaxCategory::$table),
            Column::key('visible_online')->enum(['true', 'false'])->default('true'),
            Column::key('active')->enum(['true', 'false'])->default('true'),
            Column::key('position')->int(),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::PLAN),
            Field::key('slug')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('description')->text(),
            Field::key('tax_category_id')->number()->decimals(0),
            Field::key('visible_online')->text()->sanitize(false),
            Field::key('active')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
