<?php

namespace Wonder\Plugin\Gestionale\Models\Pricing;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Un listino cliente (`customer_price_lists`, architettura 4.6): prezzi propri
 * per alcuni prodotti, con scaglioni di quantità in `PriceListItem`.
 *
 * Si assegna al cliente con `contacts.price_list_id`; l'ordine si ricorda il
 * listino usato in `orders.price_list_id`. `valid_from` e `valid_to` vuoti
 * vogliono dire senza limite; fuori dalle date, spento o in un'altra valuta
 * il listino non vale e le righe tornano al prezzo di catalogo
 * (`Support\Pricing\PriceLists`).
 *
 * Non si sincronizza: è lavoro del commerciante.
 */
final class PriceList extends Model
{
    public static string $table = 'gst_price_lists';
    public static string $folder = 'gestionale/pricing';
    public static string $icon = 'bi bi-journal-text';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('name'),
            Column::key('currency')->length(3)->default('EUR'),
            Column::key('prices_include_tax')->enum(['true', 'false'])->default('true'),
            Column::key('valid_from')->date(),
            Column::key('valid_to')->date(),
            Column::key('active')->enum(['true', 'false'])->default('true'),
            Column::key('note')->type('TEXT'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::PRICE_LIST),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('currency')->text()->sanitize(false),
            Field::key('prices_include_tax')->text()->sanitize(false),
            Field::key('valid_from')->date(),
            Field::key('valid_to')->date(),
            Field::key('active')->text()->sanitize(false),
            Field::key('note')->text(),
        ];
    }
}
