<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

/**
 * Le righe di un carrello o di un ordine, lette in due modi.
 *
 * Una confezione sta in una riga madre (quella che si vende, col suo prezzo)
 * seguita da una riga figlia per ogni prodotto che contiene (a prezzo zero, con
 * `parent_item_id` che punta alla madre). La merce che si prenota, si scarica e
 * si rimette a scaffale è quella delle figlie; ciò che il cliente ha comprato e
 * paga è la madre. Qui si decide una volta sola quale riga è quale, per la
 * lista piatta (col `parent_item_id`) e per quella annidata (con `children`).
 */
final class OrderLines
{
    /**
     * Le righe di primo livello, ciascuna seguita dalle sue figlie.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function flat(array $items): array
    {
        $flat = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $children = isset($item['children']) && is_array($item['children']) ? $item['children'] : [];
            unset($item['children']);
            $flat[] = $item;

            foreach (self::flat($children) as $child) {
                $flat[] = $child;
            }
        }

        return $flat;
    }

    /**
     * Le righe che hanno merce dietro: quelle da prenotare, scaricare, rimettere.
     * Una madre che ha figlie non è merce: lo sono le figlie.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function goods(array $items): array
    {
        $flat = self::flat($items);
        $parents = [];

        foreach ($flat as $item) {
            $parent = (int) ($item['parent_item_id'] ?? 0);

            if ($parent > 0) {
                $parents[$parent] = true;
            }
        }

        return array_values(array_filter(
            $flat,
            static fn (array $item): bool => (string) ($item['type'] ?? '') === 'product'
                && (int) ($item['product_id'] ?? 0) > 0
                && (float) ($item['quantity'] ?? 0) > 0
                && !isset($parents[(int) ($item['id'] ?? 0)])
        ));
    }

    /**
     * Le righe che il cliente ha comprato: tutte tranne le figlie.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function sold(array $items): array
    {
        return array_values(array_filter(
            self::flat($items),
            static fn (array $item): bool => (int) ($item['parent_item_id'] ?? 0) === 0
        ));
    }

    /**
     * Le figlie di una madre.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function children(array $items, int $parentId): array
    {
        return array_values(array_filter(
            self::flat($items),
            static fn (array $item): bool => $parentId > 0 && (int) ($item['parent_item_id'] ?? 0) === $parentId
        ));
    }
}
