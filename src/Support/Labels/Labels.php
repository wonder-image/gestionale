<?php

namespace Wonder\Plugin\Gestionale\Support\Labels;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductExport;

/**
 * Le etichette con codice a barre (#116) di un articolo, di una variante o
 * di un documento di carico. Il prezzo è quello al pubblico: lo scontato se
 * sconta davvero, con l'IVA quando il listino è senza.
 */
final class Labels
{
    public const SOURCES = ['model', 'product', 'receipt'];

    /** Oltre questo numero un PDF diventa un rischio per il server, non un foglio di etichette. */
    public const MAX_LABELS = 2000;

    public const MAX_COPIES = 500;

    /**
     * La risposta alla rotta: 403 a funzionalità spenta, 404 se il documento
     * non c'è, altrimenti il PDF.
     *
     * @param array<string, mixed> $query
     * @return array{status: int, body: string, filename: string}
     */
    public static function respond(string $source, int $id, array $query): array
    {
        if (!Gestionale::feature('barcode_labels')) {
            return ['status' => 403, 'body' => 'Etichette non attive', 'filename' => ''];
        }

        $options = self::options($query);
        $items = in_array($source, self::SOURCES, true) && $id > 0
            ? self::items($source, $id, $options['copies'])
            : null;

        if ($items === null) {
            return ['status' => 404, 'body' => 'Non trovato', 'filename' => ''];
        }

        $labels = array_slice(LabelSheet::labels($items, $options['fields']), 0, self::MAX_LABELS);

        return [
            'status' => 200,
            'body' => LabelSheet::render($options['format'], $labels, $options['start']),
            'filename' => 'etichette-'.$source.'-'.$id.'.pdf',
        ];
    }

    /**
     * Le scelte della finestra di stampa, ripulite: un formato che non c'è
     * diventa il primo, i campi sconosciuti spariscono.
     *
     * @param array<string, mixed> $query
     * @return array{format: LabelFormat, fields: list<string>, copies: int, start: int}
     */
    public static function options(array $query): array
    {
        $formats = LabelFormats::all();
        $format = $formats[(string) ($query['format'] ?? '')] ?? reset($formats);

        $fields = $query['fields'] ?? null;

        if (is_string($fields)) {
            $decoded = json_decode($fields, true);
            $fields = is_array($decoded) ? $decoded : explode(',', $fields);
        }

        $fields = is_array($fields)
            ? array_values(array_intersect(array_keys(LabelSheet::FIELDS), array_map('strval', $fields)))
            : [];
        // Niente spuntato è una dimenticanza, non un foglio di etichette bianche.
        $fields = $fields !== [] ? $fields : LabelSheet::DEFAULT_FIELDS;

        // `start` arriva contato da 1, come la casella sul foglio.
        $start = max(0, (int) ($query['start'] ?? 1) - 1);

        return [
            'format' => $format,
            'fields' => $fields,
            'copies' => max(1, min(self::MAX_COPIES, (int) ($query['copies'] ?? 1))),
            'start' => $format->isRoll() ? 0 : min($start, $format->perPage() - 1),
        ];
    }

    /**
     * Le righe da stampare; `null` se il documento non c'è.
     *
     * @return list<array{article: string, variant: string, sku: string, price: ?float, ean: string, copies: int}>|null
     */
    public static function items(string $source, int $id, int $copies = 1): ?array
    {
        return match ($source) {
            'model' => self::forModel($id, $copies),
            'product' => self::forProduct($id, $copies),
            'receipt' => self::forReceipt($id),
            default => null,
        };
    }

    /** Tutte le varianti attive dell'articolo, `$copies` ciascuna. */
    public static function forModel(int $id, int $copies = 1): ?array
    {
        $model = ProductModel::findById($id);

        if (!self::alive($model)) {
            return null;
        }

        $products = self::listOf(Product::find(
            ['product_model_id' => $id, 'deleted' => 'false', 'active' => 'true'],
            null,
            'position',
            'ASC'
        ));

        return self::rows($products, $copies);
    }

    public static function forProduct(int $id, int $copies = 1): ?array
    {
        $product = Product::findById($id);

        return self::alive($product) ? self::rows([$product], $copies) : null;
    }

    /** Una etichetta per pezzo ricevuto (o atteso, se non ancora contato). */
    public static function forReceipt(int $id): ?array
    {
        $document = StockDocument::findById($id);

        if (!self::alive($document) || ($document['type'] ?? '') !== 'receipt') {
            return null;
        }

        $quantities = [];

        foreach (self::listOf(StockDocumentItem::find(['stock_document_id' => $id, 'deleted' => 'false'], null, 'id', 'ASC')) as $line) {
            $quantity = $line['quantity'] ?? null;
            $quantity = $quantity === null || $quantity === '' ? ($line['expected_quantity'] ?? 0) : $quantity;
            $pieces = (int) ceil((float) $quantity);
            $productId = (int) ($line['product_id'] ?? 0);

            if ($pieces > 0 && $productId > 0) {
                $quantities[] = [$productId, $pieces];
            }
        }

        $products = [];

        foreach (array_unique(array_column($quantities, 0)) as $productId) {
            $product = Product::findById($productId);

            if (self::alive($product)) {
                $products[$productId] = $product;
            }
        }

        $rows = [];
        $byProduct = [];

        foreach (self::rows(array_values($products), 1) as $index => $row) {
            $byProduct[(int) array_values($products)[$index]['id']] = $row;
        }

        foreach ($quantities as [$productId, $pieces]) {
            if (isset($byProduct[$productId])) {
                $rows[] = ['copies' => min(self::MAX_COPIES, $pieces)] + $byProduct[$productId];
            }
        }

        return $rows;
    }

    /**
     * Prezzo al pubblico di un prodotto: lo scontato quando è sotto il pieno.
     * Il prezzo vive sul prodotto, senza ricadere sull'articolo.
     */
    public static function price(array $product, ?float $taxRate = null, bool $includesTax = true): ?float
    {
        $price = self::amount($product['price'] ?? null);
        $sale = self::amount($product['sale_price'] ?? null);

        if ($sale !== null && $sale > 0 && ($price === null || $sale < $price)) {
            $price = $sale;
        }

        if ($price === null) {
            return null;
        }

        return !$includesTax && $taxRate !== null ? round($price * (1 + $taxRate / 100), 2) : $price;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array{article: string, variant: string, sku: string, price: ?float, ean: string, copies: int}>
     */
    private static function rows(array $products, int $copies): array
    {
        $models = [];

        foreach ($products as $product) {
            $modelId = (int) ($product['product_model_id'] ?? 0);

            if ($modelId > 0 && !isset($models[$modelId])) {
                $model = ProductModel::findById($modelId);
                $models[$modelId] = is_array($model) && isset($model['id']) ? $model : ['id' => $modelId];
            }
        }

        $includesTax = ProductExport::pricesIncludeTax();
        $rates = $includesTax ? [] : ProductExport::taxRates(array_values($models));
        $rows = [];

        foreach ($products as $product) {
            $modelId = (int) ($product['product_model_id'] ?? 0);

            $rows[] = [
                'article' => self::plain($models[$modelId]['name'] ?? ''),
                'variant' => self::plain($product['name'] ?? ''),
                'sku' => self::plain($product['sku'] ?? ''),
                'price' => self::price($product, $rates[$modelId] ?? null, $includesTax),
                'ean' => trim((string) ($product['ean'] ?? '')),
                'copies' => $copies,
            ];
        }

        return $rows;
    }

    private static function alive(mixed $row): bool
    {
        return is_array($row) && isset($row['id']) && ($row['deleted'] ?? 'false') !== 'true';
    }

    private static function plain(mixed $value): string
    {
        return trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function amount(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
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
