<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Catalog\HasCatalogMedia;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Marchio dei prodotti.
 *
 * Il catalogo non si sincronizza tra ambienti: è il lavoro del commerciante,
 * si scrive dove si lavora. Aliquote e impostazioni sono configurazione e
 * viaggiano con il deploy, i prodotti no.
 *
 * Il logo è un media della libreria del core, in due colonne perché un campo
 * media ha un tipo solo: `logo_svg_id` per il logo vettoriale (un'icona svg),
 * `logo_id` per quello raster (un'immagine). {@see logoUrl()} dà il
 * vettoriale se c'è, altrimenti il raster.
 */
final class Brand extends Model
{
    use HasCatalogMedia;

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
            // Deprecata: il file di prima della libreria Media. La migrazione
            // la svuota; si legge solo come ripiego ({@see HasCatalogMedia}).
            Column::key('logo')->json(),
            ...static::mediaTableColumns(),
            Column::key('description')->type('TEXT'),
            Column::key('position')->int(),
            Column::key('visible')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::BRAND),
            Field::key('name')->text()->sanitize(),
            Field::key('slug')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('logo')
                ->image()
                ->extensions(['png', 'jpg', 'jpeg', 'webp', 'svg'])
                ->maxSize(2)
                ->maxFile(1)
                ->dir('/catalogo/marchi/')
                ->name('{slug}'),
            ...static::mediaDataFields(),
            Field::key('description')->text(),
            Field::key('position')->number()->decimals(0),
            Field::key('visible')->text()->sanitize(false),
        ];
    }

    public static function mediaFields(): array
    {
        return [
            'logo' => [
                // Il vettoriale si legge per primo.
                'columns' => ['icon' => 'logo_svg_id', 'image' => 'logo_id'],
                'slug' => 'logo-{slug}',
                'alt' => 'Logo {name}',
                // La cartella della pagina dei marchi più quella del campo.
                'legacy' => 'app/gestionale/marchi/catalogo/marchi',
            ],
        ];
    }

    /** L'indirizzo del logo: il vettoriale se c'è, altrimenti il raster; vuoto se manca. */
    public static function logoUrl(array $row): string
    {
        return static::mediaUrl($row, 'logo');
    }
}
