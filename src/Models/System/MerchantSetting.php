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
            Column::key('merchant_error_emails')->type('TEXT'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('merchant_error_emails')->text(),
        ];
    }

    /** La riga unica, array vuoto se non c'è ancora. */
    public static function current(): array
    {
        $row = static::find(['id' => 1], 1);

        return is_array($row) ? $row : [];
    }
}
