<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\MediaFileManager;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Catalog\Placements;
use Wonder\Plugin\Gestionale\Support\Catalog\SelectionBlocks;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\ForgetsRequestMemo;
use Wonder\Sql\TableSchema as Column;

/**
 * Una selezione di prodotti per il sito: «In evidenza» della home, il
 * prodotto del mese, una vetrina di Natale.
 *
 * Il sito la cerca per `slug`, che nasce dal nome e non cambia più. `block`
 * dice quale blocco del sito la mostra ({@see SelectionBlocks}): titolo,
 * sottotitolo, testo, immagine e sfondo sono colonne, i campi in più del
 * blocco stanno in `block_meta`. `source` dice da dove vengono gli articoli:
 * `manual` sono le righe di `gst_product_placements` con
 * `context_type = selection`, le altre fonti li scelgono dal catalogo fino a
 * `max_items` ({@see Placements::SELECTION_SOURCES}), filtrati dagli slug JSON
 * di categorie, tag o marchi e senza `excluded_model_ids`. `sort_mode` =
 * `manual` li tiene nell'ordine dato (o della fonte); gli altri valori li
 * ordinano.
 *
 * Il sito la chiama per slug, quindi la selezione nasce in locale e arriva
 * con il deploy, con gli stessi id: fuori dal locale si modificano solo le
 * colonne di {@see SITE_COLUMNS} e gli articoli scelti a mano, che restano
 * dove stanno.
 */
final class ProductSelection extends Model
{
    use ForgetsRequestMemo;

    public const MEMO_GROUP = 'placements';

    public static string $table = 'gst_product_selections';
    // La cartella della pagina delle selezioni: il form salva le foto lì.
    public static string $folder = 'app/gestionale/selezioni';
    public static string $icon = 'bi bi-collection';

    /**
     * Le colonne che si scrivono anche in produzione e non viaggiano con il
     * deploy: i testi e le foto del blocco, e gli esclusi, che sono id del
     * catalogo.
     */
    public const SITE_COLUMNS = ['title', 'subtitle', 'text', 'image', 'background', 'excluded_model_ids'];

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly()->exclude(self::SITE_COLUMNS);
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('name'),
            Column::key('slug')->length(150)->unique(),
            Column::key('title'),
            Column::key('subtitle'),
            Column::key('text')->type('TEXT'),
            Column::key('image')->json(),
            Column::key('background')->json(),
            Column::key('sort_mode')->enum(['manual', 'newest', 'name', 'price_asc', 'price_desc'])->default('manual'),
            // Il blocco del sito ({@see SelectionBlocks}) e i suoi campi in più.
            Column::key('block')->length(50)->default(SelectionBlocks::DEFAULT),
            Column::key('block_meta')->json(),
            // La fonte degli articoli ({@see Placements::SELECTION_SOURCES}) e i
            // suoi riferimenti: elenchi JSON di slug, senza chiave esterna.
            Column::key('source')->length(30)->default('manual'),
            Column::key('source_category_slugs')->json(),
            Column::key('source_tag_slugs')->json(),
            Column::key('source_brand_slugs')->json(),
            Column::key('excluded_model_ids')->json(),
            Column::key('max_items')->int()->default((string) Placements::SELECTION_MAX_ITEMS),
            Column::key('visible')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::SELECTION),
            Field::key('name')->text()->sanitize(),
            Field::key('slug')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('title')->text()->sanitize(),
            Field::key('subtitle')->text()->sanitize(),
            Field::key('text')->text()->richText(),
            Field::key('image')
                ->image()
                ->extensions(['png', 'jpg', 'jpeg', 'webp'])
                ->maxSize(4)
                ->maxFile(1)
                ->dir('/catalogo/selezioni/')
                ->name('{slug}'),
            Field::key('background')
                ->image()
                ->extensions(['png', 'jpg', 'jpeg', 'webp'])
                ->maxSize(4)
                ->maxFile(1)
                ->dir('/catalogo/selezioni/')
                ->name('{slug}-sfondo'),
            Field::key('sort_mode')->text()->sanitize(false),
            Field::key('block')->text()->sanitize(false),
            Field::key('block_meta')->json(),
            Field::key('source')->text()->sanitize(false),
            Field::key('source_category_slugs')->json(),
            Field::key('source_tag_slugs')->json(),
            Field::key('source_brand_slugs')->json(),
            Field::key('excluded_model_ids')->json(),
            Field::key('max_items')->number()->decimals(0),
            Field::key('visible')->text()->sanitize(false),
        ];
    }

    /** L'indirizzo dell'immagine di una riga (`image` o `background`); vuoto se non ce l'ha. */
    public static function imageUrl(array $row, string $column = 'image'): string
    {
        $field = static::dataFields()[$column] ?? null;
        $schema = is_object($field) && method_exists($field, 'getSchema') ? (array) $field->getSchema() : [];

        return static::storedFileUrls(MediaFileManager::decodeStoredFiles($row[$column] ?? ''), $schema)[0] ?? '';
    }
}
