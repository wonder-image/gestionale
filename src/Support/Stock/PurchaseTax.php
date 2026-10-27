<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
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
        return array_map(static fn (array $tax): float => $tax['rate'], self::taxes($productIds));
    }

    /**
     * Come `rates()`, con l'aliquota che la dà: il carico la propone già scelta.
     *
     * @param list<int> $productIds versioni
     * @return array<int, array{id: int, rate: float}> per versione; manca quella senza aliquota
     */
    public static function taxes(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));

        if ($productIds === []) {
            return [];
        }

        try {
            $products = self::rows(Product::find('`id` IN ('.implode(', ', $productIds).')'));
            $modelIds = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) ($row['product_model_id'] ?? 0), $products))));
            $models = $modelIds === [] ? [] : self::rows(ProductModel::find('`id` IN ('.implode(', ', $modelIds).')'));
            $rules = TaxRule::active();
            $taxes = [];

            foreach (self::rows(Tax::find(['deleted' => 'false'])) as $tax) {
                $taxes[(int) $tax['id']] = round((float) ($tax['rate'] ?? 0), 2);
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
            // Senza regola il carico non propone niente: l'aliquota la sceglie chi scrive.
            $taxId = TaxResolver::find($rules, $country, 'private', $category);

            if ($taxId !== null && isset($taxes[$taxId])) {
                $out[(int) $product['id']] = ['id' => $taxId, 'rate' => $taxes[$taxId]];
            }
        }

        return $out;
    }

    /**
     * Le aliquote del gestionale per il menu del carico, dalla più alta: tutte,
     * con `visible` a dire quali si propongono (una nascosta compare solo
     * sulla riga che la usa già). Le nature a 0% si riconoscono dalla sigla.
     *
     * @return list<array{id: int, rate: float, label: string, visible: bool}>
     */
    public static function choices(): array
    {
        try {
            $taxes = self::rows(Tax::find(['deleted' => 'false']));
        } catch (Throwable) {
            return [];
        }

        $out = array_map(static fn (array $tax): array => self::choice($tax), $taxes);

        usort($out, static fn (array $a, array $b): int => [$b['rate'], $a['label']] <=> [$a['rate'], $b['label']]);

        return $out;
    }

    /**
     * Un'aliquota del gestionale, anche nascosta, o null se non c'è.
     *
     * @return array{id: int, rate: float, label: string, visible: bool}|null
     */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        try {
            $rows = self::rows(Tax::find(['id' => $id, 'deleted' => 'false']));
        } catch (Throwable) {
            return null;
        }

        return $rows === [] ? null : self::choice($rows[0]);
    }

    /**
     * @param array<string, mixed> $tax
     * @return array{id: int, rate: float, label: string, visible: bool}
     */
    private static function choice(array $tax): array
    {
        $rate = round((float) ($tax['rate'] ?? 0), 2);
        $nature = trim((string) ($tax['nature'] ?? ''));
        $percent = str_replace('.', ',', (string) ($rate + 0)).'%';

        return [
            'id' => (int) $tax['id'],
            'rate' => $rate,
            'label' => $nature === '' ? $percent : $percent.' '.$nature,
            'visible' => ($tax['visible'] ?? 'true') !== 'false',
        ];
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
