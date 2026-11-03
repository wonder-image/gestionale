<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use RuntimeException;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;

/**
 * Le categorie di un articolo, come le salva la scheda: la usano MCP e il
 * caricamento da file.
 */
final class ModelCategories
{
    /**
     * Si tolgono i legami non più scelti, si aggiornano gli altri, si
     * aggiungono i nuovi, in ordine.
     *
     * @param list<int> $chosen
     * @throws RuntimeException se un legame non si salva
     */
    public static function link(int $modelId, array $chosen, int $main): void
    {
        $existing = [];
        $rows = ProductModelCategory::find(['product_model_id' => $modelId]);
        $rows = is_array($rows) && isset($rows['id']) ? [$rows] : (is_array($rows) ? array_filter($rows, 'is_array') : []);

        foreach ($rows as $link) {
            $existing[(int) $link['category_id']] = $link;
        }

        foreach ($existing as $categoryId => $link) {
            if (!in_array($categoryId, $chosen, true)) {
                ProductModelCategory::delete((int) $link['id']);
            }
        }

        foreach ($chosen as $index => $categoryId) {
            $values = [
                'product_model_id' => $modelId,
                'category_id' => $categoryId,
                'is_main' => $categoryId === $main ? 'true' : 'false',
                'position' => $index + 1,
            ];

            $result = isset($existing[$categoryId])
                ? ProductModelCategory::update($values, (int) $existing[$categoryId]['id'])
                : ProductModelCategory::create($values);

            if (empty($result->success)) {
                throw new RuntimeException('category_link');
            }
        }
    }

    /**
     * Gli id delle categorie dell'articolo, la principale per prima e poi per
     * posizione.
     *
     * @return list<int>
     */
    public static function of(int $modelId): array
    {
        $rows = ProductModelCategory::find(['product_model_id' => $modelId]);
        $rows = is_array($rows) && isset($rows['id']) ? [$rows] : (is_array($rows) ? array_values(array_filter($rows, 'is_array')) : []);

        usort($rows, static fn (array $a, array $b): int => [($a['is_main'] ?? '') === 'true' ? 0 : 1, (int) ($a['position'] ?? 0)]
            <=> [($b['is_main'] ?? '') === 'true' ? 0 : 1, (int) ($b['position'] ?? 0)]);

        return array_map(static fn (array $row): int => (int) $row['category_id'], $rows);
    }
}
