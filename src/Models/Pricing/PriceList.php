<?php

namespace Wonder\Plugin\Gestionale\Models\Pricing;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Columns;
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
 * `price_rule` e `percent` danno la regola in percentuale (#162): ricarico o
 * sconto sul prezzo base per i prodotti senza una riga propria nel listino.
 * Il listino si può anche assegnare a uno o più canali (`PriceListChannel`).
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
            Column::key('price_rule')->enum(['none', 'markup', 'discount'])->default('none'),
            Columns::decimal('percent', '5,2'),
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
            Field::key('name')->text()->sanitize(),
            Field::key('currency')->text()->sanitize(false),
            Field::key('prices_include_tax')->text()->sanitize(false),
            Field::key('price_rule')->text()->sanitize(false),
            Field::key('percent')->number()->decimals(2),
            Field::key('valid_from')->date(),
            Field::key('valid_to')->date(),
            Field::key('active')->text()->sanitize(false),
            Field::key('note')->text(),
        ];
    }
}
