<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Models\Tax\TaxRule;
use Wonder\Plugin\Gestionale\Support\Export\Delimited;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;
use Wonder\Plugin\Gestionale\Support\Stock\StockBadge;
use Wonder\Plugin\Gestionale\Support\Tax\TaxResolver;

/**
 * L'elenco dei prodotti da scaricare (D64): una riga per ogni opzione in
 * vendita, quella che ha un prezzo, un codice e una giacenza.
 *
 * I campi sono quelli che chiedono i cataloghi di fuori — Google, Meta e
 * gli altri leggeranno queste stesse righe — ma scritti per chi apre il
 * file in Excel: intestazioni in italiano, virgola nei decimali. Il codice
 * dell'articolo è quello che i feed chiamano `item_group_id`, l'EAN il loro
 * `gtin`.
 *
 * Ci sono tutti, anche le bozze e le opzioni spente: le colonne
 * «Pubblicato», «Online» e «Attivo» dicono chi lo è. Il prezzo è quello
 * scritto sull'opzione: le campagne di sconto non entrano.
 *
 * Oltre alle colonne del file, ogni riga porta `images` (tutte le foto) e
 * `tax_rate` (l'aliquota dell'articolo): servono ai feed in `Exports\`.
 *
 * `of()` legge; `rows()`, `csv()` e `xlsx()` sono pure.
 */
final class ProductExport
{
    /** Le colonne, nell'ordine del file: chiave → intestazione. */
    private const COLUMNS = [
        'id' => 'ID',
        'group' => 'Codice articolo',
        'sku' => 'SKU',
        'ean' => 'EAN',
        'mpn' => 'MPN',
        'article' => 'Articolo',
        'option' => 'Opzione',
        'type' => 'Tipo',
        'brand' => 'Marchio',
        'categories' => 'Categorie',
        'price' => 'Prezzo',
        'sale_price' => 'Prezzo scontato',
        'available' => 'Disponibili',
        'backorder' => 'Vendibile senza giacenza',
        'visible' => 'Pubblicato',
        'visible_online' => 'Online',
        'active' => 'Attivo',
        'weight' => 'Peso (kg)',
        'unit' => 'Unità',
        'link' => 'Link',
        'image' => 'Immagine',
        'short_description' => 'Descrizione breve',
        'description' => 'Descrizione',
    ];

    /** Le colonne numeriche, coi decimali da scrivere. */
    private const NUMBERS = ['price' => 2, 'sale_price' => 2, 'available' => 3, 'weight' => 3];

    /** Le categorie di un articolo, una dopo l'altra. */
    private const CATEGORY_SEPARATOR = ' | ';

    /**
     * Le intestazioni. Il prezzo dice se l'IVA c'è: è una sola impostazione
     * per tutto il catalogo, non serve ripeterla a ogni riga.
     *
     * @return list<string>
     */
    public static function headers(bool $pricesIncludeTax = true): array
    {
        $headers = self::COLUMNS;
        $tax = $pricesIncludeTax ? ' (IVA inclusa)' : ' (IVA esclusa)';
        $headers['price'] .= $tax;
        $headers['sale_price'] .= $tax;

        return array_values($headers);
    }

    /**
     * Le righe, dai dati già letti.
     *
     * @param array{
     *     models?: list<array<string, mixed>>,
     *     products?: list<array<string, mixed>>,
     *     variants?: list<array<string, mixed>>,
     *     images?: list<array<string, mixed>>,
     *     categories?: list<array<string, mixed>>,
     *     links?: list<array<string, mixed>>,
     *     brands?: list<array<string, mixed>>,
     *     stock?: array<int, float>,
     *     bundles?: array<int, float>,
     *     backorders?: bool,
     *     base?: string,
     *     url?: string,
     *     variantUrl?: string,
     *     taxRates?: array<int, float>
     * } $data `stock` per opzione, `bundles` e `taxRates` per articolo; `url` e
     *   `variantUrl` hanno `{slug}` e `{variante}` al posto degli slug
     * @return list<array<string, string|float|null>>
     */
    public static function rows(array $data): array
    {
        $models = self::byId($data['models'] ?? []);
        $variants = self::byId($data['variants'] ?? []);
        $brands = self::byId($data['brands'] ?? []);
        $paths = [];

        foreach (CategoryTree::sorted($data['categories'] ?? []) as $category) {
            $paths[(int) $category['id']] = self::text((string) ($category['path'] ?? ''));
        }

        $categoriesOf = [];
        $links = $data['links'] ?? [];
        usort($links, static fn (array $a, array $b): int =>
            [($a['is_main'] ?? 'false') === 'true' ? 0 : 1, (int) ($a['position'] ?? 0)]
            <=> [($b['is_main'] ?? 'false') === 'true' ? 0 : 1, (int) ($b['position'] ?? 0)]);

        foreach ($links as $link) {
            $path = $paths[(int) ($link['category_id'] ?? 0)] ?? '';

            if ($path !== '') {
                $categoriesOf[(int) ($link['product_model_id'] ?? 0)][] = $path;
            }
        }

        $imagesOf = [];

        foreach ($data['images'] ?? [] as $image) {
            $imagesOf[(int) ($image['product_model_id'] ?? 0)][] = $image;
        }

        $visibleVariants = [];

        foreach ($variants as $variant) {
            if (($variant['visible'] ?? 'true') === 'true') {
                $model = (int) ($variant['product_model_id'] ?? 0);
                $visibleVariants[$model] = ($visibleVariants[$model] ?? 0) + 1;
            }
        }

        $modelNames = array_map(static fn (array $model): string => self::text((string) ($model['name'] ?? '')), $models);
        $products = $data['products'] ?? [];
        $order = array_flip(array_keys($models));
        usort($products, static fn (array $a, array $b): int =>
            [$order[(int) ($a['product_model_id'] ?? 0)] ?? PHP_INT_MAX, (int) ($a['position'] ?? 0), (int) ($a['id'] ?? 0)]
            <=> [$order[(int) ($b['product_model_id'] ?? 0)] ?? PHP_INT_MAX, (int) ($b['position'] ?? 0), (int) ($b['id'] ?? 0)]);

        $out = [];

        foreach ($products as $product) {
            $modelId = (int) ($product['product_model_id'] ?? 0);
            $model = $models[$modelId] ?? null;

            if ($model === null) {
                continue;
            }

            $id = (int) ($product['id'] ?? 0);
            $variantId = (int) ($product['product_variant_id'] ?? 0);
            $variant = $variants[$variantId] ?? null;
            $bundle = ($model['type'] ?? 'simple') === 'bundle';
            $names = ProductNames::of($product, $modelNames);
            $price = self::amount($product['price'] ?? null);
            $sale = self::amount($product['sale_price'] ?? null);
            $weight = self::amount($product['weight'] ?? null);
            $available = $bundle ? ($data['bundles'][$modelId] ?? 0.0) : ($data['stock'][$id] ?? 0.0);
            $images = array_values(array_filter(array_map(
                static fn (array $image): string => self::absolute(ProductImages::url($image), (string) ($data['base'] ?? '')),
                ProductImages::for($imagesOf[$modelId] ?? [], $variantId ?: null, $id)
            )));
            $variantSlug = $variant !== null && ($visibleVariants[$modelId] ?? 0) >= 2 ? trim((string) ($variant['slug'] ?? '')) : '';
            $brand = $brands[(int) ($model['brand_id'] ?? 0)] ?? null;

            $out[] = [
                'id' => (string) $id,
                'group' => self::text((string) ($model['sku'] ?? '')),
                'sku' => self::text((string) ($product['sku'] ?? '')),
                'ean' => trim((string) ($product['ean'] ?? '')),
                'mpn' => self::text((string) ($product['mpn'] ?? '')),
                'article' => $names['article'] === '—' ? '' : $names['article'],
                'option' => self::text($names['option']),
                'type' => $bundle ? 'Multiprodotto' : 'Prodotto',
                'brand' => $brand !== null ? self::text((string) ($brand['name'] ?? '')) : '',
                'categories' => implode(self::CATEGORY_SEPARATOR, array_unique($categoriesOf[$modelId] ?? [])),
                'price' => $price,
                // Uno «scontato» che non sconta non è un'offerta.
                'sale_price' => $sale !== null && $sale > 0 && ($price === null || $sale < $price) ? $sale : null,
                // Un multiprodotto fatto solo di pezzi che si vendono scoperti non finisce mai.
                'available' => $available >= StockBadge::UNLIMITED ? null : round((float) $available, 3),
                'backorder' => self::yes(($data['backorders'] ?? false) && ($product['allow_backorder'] ?? 'false') === 'true'),
                'visible' => self::yes(($model['visible'] ?? 'true') === 'true'),
                'visible_online' => self::yes(($model['visible_online'] ?? 'true') === 'true'),
                'active' => self::yes(($product['active'] ?? 'true') === 'true'),
                'weight' => $weight !== null && $weight > 0 ? $weight : self::amount($model['weight'] ?? null),
                'unit' => self::text((string) ($model['unit'] ?? '')),
                'link' => self::link((string) ($data['url'] ?? ''), (string) ($data['variantUrl'] ?? ''), trim((string) ($model['slug'] ?? '')), $variantSlug),
                'image' => $images[0] ?? '',
                'short_description' => self::plain((string) ($model['short_description'] ?? '')),
                'description' => self::plain((string) ($model['description'] ?? '')),
                // Fuori dal file, per i feed: le altre foto e l'aliquota per il prezzo ivato.
                'images' => $images,
                'tax_rate' => isset($data['taxRates'][$modelId]) ? (float) $data['taxRates'][$modelId] : null,
            ];
        }

        return $out;
    }

    /**
     * Un .csv standard: UTF-8, virgola tra i campi e punto nei decimali,
     * come lo leggono i programmi d'importazione.
     *
     * @param list<array<string, string|float|null>> $rows
     */
    public static function csv(array $rows, bool $pricesIncludeTax = true): string
    {
        $keys = array_keys(self::COLUMNS);
        $lines = array_map(static fn (array $row): array => array_map(
            static fn (string $key): string => isset(self::NUMBERS[$key])
                ? self::number($key, $row[$key] ?? null)
                : (string) ($row[$key] ?? ''),
            $keys
        ), $rows);

        // Chi lo apre a mano lo apre in un foglio di calcolo: niente formule.
        return Delimited::write(self::headers($pricesIncludeTax), $lines, Delimited::CSV, true);
    }

    /**
     * Lo stesso elenco in un file Excel vero. I codici restano testo — un
     * EAN letto come numero perde gli zeri davanti e finisce in notazione
     * scientifica — e i prezzi restano numeri, da sommare.
     *
     * @param list<array<string, string|float|null>> $rows
     */
    public static function xlsx(array $rows, bool $pricesIncludeTax = true): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Prodotti');
        $keys = array_keys(self::COLUMNS);

        foreach (self::headers($pricesIncludeTax) as $index => $header) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 1).'1', $header, DataType::TYPE_STRING);
        }

        $sheet->getStyle('1:1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach ($rows as $line => $row) {
            foreach ($keys as $index => $key) {
                $cell = Coordinate::stringFromColumnIndex($index + 1).($line + 2);
                $value = $row[$key] ?? null;

                if (isset(self::NUMBERS[$key])) {
                    if ($value !== null) {
                        $sheet->setCellValueExplicit($cell, (float) $value, DataType::TYPE_NUMERIC);
                        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::NUMBERS[$key] === 2 ? '#,##0.00' : '#,##0.###');
                    } elseif ($key === 'available') {
                        $sheet->setCellValueExplicit($cell, 'Illimitata', DataType::TYPE_STRING);
                    }

                    continue;
                }

                if ((string) $value !== '') {
                    $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                }
            }
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        (new Xlsx($book))->save($handle);
        rewind($handle);
        $file = (string) stream_get_contents($handle);
        fclose($handle);
        $book->disconnectWorksheets();

        return $file;
    }

    /**
     * Legge tutto il catalogo, una query per tabella.
     *
     * @return array{rows: list<array<string, string|float|null>>, pricesIncludeTax: bool}
     */
    public static function of(): array
    {
        $models = self::listOf(ProductModel::find("`deleted` = 'false'", null, 'name', 'ASC'));
        $modelIds = array_map(static fn (array $model): int => (int) $model['id'], $models);

        if ($modelIds === []) {
            return ['rows' => [], 'pricesIncludeTax' => self::pricesIncludeTax()];
        }

        $in = '`product_model_id` IN ('.implode(', ', $modelIds).") AND `deleted` = 'false'";
        $products = self::listOf(Product::find($in));
        $simple = [];
        $bundles = [];

        foreach ($models as $model) {
            if (($model['type'] ?? 'simple') === 'bundle') {
                $bundles[(int) $model['id']] = self::bundleStock((int) $model['id']);
            }
        }

        foreach ($products as $product) {
            if (!isset($bundles[(int) ($product['product_model_id'] ?? 0)])) {
                $simple[] = (int) $product['id'];
            }
        }

        $stock = array_map(static fn (array $level): float => (float) $level['available'], StockBadge::forProducts($simple));

        return [
            'rows' => self::rows([
                'models' => $models,
                'products' => $products,
                'variants' => self::listOf(ProductVariant::find($in)),
                'images' => self::listOf(ProductImage::find($in)),
                'categories' => self::listOf(Category::find("`deleted` = 'false'")),
                'links' => self::listOf(ProductModelCategory::find($in)),
                'brands' => self::listOf(Brand::find("`deleted` = 'false'")),
                'stock' => $stock,
                'bundles' => $bundles,
                'backorders' => Gestionale::feature('backorders'),
                'base' => defined('APP_URL') ? (string) constant('APP_URL') : '',
                'url' => self::route('ecommerce.catalog.product', ['slug' => '{slug}']),
                'variantUrl' => self::route('ecommerce.catalog.product.variant', ['slug' => '{slug}', 'variante' => '{variante}']),
                'taxRates' => self::taxRates($models),
            ]),
            'pricesIncludeTax' => self::pricesIncludeTax(),
        ];
    }

    /** Il nome del file, con la data: `prodotti-2026-10-15.csv`; `$name` cambia il prefisso. */
    public static function fileName(string $extension, ?int $time = null, string $name = 'prodotti'): string
    {
        return $name.'-'.date('Y-m-d', $time ?? time()).'.'.$extension;
    }

    /**
     * L'indirizzo della pagina nel negozio. La variante entra solo quando
     * l'articolo ne ha almeno due visibili, come nel negozio.
     */
    private static function link(string $url, string $variantUrl, string $slug, string $variantSlug): string
    {
        if ($slug === '' || $url === '') {
            return '';
        }

        if ($variantSlug !== '' && $variantUrl !== '') {
            return str_replace(['{slug}', '{variante}'], [rawurlencode($slug), rawurlencode($variantSlug)], $variantUrl);
        }

        return str_replace('{slug}', rawurlencode($slug), $url);
    }

    /**
     * Una rotta dell'ecommerce con i segnaposto al loro posto; vuota quando
     * il negozio non c'è. Il router codifica le graffe: si rimettono.
     *
     * @param array<string, string> $parameters
     */
    private static function route(string $name, array $parameters): string
    {
        if (!function_exists('__r')) {
            return '';
        }

        try {
            $url = (string) __r($name, $parameters);
        } catch (Throwable) {
            return '';
        }

        $url = str_replace(['%7Bslug%7D', '%7Bvariante%7D', '%7bslug%7d', '%7bvariante%7d'], ['{slug}', '{variante}', '{slug}', '{variante}'], $url);

        foreach (array_values($parameters) as $placeholder) {
            if (!str_contains($url, $placeholder)) {
                return '';
            }
        }

        return $url;
    }

    /**
     * L'aliquota di ogni articolo per un privato del paese del negozio
     * (`exports.country`, Italia se manca): quella che il carrello
     * applicherebbe, regola del tipo fiscale o aliquota di ripiego.
     *
     * @param list<array<string, mixed>> $models
     * @return array<int, float>
     */
    private static function taxRates(array $models): array
    {
        try {
            $rules = TaxRule::active();
            $fallback = (int) (Setting::current()['fallback_tax_id'] ?? 0);
            $rates = [];

            foreach (self::listOf(Tax::find(['deleted' => 'false'])) as $tax) {
                $rates[(int) $tax['id']] = (float) ($tax['rate'] ?? 0);
            }
        } catch (Throwable) {
            return [];
        }

        $country = (string) Gestionale::config('exports.country', 'IT');
        $out = [];

        foreach ($models as $model) {
            $taxId = TaxResolver::resolve($rules, $country, 'private', (int) ($model['tax_category_id'] ?? 0), $fallback);

            if (isset($rates[$taxId])) {
                $out[(int) $model['id']] = $rates[$taxId];
            }
        }

        return $out;
    }

    private static function bundleStock(int $modelId): float
    {
        try {
            return Bundles::available($modelId);
        } catch (Throwable) {
            return 0.0;
        }
    }

    private static function pricesIncludeTax(): bool
    {
        try {
            $settings = Setting::current();
        } catch (Throwable) {
            $settings = [];
        }

        return (string) ($settings['catalog_prices_include_tax'] ?? 'true') !== 'false';
    }

    private static function absolute(string $path, string $base): string
    {
        if ($path === '' || preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    private static function amount(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /** I decimali col punto, senza zeri inutili dove non servono. */
    private static function number(string $key, mixed $value): string
    {
        if ($value === null) {
            return $key === 'available' ? 'Illimitata' : '';
        }

        $decimals = self::NUMBERS[$key];
        $text = number_format((float) $value, $decimals, '.', '');

        return $decimals === 2 ? $text : rtrim(rtrim($text, '0'), '.');
    }

    private static function yes(bool $value): string
    {
        return $value ? 'Sì' : 'No';
    }

    /** Il testo di una descrizione ricca: niente tag, gli a capo come spazi. */
    private static function plain(string $html): string
    {
        // Il form salva l'HTML con le entità: prima si decodifica, poi si tolgono i tag.
        $text = self::text($html);
        $text = preg_replace('#<(br|p|div|li|h[1-6])\b[^>]*>#i', ' ', $text) ?? $text;
        $text = self::text(strip_tags($text));

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function text(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function byId(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) {
                $out[(int) $row['id']] = $row;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function listOf(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
