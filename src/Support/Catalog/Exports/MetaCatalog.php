<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog\Exports;

use Wonder\Plugin\Gestionale\Support\Export\Delimited;

/**
 * Il file per il catalogo Meta (Facebook e Instagram), da caricare in
 * Gestore delle vendite: un .csv con i nomi dei campi di Meta.
 *
 * `id` è l'opzione, lo stesso `content_ids` che manda il pixel del negozio;
 * `item_group_id` è il codice articolo, che lega le opzioni tra loro.
 * Disponibilità `in stock`/`out of stock`, prezzi "12.90 EUR".
 *
 * Classe pura.
 */
final class MetaCatalog
{
    private const FIELDS = [
        'id', 'item_group_id', 'title', 'description', 'availability', 'condition',
        'price', 'sale_price', 'sale_price_effective_date', 'link', 'image_link',
        'additional_image_link', 'brand', 'gtin', 'mpn', 'product_type',
        'quantity_to_sell_on_facebook', 'shipping_weight', 'status',
    ];

    /**
     * @param list<array<string, mixed>> $rows righe già filtrate da `ProductFeed`
     * @param array{pricesIncludeTax?: bool, currency?: string, now?: string, brand?: string} $options
     * @return list<array<string, string>>
     */
    public static function items(array $rows, array $options = []): array
    {
        $includeTax = $options['pricesIncludeTax'] ?? true;
        $currency = $options['currency'] ?? 'EUR';
        $now = $options['now'] ?? date('Y-m-d H:i:s');
        $ids = array_flip(array_map(static fn (array $row): string => (string) $row['id'], $rows));
        $out = [];

        foreach ($rows as $row) {
            $sale = ProductFeed::salePrice($row, $includeTax);
            $group = (string) ($row['group'] ?? '');
            $available = $row['available'] ?? null;

            $out[] = [
                'id' => (string) $row['id'],
                // Un gruppo che coincide con un id confonderebbe Meta: meglio senza.
                'item_group_id' => isset($ids[$group]) ? '' : ProductFeed::cut($group, 100),
                'title' => ProductFeed::title($row, 200),
                'description' => ProductFeed::description($row, 9999),
                'availability' => ProductFeed::inStock($row) ? 'in stock' : 'out of stock',
                'condition' => 'new',
                'price' => ProductFeed::money(ProductFeed::price($row, $includeTax), $currency),
                'sale_price' => $sale !== null ? ProductFeed::money($sale, $currency) : '',
                'sale_price_effective_date' => ProductFeed::saleDates($row, $now),
                'link' => (string) $row['link'],
                'image_link' => (string) $row['image'],
                'additional_image_link' => implode(',', array_slice(array_values(array_diff($row['images'] ?? [], [$row['image']])), 0, 20)),
                'brand' => ProductFeed::brand($row, (string) ($options['brand'] ?? ''), 100),
                'gtin' => ProductFeed::gtin($row),
                'mpn' => ProductFeed::cut((string) ($row['mpn'] ?? ''), 100),
                'product_type' => (string) ($row['categories'] ?? '') !== '' ? str_replace(' › ', ' > ', explode(' | ', (string) $row['categories'])[0]) : '',
                // Illimitata: niente numero, Meta la considera disponibile.
                'quantity_to_sell_on_facebook' => $available === null ? '' : (string) max(0, (int) floor((float) $available)),
                'shipping_weight' => ProductFeed::weight($row),
                'status' => 'active',
            ];
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $rows */
    public static function csv(array $rows, array $options = []): string
    {
        $lines = array_map(
            static fn (array $item): array => array_map(static fn (string $field): string => $item[$field], self::FIELDS),
            self::items($rows, $options)
        );

        return Delimited::write(self::FIELDS, $lines);
    }
}
