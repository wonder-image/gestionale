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
 * `low_stock_emails` sono i destinatari dell'email dei prodotti sotto scorta
 * minima: vuoto, l'email non parte e gli avvisi aspettano.
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
            Column::key('low_stock_emails')->type('TEXT'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('low_stock_emails')->text(),
        ];
    }

    /** La riga unica, array vuoto se non c'è ancora. */
    public static function current(): array
    {
        $row = static::find(['id' => 1], 1);

        return is_array($row) ? $row : [];
    }
}
