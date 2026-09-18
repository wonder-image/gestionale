<?php

namespace Wonder\Plugin\Gestionale\Models\System;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Stato di una funzionalità. Il catalogo (nome, area, dipendenze) sta nel
 * codice: qui c'è solo se è sbloccata, da chi e quando.
 */
final class Feature extends Model
{
    public static string $table = 'gst_features';
    public static string $folder = 'gestionale/features';
    public static string $icon = 'bi bi-toggles';

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('feature_key')->length(100)->null(false)->unique(),
            Column::key('enabled')->enum(['true', 'false'])->default('false'),
            ...static::sqlColumnsFromDataSchema(['changed_at']),
            Column::key('changed_by')->int(),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('feature_key')->text()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('enabled')->text()->sanitize(false),
            Field::key('changed_at')->date(),
            Field::key('changed_by')->number()->decimals(0),
        ];
    }
}
