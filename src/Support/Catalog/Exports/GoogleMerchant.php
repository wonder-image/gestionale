<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog\Exports;

use Wonder\Plugin\Gestionale\Support\Export\XmlFeed;

/**
 * Il file per Google Merchant Center: un feed XML RSS 2.0 con i campi `g:`
 * della specifica dei dati di prodotto di Google.
 *
 * `g:id` è l'opzione, `g:item_group_id` il codice articolo, solo quando
 * l'articolo ha più opzioni nel feed. Disponibilità `in_stock`/`out_of_stock`,
 * prezzi "12.90 EUR". Senza EAN né MPN si dichiara `identifier_exists: no`.
 *
 * Classe pura.
 */
final class GoogleMerchant
{
    /**
     * @param list<array<string, mixed>> $rows righe già filtrate da `ProductFeed`
     * @param array{pricesIncludeTax?: bool, currency?: string, now?: string, brand?: string} $options
     * @return list<array<string, string|list<string>>>
     */
    public static function items(array $rows, array $options = []): array
    {
        $includeTax = $options['pricesIncludeTax'] ?? true;
        $currency = $options['currency'] ?? 'EUR';
        $now = $options['now'] ?? date('Y-m-d H:i:s');
        $groups = array_count_values(array_filter(array_map(static fn (array $row): string => (string) ($row['group'] ?? ''), $rows)));
        $out = [];

        foreach ($rows as $row) {
            $sale = ProductFeed::salePrice($row, $includeTax);
            $group = (string) ($row['group'] ?? '');
            $gtin = ProductFeed::gtin($row);
            $mpn = ProductFeed::cut((string) ($row['mpn'] ?? ''), 70);
            $categories = (string) ($row['categories'] ?? '');

            $out[] = [
                'g:id' => (string) $row['id'],
                'g:item_group_id' => $group !== '' && ($groups[$group] ?? 0) > 1 ? ProductFeed::cut($group, 50) : '',
                'g:title' => ProductFeed::title($row, 150),
                'g:description' => ProductFeed::description($row, 5000),
                'g:link' => (string) $row['link'],
                'g:image_link' => (string) $row['image'],
                'g:additional_image_link' => array_slice(array_values(array_diff($row['images'] ?? [], [$row['image']])), 0, 10),
                'g:availability' => ProductFeed::inStock($row) ? 'in_stock' : 'out_of_stock',
                'g:price' => ProductFeed::money(ProductFeed::price($row, $includeTax), $currency),
                'g:sale_price' => $sale !== null ? ProductFeed::money($sale, $currency) : '',
                'g:sale_price_effective_date' => ProductFeed::saleDates($row, $now),
                'g:condition' => 'new',
                'g:brand' => ProductFeed::brand($row, (string) ($options['brand'] ?? ''), 70),
                'g:gtin' => $gtin,
                'g:mpn' => $mpn,
                'g:identifier_exists' => $gtin === '' && $mpn === '' ? 'no' : '',
                'g:product_type' => $categories !== '' ? ProductFeed::cut(str_replace(' › ', ' > ', explode(' | ', $categories)[0]), 750) : '',
                'g:shipping_weight' => ProductFeed::weight($row),
            ];
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $rows */
    public static function xml(array $rows, array $options = []): string
    {
        return XmlFeed::rss([
            'title' => (string) ($options['brand'] ?? '') ?: 'Prodotti',
            'link' => (string) ($options['url'] ?? ''),
            'description' => 'Catalogo prodotti',
        ], self::items($rows, $options));
    }
}
