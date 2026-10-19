<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\Batch;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Resources\Stock\StockLevelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\CategoryTree;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductPhotos;
use Wonder\Plugin\Gestionale\Support\Contacts\SupplierNames;

/**
 * Le versioni per la casella «Cerca o scansiona» dei documenti di magazzino
 * (G2b-bis 4.2). G3 la riuserà per gli ordini ai fornitori.
 *
 * Mai i multiprodotti (arrivano con G5) né le versioni cancellate; le ferme
 * sì, con il segno. SKU ed EAN non sono unici: il lettore dice se il codice è
 * di una versione, di più versioni o di nessuna, e la pagina aggiunge la riga
 * solo nel primo caso.
 *
 * G3: con *Acquisti* ogni versione porta anche `costs`, `suppliers` e `tax_rate`, con
 * *Lotti e scadenze* `tracks_batches` e `lots` (da dove può uscire la merce
 * nella sede). Spente, le chiavi non ci sono.
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
     * *Aggiungi versioni* dell'inventario: tutte le versioni (`all`), quelle
     * di una categoria con le sottocategorie (`category`) o di un marchio
     * (`brand`). Entrano quelle in vendita e le ferme che hanno ancora pezzi
     * nella sede; mai i multiprodotti.
     *
     * Prima si contano: oltre le 500 righe di un documento tornano solo gli
     * id, perché la pagina dica quante sarebbero senza ricevere migliaia di
     * versioni da rifiutare. Le versioni stanno in ordine di nome.
     *
     * @return array{count: int, ids: list<int>, versions: list<Version>}
     */
    public static function scope(string $scope, int $scopeId, int $locationId): array
    {
        $none = ['count' => 0, 'ids' => [], 'versions' => []];
        $filter = match ($scope) {
            'all' => '',
            'category' => $scopeId > 0 ? StockLevelResource::categoryCondition([(string) $scopeId], self::rows(Category::all())) : null,
            'brand' => $scopeId > 0 ? StockLevelResource::brandCondition([(string) $scopeId]) : null,
            default => null,
        };

        if ($filter === null) {
            return $none;
        }

        $location = $locationId > 0 ? $locationId : Locations::mainId();
        $products = '`'.Product::$table.'`';
        $conditions = [
            "`deleted` = 'false'",
            self::notBundle(),
            '`product_model_id` IN (SELECT a.`id` FROM `'.ProductModel::$table."` a WHERE a.`deleted` = 'false')",
            "(`active` = 'true' OR ".self::stockAt($location, $products.'.`id`').' > 0)',
        ];

        if ($filter !== '') {
            $conditions[] = $filter;
        }

        $ids = array_map('intval', array_column(self::rows(Product::find(implode(' AND ', $conditions), null, 'id', 'ASC', 'id')), 'id'));

        if ($ids === [] || count($ids) > StockDocumentRules::MAX_LINES) {
            return ['count' => count($ids), 'ids' => $ids, 'versions' => []];
        }

        $versions = array_values(self::describe($ids, $location));
        usort($versions, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']) ?: $a['id'] <=> $b['id']);

        return ['count' => count($ids), 'ids' => $ids, 'versions' => $versions];
    }

    /**
     * Le scelte di *Aggiungi versioni*: le categorie indentate come nella
     * scheda e i marchi per nome.
     *
     * @return array{categories: array<string, string>, brands: array<string, string>}
     */
    public static function scopeOptions(): array
    {
        $categories = CategoryTree::options(self::rows(Category::all()));
        unset($categories['']);
        $brands = [];

        foreach (self::rows(Brand::find(null, null, 'name', 'ASC')) as $brand) {
            $brands[(string) $brand['id']] = (string) ($brand['name'] ?? '');
        }

        return ['categories' => $categories, 'brands' => $brands];
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
        $productIds = array_map('intval', array_column($products, 'id'));
        $useBatches = Gestionale::feature('batch_tracking');
        $useSuppliers = Gestionale::feature('purchasing');
        // G3, Acquisti: i costi per fornitore, per riempire il costo del carico.
        $costs = $useSuppliers ? self::costs($productIds) : [];
        // E l'aliquota, per scorporare l'IVA dai costi scritti IVA inclusa.
        $rates = $useSuppliers ? PurchaseTax::taxes($productIds) : [];
        // G3, lotti: quali versioni chiedono il lotto, e da dove può uscire la merce nello scarico.
        $tracking = $useBatches ? array_flip(Batches::trackingProducts($productIds)) : [];
        $buckets = $useBatches || $useSuppliers ? self::buckets($productIds, $location, $useBatches, $useSuppliers) : ['lots' => [], 'suppliers' => []];
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
            ]
                + ($useSuppliers ? ['costs' => (object) ($costs[$id] ?? []), 'suppliers' => $buckets['suppliers'][$id] ?? [], 'tax_rate' => $rates[$id]['rate'] ?? null, 'tax_id' => $rates[$id]['id'] ?? 0] : [])
                + ($useBatches ? ['tracks_batches' => isset($tracking[$id]), 'lots' => $buckets['lots'][$id] ?? []] : []);
        }

        return $versions;
    }

    /**
     * I costi d'acquisto delle versioni, per fornitore, in una lettura sola.
     *
     * @param list<int> $productIds
     * @return array<int, array<int, float>>
     */
    private static function costs(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $out = [];

        foreach (self::rows(ProductSupplier::find('`product_id` IN ('.implode(', ', $productIds).") AND `deleted` = 'false'")) as $link) {
            if ($link['cost'] !== null && trim((string) $link['cost']) !== '') {
                $out[(int) $link['product_id']][(int) $link['supplier_id']] = round((float) $link['cost'], 4);
            }
        }

        return $out;
    }

    /**
     * Da dove può uscire la merce nella sede, per la scelta a mano dello
     * scarico (G3.12): i lotti con pezzi, nell'ordine in cui la merce esce
     * (scadenza più vicina prima, gli scaduti segnati), e i fornitori con
     * pezzi, per nome. I secchi 0 non si elencano: sono «automatico».
     *
     * @param list<int> $productIds
     * @return array{lots: array<int, list<array{id: int, number: string, expiry: string, quantity: float, expired: bool}>>, suppliers: array<int, list<array{id: int, name: string, quantity: float}>>}
     */
    private static function buckets(array $productIds, int $locationId, bool $useBatches, bool $useSuppliers): array
    {
        $out = ['lots' => [], 'suppliers' => []];

        if ($productIds === []) {
            return $out;
        }

        $rows = self::rows(StockRow::find(
            '`product_id` IN ('.implode(', ', $productIds).') AND `location_id` = '.$locationId
            ." AND `quantity` > 0 AND `deleted` = 'false' AND (`batch_id` > 0 OR `supplier_id` > 0)"
        ));
        $lots = [];
        $suppliers = [];

        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $pieces = round((float) $row['quantity'], 3);
            $batchId = (int) ($row['batch_id'] ?? 0);
            $supplierId = (int) ($row['supplier_id'] ?? 0);

            if ($useBatches && $batchId > 0) {
                $lots[$productId][$batchId] = round(($lots[$productId][$batchId] ?? 0.0) + $pieces, 3);
            }

            if ($useSuppliers && $supplierId > 0) {
                $suppliers[$productId][$supplierId] = round(($suppliers[$productId][$supplierId] ?? 0.0) + $pieces, 3);
            }
        }

        $batches = self::batches(array_merge([], ...array_map('array_keys', array_values($lots))));
        $today = date('Y-m-d');

        foreach ($lots as $productId => $byBatch) {
            $list = [];

            foreach ($byBatch as $batchId => $pieces) {
                $expiry = $batches[$batchId]['expiry'] ?? '';
                $list[] = [
                    'id' => $batchId,
                    'number' => $batches[$batchId]['number'] ?? '#'.$batchId,
                    'expiry' => $expiry,
                    'quantity' => $pieces + 0.0,
                    'expired' => StockPicking::isExpired($expiry, $today),
                ];
            }

            usort($list, static fn (array $a, array $b): int => [$a['expiry'] === '', $a['expiry'], $a['id']] <=> [$b['expiry'] === '', $b['expiry'], $b['id']]);
            $out['lots'][$productId] = $list;
        }

        foreach ($suppliers as $productId => $bySupplier) {
            $list = [];

            foreach ($bySupplier as $supplierId => $pieces) {
                $list[] = ['id' => $supplierId, 'name' => SupplierNames::of($supplierId), 'quantity' => $pieces + 0.0];
            }

            usort($list, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']);
            $out['suppliers'][$productId] = $list;
        }

        return $out;
    }

    /**
     * Numero e scadenza dei lotti, per id.
     *
     * @param list<int> $ids
     * @return array<int, array{number: string, expiry: string}>
     */
    public static function batches(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (self::rows(Batch::find('`id` IN ('.implode(', ', $ids).") AND `deleted` IN ('true', 'false')")) as $batch) {
            $expiry = substr(trim((string) ($batch['expiry_date'] ?? '')), 0, 10);
            $out[(int) $batch['id']] = [
                'number' => (string) ($batch['number'] ?? ''),
                'expiry' => $expiry === '' || str_starts_with($expiry, '0000') ? '' : $expiry,
            ];
        }

        return $out;
    }

    /** `'%parola%'` per LIKE: l'apice è escapato dal driver, `%` e `_` restano caratteri. */
    private static function like(string $word): string
    {
        return "'%".addcslashes(Product::connection()->real_escape_string($word), '%_')."%'";
    }

    /** I pezzi della versione nella sede, come `LevelsSql::quantityAt()`. */
    private static function stockAt(int $locationId, string $product): string
    {
        return '(SELECT COALESCE(SUM(s.`quantity`), 0) FROM `'.StockRow::$table.'` s WHERE s.`product_id` = '.$product
            .' AND s.`location_id` = '.$locationId." AND s.`deleted` = 'false')";
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
