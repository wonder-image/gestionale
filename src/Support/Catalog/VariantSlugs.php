<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;

/**
 * Gli slug delle varianti già salvate, rifatti dal loro valore: `blu`,
 * `rosso`, `blu-2`. Lo usa il comando `gestionale:variant-slugs`, l'unico
 * punto che riscrive uno slug già salvato; le varianti nuove lo prendono dal
 * `Generator`.
 *
 * La regola (`compute`) è pura; le letture stanno in `plan()`.
 */
final class VariantSlugs
{
    /**
     * Chi ha già uno slug giusto lo tiene, così il comando non sposta
     * indirizzi buoni; gli altri prendono il primo libero, per posizione.
     *
     * @param list<array{id: int, label: string, slug: string}> $variants per posizione
     * @param list<string> $reserved slug già presi (le varianti cancellate)
     * @return array<int, string> [variantId => slug]
     */
    public static function compute(array $variants, array $reserved = []): array
    {
        $taken = array_fill_keys(array_filter($reserved), true);
        $bases = [];
        $slugs = [];

        foreach ($variants as $variant) {
            $id = (int) $variant['id'];
            $bases[$id] = self::baseFor((string) $variant['label']);
            $current = (string) $variant['slug'];

            if ($bases[$id] !== '' && $current !== '' && !isset($taken[$current])
                && preg_match('/^'.preg_quote($bases[$id], '/').'(-\d+)?$/', $current) === 1) {
                $slugs[$id] = $current;
                $taken[$current] = true;
            }
        }

        $result = [];

        foreach ($variants as $variant) {
            $id = (int) $variant['id'];

            if (!isset($slugs[$id]) && $bases[$id] !== '') {
                $slugs[$id] = Slug::firstFree($bases[$id], static fn (string $slug): bool => isset($taken[$slug]));
                $taken[$slugs[$id]] = true;
            }

            $result[$id] = $slugs[$id] ?? '';
        }

        return $result;
    }

    /**
     * Le varianti del modello il cui slug cambia.
     *
     * @return array<int, string> [variantId => slugNuovo]
     */
    public static function plan(int $modelId): array
    {
        $variants = [];
        $current = [];

        foreach (self::rows(ProductVariant::find(['product_model_id' => $modelId, 'deleted' => 'false'], null, 'position', 'ASC')) as $row) {
            $id = (int) $row['id'];
            $current[$id] = (string) ($row['slug'] ?? '');
            $variants[] = ['id' => $id, 'label' => self::label($id), 'slug' => $current[$id]];
        }

        $reserved = array_map(
            static fn (array $row): string => (string) ($row['slug'] ?? ''),
            self::rows(ProductVariant::find(['product_model_id' => $modelId, 'deleted' => 'true']))
        );
        $changes = [];

        foreach (self::compute($variants, $reserved) as $id => $slug) {
            if ($slug !== $current[$id]) {
                $changes[$id] = $slug;
            }
        }

        return $changes;
    }

    /** @param array<int, string> $plan @return int le varianti scritte */
    public static function apply(array $plan): int
    {
        foreach ($plan as $variantId => $slug) {
            ProductVariant::update(['slug' => $slug], (int) $variantId);
        }

        return count($plan);
    }

    /** @return list<int> */
    public static function modelIds(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            self::rows(ProductModel::find(['deleted' => 'false'], null, 'id', 'ASC'))
        );
    }

    private static function baseFor(string $label): string
    {
        if (trim($label) === '') {
            return '';
        }

        $base = Slug::base($label);

        return $base !== '' ? $base : 'variante';
    }

    /** L'etichetta del valore della variante; vuota per lo scheletro. */
    private static function label(int $variantId): string
    {
        foreach (ProductAttributes::read('variant', $variantId) as $link) {
            $valueId = (int) ($link['attribute_value_id'] ?? 0);

            if ($valueId > 0) {
                $value = AttributeValue::find(['id' => $valueId], 1);

                return is_array($value) ? trim((string) ($value['label'] ?? '')) : '';
            }
        }

        return '';
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return array_is_list($rows) ? array_values(array_filter($rows, 'is_array')) : [$rows];
    }
}
