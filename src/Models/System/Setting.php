<?php

namespace Wonder\Plugin\Gestionale\Models\System;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Sql\TableSchema as Column;

/**
 * Impostazioni tecniche e fiscali: una riga sola, scritta da `admin` in locale
 * e portata in produzione dal deploy.
 *
 * Qui stanno le scelte che si fanno una volta con il commercialista o in fase
 * di installazione. Quello che il commerciante cambia ogni giorno sta in
 * `MerchantSetting`: è la regola di 8.2, e ogni sotto-progetto aggiunge le sue
 * colonne alla riga giusta.
 */
final class Setting extends Model
{
    public static string $table = 'gst_settings';
    public static string $folder = 'gestionale/settings';
    public static string $icon = 'bi bi-sliders';

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::singleton()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('invoice_provider')->length(50),
            Column::key('tax_regime')->length(10)->default('RF01'),
            Column::key('vat_collectability')->length(5)->default('I'),
            Column::key('transmitter_country')->length(2)->default('IT'),
            Column::key('transmitter_fiscal_code')->length(50),
            Column::key('catalog_prices_include_tax')->enum(['true', 'false'])->default('true'),
            Column::key('fallback_tax_id')->int()->foreign(Tax::$table),
            Column::key('shipping_tax_id')->int()->foreign(Tax::$table),
            Column::key('invoice_numeration')->length(20)->default('WEB'),
            Column::key('stamp_duty_auto')->enum(['true', 'false'])->default('true'),
            Column::key('fiscal_confirmed_at')->datetime(),
            Column::key('developer_error_emails')->type('TEXT'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('invoice_provider')->text()->sanitize(false),
            Field::key('tax_regime')->text()->sanitize(false),
            Field::key('vat_collectability')->text()->sanitize(false),
            Field::key('transmitter_country')->text()->sanitize(false),
            Field::key('transmitter_fiscal_code')->text()->sanitize(false),
            Field::key('catalog_prices_include_tax')->text()->sanitize(false),
            Field::key('fallback_tax_id')->number()->decimals(0),
            Field::key('shipping_tax_id')->number()->decimals(0),
            Field::key('invoice_numeration')->text()->sanitize(false),
            Field::key('stamp_duty_auto')->text()->sanitize(false),
            Field::key('fiscal_confirmed_at')->date(),
            Field::key('developer_error_emails')->text(),
        ];
    }

    /** La riga unica delle impostazioni, array vuoto se non c'è ancora. */
    public static function current(): array
    {
        $row = static::find(['id' => 1], 1);

        return is_array($row) ? $row : [];
    }
}
