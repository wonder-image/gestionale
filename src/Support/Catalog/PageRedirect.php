<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;

/**
 * Dove va un indirizzo di prima quando l'interruttore «Una pagina per ogni
 * valore» cambia (#85).
 *
 * Spento: `/prodotto/blu/` non esiste più, va su `/prodotto/?colore=blu`.
 * Acceso: `/prodotto/?colore=blu` va su `/prodotto/blu/`. Il negozio fa il
 * 301 con quello che torna di qui; le chiavi e i valori della query sono
 * quelli che legge `OptionQuery` dell'ecommerce (slug dell'attributo, slug
 * del nome del valore).
 */
final class PageRedirect
{
    /**
     * La query che prende il posto di una pagina andata nel cestino; vuota
     * quando quello slug non era una pagina di quell'articolo.
     *
     * @return array<string, string>
     */
    public static function formerQuery(int $modelId, string $variantSlug): array
    {
        if ($modelId <= 0 || trim($variantSlug) === '') {
            return [];
        }

        $row = ProductVariant::find(['product_model_id' => $modelId, 'slug' => $variantSlug, 'deleted' => 'true'], 1);

        if (!is_array($row) || (int) ($row['id'] ?? 0) === 0) {
            return [];
        }

        foreach (ProductAttributes::read('variant', (int) $row['id']) as $attributeId => $link) {
            $page = self::page((int) $attributeId, (int) ($link['attribute_value_id'] ?? 0), '');

            if ($page !== null && $page['attribute_slug'] !== '') {
                return [$page['attribute_slug'] => self::slug($page['value_label'])];
            }
        }

        return [];
    }

    /**
     * Le pagine di un articolo, con quello che serve per riconoscerle in una
     * query.
     *
     * @return list<array{slug: string, attribute_slug: string, value_id: int, value_label: string}>
     */
    public static function pages(int $modelId): array
    {
        $rows = ProductVariant::find(['product_model_id' => $modelId, 'deleted' => 'false'], null, 'position', 'ASC');
        $rows = !is_array($rows) || $rows === [] ? [] : (isset($rows['id']) ? [$rows] : array_filter($rows, 'is_array'));
        $pages = [];

        foreach ($rows as $variant) {
            $slug = trim((string) ($variant['slug'] ?? ''));

            foreach (ProductAttributes::read('variant', (int) $variant['id']) as $attributeId => $link) {
                $page = self::page((int) $attributeId, (int) ($link['attribute_value_id'] ?? 0), $slug);

                if ($page !== null && $slug !== '') {
                    $pages[] = $page;
                }
            }
        }

        return $pages;
    }

    /**
     * La pagina che corrisponde a una query, con la query senza la sua
     * chiave; null quando nessuna corrisponde. Senza database.
     *
     * @param list<array{slug: string, attribute_slug: string, value_id: int, value_label: string}> $pages
     * @param array<string, mixed> $query
     * @return array{slug: string, query: array<string, mixed>}|null
     */
    public static function pageFor(array $pages, array $query): ?array
    {
        foreach ($pages as $page) {
            $key = $page['attribute_slug'];
            $wanted = $query[$key] ?? null;

            if ($key === '' || !is_scalar($wanted) || trim((string) $wanted) === '') {
                continue;
            }

            $wanted = trim((string) $wanted);

            if ($wanted === (string) $page['value_id'] || self::slug($wanted) === self::slug($page['value_label'])) {
                unset($query[$key]);

                return ['slug' => $page['slug'], 'query' => $query];
            }
        }

        return null;
    }

    /** Lo slug di un valore come lo scrive `OptionQuery::slug()` dell'ecommerce. */
    public static function slug(string $label): string
    {
        $slug = str_replace('_', '-', \Wonder\Support\Text\Slug::make(html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return trim((string) preg_replace('/-+/', '-', $slug), '-');
    }

    /** @return array{slug: string, attribute_slug: string, value_id: int, value_label: string}|null */
    private static function page(int $attributeId, int $valueId, string $slug): ?array
    {
        if ($attributeId <= 0 || $valueId <= 0) {
            return null;
        }

        $attribute = Attribute::find(['id' => $attributeId], 1);
        $value = AttributeValue::find(['id' => $valueId], 1);

        if (!is_array($attribute) || !is_array($value)) {
            return null;
        }

        return [
            'slug' => $slug,
            'attribute_slug' => trim((string) ($attribute['slug'] ?? '')),
            'value_id' => $valueId,
            'value_label' => (string) ($value['label'] ?? ''),
        ];
    }
}
