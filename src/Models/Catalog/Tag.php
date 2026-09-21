<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Tag del catalogo: un'etichetta trasversale alle categorie ("novità",
 * "saldi", "made in Italy").
 *
 * Non si sincronizza tra ambienti, come il resto del catalogo.
 */
final class Tag extends Model
{
    public static string $table = 'gst_tags';
    public static string $folder = 'gestionale/tags';
    public static string $icon = 'bi bi-tag';

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
            Column::key('image')->json(),
            Column::key('visible')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::TAG),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('slug')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('image')
                ->image()
                ->extensions(['png', 'jpg', 'jpeg', 'webp'])
                ->maxSize(2)
                ->maxFile(1)
                ->dir('/catalogo/tag/')
                ->name('{slug}'),
            Field::key('visible')->text()->sanitize(false),
        ];
    }
}
