<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Marchio dei prodotti.
 *
 * Il catalogo non si sincronizza tra ambienti: è il lavoro del commerciante,
 * si scrive dove si lavora. Aliquote e impostazioni sono configurazione e
 * viaggiano con il deploy, i prodotti no.
 */
final class Brand extends Model
{
    public static string $table = 'gst_brands';
    public static string $folder = 'gestionale/brands';
    public static string $icon = 'bi bi-award';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('name'),
            Column::key('slug')->length(150)->unique(),
            Column::key('logo')->json(),
            Column::key('description')->type('TEXT'),
            Column::key('position')->int(),
            Column::key('visible')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::BRAND),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('slug')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('logo')
                ->image()
                ->extensions(['png', 'jpg', 'jpeg', 'webp', 'svg'])
                ->maxSize(2)
                ->maxFile(1)
                ->dir('/catalogo/marchi/')
                ->name('{slug}'),
            Field::key('description')->text(),
            Field::key('position')->number()->decimals(0),
            Field::key('visible')->text()->sanitize(false),
        ];
    }
}
