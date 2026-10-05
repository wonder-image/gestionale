<?php

namespace Wonder\Plugin\Gestionale\Models\Promotions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Un coupon: un codice che il cliente scrive nel carrello per avere uno sconto
 * (percentuale o importo sul carrello), la spedizione gratuita o un credito.
 *
 * Il codice è scelto dal commerciante e **unico**: il confronto non distingue
 * maiuscole e minuscole, così `SAVE10` e `save10` sono lo stesso coupon. Un
 * limite a zero vuol dire senza limite; `ends_at` vuoto, senza fine. Lo stato
 * non si scrive: si ricava da `active` e dalle date. A chi si applica sta
 * nelle quattro tabelle collegate (come le campagne) e i clienti assegnati in
 * `CouponCustomer`; `applies_to_all` rende inutile la selezione dei prodotti.
 *
 * Non si sincronizza: è lavoro del commerciante.
 */
final class Coupon extends Model
{
    public static string $table = 'gst_coupons';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-ticket-perforated';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('code')->length(100)->null(false)->unique(),
            Column::key('name'),
            Column::key('discount_type')->enum(['percent', 'amount', 'free_shipping', 'store_credit'])->default('percent'),
            Columns::decimal('discount_value', '12,2'),
            Columns::decimal('min_order_amount', '12,2'),
            Column::key('applies_to_all')->enum(['true', 'false'])->default('false'),
            Column::key('exclude_discounted_products')->enum(['true', 'false'])->default('false'),
            Column::key('first_order_only')->enum(['true', 'false'])->default('false'),
            Column::key('usage_limit')->int()->default(0),
            Column::key('usage_limit_per_customer')->int()->default(0),
            Column::key('starts_at')->datetime(),
            Column::key('ends_at')->datetime(),
            Column::key('applies_online')->enum(['true', 'false'])->default('true'),
            Column::key('applies_office')->enum(['true', 'false'])->default('false'),
            Column::key('applies_pos')->enum(['true', 'false'])->default('false'),
            Column::key('active')->enum(['true', 'false'])->default('true'),
            Column::key('note')->type('TEXT'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->sanitize(false),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('discount_type')->text()->sanitize(false),
            Field::key('discount_value')->number()->decimals(2),
            Field::key('min_order_amount')->number()->decimals(2),
            Field::key('applies_to_all')->text()->sanitize(false),
            Field::key('exclude_discounted_products')->text()->sanitize(false),
            Field::key('first_order_only')->text()->sanitize(false),
            Field::key('usage_limit')->number()->decimals(0),
            Field::key('usage_limit_per_customer')->number()->decimals(0),
            Field::key('starts_at')->date(),
            Field::key('ends_at')->date(),
            Field::key('applies_online')->text()->sanitize(false),
            Field::key('applies_office')->text()->sanitize(false),
            Field::key('applies_pos')->text()->sanitize(false),
            Field::key('active')->text()->sanitize(false),
            Field::key('note')->text(),
        ];
    }
}
