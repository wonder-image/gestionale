<?php

namespace Wonder\Plugin\Gestionale\Support\Labels;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductExport;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentPdf;
use Wonder\Plugin\Gestionale\Support\Promotions\ProductScope;
use Wonder\Plugin\Gestionale\Support\Promotions\ScopeMatcher;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionModels;
use Wonder\Support\Labels\LabelFormat;
use Wonder\Support\Labels\LabelSheet;

/**
 * Le etichette con codice a barre (#116) di una scelta di prodotti e
 * varianti (la pagina Catalogo › Etichette) o di un documento di carico. Il prezzo è quello al pubblico: lo scontato se
 * sconta davvero, con l'IVA quando il listino è senza.
 */
final class Labels
{
    public const SOURCES = ['model', 'product', 'receipt', 'scope'];

    /** Oltre questo numero un PDF diventa un rischio per il server, non un foglio di etichette. */
    public const MAX_LABELS = 2000;

    public const MAX_COPIES = 500;

    /** I campi che si possono stampare, nell'ordine in cui escono. */
    public const FIELDS = [
        'article' => 'Nome articolo',
        'variant' => 'Variante',
        'sku' => 'SKU',
        'price' => 'Prezzo',
        'barcode' => 'Codice a barre',
    ];

    public const DEFAULT_FIELDS = ['article', 'variant', 'price', 'barcode'];

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
        $items = match (true) {
            $source === 'scope' => self::forScope(is_array($query['scope'] ?? null) ? $query['scope'] : [], $options['copies']),
            in_array($source, self::SOURCES, true) && $id > 0 => self::items($source, $id, $options['copies']),
            default => null,
        };

        if ($items === null) {
            return ['status' => 404, 'body' => $source === 'scope' ? 'Nessun prodotto da stampare nella selezione' : 'Non trovato', 'filename' => ''];
        }

        $labels = array_slice(LabelSheet::labels(self::contents($items, $options['fields'])), 0, self::MAX_LABELS);

        return [
            'status' => 200,
            'body' => LabelSheet::render($options['format'], $labels, $options['start']),
            'filename' => $source === 'scope' ? 'etichette-prodotti.pdf' : 'etichette-'.$source.'-'.$id.'.pdf',
        ];
    }

    /**
     * Le scelte della finestra di stampa, ripulite: il formato è quello delle
     * Impostazioni, i campi sconosciuti spariscono.
     *
     * @param array<string, mixed> $query
     * @return array{format: LabelFormat, fields: list<string>, copies: int, start: int}
     */
    public static function options(array $query): array
    {
        $format = LabelFormats::current();

        $fields = $query['fields'] ?? null;

        if (is_string($fields)) {
            $decoded = json_decode($fields, true);
            $fields = is_array($decoded) ? $decoded : explode(',', $fields);
        }

        $fields = is_array($fields)
            ? array_values(array_intersect(array_keys(self::FIELDS), array_map('strval', $fields)))
            : [];
        // Niente spuntato è una dimenticanza, non un foglio di etichette bianche.
        $fields = $fields !== [] ? $fields : self::DEFAULT_FIELDS;

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
     * Le righe da stampare tradotte nel contenuto del foglio del core: solo i
     * campi scelti, lo SKU col prefisso, il prezzo come valore in evidenza.
     *
     * @param list<array{article?: string, variant?: string, sku?: string, price?: float|null, ean?: string, copies?: int}> $items
     * @param list<string> $fields
     * @return list<array{lines: list<string>, highlight: string, ean: string, copies: int}>
     */
    public static function contents(array $items, array $fields): array
    {
        $out = [];

        foreach ($items as $item) {
            $lines = [];

            foreach (['article', 'variant', 'sku'] as $field) {
                $value = trim((string) ($item[$field] ?? ''));

                if (in_array($field, $fields, true) && $value !== '') {
                    $lines[] = $field === 'sku' ? 'SKU '.$value : $value;
                }
            }

            $price = $item['price'] ?? null;

            $out[] = [
                // La variante che ripete l'articolo (prodotto senza varianti) non si stampa due volte.
                'lines' => array_values(array_unique($lines)),
                'highlight' => in_array('price', $fields, true) && $price !== null ? DocumentPdf::money($price) : '',
                'ean' => in_array('barcode', $fields, true) ? trim((string) ($item['ean'] ?? '')) : '',
                'copies' => (int) ($item['copies'] ?? 1),
            ];
        }

        return $out;
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

    /**
     * Le righe della pagina Catalogo › Etichette: le varianti attive degli
     * articoli che il selettore (lo stesso del coupon) prende, in ordine di
     * nome; `null` se non resta niente.
     *
     * @param array{all?: bool, categories?: list<int>, tags?: list<int>, brands?: list<int>, models?: list<int>, excluded_models?: list<int>} $scope
     * @return list<array{article: string, variant: string, sku: string, price: ?float, ean: string, copies: int}>|null
     */
    public static function forScope(array $scope, int $copies = 1): ?array
    {
        // Niente scelto: inutile leggere il catalogo.
        if (empty($scope['all']) && array_filter(array_intersect_key($scope, array_flip(['categories', 'tags', 'brands', 'models']))) === []) {
            return null;
        }

        $facts = [];

        foreach (ProductScope::catalog() as $entry) {
            $facts[(int) $entry['facts']['model_id']] ??= $entry['facts'];
        }

        $models = self::listOf(ProductModel::find(['deleted' => 'false'], null, 'name', 'ASC'));
        $chosen = self::modelsInScope($scope, $models, $facts);
        $products = [];

        foreach ($chosen as $modelId) {
            foreach (self::modelProducts($modelId) as $product) {
                $products[] = $product;
            }

            if (count($products) >= self::MAX_LABELS) {
                break;
            }
        }

        return $products === [] ? null : self::rows(array_slice($products, 0, self::MAX_LABELS), $copies);
    }

    /**
     * Gli articoli presi dal selettore, nell'ordine di `$models`: fuori gli
     * abbonamenti e quelli senza fatti (nessuna variante attiva).
     *
     * @param array<string, mixed> $scope
     * @param list<array<string, mixed>> $models
     * @param array<int, array{categories: list<int>, tags: list<int>, brand_id: int, model_id: int}> $facts
     * @return list<int>
     */
    public static function modelsInScope(array $scope, array $models, array $facts): array
    {
        $ids = [];

        foreach ($models as $model) {
            $id = (int) ($model['id'] ?? 0);

            if (isset($facts[$id]) && ($model['type'] ?? '') !== SubscriptionModels::TYPE && ScopeMatcher::matches($scope, $facts[$id])) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** Tutte le varianti attive dell'articolo, `$copies` ciascuna. */
    public static function forModel(int $id, int $copies = 1): ?array
    {
        $model = ProductModel::findById($id);

        if (!self::alive($model)) {
            return null;
        }

        return self::rows(self::modelProducts($id), $copies);
    }

    /** @return list<array<string, mixed>> */
    private static function modelProducts(int $id): array
    {
        return self::listOf(Product::find(
            ['product_model_id' => $id, 'deleted' => 'false', 'active' => 'true'],
            null,
            'position',
            'ASC'
        ));
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
