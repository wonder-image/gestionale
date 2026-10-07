<?php

namespace Wonder\Plugin\Gestionale\Models\System;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Impostazioni del commerciante: una riga sola, che si cambia dove si lavora.
 *
 * Non si sincronizza: sono scelte di chi usa il gestionale ogni giorno, e un
 * deploy non deve riportarle indietro.
 *
 * `merchant_notification_emails` sono gli indirizzi delle **notifiche** del
 * negozio — un ordine da controllare, una spedizione ferma — non degli errori
 * tecnici, che vanno a chi sviluppa.
 *
 * `low_stock_emails` sono i destinatari dell'email dei prodotti sotto scorta
 * minima: vuoto, l'email non parte e gli avvisi aspettano.
 *
 * `font_*` sono i font del negozio online per area (chiavi di `WebFonts`);
 * vuoto vuol dire come il sito.
 */
final class MerchantSetting extends Model
{
    public static string $table = 'gst_merchant_settings';
    public static string $folder = 'gestionale/merchant-settings';
    public static string $icon = 'bi bi-shop';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('merchant_notification_emails')->type('TEXT'),
            Column::key('low_stock_emails')->type('TEXT'),
            Column::key('font_auth')->length(40),
            Column::key('font_account')->length(40),
            Column::key('font_cart')->length(40),
            Column::key('font_checkout')->length(40)->default('inter'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('merchant_notification_emails')->text(),
            Field::key('low_stock_emails')->text(),
            Field::key('font_auth')->text()->sanitize(false),
            Field::key('font_account')->text()->sanitize(false),
            Field::key('font_cart')->text()->sanitize(false),
            Field::key('font_checkout')->text()->sanitize(false),
        ];
    }

    /** La riga unica, array vuoto se non c'è ancora. */
    public static function current(): array
    {
        $row = static::find(['id' => 1], 1);

        return is_array($row) ? $row : [];
    }
}
