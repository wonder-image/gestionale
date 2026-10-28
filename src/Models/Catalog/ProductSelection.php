<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\MediaFileManager;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\ForgetsRequestMemo;
use Wonder\Sql\TableSchema as Column;

/**
 * Una selezione di prodotti scelta a mano per il sito: «In evidenza» della
 * home, il prodotto del mese, una vetrina di Natale.
 *
 * Il sito la cerca per `slug`, che nasce dal nome e non cambia più. Titolo,
 * sottotitolo, testo e immagine sono quelli del blocco che la mostra; gli
 * articoli e le varianti, nel loro ordine, stanno in `gst_product_placements`
 * con `context_type = selection`. `sort_mode` = `manual` li tiene nell'ordine
 * dato; gli altri valori li riordinano ({@see \Wonder\Plugin\Gestionale\Support\Catalog\Placements::SORT_MODES}).
 *
 * Non si sincronizza, come il resto del catalogo.
 */
final class ProductSelection extends Model
{
    use ForgetsRequestMemo;

    public const MEMO_GROUP = 'placements';

    public static string $table = 'gst_product_selections';
    public static string $folder = 'gestionale/selections';
    public static string $icon = 'bi bi-collection';

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
            Column::key('title'),
            Column::key('subtitle'),
            Column::key('text')->type('TEXT'),
            Column::key('image')->json(),
            Column::key('sort_mode')->enum(['manual', 'newest', 'name', 'price_asc', 'price_desc'])->default('manual'),
            Column::key('visible')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::SELECTION),
            Field::key('name')->text()->sanitizeFirst(),
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
            Field::key('sort_mode')->text()->sanitize(false),
            Field::key('visible')->text()->sanitize(false),
        ];
    }

    /** L'indirizzo dell'immagine di una riga; vuoto se non ce l'ha. */
    public static function imageUrl(array $row): string
    {
        $field = static::dataFields()['image'] ?? null;
        $schema = is_object($field) && method_exists($field, 'getSchema') ? (array) $field->getSchema() : [];

        return static::storedFileUrls(MediaFileManager::decodeStoredFiles($row['image'] ?? ''), $schema)[0] ?? '';
    }
}
