<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;

/**
 * Cambia la variante che fa una pagina per valore su un articolo che ha già
 * le sue versioni (#85).
 *
 * Da un asse all'altro si passa sempre dall'articolo senza pagine: `off()`
 * porta i valori delle pagine sulle versioni, `on()` li riporta sulle pagine.
 * Le varianti lasciate vanno nel cestino con il loro collegamento e il loro
 * slug: da lì nasce il 301 ({@see PageRedirect}), e riaccendendo le pagine
 * tornano fuori invece di rinascere con uno slug `-2`.
 */
final class PageAxis
{
    /** @return bool vero quando ha spostato qualcosa */
    public static function switch(int $modelId, int $from, int $to): bool
    {
        if ($modelId <= 0 || $from === $to) {
            return false;
        }

        $changed = $from > 0 && self::off($modelId, $from);

        return ($to > 0 && self::on($modelId, $to)) || $changed;
    }

    /**
     * Le pagine di `$attributeId` diventano un valore di ogni versione: tutte
     * le versioni su una variante nuova senza pagina, le foto dei colori
     * all'articolo intero.
     */
    public static function off(int $modelId, int $attributeId): bool
    {
        $attribute = self::attribute($attributeId);
        $valueOf = self::pageValues($modelId, $attributeId);

        if ($attribute === null || $valueOf === []) {
            return false;
        }

        $model = ProductModel::find(['id' => $modelId], 1);
        $name = is_array($model) ? (string) ($model['name'] ?? '') : '';
        $keep = Generator::newVariant($modelId, $name, count(self::variants($modelId)) + 1, false);

        if ($keep === 0) {
            return false;
        }

        foreach (self::products($modelId) as $product) {
            $valueId = $valueOf[(int) ($product['product_variant_id'] ?? 0)] ?? 0;

            if ($valueId > 0) {
                ProductAttributes::save('product', (int) $product['id'], [$attribute], [$attributeId => (string) $valueId]);
            }

            self::set(Product::class, ['product_variant_id' => $keep], (int) $product['id']);
        }

        foreach (self::images($modelId) as $image) {
            $variantId = (int) ($image['product_variant_id'] ?? 0);

            if ((int) ($image['product_id'] ?? 0) > 0) {
                self::moveImage((int) $image['id'], $keep);
            } elseif (isset($valueOf[$variantId])) {
                self::moveImage((int) $image['id'], null);
            }
        }

        foreach (self::variants($modelId) as $variant) {
            if ((int) $variant['id'] !== $keep) {
                self::set(ProductVariant::class, ['deleted' => 'true'], (int) $variant['id']);
            }
        }

        return true;
    }

    /**
     * Il valore di `$attributeId` di ogni versione diventa la sua pagina. Una
     * versione senza quel valore resta dov'è.
     */
    public static function on(int $modelId, int $attributeId): bool
    {
        $valueOfProduct = [];

        foreach (self::products($modelId) as $product) {
            $rows = ProductAttributes::rows('product', (int) $product['id'])[$attributeId] ?? [];
            $valueId = (int) ($rows[0]['attribute_value_id'] ?? 0);

            if ($valueId > 0) {
                $valueOfProduct[(int) $product['id']] = $valueId;
            }
        }

        if ($valueOfProduct === []) {
            return false;
        }

        $attribute = self::attribute($attributeId);
        $labels = self::valueLabels(array_values($valueOfProduct));
        $position = count(self::variants($modelId));
        $variantOf = [];

        foreach (array_unique($valueOfProduct) as $valueId) {
            $variantOf[$valueId] = self::restore($modelId, $valueId, ++$position)
                ?: self::create($modelId, $attribute, $valueId, $labels[$valueId] ?? '', $position);
        }

        $moved = [];

        foreach ($valueOfProduct as $productId => $valueId) {
            $variantId = $variantOf[$valueId] ?? 0;

            if ($variantId === 0) {
                continue;
            }

            self::set(Product::class, ['product_variant_id' => $variantId], $productId);
            $moved[$productId] = $variantId;

            foreach (ProductAttributes::rows('product', $productId)[$attributeId] ?? [] as $link) {
                ProductAttributes::modelClass('product')::delete((int) $link['id']);
            }
        }

        $pages = array_flip(array_values($variantOf));

        foreach (self::images($modelId) as $image) {
            $productId = (int) ($image['product_id'] ?? 0);
            $variantId = (int) ($image['product_variant_id'] ?? 0);

            if (isset($moved[$productId])) {
                self::moveImage((int) $image['id'], $moved[$productId]);
            } elseif ($productId === 0 && $variantId > 0 && !isset($pages[$variantId])) {
                // Le foto della variante senza pagina valevano per tutti.
                self::moveImage((int) $image['id'], null);
            }
        }

        // La variante senza pagina rimasta vuota non serve più.
        foreach (self::variants($modelId) as $variant) {
            $id = (int) $variant['id'];

            if (!isset($pages[$id]) && self::rows(Product::class, ['product_variant_id' => $id]) === []) {
                self::set(ProductVariant::class, ['deleted' => 'true'], $id);
            }
        }

        return true;
    }

    /**
     * Il valore della pagina di ogni variante, per id della variante; solo
     * quelle di `$attributeId`.
     *
     * @return array<int, int>
     */
    private static function pageValues(int $modelId, int $attributeId): array
    {
        $values = [];

        foreach (self::variants($modelId) as $variant) {
            $link = ProductAttributes::read('variant', (int) $variant['id'])[$attributeId] ?? null;
            $valueId = (int) ($link['attribute_value_id'] ?? 0);

            if ($valueId > 0) {
                $values[(int) $variant['id']] = $valueId;
            }
        }

        return $values;
    }

    /** La variante nel cestino che aveva quel valore, rimessa fuori; 0 se non c'è. */
    private static function restore(int $modelId, int $valueId, int $position): int
    {
        foreach (self::rows(ProductVariant::class, ['product_model_id' => $modelId], 'true') as $variant) {
            $link = ProductAttributes::read('variant', (int) $variant['id']);

            foreach ($link as $row) {
                if ((int) ($row['attribute_value_id'] ?? 0) === $valueId) {
                    self::set(ProductVariant::class, ['deleted' => 'false', 'position' => $position], (int) $variant['id']);

                    return (int) $variant['id'];
                }
            }
        }

        return 0;
    }

    /** @param array<string, mixed>|null $attribute */
    private static function create(int $modelId, ?array $attribute, int $valueId, string $label, int $position): int
    {
        $variantId = Generator::newVariant($modelId, $label, $position);

        if ($variantId > 0 && $attribute !== null) {
            ProductAttributes::save('variant', $variantId, [$attribute], [(int) $attribute['id'] => (string) $valueId]);
        }

        return $variantId;
    }

    /** @return array<string, mixed>|null */
    private static function attribute(int $attributeId): ?array
    {
        $row = Attribute::find(['id' => $attributeId], 1);

        return is_array($row) && (int) ($row['id'] ?? 0) > 0 ? $row : null;
    }

    /**
     * @param list<int> $valueIds
     * @return array<int, string>
     */
    private static function valueLabels(array $valueIds): array
    {
        $labels = [];

        foreach (array_unique($valueIds) as $valueId) {
            $row = AttributeValue::find(['id' => $valueId], 1);
            $labels[$valueId] = is_array($row) ? (string) ($row['label'] ?? '') : '';
        }

        return $labels;
    }

    private static function moveImage(int $imageId, ?int $variantId): void
    {
        self::set(ProductImage::class, ['product_variant_id' => $variantId], $imageId);
    }

    /** Scrive senza passare dalla validazione: tocca solo collegamenti e cestino. */
    private static function set(string $modelClass, array $values, int $id): void
    {
        $modelClass::query()->Update($modelClass::$table, $values, 'id', $id);
    }

    /** @return list<array<string, mixed>> */
    private static function variants(int $modelId): array
    {
        return self::rows(ProductVariant::class, ['product_model_id' => $modelId]);
    }

    /** Anche le versioni tolte dalla griglia: restano attaccate alla loro variante. @return list<array<string, mixed>> */
    private static function products(int $modelId): array
    {
        return array_merge(
            self::rows(Product::class, ['product_model_id' => $modelId]),
            self::rows(Product::class, ['product_model_id' => $modelId], 'true')
        );
    }

    /** @return list<array<string, mixed>> */
    private static function images(int $modelId): array
    {
        return self::rows(ProductImage::class, ['product_model_id' => $modelId]);
    }

    /**
     * @param array<string, mixed> $where
     * @return list<array<string, mixed>>
     */
    private static function rows(string $modelClass, array $where, string $deleted = 'false'): array
    {
        $rows = $modelClass::find(['deleted' => $deleted] + $where, null, 'position', 'ASC');

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
