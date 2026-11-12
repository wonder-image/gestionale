<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Catalog\HasCatalogMedia;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Tag del catalogo: un'etichetta trasversale alle categorie ("novità",
 * "saldi", "made in Italy").
 *
 * Non si sincronizza tra ambienti, come il resto del catalogo.
 *
 * L'immagine è un media della libreria del core: `image_id` ne tiene l'id
 * e si legge con {@see imageUrl()} o {@see HasCatalogMedia::media()}.
 */
final class Tag extends Model
{
    use HasCatalogMedia;

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
            // Deprecata: il file di prima della libreria Media. La migrazione
            // la svuota; si legge solo come ripiego ({@see HasCatalogMedia}).
            Column::key('image')->json(),
            ...static::mediaTableColumns(),
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
            ...static::mediaDataFields(),
            Field::key('position')->number()->decimals(0),
            Field::key('visible')->text()->sanitize(false),
            Field::key('sort_mode')->text()->sanitize(false),
            Field::key('show_placements')->number()->decimals(0),
        ];
    }

    public static function mediaFields(): array
    {
        return [
            'image' => [
                'columns' => ['image' => 'image_id'],
                'slug' => 'tag-{slug}',
                'alt' => '{name}',
                // La cartella della pagina dei tag più quella del campo.
                'legacy' => 'app/gestionale/tag/catalogo/tag',
            ],
        ];
    }

    /** L'indirizzo dell'immagine di un tag; vuoto se non ce l'ha. */
    public static function imageUrl(array $row): string
    {
        return static::mediaUrl($row, 'image');
    }
}
