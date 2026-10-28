<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;

/**
 * Gli articoli per la casella «Cerca un articolo» di *Articoli collegati*:
 * per nome, per SKU dell'articolo o per SKU ed EAN di una sua versione.
 * Come la ricerca delle versioni di magazzino, ma restituisce articoli.
 *
 * Gli articoli nascosti al sito ci sono, con il segno: chi vende li può
 * collegare, il sito li mostrerà quando tornano visibili.
 *
 * {@see searchItems()} e {@see describeItems()} sono la stessa ricerca per
 * gli elenchi del sito ({@see Placements}): l'`id` è la voce `m12` / `v34`,
 * e sotto un articolo con più varianti ci sono anche le sue varianti.
 *
 * @phpstan-type Model array{id: int|string, label: string, sku: string, photo: string, hidden: bool}
 */
final class RelatedModelSearch
{
    public const LIMIT = 20;

    /** Quante parole della ricerca contano: le altre si ignorano. */
    private const WORDS = 5;

    /**
     * Al più 20 articoli che contengono ogni parola, per nome; mai `$excludeId`.
     *
     * @return list<Model>
     */
    public static function search(string $q, int $excludeId = 0): array
    {
        $words = array_slice(preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, self::WORDS);

        if ($words === []) {
            return [];
        }

        $versions = '`'.Product::$table.'`';
        $conditions = ["`deleted` = 'false'"];

        if ($excludeId > 0) {
            $conditions[] = '`id` <> '.$excludeId;
        }

        foreach ($words as $word) {
            $like = self::like($word);
            $conditions[] = '(`name` LIKE '.$like.' OR `sku` LIKE '.$like
                .' OR `id` IN (SELECT p.`product_model_id` FROM '.$versions.' p WHERE (p.`sku` LIKE '.$like.' OR p.`ean` LIKE '.$like.") AND p.`deleted` = 'false'))";
        }

        $ids = array_map('intval', array_column(self::rows(ProductModel::find(implode(' AND ', $conditions), self::LIMIT, 'name', 'ASC')), 'id'));

        return array_values(self::describe($ids));
    }

    /**
     * Gli articoli dati, nel loro ordine; quelli eliminati non ci sono.
     *
     * @param list<int|string> $ids
     * @return array<int, Model>
     */
    public static function describe(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $found = [];

        foreach (self::rows(ProductModel::find('`id` IN ('.implode(', ', $ids).") AND `deleted` = 'false'")) as $row) {
            $found[(int) $row['id']] = $row;
        }

        $out = [];

        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $out[$id] = self::model($found[$id]);
            }
        }

        return $out;
    }

    /**
     * Come {@see search()}, ma per voci: ogni articolo e, se ne ha più d'una,
     * le sue varianti sotto di lui.
     *
     * @return list<Model>
     */
    public static function searchItems(string $q): array
    {
        $models = self::search($q);
        $variants = self::variantsOf(array_column($models, 'id'));
        $out = [];

        foreach ($models as $model) {
            $out[] = ['id' => Placements::itemKey($model['id'])] + $model;

            if (count($variants[$model['id']] ?? []) < 2) {
                continue;
            }

            foreach ($variants[$model['id']] as $variant) {
                $out[] = self::variant($model, $variant);
            }
        }

        return $out;
    }

    /**
     * Le voci date (`m12`, `v34`), nel loro ordine; quelle di un articolo o
     * di una variante eliminati non ci sono.
     *
     * @param list<string> $keys
     * @return array<string, Model>
     */
    public static function describeItems(array $keys): array
    {
        $items = [];
        $variantIds = [];

        foreach ($keys as $key) {
            $item = Placements::parseItem($key);

            if ($item !== null) {
                $items[trim((string) $key)] = $item;

                if ($item['product_variant_id'] > 0) {
                    $variantIds[] = $item['product_variant_id'];
                }
            }
        }

        $variants = [];

        if ($variantIds !== []) {
            foreach (self::rows(ProductVariant::find('`id` IN ('.implode(', ', array_unique($variantIds)).") AND `deleted` = 'false'")) as $row) {
                $variants[(int) $row['id']] = $row;
            }
        }

        $modelIds = [];

        foreach ($items as $item) {
            $modelIds[] = $item['product_variant_id'] > 0
                ? (int) ($variants[$item['product_variant_id']]['product_model_id'] ?? 0)
                : $item['product_model_id'];
        }

        $models = self::describe($modelIds);
        $out = [];

        foreach ($items as $key => $item) {
            if ($item['product_variant_id'] <= 0) {
                if (isset($models[$item['product_model_id']])) {
                    $out[$key] = ['id' => $key] + $models[$item['product_model_id']];
                }

                continue;
            }

            $variant = $variants[$item['product_variant_id']] ?? null;
            $model = $variant !== null ? ($models[(int) $variant['product_model_id']] ?? null) : null;

            if ($model !== null) {
                $out[$key] = self::variant($model, $variant);
            }
        }

        return $out;
    }

    /**
     * Una riga del catalogo come la vuole la pagina. Pura, salvo la foto.
     *
     * @param array<string, mixed> $row
     * @return Model
     */
    public static function model(array $row, ?string $photo = null): array
    {
        $id = (int) ($row['id'] ?? 0);

        return [
            'id' => $id,
            'label' => html_entity_decode((string) ($row['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'sku' => (string) ($row['sku'] ?? ''),
            'photo' => $photo ?? ProductPhotos::forModel($id, 0, 0),
            'hidden' => ($row['visible'] ?? 'true') !== 'true' || ($row['visible_online'] ?? 'true') !== 'true',
        ];
    }

    /**
     * La voce di una variante: il nome dell'articolo con quello della
     * variante, nascosta se lo è una delle due.
     *
     * @param Model $model
     * @param array<string, mixed> $variant
     * @return Model
     */
    private static function variant(array $model, array $variant): array
    {
        $id = (int) ($variant['id'] ?? 0);

        return [
            'id' => Placements::itemKey($model['id'], $id),
            'label' => $model['label'].' · '.html_entity_decode((string) ($variant['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'sku' => $model['sku'],
            'photo' => ProductPhotos::forModel($model['id'], $id, 0) ?: $model['photo'],
            'hidden' => $model['hidden'] || ($variant['visible'] ?? 'true') !== 'true',
        ];
    }

    /**
     * Le varianti degli articoli dati, per articolo e nel loro ordine.
     *
     * @param list<int> $modelIds
     * @return array<int, list<array<string, mixed>>>
     */
    private static function variantsOf(array $modelIds): array
    {
        $modelIds = array_values(array_filter(array_map('intval', $modelIds), static fn (int $id): bool => $id > 0));

        if ($modelIds === []) {
            return [];
        }

        $out = [];

        foreach (self::rows(ProductVariant::find('`product_model_id` IN ('.implode(', ', $modelIds).") AND `deleted` = 'false'", null, 'position', 'ASC')) as $row) {
            $out[(int) $row['product_model_id']][] = $row;
        }

        return $out;
    }

    /** `'%parola%'` per LIKE: l'apice è escapato dal driver, `%` e `_` restano caratteri. */
    private static function like(string $word): string
    {
        return "'%".addcslashes(ProductModel::connection()->real_escape_string($word), '%_')."%'";
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
