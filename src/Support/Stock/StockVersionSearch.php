<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductPhotos;

/**
 * Le versioni per la casella «Cerca o scansiona» dei documenti di magazzino
 * (G2b-bis 4.2). G3 la riuserà per gli ordini ai fornitori.
 *
 * Mai i multiprodotti (arrivano con G5) né le versioni cancellate; le ferme
 * sì, con il segno. SKU ed EAN non sono unici: il lettore dice se il codice è
 * di una versione, di più versioni o di nessuna, e la pagina aggiunge la riga
 * solo nel primo caso.
 *
 * @phpstan-type Version array{id: int, label: string, sku: string, ean: string, photo: string, stock: float, inactive: bool}
 */
final class StockVersionSearch
{
    public const LIMIT = 20;

    /** Quante parole della ricerca contano: le altre si ignorano. */
    private const WORDS = 5;

    /** @param list<mixed> $matches */
    public static function outcome(array $matches): string
    {
        return match (count($matches)) {
            0 => 'none',
            1 => 'one',
            default => 'many',
        };
    }

    /**
     * Al più 20 versioni che contengono ogni parola nel nome, nello SKU,
     * nell'EAN o nel nome dell'articolo.
     *
     * @return list<Version>
     */
    public static function search(string $q, int $locationId): array
    {
        $words = array_slice(preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, self::WORDS);

        if ($words === []) {
            return [];
        }

        $models = '`'.ProductModel::$table.'`';
        $conditions = ["`deleted` = 'false'", self::notBundle()];

        foreach ($words as $word) {
            $like = self::like($word);
            $conditions[] = '(`name` LIKE '.$like.' OR `sku` LIKE '.$like.' OR `ean` LIKE '.$like
                .' OR `product_model_id` IN (SELECT m.`id` FROM '.$models.' m WHERE m.`name` LIKE '.$like." AND m.`deleted` = 'false'))";
        }

        $ids = array_map('intval', array_column(self::rows(Product::find(implode(' AND ', $conditions), self::LIMIT, 'id', 'ASC')), 'id'));
        $found = self::describe($ids, $locationId);

        return array_values(array_filter(array_map(static fn (int $id): ?array => $found[$id] ?? null, $ids)));
    }

    /**
     * Il codice del lettore: SKU o EAN esattamente uguale.
     *
     * @return array{result: string, versions: list<Version>}
     */
    public static function scan(string $code, int $locationId): array
    {
        $code = trim($code);

        if ($code === '') {
            return ['result' => 'none', 'versions' => []];
        }

        $ids = [];

        foreach (['sku', 'ean'] as $column) {
            foreach (self::rows(Product::find([$column => $code, 'deleted' => 'false'])) as $row) {
                $ids[(int) $row['id']] = (int) $row['id'];
            }
        }

        $versions = array_values(self::describe(array_values($ids), $locationId));

        return ['result' => self::outcome($versions), 'versions' => $versions];
    }

    /**
     * Le versioni di questi id, pronte per la pagina; quelle che non ci sono
     * più, i multiprodotti e gli id non validi mancano.
     *
     * @param list<mixed> $ids
     * @return array<int, Version>
     */
    public static function describe(array $ids, int $locationId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $products = self::rows(Product::find(
            '`id` IN ('.implode(', ', $ids).") AND `deleted` = 'false' AND ".self::notBundle(),
            null, 'id', 'ASC'
        ));
        $location = $locationId > 0 ? $locationId : Locations::mainId();
        $modelNames = ProductNames::models($products);
        $levels = Levels::byLocationForProducts(array_map('intval', array_column($products, 'id')));
        $versions = [];

        foreach ($products as $product) {
            $id = (int) $product['id'];
            $versions[$id] = [
                'id' => $id,
                'label' => ProductNames::full($product, $modelNames),
                'sku' => (string) ($product['sku'] ?? ''),
                'ean' => (string) ($product['ean'] ?? ''),
                'photo' => ProductPhotos::forModel((int) $product['product_model_id'], (int) ($product['product_variant_id'] ?? 0), $id),
                'stock' => round((float) ($levels[$id][$location]['quantity'] ?? 0), 3),
                'inactive' => (string) ($product['active'] ?? 'true') === 'false',
            ];
        }

        return $versions;
    }

    /** `'%parola%'` per LIKE: l'apice è escapato dal driver, `%` e `_` restano caratteri. */
    private static function like(string $word): string
    {
        return "'%".addcslashes(Product::connection()->real_escape_string($word), '%_')."%'";
    }

    private static function notBundle(): string
    {
        return '`product_model_id` NOT IN (SELECT b.`id` FROM `'.ProductModel::$table."` b WHERE b.`type` = 'bundle')";
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
