<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;

/**
 * Gli articoli per la casella «Cerca un articolo» di *Articoli collegati*:
 * per nome, per SKU dell'articolo o per SKU ed EAN di una sua versione.
 * Come la ricerca delle versioni di magazzino, ma restituisce articoli.
 *
 * Gli articoli nascosti al sito ci sono, con il segno: chi vende li può
 * collegare, il sito li mostrerà quando tornano visibili.
 *
 * @phpstan-type Model array{id: int, label: string, sku: string, photo: string, hidden: bool}
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
