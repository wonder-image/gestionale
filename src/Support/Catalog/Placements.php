<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductPlacement;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;

/**
 * L'ordine degli articoli negli elenchi del sito.
 *
 * Ogni elenco è un contesto: una selezione, una categoria o un tag. Dentro un
 * contesto conta prima chi vende, poi una regola:
 *
 * - le righe di `gst_product_placements` del contesto, nel loro ordine:
 *   per la selezione sono il contenuto, per categoria e tag gli articoli
 *   «in evidenza», che vanno in cima;
 * - gli altri seguono il `sort_mode` del contesto, e per categoria e tag
 *   «Come il catalogo» rimanda a `catalog_sort` delle impostazioni.
 *
 * Una riga indica un articolo (tutte le sue varianti) o una sola variante: nel
 * form è un valore solo, `m12` o `v34` ({@see itemKey()}), che si scompone in
 * `product_model_id` e `product_variant_id` prima di salvare.
 *
 * Qui stanno solo regole e scritture del gestionale; l'ordine lo applica il
 * sito (`ProductListing` dell'ecommerce), che legge le stesse costanti.
 */
final class Placements
{
    public const SELECTION = 'selection';
    public const CATEGORY = 'category';
    public const TAG = 'tag';
    public const CONTEXTS = [self::SELECTION, self::CATEGORY, self::TAG];

    /** Le regole del catalogo, nell'ordine del select. */
    public const CATALOG_SORT_MODES = [
        'position' => 'Ordine di inserimento',
        'newest' => 'Prima le novità',
        'name' => 'Nome, dalla A alla Z',
        'price_asc' => 'Prezzo, dal più basso',
        'price_desc' => 'Prezzo, dal più alto',
    ];

    /** Categoria e tag: la regola del catalogo o una loro. */
    public const CONTEXT_SORT_MODES = ['catalog' => 'Come il catalogo'] + self::CATALOG_SORT_MODES;

    /** La selezione: l'ordine dato, o una regola sugli articoli scelti. */
    public const SELECTION_SORT_MODES = [
        'manual' => 'Nell\'ordine dato',
        'newest' => 'Prima le novità',
        'name' => 'Nome, dalla A alla Z',
        'price_asc' => 'Prezzo, dal più basso',
        'price_desc' => 'Prezzo, dal più alto',
    ];

    /**
     * Da dove prende gli articoli una selezione: `manual` sono le sue righe,
     * le altre fonti li scelgono dal catalogo, fino a `max_items`, tolti gli
     * articoli esclusi. Con una fonte automatica `manual` fra gli ordini vuol
     * dire l'ordine della fonte: quello del catalogo, o della categoria o del
     * tag quando ne è scelto uno solo.
     */
    public const SELECTION_SOURCES = [
        'manual' => 'Scelti a mano',
        'catalog' => 'Catalogo',
        'offers' => 'Catalogo in offerta',
        'category' => 'Categorie',
        'tag' => 'Tag',
        'brand' => 'Marchi',
    ];

    /**
     * Le fonti che vogliono dei riferimenti, con la colonna JSON che li tiene:
     * slug, non id, perché la selezione arriva dal locale e il catalogo no.
     */
    public const SELECTION_SOURCE_REFS = [
        'category' => 'source_category_slugs',
        'tag' => 'source_tag_slugs',
        'brand' => 'source_brand_slugs',
    ];

    /** Le fonti di prima, che erano ordini: ora sono il catalogo. */
    private const LEGACY_SELECTION_SOURCES = ['newest' => 'catalog', 'novelty' => 'catalog'];

    /** Quanti articoli prende una fonte automatica, di serie e al massimo. */
    public const SELECTION_MAX_ITEMS = 8;
    public const SELECTION_MAX_ITEMS_LIMIT = 48;

    public static function selectionSource(mixed $value): string
    {
        $legacy = self::LEGACY_SELECTION_SOURCES[is_scalar($value) ? trim((string) $value) : ''] ?? null;

        return $legacy ?? self::known($value, self::SELECTION_SOURCES, 'manual');
    }

    /**
     * Gli id positivi, senza doppioni, da un JSON salvato o da un elenco
     * del form.
     *
     * @return list<int>
     */
    public static function selectionIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        $ids = [];

        foreach (is_array($value) ? $value : [] as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Gli slug, senza doppioni né vuoti, da un JSON salvato o da un elenco
     * del form.
     *
     * @return list<string>
     */
    public static function selectionSlugs(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        $slugs = [];

        foreach (is_array($value) ? $value : [] as $slug) {
            $slug = is_scalar($slug) ? trim((string) $slug) : '';

            if ($slug !== '') {
                $slugs[$slug] = $slug;
            }
        }

        return array_values($slugs);
    }

    /**
     * I riferimenti della fonte di una riga: gli slug di categorie, tag o marchi.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public static function selectionRefSlugs(array $row, string $source): array
    {
        $column = self::SELECTION_SOURCE_REFS[$source] ?? '';

        return $column !== '' ? self::selectionSlugs($row[$column] ?? null) : [];
    }

    /**
     * Gli articoli esclusi da una fonte automatica.
     *
     * @param array<string, mixed> $row
     * @return list<int>
     */
    public static function selectionExcluded(array $row): array
    {
        return self::selectionIds($row['excluded_model_ids'] ?? null);
    }

    public static function selectionMaxItems(mixed $value): int
    {
        $value = is_numeric($value) ? (int) $value : 0;

        return $value > 0 ? min(self::SELECTION_MAX_ITEMS_LIMIT, $value) : self::SELECTION_MAX_ITEMS;
    }

    public static function catalogSortMode(mixed $value): string
    {
        return self::known($value, self::CATALOG_SORT_MODES, 'position');
    }

    public static function contextSortMode(mixed $value): string
    {
        return self::known($value, self::CONTEXT_SORT_MODES, 'catalog');
    }

    public static function selectionSortMode(mixed $value): string
    {
        return self::known($value, self::SELECTION_SORT_MODES, 'manual');
    }

    /** `m12` per l'articolo 12, `v34` per la sola variante 34. */
    public static function itemKey(int $modelId, int $variantId = 0): string
    {
        return $variantId > 0 ? 'v'.$variantId : ($modelId > 0 ? 'm'.$modelId : '');
    }

    /**
     * La chiave del form scomposta nelle due colonne. Pura: la variante non si
     * legge dal database, quindi l'articolo di una variante resta 0 e lo
     * completa {@see cleanRows()}.
     *
     * @return array{product_model_id: int, product_variant_id: int}|null
     */
    public static function parseItem(mixed $value): ?array
    {
        if (!is_scalar($value) || !preg_match('/^([mv])([1-9][0-9]*)$/', trim((string) $value), $match)) {
            return null;
        }

        return $match[1] === 'm'
            ? ['product_model_id' => (int) $match[2], 'product_variant_id' => 0]
            : ['product_model_id' => 0, 'product_variant_id' => (int) $match[2]];
    }

    /**
     * Le righe di un elenco pronte da salvare: ogni `item` diventa articolo e
     * variante; vanno via le righe vuote, quelle di articoli o varianti che non
     * ci sono e i doppioni (vale la prima). L'ordine resta quello dato.
     *
     * `$variantModel` dice di quale articolo è una variante (null se non c'è):
     * di serie lo chiede al database, i test passano la loro.
     *
     * @param array<int|string, mixed> $rows
     * @param (callable(int): ?int)|null $variantModel
     * @return list<array<string, mixed>>
     */
    public static function cleanRows(array $rows, ?callable $variantModel = null): array
    {
        $variantModel ??= self::variantModel(...);
        $seen = [];
        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = self::parseItem($row['item'] ?? '')
                ?? self::parseItem(self::itemKey((int) ($row['product_model_id'] ?? 0), (int) ($row['product_variant_id'] ?? 0)));

            if ($item === null) {
                continue;
            }

            if ($item['product_variant_id'] > 0) {
                $item['product_model_id'] = (int) ($variantModel($item['product_variant_id']) ?? 0);

                if ($item['product_model_id'] <= 0) {
                    continue;
                }
            }

            $key = self::itemKey($item['product_model_id'], $item['product_variant_id']);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            unset($row['item']);
            $out[] = array_merge($row, $item);
        }

        return $out;
    }

    /**
     * Le voci del select «Articolo»: ogni articolo e, se ne ha più d'una, le
     * sue varianti sotto di lui. Gli articoli nascosti al sito ci sono, con il
     * segno: si possono mettere in elenco, il sito li mostrerà quando tornano
     * visibili.
     *
     * @return array<string, string>
     */
    public static function itemOptions(): array
    {
        try {
            $models = self::rows(ProductModel::find(['deleted' => 'false'], null, 'name', 'ASC'));
            $allVariants = self::rows(ProductVariant::find(['deleted' => 'false'], null, 'position', 'ASC'));
        } catch (Throwable) {
            // Senza database (test degli schemi) il select resta vuoto.
            return [];
        }

        $variants = [];

        foreach ($allVariants as $variant) {
            $variants[(int) ($variant['product_model_id'] ?? 0)][] = $variant;
        }

        $options = [];

        foreach ($models as $model) {
            $id = (int) ($model['id'] ?? 0);
            $name = self::decode((string) ($model['name'] ?? ''));
            $hidden = ($model['visible'] ?? 'true') !== 'true' || ($model['visible_online'] ?? 'true') !== 'true';
            $options[self::itemKey($id)] = $name.($hidden ? ' (nascosto al sito)' : '');

            if (count($variants[$id] ?? []) < 2) {
                continue;
            }

            foreach ($variants[$id] as $variant) {
                $options[self::itemKey($id, (int) $variant['id'])] = $name.' · '.self::decode((string) ($variant['name'] ?? ''))
                    .($hidden || ($variant['visible'] ?? 'true') !== 'true' ? ' (nascosto al sito)' : '');
            }
        }

        return $options;
    }

    /**
     * Aggiunge `item` alle righe salvate, per il select del form.
     *
     * @param array<int|string, mixed> $rows
     * @return list<array<string, mixed>>
     */
    public static function withItemKeys(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $row['item'] = self::itemKey((int) ($row['product_model_id'] ?? 0), (int) ($row['product_variant_id'] ?? 0));
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Le righe salvate di un elenco, nel loro ordine: `id` e la voce `item`,
     * per l'editor della scheda.
     *
     * @return list<array{id: int, item: string}>
     */
    public static function savedRows(string $type, int $id): array
    {
        if ($id <= 0 || !in_array($type, self::CONTEXTS, true)) {
            return [];
        }

        $out = [];

        foreach (self::withItemKeys(self::rows(ProductPlacement::find(['context_type' => $type, 'context_id' => $id, 'deleted' => 'false'], null, 'position', 'ASC'))) as $row) {
            if ($row['item'] !== '') {
                $out[] = ['id' => (int) $row['id'], 'item' => $row['item']];
            }
        }

        return $out;
    }

    /** Le righe di un contesto che se ne va: una categoria, un tag o una selezione eliminati. */
    public static function dropContext(string $type, int $id): void
    {
        if ($id <= 0 || !in_array($type, self::CONTEXTS, true)) {
            return;
        }

        foreach (self::rows(ProductPlacement::find(['context_type' => $type, 'context_id' => $id, 'deleted' => ['true', 'false']])) as $row) {
            ProductPlacement::delete((int) $row['id']);
        }
    }

    /**
     * Le righe di un articolo che se ne va, in tutti gli elenchi: la chiave
     * esterna non lascerebbe eliminare l'articolo né le sue varianti.
     */
    public static function dropForModel(int $modelId): void
    {
        if ($modelId <= 0) {
            return;
        }

        foreach (self::rows(ProductPlacement::find(['product_model_id' => $modelId, 'deleted' => ['true', 'false']])) as $row) {
            ProductPlacement::delete((int) $row['id']);
        }
    }

    private static function variantModel(int $variantId): ?int
    {
        $variant = ProductVariant::find(['id' => $variantId, 'deleted' => 'false'], 1);

        return is_array($variant) && (int) ($variant['product_model_id'] ?? 0) > 0 ? (int) $variant['product_model_id'] : null;
    }

    /** @param array<string, string> $known */
    private static function known(mixed $value, array $known, string $default): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return isset($known[$value]) ? $value : $default;
    }

    private static function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }

        return isset($result['id']) ? [$result] : array_values(array_filter($result, 'is_array'));
    }
}
