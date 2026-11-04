<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog\Import;

use RuntimeException;
use Throwable;
use Wonder\App\Import\Change;
use Wonder\App\Import\Column;
use Wonder\App\Import\Context;
use Wonder\App\Import\Layout;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Generator;
use Wonder\Plugin\Gestionale\Support\Catalog\ModelCategories;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductExport;
use Wonder\Plugin\Gestionale\Support\Catalog\SaleUnits;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Adjustment;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Stock\StockOut;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionModels;
use Wonder\Plugin\Gestionale\Support\Tax\TaxCategories;

/**
 * Il caricamento del catalogo: lo stesso file dell'esportazione, riletto.
 *
 * Una riga è una versione (`sku`). Uno SKU che c'è si aggiorna; uno nuovo con
 * un codice articolo che c'è diventa una versione in più di quell'articolo;
 * uno nuovo con un codice articolo nuovo diventa un articolo. Si scrivono solo
 * le celle che dicono qualcosa di diverso da quello che c'è.
 */
final class CatalogLayout extends Layout
{
    public const KEY = 'catalog';

    /** Nomi che le persone danno alle colonne, oltre alle intestazioni dell'esportazione. */
    private const ALIASES = [
        'sku' => ['Codice', 'SKU'],
        'available' => ['Quantità', 'Giacenza', 'Disponibili'],
        'article' => ['Nome', 'Articolo'],
        'group' => ['Codice articolo', 'Gruppo'],
        'price' => ['Prezzo'],
        'sale_price' => ['Prezzo scontato'],
    ];

    /** Celle che, vuote, non cancellano niente. */
    private const KEEP_WHEN_EMPTY = ['group', 'article', 'type', 'unit', 'price', 'weight', 'available'];

    private const MODEL_KEYS = ['article', 'brand', 'visible', 'visible_online', 'unit', 'short_description', 'description', 'categories'];

    private const PRODUCT_KEYS = ['sku', 'ean', 'mpn', 'option', 'price', 'sale_price', 'backorder', 'active', 'weight'];

    /** @var list<Column>|null */
    private ?array $columns = null;

    public static function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Catalogo';
    }

    public function permits(object $user): bool
    {
        return ProductModelResource::allows((array) ($user->authority ?? []), 'update');
    }

    public function backUrl(): ?string
    {
        try {
            return ProductModelResource::url();
        } catch (Throwable) {
            return null;
        }
    }

    public function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $columns = [];

        foreach (ProductExport::columns(ProductExport::pricesIncludeTax(), ProductExport::attributes()) as $column) {
            $aliases = array_values(array_unique(array_merge($column->aliases, self::ALIASES[$column->key] ?? [])));
            $columns[] = Column::make($column->key, $column->header, $aliases);
        }

        return $this->columns = $columns;
    }

    public function mappingErrors(array $mapping, array $headers): array
    {
        $errors = [];
        $keys = array_values(array_filter($mapping, static fn (?string $key): bool => $key !== null));

        if (!in_array('sku', $keys, true)) {
            $errors[] = self::text('sku_column');
        }

        $index = array_search('price', $mapping, true);

        if ($index !== false) {
            $tax = CatalogCells::headerTax((string) ($headers[$index] ?? ''));

            if ($tax !== null && $tax !== ProductExport::pricesIncludeTax()) {
                $errors[] = self::text($tax ? 'price_tax_included' : 'price_tax_excluded');
            }
        }

        return $errors;
    }

    public function validate(array $rows, Context $context): array
    {
        $export = $this->export($context);
        $models = $this->models($context);
        $attributes = $this->attributes($context);
        $seenSkus = [];
        $newGroups = [];
        $results = [];

        foreach ($rows as $row) {
            $data = array_diff_key($row['data'], array_flip(CatalogCells::IGNORED));
            $errors = [];
            $warnings = [];
            $sku = trim((string) ($data['sku'] ?? ''));
            $group = trim((string) ($data['group'] ?? ''));
            $skuKey = mb_strtolower($sku, 'UTF-8');
            $groupKey = mb_strtolower($group, 'UTF-8');
            $current = $export[$skuKey] ?? null;

            if ($sku === '') {
                $errors[] = self::text('sku_missing');
            } elseif (isset($seenSkus[$skuKey])) {
                $errors[] = self::text('sku_duplicate', ['line' => $seenSkus[$skuKey]]);
            } else {
                $seenSkus[$skuKey] = $row['line'];
            }

            if ($current !== null) {
                $mode = 'update';

                if ($group !== '' && $groupKey !== mb_strtolower((string) $current['group'], 'UTF-8')) {
                    $errors[] = self::text('group_mismatch', ['group' => (string) $current['group']]);
                }

                if (trim((string) ($data['type'] ?? '')) !== '' && trim((string) $data['type']) !== (string) $current['type']) {
                    $errors[] = self::text('type_change');
                }
            } else {
                $model = $models[$groupKey] ?? null;
                $mode = $group !== '' && ($model !== null || isset($newGroups[$groupKey])) ? 'new_version' : 'new_model';
                $type = trim((string) ($data['type'] ?? ''));

                if ($type !== '' && $type !== 'Prodotto') {
                    $errors[] = self::text('type_new');
                }

                if ($mode === 'new_version' && $model !== null && in_array((string) ($model['type'] ?? ''), ['bundle', SubscriptionModels::TYPE], true)) {
                    $errors[] = self::text('version_type');
                }

                if ($mode === 'new_model') {
                    if (trim((string) ($data['article'] ?? '')) === '') {
                        $errors[] = self::text('article_missing');
                    }

                    if ($group !== '') {
                        $newGroups[$groupKey] = true;
                    }
                }
            }

            $errors = array_merge($errors, $this->cellErrors($data, $current, $attributes));

            $image = trim((string) ($data['image'] ?? ''));

            if ($image !== '' && !RemoteImage::isHttps($image)) {
                $warnings[] = self::text('image_https');
            }

            $changed = $this->changedKeys($data, $current);

            $results[] = [
                'action' => $mode === 'update' ? ($changed === [] ? 'unchanged' : 'update') : 'create',
                'errors' => $errors,
                'warnings' => $warnings,
                'data' => $data + [
                    '_mode' => $mode,
                    '_product_id' => $current !== null ? (string) $current['id'] : '',
                ],
            ];
        }

        return $results;
    }

    public function apply(array $data, Context $context): array
    {
        $mode = (string) ($data['_mode'] ?? '');
        $current = $mode === 'update' ? ($this->export($context)[mb_strtolower(trim((string) $data['sku']), 'UTF-8')] ?? null) : null;
        $changes = [];

        if ($mode === 'update') {
            $productId = (int) ($data['_product_id'] ?? 0);
            $product = Product::findById($productId);

            if (!is_array($product) || !isset($product['id'])) {
                throw UserError::make('import.product_missing');
            }

            $modelId = (int) $product['product_model_id'];
            $variantId = (int) $product['product_variant_id'];
            $keys = $this->changedKeys($data, $current);

            if ($keys === []) {
                return ['action' => 'unchanged', 'changes' => []];
            }
        } elseif ($mode === 'new_version') {
            $model = $this->findModel(trim((string) $data['group']));

            if ($model === null) {
                throw UserError::make('import.model_missing');
            }

            $modelId = (int) $model['id'];
            $position = $this->productCount($modelId) + 1;
            $variantId = Generator::newVariant($modelId, $this->optionName($data), $position);
            $productId = $variantId > 0 ? Skeleton::product($modelId, $variantId, trim((string) $data['sku']), $position) : 0;

            if ($productId === 0) {
                throw UserError::make('import.save_failed');
            }

            $changes[] = new Change('variant', $variantId, 'created');
            $changes[] = new Change('product', $productId, 'created');

            if ((string) ($model['has_variants'] ?? 'false') !== 'true') {
                ProductModel::update(['has_variants' => 'true'], $modelId);
                $changes[] = new Change('model', $modelId, 'updated', ['has_variants' => (string) ($model['has_variants'] ?? 'false')]);
            }

            $keys = $this->filledKeys($data);
        } else {
            [$modelId, $variantId, $productId] = $this->createModel($data);
            $changes[] = new Change('model', $modelId, 'created');
            $changes[] = new Change('variant', $variantId, 'created');
            $changes[] = new Change('product', $productId, 'created');
            $keys = array_values(array_diff($this->filledKeys($data), ['article', 'unit', 'visible', 'visible_online', 'short_description', 'sku']));
        }

        array_push($changes, ...$this->writeModel($modelId, $data, $keys));
        array_push($changes, ...$this->writeProduct($productId, $data, $mode === 'update' ? $keys : array_diff($keys, ['sku'])));
        array_push($changes, ...$this->writeAttributes($productId, $variantId, $data, $keys, $context));

        if (in_array('available', $keys, true)) {
            array_push($changes, ...$this->writeStock($productId, $modelId, (string) $data['available'], $context));
        }

        if (in_array('image', $keys, true) && trim((string) $data['image']) !== '') {
            try {
                $shared = $mode !== 'new_version' && $this->productCount($modelId) <= 1;
                $imageId = RemoteImage::attach((string) $data['image'], $modelId, $shared ? 0 : $productId);

                if ($imageId > 0) {
                    $changes[] = new Change('image', $imageId, 'created');
                }
            } catch (RuntimeException $exception) {
                $context->warn($exception->getMessage());
            }
        }

        $context->forget('catalog.export');
        $context->forget('catalog.models');

        return ['action' => $mode === 'update' ? 'update' : 'create', 'changes' => $changes];
    }

    public function undo(Change $change, Context $context): void
    {
        $old = $change->oldValues;

        if ($change->action === 'stock') {
            $delta = -(float) ($old['delta'] ?? 0);

            if ($delta !== 0.0) {
                $this->moveStock($change->recordId, $delta, 'import_undo', $context);
            }

            return;
        }

        if ($change->target === 'categories') {
            $ids = array_map('intval', (array) ($old['ids'] ?? []));
            ModelCategories::link($change->recordId, $ids, $ids[0] ?? 0);

            return;
        }

        if (str_starts_with($change->target, 'attributes:')) {
            $level = substr($change->target, strlen('attributes:'));
            $attribute = $this->attributes($context)[(int) ($old['attribute_id'] ?? 0)] ?? null;

            if ($attribute !== null) {
                ProductAttributes::save($level, $change->recordId, [$attribute], [(int) $attribute['id'] => $old['input'] ?? null]);
            }

            return;
        }

        $class = match ($change->target) {
            'model' => ProductModel::class,
            'variant' => ProductVariant::class,
            'product' => Product::class,
            'brand' => Brand::class,
            'category' => Category::class,
            'attribute_value' => AttributeValue::class,
            'image' => ProductImage::class,
            default => null,
        };

        if ($class === null) {
            return;
        }

        if ($change->action === 'created') {
            $class::update(['deleted' => 'true'], $change->recordId);
        } elseif ($change->action === 'updated' && $old !== []) {
            $class::update($old, $change->recordId);
        }
    }

    public function message(Throwable $exception): ?string
    {
        return $exception instanceof RuntimeException ? $exception->getMessage() : null;
    }

    /**
     * Le righe dell'esportazione per SKU in minuscolo: quello che c'è.
     *
     * @return array<string, array<string, mixed>>
     */
    private function export(Context $context): array
    {
        return $context->remember('catalog.export', static function (): array {
            $bySku = [];

            foreach (ProductExport::of(true)['rows'] as $row) {
                $sku = mb_strtolower(trim((string) $row['sku']), 'UTF-8');

                if ($sku !== '' && !isset($bySku[$sku])) {
                    $bySku[$sku] = $row;
                }
            }

            return $bySku;
        });
    }

    /** @return array<string, array<string, mixed>> gli articoli per codice in minuscolo */
    private function models(Context $context): array
    {
        return $context->remember('catalog.models', static function (): array {
            $bySku = [];

            foreach (self::listOf(ProductModel::find(['deleted' => 'false'])) as $model) {
                $sku = mb_strtolower(trim((string) ($model['sku'] ?? '')), 'UTF-8');

                if ($sku !== '' && !isset($bySku[$sku])) {
                    $bySku[$sku] = $model;
                }
            }

            return $bySku;
        });
    }

    /** @return array<int, array<string, mixed>> gli attributi con una colonna, per id */
    private function attributes(Context $context): array
    {
        return $context->remember('catalog.attributes', static function (): array {
            $byId = [];

            foreach (ProductExport::attributes() as $attribute) {
                $byId[(int) $attribute['id']] = $attribute;
            }

            return $byId;
        });
    }

    /**
     * @param array<string, string> $data
     * @param array<string, mixed>|null $current
     * @param array<int, array<string, mixed>> $attributes
     * @return list<string>
     */
    private function cellErrors(array $data, ?array $current, array $attributes): array
    {
        $errors = [];
        $headers = [];

        foreach ($this->columns() as $column) {
            $headers[$column->key] = $column->header;
        }

        foreach (CatalogCells::NUMBERS as $key) {
            $value = trim((string) ($data[$key] ?? ''));

            if ($value === '' || ($key === 'available' && CatalogCells::unlimited($value))) {
                continue;
            }

            $number = CatalogCells::number($value);

            if ($number === null) {
                $errors[] = self::text('number', ['column' => $headers[$key] ?? $key]);
            } elseif ($number < 0) {
                $errors[] = self::text('negative', ['column' => $headers[$key] ?? $key]);
            }
        }

        foreach (CatalogCells::BOOLEANS as $key) {
            $value = trim((string) ($data[$key] ?? ''));

            if ($value !== '' && CatalogCells::boolean($value) === null) {
                $errors[] = self::text('boolean', ['column' => $headers[$key] ?? $key]);
            }
        }

        $price = array_key_exists('price', $data) && trim($data['price']) !== ''
            ? CatalogCells::number($data['price'])
            : ($current['price'] ?? null);
        $sale = CatalogCells::number((string) ($data['sale_price'] ?? ''));

        if ($sale !== null && $sale > 0 && $price !== null && $sale >= (float) $price) {
            $errors[] = self::text('sale_price');
        }

        $unit = trim((string) ($data['unit'] ?? ''));

        if ($unit !== '' && !array_key_exists($unit, SaleUnits::all())) {
            $errors[] = self::text('unit', ['units' => implode(', ', array_keys(SaleUnits::all()))]);
        }

        foreach ($attributes as $id => $attribute) {
            $value = trim((string) ($data['attr:'.$id] ?? ''));

            if ($value !== '' && (string) ($attribute['type'] ?? '') === 'number' && CatalogCells::number($value) === null) {
                $errors[] = self::text('number', ['column' => $headers['attr:'.$id] ?? (string) $attribute['name']]);
            }
        }

        if ($current !== null && in_array((string) $current['type'], ['Multiprodotto', 'Abbonamento'], true)
            && trim((string) ($data['available'] ?? '')) !== '' && !CatalogCells::same('available', (string) $data['available'], $current)) {
            $errors[] = self::text('available_type');
        }

        return $errors;
    }

    /**
     * Le colonne di una riga che dicono qualcosa di diverso da quello che c'è.
     *
     * @param array<string, string> $data
     * @param array<string, mixed>|null $current
     * @return list<string>
     */
    private function changedKeys(array $data, ?array $current): array
    {
        if ($current === null) {
            return $this->filledKeys($data);
        }

        $keys = [];

        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_') || in_array($key, ['group', 'type'], true)) {
                continue;
            }

            if (trim((string) $value) === '' && in_array($key, self::KEEP_WHEN_EMPTY, true)) {
                continue;
            }

            if ($key === 'image') {
                // Una foto si aggiunge, non si sostituisce: conta solo se è nuova.
                if (trim($value) !== '' && trim($value) !== trim((string) ($current['image'] ?? ''))) {
                    $keys[] = $key;
                }

                continue;
            }

            if (!CatalogCells::same($key, (string) $value, $current)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** @param array<string, string> $data @return list<string> */
    private function filledKeys(array $data): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            if (!str_starts_with($key, '_') && !in_array($key, ['group', 'type'], true) && trim((string) $value) !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Un articolo nuovo con la sua variante e la sua versione.
     *
     * @param array<string, string> $data
     * @return array{0: int, 1: int, 2: int}
     */
    private function createModel(array $data): array
    {
        $name = trim((string) $data['article']);
        $group = trim((string) ($data['group'] ?? ''));
        $sku = trim((string) $data['sku']);
        $unit = trim((string) ($data['unit'] ?? ''));

        $result = ProductModel::create([
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => $name,
            'slug' => Slug::unique($name, ProductModel::class),
            'sku' => $group !== '' ? $group : $sku,
            'tax_category_id' => TaxCategories::defaultId(),
            'unit' => $unit !== '' ? $unit : 'pz',
            'type' => 'simple',
            'short_description' => mb_substr(trim((string) ($data['short_description'] ?? '')), 0, 255),
            'returnable' => 'true',
            'requires_shipping' => 'true',
            'visible' => self::flag($data['visible'] ?? '', true),
            'visible_online' => self::flag($data['visible_online'] ?? '', true),
            'position' => 1,
        ]);

        $modelId = (int) ($result->insert_id ?? 0);

        if (empty($result->success) || $modelId === 0) {
            throw UserError::make('import.save_failed');
        }

        $skeleton = Skeleton::forModel($modelId, $name, $sku);

        if ($skeleton['product_id'] === 0) {
            throw UserError::make('import.save_failed');
        }

        return [$modelId, $skeleton['variant_id'], $skeleton['product_id']];
    }

    /**
     * @param array<string, string> $data
     * @param list<string> $keys
     * @return list<Change>
     */
    private function writeModel(int $modelId, array $data, array $keys): array
    {
        $keys = array_values(array_intersect($keys, self::MODEL_KEYS));

        if ($keys === []) {
            return [];
        }

        $changes = [];
        $values = [];

        foreach ($keys as $key) {
            $value = trim((string) $data[$key]);

            match ($key) {
                'article' => $values['name'] = $value,
                'visible', 'visible_online' => $values[$key] = self::flag($value, true),
                'unit' => $values['unit'] = $value,
                'short_description' => $values['short_description'] = mb_substr($value, 0, 255),
                'description' => $values['description'] = $value === '' ? '' : '<p>'.e_br($value).'</p>',
                'brand' => $values['brand_id'] = $value === '' ? 0 : $this->brandId($value, $changes),
                default => null,
            };
        }

        if ($values !== []) {
            $model = ProductModel::findById($modelId);
            $old = array_intersect_key(is_array($model) ? $model : [], $values);

            if (empty(ProductModel::update($values, $modelId)->success)) {
                throw UserError::make('import.save_failed');
            }

            $changes[] = new Change('model', $modelId, 'updated', $old);
        }

        if (in_array('categories', $keys, true)) {
            $old = ModelCategories::of($modelId);
            $ids = [];

            foreach (CatalogCells::categories((string) $data['categories']) as $path) {
                $ids[] = $this->categoryId($path, $changes);
            }

            $ids = array_values(array_unique($ids));
            ModelCategories::link($modelId, $ids, $ids[0] ?? 0);
            $changes[] = new Change('categories', $modelId, 'replaced', ['ids' => $old]);
        }

        return $changes;
    }

    /**
     * @param array<string, string> $data
     * @param list<string> $keys
     * @return list<Change>
     */
    private function writeProduct(int $productId, array $data, array $keys): array
    {
        $values = [];

        foreach (array_intersect($keys, self::PRODUCT_KEYS) as $key) {
            $value = trim((string) $data[$key]);

            match ($key) {
                'option' => $values['name'] = $value,
                'price', 'weight' => $values[$key] = CatalogCells::number($value),
                'sale_price' => $values['sale_price'] = $value === '' ? null : CatalogCells::number($value),
                'backorder' => $values['allow_backorder'] = self::flag($value, false),
                'active' => $values['active'] = self::flag($value, true),
                default => $values[$key] = $value,
            };
        }

        if ($values === []) {
            return [];
        }

        $product = Product::findById($productId);
        $old = array_intersect_key(is_array($product) ? $product : [], $values);

        if (empty(Product::update($values, $productId)->success)) {
            throw UserError::make('import.save_failed');
        }

        return [new Change('product', $productId, 'updated', $old)];
    }

    /**
     * @param array<string, string> $data
     * @param list<string> $keys
     * @return list<Change>
     */
    private function writeAttributes(int $productId, int $variantId, array $data, array $keys, Context $context): array
    {
        $changes = [];

        foreach ($this->attributes($context) as $id => $attribute) {
            if (!in_array('attr:'.$id, $keys, true)) {
                continue;
            }

            $level = (string) ($attribute['level'] ?? 'product');
            $parentId = $level === 'variant' ? $variantId : $productId;
            $value = trim((string) $data['attr:'.$id]);
            $rows = ProductAttributes::rows($level, $parentId)[$id] ?? [];

            if (Attributes::usesValues((string) ($attribute['type'] ?? ''))) {
                $old = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['attribute_value_id'] ?? 0), $rows)));
                $input = array_map(fn (string $label): int => $this->valueId($attribute, $label, $changes), CatalogCells::list($value));
            } else {
                $first = $rows[0] ?? [];
                $old = ($attribute['type'] ?? '') === 'number' ? ($first['value_number'] ?? null) : (string) ($first['value_text'] ?? '');
                $input = ($attribute['type'] ?? '') === 'number' && $value !== '' ? (string) CatalogCells::number($value) : $value;
            }

            ProductAttributes::save($level, $parentId, [$attribute], [$id => $input]);
            $changes[] = new Change('attributes:'.$level, $parentId, 'replaced', ['attribute_id' => $id, 'input' => $old]);
        }

        return $changes;
    }

    /** @return list<Change> */
    private function writeStock(int $productId, int $modelId, string $cell, Context $context): array
    {
        $model = ProductModel::findById($modelId);

        if (!is_array($model) || in_array((string) ($model['type'] ?? ''), ['bundle', SubscriptionModels::TYPE], true)) {
            return [];
        }

        if (trim($cell) === '' || CatalogCells::unlimited($cell)) {
            return [];
        }

        $target = CatalogCells::number($cell);

        if ($target === null) {
            return [];
        }

        $delta = Adjustment::fromTarget((float) Levels::of($productId)['available'], $target)['delta'];

        if ($delta === 0.0) {
            return [];
        }

        $movementId = $this->moveStock($productId, $delta, 'import', $context);

        return [new Change('product', $productId, 'stock', ['delta' => $delta], $movementId)];
    }

    private function moveStock(int $productId, float $delta, string $reason, Context $context): int
    {
        $movement = [
            'product_id' => $productId,
            'quantity' => $delta,
            'reason' => $reason,
            'source' => 'import',
            'reference_type' => 'import',
            'reference_id' => $context->jobId,
            'user_id' => $context->userId,
        ];

        return $delta < 0
            ? (int) StockOut::take($movement)['movement_id']
            : (int) Stock::apply($movement)['movement_id'];
    }

    /** @param list<Change> $changes */
    private function brandId(string $name, array &$changes): int
    {
        foreach (self::listOf(Brand::find(['deleted' => 'false'])) as $brand) {
            if (mb_strtolower(trim((string) $brand['name']), 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
                return (int) $brand['id'];
            }
        }

        $result = Brand::create([
            'code' => Code::make(Brand::class, Codes::BRAND),
            'name' => $name,
            'slug' => Slug::unique($name, Brand::class),
            'description' => '',
            'position' => 1,
            'visible' => 'true',
        ]);
        $id = (int) ($result->insert_id ?? 0);

        if (empty($result->success) || $id === 0) {
            throw UserError::make('import.save_failed');
        }

        $changes[] = new Change('brand', $id, 'created');

        return $id;
    }

    /**
     * La categoria di un percorso «Padre › Figlia»: i livelli che mancano nascono.
     *
     * @param list<string> $path
     * @param list<Change> $changes
     */
    private function categoryId(array $path, array &$changes): int
    {
        $parentId = 0;

        foreach ($path as $name) {
            $found = 0;

            foreach (self::listOf(Category::find(['parent_id' => $parentId, 'deleted' => 'false'])) as $category) {
                if (mb_strtolower(trim((string) $category['name']), 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
                    $found = (int) $category['id'];

                    break;
                }
            }

            if ($found === 0) {
                $result = Category::create([
                    'code' => Code::make(Category::class, Codes::CATEGORY),
                    'parent_id' => $parentId,
                    'name' => $name,
                    'slug' => Slug::unique($name, Category::class),
                    'description' => '',
                    'position' => 1,
                    'visible' => 'true',
                ]);
                $found = (int) ($result->insert_id ?? 0);

                if (empty($result->success) || $found === 0) {
                    throw UserError::make('import.save_failed');
                }

                $changes[] = new Change('category', $found, 'created');
            }

            $parentId = $found;
        }

        return $parentId;
    }

    /**
     * @param array<string, mixed> $attribute
     * @param list<Change> $changes
     */
    private function valueId(array $attribute, string $label, array &$changes): int
    {
        $attributeId = (int) $attribute['id'];
        $values = self::listOf(AttributeValue::find(['attribute_id' => $attributeId, 'deleted' => 'false']));

        foreach ($values as $value) {
            if (mb_strtolower(trim((string) $value['label']), 'UTF-8') === mb_strtolower($label, 'UTF-8')) {
                return (int) $value['id'];
            }
        }

        $result = AttributeValue::create([
            'attribute_id' => $attributeId,
            'label' => $label,
            'description' => '',
            'position' => count($values) + 1,
        ]);
        $id = (int) ($result->insert_id ?? 0);

        if (empty($result->success) || $id === 0) {
            throw UserError::make('import.save_failed');
        }

        $changes[] = new Change('attribute_value', $id, 'created');

        return $id;
    }

    /** @return array<string, mixed>|null */
    private function findModel(string $sku): ?array
    {
        foreach (self::listOf(ProductModel::find(['sku' => $sku, 'deleted' => 'false'])) as $model) {
            return $model;
        }

        return null;
    }

    private function productCount(int $modelId): int
    {
        return count(self::listOf(Product::find(['product_model_id' => $modelId, 'deleted' => 'false'])));
    }

    /** @param array<string, string> $data */
    private function optionName(array $data): string
    {
        $option = trim((string) ($data['option'] ?? ''));

        return $option !== '' ? $option : trim((string) $data['sku']);
    }

    private static function flag(string $value, bool $default): string
    {
        return (CatalogCells::boolean($value) ?? $default) ? 'true' : 'false';
    }

    /** @param array<string, string|int> $replacements */
    private static function text(string $key, array $replacements = []): string
    {
        return UserError::make('import.'.$key, $replacements)->getMessage();
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
