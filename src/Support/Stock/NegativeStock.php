<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;

/**
 * I prodotti con la giacenza sotto zero, per *Da controllare*.
 *
 * Sotto zero si va solo con *Vendita senza giacenza* sbloccata, o con dati
 * arrivati da fuori. `Stock::apply()` lo controlla riga per riga (sede, lotto,
 * fornitore); qui un prodotto con più righe in negativo diventa una riga sola,
 * con la somma dei negativi e il numero delle sedi diverse.
 *
 * Un prodotto eliminato o tolto dalla griglia non si segnala: il core lo
 * esclude già dalla lettura, e non c'è niente da rettificare.
 */
final class NegativeStock
{
    /** @return list<array{product_id: int, article: string, option: string, sku: string, quantity: float, locations: int}> */
    public static function items(): array
    {
        $rows = self::rows(StockRow::class, "deleted = 'false' AND quantity < 0");

        if ($rows === []) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['product_id'] ?? 0),
            $rows
        ))));

        if ($ids === []) {
            return [];
        }

        $products = [];

        foreach (self::rows(Product::class, 'id IN ('.implode(',', $ids).')') as $product) {
            $products[(int) $product['id']] = $product;
        }

        return self::group($rows, $products, ProductNames::models($products));
    }

    /**
     * Pura: dalle righe di giacenza ai prodotti da controllare.
     *
     * @param list<array<string, mixed>> $rows righe di `gst_stock`
     * @param array<int, array<string, mixed>> $products per id
     * @param array<int, string> $modelNames nomi degli articoli, per id del modello
     * @return list<array{product_id: int, article: string, option: string, sku: string, quantity: float, locations: int}>
     */
    public static function group(array $rows, array $products, array $modelNames): array
    {
        $items = [];
        // Le sedi si contano una volta: un lotto o un fornitore in più nella
        // stessa sede è un'altra riga, non un'altra sede.
        $locations = [];

        foreach ($rows as $row) {
            $id = (int) ($row['product_id'] ?? 0);
            $quantity = round((float) ($row['quantity'] ?? 0), 3);
            $product = $products[$id] ?? null;

            if ($quantity >= 0 || !is_array($product) || ($product['deleted'] ?? 'false') === 'true') {
                continue;
            }

            if (!isset($items[$id])) {
                $names = ProductNames::of($product, $modelNames);
                $items[$id] = [
                    'product_id' => $id,
                    'article' => $names['article'],
                    'option' => $names['option'],
                    'sku' => (string) ($product['sku'] ?? ''),
                    'quantity' => 0.0,
                    'locations' => 0,
                ];
            }

            $locations[$id][(int) ($row['location_id'] ?? 0)] = true;
            $items[$id]['quantity'] = round($items[$id]['quantity'] + $quantity, 3);
            $items[$id]['locations'] = count($locations[$id]);
        }

        usort($items, static fn (array $a, array $b): int =>
            [mb_strtolower($a['article'], 'UTF-8'), mb_strtolower($a['option'], 'UTF-8'), $a['sku']]
            <=> [mb_strtolower($b['article'], 'UTF-8'), mb_strtolower($b['option'], 'UTF-8'), $b['sku']]
        );

        return array_values($items);
    }

    /**
     * @param class-string<\Wonder\App\Model> $modelClass
     * @return list<array<string, mixed>>
     */
    private static function rows(string $modelClass, string $condition): array
    {
        try {
            $rows = $modelClass::find($condition);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
