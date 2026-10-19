<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Models\Tax\TaxRule;
use Wonder\Plugin\Gestionale\Support\Tax\TaxResolver;

/**
 * L'aliquota con cui si scorpora il costo di un carico scritto IVA inclusa:
 * quella del tipo fiscale dell'articolo nel paese del negozio
 * (`exports.country`, Italia se manca), o l'aliquota di ripiego. La stessa
 * che usa l'esportazione del catalogo.
 */
final class PurchaseTax
{
    /**
     * @param list<int> $productIds versioni
     * @return array<int, float> aliquota per versione; manca quella senza aliquota
     */
    public static function rates(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));

        if ($productIds === []) {
            return [];
        }

        try {
            $products = self::rows(Product::find('`id` IN ('.implode(', ', $productIds).')'));
            $modelIds = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) ($row['product_model_id'] ?? 0), $products))));
            $models = $modelIds === [] ? [] : self::rows(ProductModel::find('`id` IN ('.implode(', ', $modelIds).')'));
            $rules = self::rows(TaxRule::find(['deleted' => 'false']));
            $fallback = (int) (Setting::current()['fallback_tax_id'] ?? 0);
            $taxes = [];

            foreach (self::rows(Tax::find(['deleted' => 'false'])) as $tax) {
                $taxes[(int) $tax['id']] = (float) ($tax['rate'] ?? 0);
            }
        } catch (Throwable) {
            return [];
        }

        $country = (string) Gestionale::config('exports.country', 'IT');
        $categories = [];

        foreach ($models as $model) {
            $categories[(int) $model['id']] = (int) ($model['tax_category_id'] ?? 0);
        }

        $out = [];

        foreach ($products as $product) {
            $category = $categories[(int) ($product['product_model_id'] ?? 0)] ?? 0;
            $taxId = TaxResolver::resolve($rules, $country, 'private', $category, $fallback);

            if (isset($taxes[$taxId])) {
                $out[(int) $product['id']] = $taxes[$taxId];
            }
        }

        return $out;
    }

    /**
     * Le aliquote che il carico fa scegliere: una per valore, dalla più alta, 0
     * compreso (le nature N1-N7 sono tutte 0%: per il costo conta il valore).
     *
     * @return list<float>
     */
    public static function choices(): array
    {
        try {
            $rates = array_map(static fn (array $tax): float => round((float) ($tax['rate'] ?? 0), 2), self::rows(Tax::find(['deleted' => 'false'])));
        } catch (Throwable) {
            $rates = [];
        }

        $rates = array_values(array_unique(array_merge($rates, [0.0]), SORT_REGULAR));
        rsort($rates);

        return $rates;
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
