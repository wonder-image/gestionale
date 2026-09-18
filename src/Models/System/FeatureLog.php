<?php

namespace Wonder\Plugin\Gestionale\Models\System;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Storico dei cambi di stato delle funzionalità: chi ha sbloccato o bloccato
 * cosa, quando e perché.
 */
final class FeatureLog extends Model
{
    public static string $table = 'gst_feature_logs';
    public static string $folder = 'gestionale/features';
    public static string $icon = 'bi bi-clock-history';

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('feature_id')->int()->null(false)->foreign(Feature::$table),
            ...static::sqlColumnsFromDataSchema(['from_value', 'to_value', 'source', 'note']),
            Column::key('user_id')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_feature' => ['index' => 'feature_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('feature_id')->number()->decimals(0),
            Field::key('from_value')->text()->sanitize(false),
            Field::key('to_value')->text()->sanitize(false),
            Field::key('source')->text()->sanitize(false),
            Field::key('user_id')->number()->decimals(0),
            Field::key('note')->text(),
        ];
    }
}
