<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Numbers;

/**
 * Crea le varianti e i prodotti che mancano, dati gli assi spuntati.
 *
 * Sta fuori dalla scheda perché la scheda ha già il suo lavoro — campi,
 * layout, lettura di quello che arriva dal form — e questo è un lavoro
 * diverso: guardare cosa c'è, chiedere a `Combinations` cosa manca, scrivere le
 * righe con il loro nome e i loro collegamenti.
 *
 * Quando il modello ha ancora solo lo scheletro — una variante e un prodotto
 * senza attributi — la prima combinazione lo riusa, invece di lasciare in giro
 * una variante vuota.
 */
final class Generator
{
    /**
     * @param list<array{id: int, label: string}> $variantValues l'asse con pagina propria
     * @param list<list<array{id: int, label: string}>> $axes gli altri assi
     */
    /**
     * @param array<string, array<string, mixed>> $typed quello che è stato
     *        scritto nella griglia delle versioni nuove, per chiave di
     *        `Combinations::clientKey()`: nome, codice, EAN, prezzo
     * @return array<string, array{product_id: int, variant_id: int, priced: bool}>
     *         le versioni appena nate, per la stessa chiave: da lì la scheda
     *         ritrova la riga a cui agganciare giacenza e foto
     */
    public static function run(
        int $modelId,
        array $variantValues,
        array $axes,
        string $modelSku = '',
        array $typed = []
    ): array {
        if ($modelId <= 0 || ($variantValues === [] && $axes === [])) {
            return [];
        }

        $plan = Combinations::plan($variantValues, $axes, self::existing($modelId));

        if ($plan['variants'] === [] && $plan['products'] === []) {
            return [];
        }

        if ($modelSku === '') {
            $model = ProductModel::find(['id' => $modelId], 1);
            $modelSku = is_array($model) ? (string) ($model['sku'] ?? '') : '';
        }

        $attributeOf = self::attributeOfValues();
        $reuse = self::skeletonToReuse($modelId);
        $variantIds = self::existing($modelId)['variants'];
        $position = count(self::variants($modelId));

        foreach ($plan['variants'] as $variant) {
            $valueId = (int) $variant['value_id'];

            if ($reuse !== null && $reuse['variant_id'] > 0) {
                $variantId = $reuse['variant_id'];
                ProductVariant::update(['name' => $variant['label']], $variantId);
                $reuse['variant_id'] = 0;
            } else {
                $created = ProductVariant::create([
                    'code' => Code::make(ProductVariant::class, Codes::VARIANT),
                    'product_model_id' => $modelId,
                    'name' => $variant['label'],
                    'slug' => Slug::make($variant['label'].'-'.$modelId.'-'.$valueId),
                    'position' => ++$position,
                    'visible' => 'true',
                ]);
                $variantId = (int) ($created->insert_id ?? 0);
            }

            if ($variantId === 0) {
                continue;
            }

            $variantIds[$valueId] = $variantId;
            $attribute = $attributeOf[$valueId] ?? null;

            if ($attribute !== null) {
                ProductAttributes::save('variant', $variantId, [$attribute], [
                    (int) $attribute['id'] => (string) $valueId,
                ]);
            }
        }

        $firstVariantId = (int) (self::variants($modelId)[0]['id'] ?? 0);
        $productPosition = count(self::products($modelId));
        $nate = [];

        foreach ($plan['products'] as $product) {
            $valueId = (int) $product['variant_value_id'];
            $variantId = $valueId > 0 ? ($variantIds[$valueId] ?? 0) : $firstVariantId;

            if ($variantId === 0) {
                continue;
            }

            $chiave = Combinations::clientKey($valueId, $product['value_ids']);
            $scritto = is_array($typed[$chiave] ?? null) ? $typed[$chiave] : [];
            $sku = trim((string) ($scritto['sku'] ?? '')) ?: Sku::propose($modelSku, $product['labels']);
            // Il nome scritto a mano vince su quello proposto: "Blu / M" è una
            // proposta, "Maglia leggera blu" è una decisione.
            $name = trim((string) ($scritto['name'] ?? '')) ?: VersionName::from($product['labels'], $sku);
            $ean = trim((string) ($scritto['ean'] ?? ''));
            $prezzo = Numbers::fromForm($scritto['price'] ?? null);

            $reused = $reuse !== null && $reuse['product_id'] > 0;

            if ($reused) {
                $productId = $reuse['product_id'];
                $ripresa = [
                    'product_variant_id' => $variantId,
                    'sku' => $sku,
                    'name' => $name,
                ];

                if ($ean !== '') {
                    $ripresa['ean'] = $ean;
                }

                Product::update($ripresa, $productId);
                $reuse['product_id'] = 0;
            } else {
                $riga = [
                    'code' => Code::make(Product::class, Codes::PRODUCT),
                    'product_model_id' => $modelId,
                    'product_variant_id' => $variantId,
                    'sku' => $sku,
                    'name' => $name,
                    'position' => ++$productPosition,
                    'active' => 'true',
                ];

                if ($prezzo !== null) {
                    $riga['price'] = $prezzo;
                }

                if ($ean !== '') {
                    $riga['ean'] = $ean;
                }

                $created = Product::create($riga);
                $productId = (int) ($created->insert_id ?? 0);
            }

            if ($productId === 0) {
                continue;
            }

            // Un prezzo scritto a mano non lo tocca più nessuno: chi salva
            // dopo di noi deve saltare queste righe.
            $nate[$chiave] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'priced' => $prezzo !== null,
            ];

            if ($prezzo !== null && $reused) {
                Product::update(['price' => $prezzo], $productId);
            }

            // Un collegamento per asse: con tre opzioni spuntate il prodotto ne
            // ha tre, non uno.
            foreach ($product['value_ids'] as $productValueId) {
                $attribute = $attributeOf[(int) $productValueId] ?? null;

                if ($attribute === null) {
                    continue;
                }

                ProductAttributes::save('product', $productId, [$attribute], [
                    (int) $attribute['id'] => (string) $productValueId,
                ]);
            }
        }

        return $nate;
    }

    /**
     * Varianti e prodotti già presenti, nella forma che `Combinations` legge.
     *
     * @return array{variants: array<int, int>, products: array<string, bool>}
     */
    public static function existing(int $modelId): array
    {
        $variants = [];
        $variantValueOf = [];

        foreach (self::variants($modelId) as $variant) {
            $variantId = (int) $variant['id'];

            foreach (ProductAttributes::read('variant', $variantId) as $link) {
                $valueId = (int) ($link['attribute_value_id'] ?? 0);

                if ($valueId > 0) {
                    $variants[$valueId] = $variantId;
                    $variantValueOf[$variantId] = $valueId;
                }
            }
        }

        $products = [];

        foreach (self::products($modelId) as $product) {
            $variantValue = $variantValueOf[(int) ($product['product_variant_id'] ?? 0)] ?? 0;
            $valueIds = [];

            foreach (ProductAttributes::read('product', (int) $product['id']) as $link) {
                $valueId = (int) ($link['attribute_value_id'] ?? 0);

                if ($valueId > 0) {
                    $valueIds[] = $valueId;
                }
            }

            $products[Combinations::key($variantValue, $valueIds)] = true;
        }

        return ['variants' => $variants, 'products' => $products];
    }

    /**
     * Le combinazioni che esistono già, nella forma che usa il browser.
     *
     * Serve alla griglia delle versioni nuove: quello che c'è non si
     * ripropone.
     *
     * @return list<string>
     */
    public static function existingClientKeys(int $modelId): array
    {
        if ($modelId <= 0) {
            return [];
        }

        $variantValueOf = [];

        foreach (self::variants($modelId) as $variant) {
            foreach (ProductAttributes::read('variant', (int) $variant['id']) as $link) {
                $valueId = (int) ($link['attribute_value_id'] ?? 0);

                if ($valueId > 0) {
                    $variantValueOf[(int) $variant['id']] = $valueId;
                }
            }
        }

        $keys = [];

        foreach (self::products($modelId) as $product) {
            $valueIds = [];

            foreach (ProductAttributes::read('product', (int) $product['id']) as $link) {
                $valueId = (int) ($link['attribute_value_id'] ?? 0);

                if ($valueId > 0) {
                    $valueIds[] = $valueId;
                }
            }

            $keys[] = Combinations::clientKey(
                $variantValueOf[(int) ($product['product_variant_id'] ?? 0)] ?? 0,
                $valueIds
            );
        }

        return array_values(array_unique(array_filter($keys, static fn (string $k): bool => $k !== '')));
    }

    /**
     * Lo scheletro da riusare: una variante e un prodotto, senza attributi e
     * senza niente scritto sopra.
     *
     * @return array{variant_id: int, product_id: int}|null
     */
    private static function skeletonToReuse(int $modelId): ?array
    {
        $variants = self::variants($modelId);
        $products = self::products($modelId);

        if (count($variants) !== 1 || count($products) !== 1) {
            return null;
        }

        if (ProductAttributes::read('variant', (int) $variants[0]['id']) !== []
            || ProductAttributes::read('product', (int) $products[0]['id']) !== []) {
            return null;
        }

        return ['variant_id' => (int) $variants[0]['id'], 'product_id' => (int) $products[0]['id']];
    }

    /**
     * L'attributo di ogni valore, per id del valore.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function attributeOfValues(): array
    {
        $attributes = [];

        foreach (self::rows(Attribute::class) as $attribute) {
            $attributes[(int) $attribute['id']] = $attribute;
        }

        $byValue = [];

        foreach (self::rows(AttributeValue::class) as $value) {
            $attributeId = (int) ($value['attribute_id'] ?? 0);

            if (isset($attributes[$attributeId])) {
                $byValue[(int) $value['id']] = $attributes[$attributeId];
            }
        }

        return $byValue;
    }

    /** @return list<array<string, mixed>> */
    private static function variants(int $modelId): array
    {
        return self::rows(ProductVariant::class, ['product_model_id' => $modelId]);
    }

    /** @return list<array<string, mixed>> */
    private static function products(int $modelId): array
    {
        return self::rows(Product::class, ['product_model_id' => $modelId]);
    }

    /**
     * @param array<string, mixed> $where
     * @return list<array<string, mixed>>
     */
    private static function rows(string $modelClass, array $where = []): array
    {
        $rows = $modelClass::find(array_merge(['deleted' => 'false'], $where), null, 'position', 'ASC');

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
