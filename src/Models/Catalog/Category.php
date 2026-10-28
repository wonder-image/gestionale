<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;
use Wonder\Plugin\Gestionale\Support\ForgetsRequestMemo;

/**
 * Categoria del catalogo, con un albero a profondità libera.
 *
 * L'albero è solo `parent_id`: ordinamento, indentazione e controllo dei cicli
 * stanno in `Support\Catalog\CategoryTree`, che è pura e si prova senza
 * database. Il catalogo non si sincronizza tra ambienti.
 */
final class Category extends Model
{
    use ForgetsRequestMemo;

    public const MEMO_GROUP = 'categories';

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
            // L'ordine degli articoli nel sito ({@see \Wonder\Plugin\Gestionale\Support\Catalog\Placements}).
            Column::key('sort_mode')->enum(['catalog', 'position', 'newest', 'name', 'price_asc', 'price_desc'])->default('catalog'),
            Column::key('show_placements')->type('TINYINT')->length(1)->null(false)->default('0'),
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
            Field::key('name')->text()->sanitize(),
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
            Field::key('sort_mode')->text()->sanitize(false),
            Field::key('show_placements')->number()->decimals(0),
        ];
    }
}
