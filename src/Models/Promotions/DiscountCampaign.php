<?php

namespace Wonder\Plugin\Gestionale\Models\Promotions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Una campagna di sconto: un prezzo che cambia da solo, in un periodo, per
 * tutto il catalogo o per una selezione di prodotti.
 *
 * Lo sconto è una percentuale o un importo sul prezzo base. `ends_at` vuoto
 * vuol dire senza fine. Lo stato (programmata, in corso, terminata,
 * disattivata) non si scrive: si ricava da `active` e dalle date. La selezione
 * dei prodotti sta nelle quattro tabelle collegate; `applies_to_all` la
 * rende inutile.
 *
 * Non si sincronizza: è lavoro del commerciante.
 */
final class DiscountCampaign extends Model
{
    public static string $table = 'gst_discount_campaigns';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-percent';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('name'),
            Column::key('discount_type')->enum(['percent', 'amount'])->default('percent'),
            Columns::decimal('discount_value', '12,2'),
            Column::key('starts_at')->datetime(),
            Column::key('ends_at')->datetime(),
            Column::key('active')->enum(['true', 'false'])->default('true'),
            Column::key('applies_to_all')->enum(['true', 'false'])->default('false'),
            Column::key('exclude_sale_products')->enum(['true', 'false'])->default('false'),
            Column::key('applies_online')->enum(['true', 'false'])->default('true'),
            Column::key('applies_office')->enum(['true', 'false'])->default('false'),
            Column::key('applies_pos')->enum(['true', 'false'])->default('false'),
            Column::key('note')->type('TEXT'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::DISCOUNT_CAMPAIGN),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('discount_type')->text()->sanitize(false),
            Field::key('discount_value')->number()->decimals(2),
            Field::key('starts_at')->date(),
            Field::key('ends_at')->date(),
            Field::key('active')->text()->sanitize(false),
            Field::key('applies_to_all')->text()->sanitize(false),
            Field::key('exclude_sale_products')->text()->sanitize(false),
            Field::key('applies_online')->text()->sanitize(false),
            Field::key('applies_office')->text()->sanitize(false),
            Field::key('applies_pos')->text()->sanitize(false),
            Field::key('note')->text(),
        ];
    }
}
