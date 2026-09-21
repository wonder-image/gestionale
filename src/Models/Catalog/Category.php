<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Categoria del catalogo, con un albero a profondità libera.
 *
 * L'albero è solo `parent_id`: ordinamento, indentazione e controllo dei cicli
 * stanno in `Support\Catalog\CategoryTree`, che è pura e si prova senza
 * database. Il catalogo non si sincronizza tra ambienti.
 */
final class Category extends Model
{
    public static string $table = 'gst_categories';
    public static string $folder = 'gestionale/categories';
    public static string $icon = 'bi bi-diagram-3';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('parent_id')->int()->foreign(self::$table),
            Column::key('name'),
            Column::key('slug')->length(150)->unique(),
            Column::key('image')->json(),
            Column::key('description')->type('TEXT'),
            Column::key('position')->int(),
            Column::key('visible')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_parent' => ['index' => 'parent_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::CATEGORY),
            Field::key('parent_id')->number()->decimals(0),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('slug')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('image')
                ->image()
                ->extensions(['png', 'jpg', 'jpeg', 'webp'])
                ->maxSize(2)
                ->maxFile(1)
                ->dir('/catalogo/categorie/')
                ->name('{slug}'),
            Field::key('description')->text(),
            Field::key('position')->number()->decimals(0),
            Field::key('visible')->text()->sanitize(false),
        ];
    }
}
