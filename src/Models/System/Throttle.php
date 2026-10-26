<?php

namespace Wonder\Plugin\Gestionale\Models\System;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Sql\TableSchema as Column;

/**
 * I contatori dei limiti a finestra fissa (Support\Throttle::attempt): una
 * riga per gruppo e chiave, la chiave è lo sha256 di «gruppo|chiave».
 */
final class Throttle extends Model
{
    public static string $table = 'gst_throttles';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('throttle_key')->type('CHAR')->length(64)->null(false)->unique(),
            Column::key('hits')->int()->null(false)->default('0'),
            Column::key('window_start')->int()->null(false)->default('0'),
        ];
    }

    public static function dataSchema(): array
    {
        return [];
    }
}
