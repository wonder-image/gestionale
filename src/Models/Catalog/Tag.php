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
            Column::key('position')->int(),
            Column::key('visible')->enum(['true', 'false'])->default('true'),
            // L'ordine degli articoli nel sito ({@see \Wonder\Plugin\Gestionale\Support\Catalog\Placements}).
            Column::key('sort_mode')->enum(['catalog', 'position', 'newest', 'name', 'price_asc', 'price_desc'])->default('catalog'),
            Column::key('show_placements')->type('TINYINT')->length(1)->null(false)->default('0'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::TAG),
            Field::key('name')->text()->sanitize(),
            Field::key('slug')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('image')
                ->image()
                ->extensions(['png', 'jpg', 'jpeg', 'webp'])
                ->maxSize(2)
                ->maxFile(1)
                ->dir('/catalogo/tag/')
                ->name('{slug}'),
            Field::key('position')->number()->decimals(0),
            Field::key('visible')->text()->sanitize(false),
            Field::key('sort_mode')->text()->sanitize(false),
            Field::key('show_placements')->number()->decimals(0),
        ];
    }
}
