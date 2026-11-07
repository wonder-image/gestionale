<?php

namespace Wonder\Plugin\Gestionale\Support\Pricing;

use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceList;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceListItem;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductExport;
use Wonder\Plugin\Gestionale\Support\Promotions\ProductScope;
use Wonder\Plugin\Gestionale\Support\Promotions\ScopeMatcher;
use Wonder\Sql\Transaction;

/**
 * «Cerca prodotti»: una riga per ogni prodotto attivo del
 * selettore del listino che non ne ha ancora una.
 *
 * Il prezzo è quello pieno del catalogo, o quello con la regola del listino
 * se c'è, portato alla base IVA del listino. Le righe che ci sono già non si
 * toccano: il bottone si può premere di nuovo dopo aver aggiunto prodotti.
 * Le righe nate così poi sono righe come le altre: cambiare la percentuale
 * non le ricalcola.
 *
 * `rows()` è pura; `seed()` legge e scrive il database.
 */
final class PriceListSeeder
{
    /**
     * Le righe da creare.
     *
     * Un prodotto senza prezzo resta fuori. Senza aliquota nota il prezzo non
     * si converte fra IVA inclusa ed esclusa (lo stesso fa `toBasis()`).
     *
     * @param array{rule?: string, percent?: float, prices_include_tax?: bool} $list
     * @param array<string, mixed> $scope come `ProductScope::of()`
     * @param array<int, array{price: float, facts: array<string, mixed>}> $catalog come `ProductScope::catalog()`
     * @param list<int> $existing i prodotti che hanno già una riga
     * @param array<int, float> $rates aliquota per articolo (`model_id`)
     * @return list<array{product_id: int, min_quantity: string, price: string}>
     */
    public static function rows(array $list, array $scope, array $catalog, array $existing, bool $catalogIncludesTax, array $rates): array
    {
        $rule = (string) ($list['rule'] ?? 'none');
        $percent = (float) ($list['percent'] ?? 0);
        $listIncludesTax = (bool) ($list['prices_include_tax'] ?? true);
        $existing = array_flip(array_map('intval', $existing));
        $rows = [];

        foreach ($catalog as $productId => $product) {
            $productId = (int) $productId;
            $base = round((float) ($product['price'] ?? 0), 2);
            $facts = (array) ($product['facts'] ?? []);

            if ($base <= 0.0 || isset($existing[$productId]) || !ScopeMatcher::matches($scope, $facts)) {
                continue;
            }

            $price = PriceLists::byRule($rule, $percent, $base);
            $price = $price > 0.0 ? $price : $base;
            $rate = (float) ($rates[(int) ($facts['model_id'] ?? 0)] ?? 0);

            $rows[] = [
                'product_id' => $productId,
                'min_quantity' => '1',
                'price' => number_format(PriceLists::toBasis($price, $catalogIncludesTax, $listIncludesTax, $rate), 2, '.', ''),
            ];
        }

        return $rows;
    }

    /** Crea le righe mancanti del listino; quante ne ha create. */
    public static function seed(int $priceListId): int
    {
        $list = $priceListId > 0 ? PriceList::findById($priceListId) : null;

        if (!is_array($list)) {
            return 0;
        }

        $items = self::listOf(PriceListItem::find(['price_list_id' => $priceListId]));
        $rows = self::rows(
            [
                'rule' => (string) ($list['price_rule'] ?? 'none'),
                'percent' => (float) ($list['percent'] ?? 0),
                'prices_include_tax' => ($list['prices_include_tax'] ?? 'true') === 'true',
            ],
            ProductScope::of('price_list', $priceListId),
            ProductScope::catalog(),
            array_map(static fn (array $row): int => (int) $row['product_id'], $items),
            ProductExport::pricesIncludeTax(),
            ProductExport::taxRates(self::listOf(ProductModel::all()))
        );

        if ($rows === []) {
            return 0;
        }

        $position = max([0, ...array_map(static fn (array $row): int => (int) ($row['position'] ?? 0), $items)]);

        Transaction::run(static function () use ($rows, $priceListId, $position): void {
            foreach ($rows as $index => $row) {
                PriceListItem::create($row + ['price_list_id' => $priceListId, 'position' => $position + $index + 1]);
            }
        });

        return count($rows);
    }

    /** @return list<array<string, mixed>> */
    private static function listOf(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return array_key_exists('id', $found) ? [$found] : array_values(array_filter($found, 'is_array'));
    }
}
