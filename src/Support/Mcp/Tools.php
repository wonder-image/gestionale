<?php

namespace Wonder\Plugin\Gestionale\Support\Mcp;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Resources\Catalog\CategoryResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderActionResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\RelatedProducts;
use Wonder\Plugin\Gestionale\Support\Catalog\SaleUnits;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Orders\OrderActions;
use Wonder\Plugin\Gestionale\Support\Stock\Adjustment;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Stock\StockOut;
use Wonder\Plugin\Gestionale\Support\Stock\StockVersionSearch;
use Wonder\Plugin\Gestionale\Support\Tax\TaxCategories;
use Wonder\Sql\Transaction;

/**
 * Gli strumenti del server MCP: leggere, creare e modificare prodotti,
 * prezzi, giacenze e categorie, e far avanzare lo stato degli ordini.
 * Niente altro.
 *
 * Ogni strumento dichiara il permesso della risorsa del backend che fa la
 * stessa cosa (`[Resource, azione]`): chi non può farlo dal backend non lo
 * può fare nemmeno da qui. Le scritture passano per gli stessi Model e
 * servizi del backend e finiscono in `gst_mcp_logs`.
 *
 * @phpstan-type Outcome array{result: mixed, subject?: int, before?: mixed, after?: mixed}
 */
final class Tools
{
    use ToolSupport;

    /** Le azioni d'ordine che cambiano solo lo stato: le altre vogliono finestre e righe. */
    public const ORDER_ACTIONS = [
        OrderActions::CONFIRM, OrderActions::CANCEL, OrderActions::FULFILL,
        OrderActions::READY, OrderActions::PICKED_UP,
    ];

    public const LIST_LIMIT = 50;

    /**
     * @return array<string, array{description: string, schema: array<string, mixed>, permission: array{0: class-string, 1: string}, write: bool, run: callable(array<string, mixed>, Caller): array}>
     */
    public static function all(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $flag = ['type' => 'boolean'];

        return [
            'products_search' => [
                'description' => 'Cerca le versioni vendibili (prodotti) per nome, SKU, EAN o nome dell\'articolo. Restituisce id, nome, SKU, EAN, prezzi, giacenza nella sede principale e se la versione è attiva. Al massimo '.StockVersionSearch::LIMIT.' risultati.',
                'schema' => self::schema(['query' => ['type' => 'string', 'description' => 'Testo da cercare.']], ['query']),
                'permission' => [ProductResource::class, 'list'],
                'write' => false,
                'run' => self::productsSearch(...),
            ],
            'products_list' => [
                'description' => 'Elenca le versioni vendibili (prodotti), le più recenti prima, senza bisogno di un testo da cercare. Filtri facoltativi: categoria dell\'articolo e attiva. Al massimo '.self::LIST_LIMIT.' per pagina; `offset` per le successive.',
                'schema' => self::schema([
                    'category_id' => $id,
                    'active' => $flag,
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::LIST_LIMIT],
                    'offset' => ['type' => 'integer', 'minimum' => 0],
                ], []),
                'permission' => [ProductResource::class, 'list'],
                'write' => false,
                'run' => self::productsList(...),
            ],
            'product_create' => [
                'description' => 'Crea un articolo semplice con la sua versione vendibile, come la scheda del backend. Obbligatori: nome e prezzo. Di serie: unità `pz`, tipo di IVA predefinito, pubblicato e visibile online. Codice e slug si ricavano dal nome. Restituisce la versione creata, come `product_get`.',
                'schema' => self::schema([
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                    'price' => ['type' => 'number', 'minimum' => 0],
                    'sale_price' => ['type' => 'number', 'minimum' => 0],
                    'sku' => ['type' => 'string', 'maxLength' => 100],
                    'ean' => ['type' => 'string', 'maxLength' => 13],
                    'unit' => ['type' => 'string', 'enum' => array_keys(SaleUnits::all())],
                    'tax_category_id' => $id,
                    'short_description' => ['type' => 'string', 'maxLength' => 255],
                    'visible' => $flag,
                    'visible_online' => $flag,
                    'category_ids' => ['type' => 'array', 'items' => $id],
                    'main_category_id' => $id,
                ], ['name', 'price']),
                'permission' => [ProductModelResource::class, 'store'],
                'write' => true,
                'run' => self::productCreate(...),
            ],
            'product_get' => [
                'description' => 'Legge una versione (prodotto) per id: prezzi, stato, giacenza (quantità, impegnata, disponibile), articolo di appartenenza e sue categorie.',
                'schema' => self::schema(['id' => $id], ['id']),
                'permission' => [ProductResource::class, 'view'],
                'write' => false,
                'run' => self::productGet(...),
            ],
            'product_update' => [
                'description' => 'Modifica una versione: prezzo, prezzo scontato (null lo toglie; deve essere minore del prezzo), attiva, vendibile senza scorte. `visible_online` cambia la visibilità online dell\'intero articolo. Si passano solo i campi da cambiare.',
                'schema' => self::schema([
                    'id' => $id,
                    'price' => ['type' => 'number', 'minimum' => 0],
                    'sale_price' => ['type' => ['number', 'null'], 'minimum' => 0],
                    'active' => $flag,
                    'allow_backorder' => $flag,
                    'visible_online' => $flag,
                ], ['id']),
                'permission' => [ProductResource::class, 'update'],
                'write' => true,
                'run' => self::productUpdate(...),
            ],
            'stock_adjust' => [
                'description' => 'Rettifica la giacenza di una versione nella sede principale: `add` aggiunge, `subtract` toglie, `set` imposta la quantità. Causale facoltativa tra: '.implode(', ', self::reasons()).'.',
                'schema' => self::schema([
                    'product_id' => $id,
                    'mode' => ['type' => 'string', 'enum' => ['add', 'subtract', 'set']],
                    'quantity' => ['type' => 'number', 'minimum' => 0],
                    'reason' => ['type' => 'string', 'enum' => self::reasons()],
                    'note' => ['type' => 'string', 'maxLength' => 500],
                ], ['product_id', 'mode', 'quantity']),
                'permission' => [StockAdjustmentResource::class, 'navigation'],
                'write' => true,
                'run' => self::stockAdjust(...),
            ],
            'categories_list' => [
                'description' => 'Elenca le categorie del catalogo: id, padre (0 = radice), nome, slug, posizione, visibilità.',
                'schema' => self::schema([], []),
                'permission' => [CategoryResource::class, 'list'],
                'write' => false,
                'run' => self::categoriesList(...),
            ],
            'category_create' => [
                'description' => 'Crea una categoria. Obbligatorio solo il nome; di serie sta nella radice (`parent_id` 0) ed è visibile. Codice e slug si ricavano dal nome e lo slug non cambia più.',
                'schema' => self::schema([
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                    'parent_id' => ['type' => 'integer', 'minimum' => 0],
                    'description' => ['type' => 'string'],
                    'visible' => $flag,
                ], ['name']),
                'permission' => [CategoryResource::class, 'store'],
                'write' => true,
                'run' => self::categoryCreate(...),
            ],
            'category_update' => [
                'description' => 'Modifica una categoria: nome, padre, descrizione, posizione, visibilità. Lo slug non cambia.',
                'schema' => self::schema([
                    'id' => $id,
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                    'parent_id' => ['type' => 'integer', 'minimum' => 0],
                    'description' => ['type' => 'string'],
                    'position' => ['type' => 'integer', 'minimum' => 0],
                    'visible' => $flag,
                ], ['id']),
                'permission' => [CategoryResource::class, 'update'],
                'write' => true,
                'run' => self::categoryUpdate(...),
            ],
            'product_set_categories' => [
                'description' => 'Imposta le categorie dell\'articolo a cui appartiene la versione `product_id` (valgono per tutte le sue versioni). `main_category_id` è la principale; se manca, la prima dell\'elenco.',
                'schema' => self::schema([
                    'product_id' => $id,
                    'category_ids' => ['type' => 'array', 'items' => $id],
                    'main_category_id' => $id,
                ], ['product_id', 'category_ids']),
                'permission' => [ProductResource::class, 'update'],
                'write' => true,
                'run' => self::productSetCategories(...),
            ],
            'orders_list' => [
                'description' => 'Elenca gli ordini, i più recenti prima. Filtri facoltativi: stato e numero d\'ordine o email. Al massimo '.self::LIST_LIMIT.'.',
                'schema' => self::schema([
                    'status' => ['type' => 'string', 'enum' => Order::LIVE_STATUSES],
                    'query' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::LIST_LIMIT],
                ], []),
                'permission' => [OrderResource::class, 'list'],
                'write' => false,
                'run' => self::ordersList(...),
            ],
            'order_get' => [
                'description' => 'Legge un ordine: stati, totali, cliente, righe e azioni di stato disponibili ora.',
                'schema' => self::schema(['id' => $id], ['id']),
                'permission' => [OrderResource::class, 'view'],
                'write' => false,
                'run' => self::orderGet(...),
            ],
            'order_action' => [
                'description' => 'Cambia lo stato di un ordine con una delle azioni disponibili ora (vedi `order_get`): '.implode(', ', self::ORDER_ACTIONS).'.',
                'schema' => self::schema([
                    'order_id' => $id,
                    'action' => ['type' => 'string', 'enum' => self::ORDER_ACTIONS],
                ], ['order_id', 'action']),
                'permission' => [OrderActionResource::class, 'update'],
                'write' => true,
                'run' => self::orderAction(...),
            ],
        ] + CatalogTools::all() + QuoteTools::all();
    }

    /**
     * I ruoli che aprono uno strumento, letti dalla risorsa del backend.
     * `navigation` è per le pagine senza CRUD, come la rettifica di giacenza.
     *
     * @param array{0: class-string, 1: string} $permission
     * @return list<string>
     */
    public static function roles(array $permission): array
    {
        [$resource, $action] = $permission;
        $roles = $action === 'navigation'
            ? $resource::navigationSchema()->get('authority')
            : ((array) $resource::permissionSchema()->get('backend'))[$action] ?? [];

        return array_values(array_map('strval', array_filter((array) $roles, 'is_scalar')));
    }

    /**
     * Lo scope OAuth che apre uno strumento: l'area è la cartella della
     * risorsa del permesso (`Resources\Catalog\…` → `catalog`).
     */
    public static function scope(array $tool): string
    {
        $parts = explode('\\', $tool['permission'][0]);

        return strtolower($parts[count($parts) - 2]).':'.($tool['write'] ? 'write' : 'read');
    }

    /**
     * Gli scope della risorsa OAuth con l'etichetta della pagina di consenso:
     * solo quelli che aprono almeno uno strumento.
     *
     * @return array<string, string>
     */
    public static function scopes(): array
    {
        $out = [];

        foreach (self::all() as $tool) {
            $scope = self::scope($tool);
            $out[$scope] = Server::text('scope.'.str_replace(':', '_', $scope));
        }

        ksort($out);

        return $out;
    }

    // ── Prodotti ─────────────────────────────────────────────────────────

    private static function productsSearch(array $args): array
    {
        $versions = StockVersionSearch::search(self::string($args, 'query'), Locations::mainId());
        $prices = [];

        if ($versions !== []) {
            foreach (self::rows(Product::find('`id` IN ('.implode(', ', array_column($versions, 'id')).')')) as $row) {
                $prices[(int) $row['id']] = self::prices($row);
            }
        }

        return ['result' => array_map(static fn (array $version): array => [
            'id' => $version['id'],
            'name' => $version['label'],
            'sku' => $version['sku'],
            'ean' => $version['ean'],
            'stock' => $version['stock'],
            'active' => !$version['inactive'],
        ] + ($prices[$version['id']] ?? []), $versions)];
    }

    private static function productsList(array $args): array
    {
        $conditions = ["`deleted` = 'false'", '`product_model_id` > 0'];

        if (array_key_exists('category_id', $args)) {
            $category = (int) self::categoryRow(self::int($args, 'category_id'))['id'];
            $conditions[] = '`product_model_id` IN (SELECT `product_model_id` FROM `'.ProductModelCategory::$table.'` WHERE `category_id` = '.$category.')';
        }

        if (array_key_exists('active', $args)) {
            $conditions[] = "`active` = '".self::flag($args['active'], 'active')."'";
        }

        $limit = min(self::LIST_LIMIT, max(1, (int) ($args['limit'] ?? 20)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $rows = self::rows(Product::find(implode(' AND ', $conditions), $offset.', '.$limit, 'id', 'DESC'));
        $models = [];

        foreach (array_unique(array_map(static fn (array $row): int => (int) $row['product_model_id'], $rows)) as $modelId) {
            $model = ProductModel::findById($modelId);
            $models[$modelId] = is_array($model) ? (string) ($model['name'] ?? '') : '';
        }

        return ['result' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => ProductNames::full($row, $models),
            'sku' => (string) ($row['sku'] ?? ''),
            'ean' => (string) ($row['ean'] ?? ''),
            'stock' => Levels::of((int) $row['id']),
            'active' => (string) ($row['active'] ?? 'true') === 'true',
            'product_model_id' => (int) $row['product_model_id'],
        ] + self::prices($row), $rows)];
    }

    private static function productCreate(array $args): array
    {
        $name = trim(self::string($args, 'name'));
        $price = self::price($args['price'] ?? null, 'price');
        $sale = array_key_exists('sale_price', $args) ? self::price($args['sale_price'], 'sale_price') : null;

        if ($sale !== null && $sale >= $price) {
            throw McpError::make('sale_price');
        }

        $unit = (string) ($args['unit'] ?? 'pz');

        if (!array_key_exists($unit, SaleUnits::all())) {
            throw McpError::make('unit', 0, ['units' => implode(', ', array_keys(SaleUnits::all()))]);
        }

        $sku = trim((string) ($args['sku'] ?? ''));
        $ean = trim((string) ($args['ean'] ?? ''));
        [$chosen, $main] = self::chosenCategories($args);
        $model = [
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => $name,
            'slug' => Slug::unique($name, ProductModel::class),
            'sku' => $sku,
            'tax_category_id' => self::taxCategory($args),
            'unit' => $unit,
            'type' => 'simple',
            'short_description' => mb_substr(trim((string) ($args['short_description'] ?? '')), 0, 255),
            'returnable' => 'true',
            'requires_shipping' => 'true',
            'visible' => array_key_exists('visible', $args) ? self::flag($args['visible'], 'visible') : 'true',
            'visible_online' => array_key_exists('visible_online', $args) ? self::flag($args['visible_online'], 'visible_online') : 'true',
            'position' => 1,
        ];

        // Come la scheda del backend: l'articolo, poi la sua opzione e la sua
        // versione vendibile, su cui stanno prezzo ed EAN.
        $id = Transaction::run(static function () use ($model, $name, $sku, $price, $sale, $ean, $chosen, $main): int {
            $saved = self::saved(ProductModel::create($model));
            $modelId = (int) ($saved->insert_id ?? $saved->id ?? 0);
            $skeleton = Skeleton::forModel($modelId, $name, $sku);
            $productId = (int) $skeleton['product_id'];

            if ($productId <= 0) {
                throw McpError::make('save_failed', 0, ['fields' => 'product']);
            }

            self::saved(Product::update(['price' => $price, 'sale_price' => $sale, 'ean' => $ean], $productId));

            if ($chosen !== []) {
                self::linkCategories($modelId, $chosen, $main);
            }

            return $productId;
        });
        $after = self::product(self::productRow($id));

        return ['result' => $after, 'subject' => $id, 'after' => $after];
    }

    private static function productGet(array $args): array
    {
        return ['result' => self::product(self::productRow(self::int($args, 'id')))];
    }

    private static function productUpdate(array $args): array
    {
        $row = self::productRow(self::int($args, 'id'));
        $id = (int) $row['id'];
        $before = self::product($row);
        $values = [];

        foreach (['price', 'sale_price'] as $key) {
            if (array_key_exists($key, $args)) {
                $values[$key] = self::price($args[$key], $key);
            }
        }

        foreach (['active', 'allow_backorder'] as $key) {
            if (array_key_exists($key, $args)) {
                $values[$key] = self::flag($args[$key], $key);
            }
        }

        $online = array_key_exists('visible_online', $args) ? self::flag($args['visible_online'], 'visible_online') : null;

        if ($values === [] && $online === null) {
            throw McpError::make('nothing_to_change');
        }

        $price = array_key_exists('price', $values) ? $values['price'] : self::nullable($row['price'] ?? null);
        $sale = array_key_exists('sale_price', $values) ? $values['sale_price'] : self::nullable($row['sale_price'] ?? null);

        if ($sale !== null && ($price === null || $sale >= $price)) {
            throw McpError::make('sale_price');
        }

        Transaction::run(static function () use ($values, $id, $online, $row): void {
            if ($values !== []) {
                self::saved(Product::update($values, $id));
            }

            if ($online !== null) {
                self::saved(ProductModel::update(['visible_online' => $online], (int) $row['product_model_id']));
            }
        });

        return ['result' => $after = self::product(self::productRow($id)), 'subject' => $id, 'before' => $before, 'after' => $after];
    }

    // ── Giacenze ─────────────────────────────────────────────────────────

    private static function stockAdjust(array $args, Caller $caller): array
    {
        $row = self::productRow(self::int($args, 'product_id'));
        $id = (int) $row['id'];
        $quantity = $args['quantity'] ?? null;

        if (!is_int($quantity) && !is_float($quantity) || $quantity < 0) {
            throw McpError::make('not_negative', 0, ['field' => 'quantity']);
        }

        $reason = (string) ($args['reason'] ?? Reasons::DEFAULT);

        if (!in_array($reason, self::reasons(), true)) {
            throw McpError::make('reason');
        }

        $before = Levels::of($id);
        $change = Adjustment::of(self::string($args, 'mode'), (float) $before['quantity'], (float) $quantity);

        if ($change['delta'] === 0.0) {
            throw McpError::make('stock_unchanged');
        }

        ($change['delta'] < 0 ? StockOut::take(...) : Stock::apply(...))([
            'product_id' => $id,
            'quantity' => $change['delta'],
            'reason' => $reason,
            'note' => mb_substr(trim((string) ($args['note'] ?? '')), 0, 500),
            'user_id' => $caller->userId,
            'source' => 'api',
        ]);

        $after = Levels::of($id);

        return ['result' => ['product_id' => $id, 'delta' => $change['delta'], 'stock' => $after], 'subject' => $id, 'before' => $before, 'after' => $after];
    }

    // ── Categorie ────────────────────────────────────────────────────────

    private static function categoriesList(): array
    {
        return ['result' => array_map(self::category(...), self::rows(Category::find(['deleted' => 'false'], null, 'position', 'ASC')))];
    }

    private static function categoryCreate(array $args): array
    {
        $name = trim(self::string($args, 'name'));
        $values = [
            'code' => Code::make(Category::class, Codes::CATEGORY),
            'name' => $name,
            'slug' => Slug::unique($name, Category::class),
            'parent_id' => self::parent($args, 0) ?: null,
            'description' => (string) ($args['description'] ?? ''),
            'visible' => array_key_exists('visible', $args) ? self::flag($args['visible'], 'visible') : 'true',
        ];
        $saved = self::saved(Category::create($values));
        $id = (int) ($saved->insert_id ?? $saved->id ?? 0);
        $after = self::category(self::categoryRow($id));

        return ['result' => $after, 'subject' => $id, 'after' => $after];
    }

    private static function categoryUpdate(array $args): array
    {
        $row = self::categoryRow(self::int($args, 'id'));
        $id = (int) $row['id'];
        $values = [];

        if (array_key_exists('name', $args)) {
            $values['name'] = trim(self::string($args, 'name'));
        }

        if (array_key_exists('parent_id', $args)) {
            $values['parent_id'] = self::parent($args, $id) ?: null;
        }

        if (array_key_exists('description', $args)) {
            $values['description'] = (string) $args['description'];
        }

        if (array_key_exists('position', $args)) {
            $values['position'] = max(0, (int) $args['position']);
        }

        if (array_key_exists('visible', $args)) {
            $values['visible'] = self::flag($args['visible'], 'visible');
        }

        if ($values === []) {
            throw McpError::make('nothing_to_change');
        }

        self::saved(Category::update($values, $id));
        $after = self::category(self::categoryRow($id));

        return ['result' => $after, 'subject' => $id, 'before' => self::category($row), 'after' => $after];
    }

    private static function productSetCategories(array $args): array
    {
        $row = self::productRow(self::int($args, 'product_id'));
        $modelId = (int) $row['product_model_id'];
        [$chosen, $main] = self::chosenCategories($args);
        $before = self::modelCategories($modelId);

        Transaction::run(static fn () => self::linkCategories($modelId, $chosen, $main));

        $after = self::modelCategories($modelId);

        return ['result' => ['product_model_id' => $modelId, 'categories' => $after], 'subject' => (int) $row['id'], 'before' => $before, 'after' => $after];
    }

    /**
     * Le categorie scelte, tutte esistenti, e la principale: quella data o
     * la prima dell'elenco.
     *
     * @return array{0: list<int>, 1: int}
     */
    private static function chosenCategories(array $args): array
    {
        $chosen = array_values(array_unique(array_map('intval', (array) ($args['category_ids'] ?? []))));

        foreach ($chosen as $categoryId) {
            self::categoryRow($categoryId);
        }

        $main = (int) ($args['main_category_id'] ?? 0);

        if ($main > 0 && !in_array($main, $chosen, true)) {
            throw McpError::make('main_category');
        }

        return [$chosen, $main ?: ($chosen[0] ?? 0)];
    }

    /**
     * Come la scheda dell'articolo: si tolgono i legami non più scelti, si
     * aggiornano gli altri, si aggiungono i nuovi, in ordine.
     *
     * @param list<int> $chosen
     */
    private static function linkCategories(int $modelId, array $chosen, int $main): void
    {
        $existing = [];

        foreach (self::rows(ProductModelCategory::find(['product_model_id' => $modelId])) as $link) {
            $existing[(int) $link['category_id']] = $link;
        }

        foreach ($existing as $categoryId => $link) {
            if (!in_array($categoryId, $chosen, true)) {
                ProductModelCategory::delete((int) $link['id']);
            }
        }

        foreach ($chosen as $index => $categoryId) {
            $values = [
                'product_model_id' => $modelId,
                'category_id' => $categoryId,
                'is_main' => $categoryId === $main ? 'true' : 'false',
                'position' => $index + 1,
            ];

            self::saved(isset($existing[$categoryId])
                ? ProductModelCategory::update($values, (int) $existing[$categoryId]['id'])
                : ProductModelCategory::create($values));
        }
    }

    /**
     * Il tipo di IVA dell'articolo: quello dato, se esiste ed è visibile,
     * altrimenti il predefinito del negozio (zero se non ce n'è nessuno).
     * Con «Aliquote» spenta vale sempre il predefinito, come nella scheda.
     */
    private static function taxCategory(array $args): int
    {
        if (!array_key_exists('tax_category_id', $args) || !Gestionale::feature('tax_categories')) {
            return TaxCategories::defaultId();
        }

        $id = self::int($args, 'tax_category_id');
        $row = TaxCategory::findById($id);

        if (!is_array($row) || $row === [] || (string) ($row['deleted'] ?? 'false') === 'true' || (string) ($row['visible'] ?? 'true') !== 'true') {
            throw McpError::make('tax_category', 0, ['id' => $id]);
        }

        return $id;
    }

    // ── Ordini ───────────────────────────────────────────────────────────

    private static function ordersList(array $args): array
    {
        $conditions = ["`deleted` = 'false'", "`stage` = 'order'", "`status` NOT IN ('awaiting_payment', 'abandoned')"];
        $status = (string) ($args['status'] ?? '');

        if ($status !== '') {
            if (!in_array($status, Order::LIVE_STATUSES, true)) {
                throw McpError::make('status');
            }
            $conditions[] = "`status` = '".$status."'";
        }

        $query = trim((string) ($args['query'] ?? ''));

        if ($query !== '') {
            $like = "'%".Order::connection()->real_escape_string(addcslashes($query, '%_\\'))."%'";
            $conditions[] = '(`order_number` LIKE '.$like.' OR `email` LIKE '.$like.')';
        }

        $limit = min(self::LIST_LIMIT, max(1, (int) ($args['limit'] ?? 20)));

        return ['result' => array_map(
            static fn (array $order): array => self::order($order),
            self::rows(Order::find(implode(' AND ', $conditions), $limit, 'id', 'DESC'))
        )];
    }

    private static function orderGet(array $args): array
    {
        $order = self::orderRow(self::int($args, 'id'));
        $items = array_map(static fn (array $item): array => [
            'id' => (int) $item['id'],
            'type' => (string) ($item['type'] ?? ''),
            'product_id' => (int) ($item['product_id'] ?? 0),
            'sku' => (string) ($item['sku'] ?? ''),
            'name' => (string) ($item['name'] ?? ''),
            'quantity' => (float) ($item['quantity'] ?? 0),
            'unit_price' => (float) ($item['unit_price'] ?? 0),
            'line_total' => (float) ($item['line_total'] ?? 0),
        ], self::rows(OrderItem::find(['order_id' => (int) $order['id'], 'deleted' => 'false'], null, 'position', 'ASC')));

        return ['result' => self::order($order) + [
            'fulfillment_type' => (string) ($order['fulfillment_type'] ?? ''),
            'customer_note' => (string) ($order['customer_note'] ?? ''),
            'items' => $items,
        ]];
    }

    private static function orderAction(array $args, Caller $caller): array
    {
        $order = self::orderRow(self::int($args, 'order_id'));
        $id = (int) $order['id'];
        $action = self::string($args, 'action');

        if (!in_array($action, self::actions($order), true)) {
            throw McpError::make('action_unavailable', 0, ['available' => implode(', ', self::actions($order)) ?: '-']);
        }

        $before = self::order($order);
        $outcome = OrderActionResource::run($action, $id, $caller->userId);

        if (empty($outcome['ok'])) {
            throw (string) ($outcome['message'] ?? '') !== '' ? new McpError((string) $outcome['message']) : McpError::make('action_failed');
        }

        $after = self::order(self::orderRow($id));

        return ['result' => ['message' => (string) $outcome['message'], 'order' => $after], 'subject' => $id, 'before' => $before, 'after' => $after];
    }

    // ── Righe ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function categoryRow(int $id): array
    {
        $row = $id > 0 ? Category::findById($id) : null;

        if (!is_array($row) || $row === [] || (string) ($row['deleted'] ?? 'false') === 'true') {
            throw McpError::make('category_not_found', 0, ['id' => $id]);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private static function orderRow(int $id): array
    {
        $row = Order::findById($id);

        if (!is_array($row) || $row === [] || (string) ($row['deleted'] ?? 'false') === 'true' || (string) ($row['stage'] ?? '') !== 'order' || (string) ($row['status'] ?? '') === 'awaiting_payment') {
            throw McpError::make('order_not_found', 0, ['id' => $id]);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private static function product(array $row): array
    {
        $id = (int) $row['id'];
        $model = ProductModel::findById((int) $row['product_model_id']);
        $model = is_array($model) ? $model : [];

        return [
            'id' => $id,
            'name' => ProductNames::full($row, is_array($model) && isset($model['id']) ? [(int) $model['id'] => (string) ($model['name'] ?? '')] : []),
            'option' => (string) ($row['name'] ?? ''),
            'sku' => (string) ($row['sku'] ?? ''),
            'ean' => (string) ($row['ean'] ?? ''),
            'active' => (string) ($row['active'] ?? 'true') === 'true',
            'allow_backorder' => (string) ($row['allow_backorder'] ?? 'false') === 'true',
            'stock' => Levels::of($id),
            'model' => [
                'id' => (int) ($model['id'] ?? 0),
                'name' => (string) ($model['name'] ?? ''),
                'slug' => (string) ($model['slug'] ?? ''),
                'type' => (string) ($model['type'] ?? ''),
                'visible' => (string) ($model['visible'] ?? 'true') === 'true',
                'visible_online' => (string) ($model['visible_online'] ?? 'true') === 'true',
                'categories' => self::modelCategories((int) ($model['id'] ?? 0)),
                'related' => self::modelRelated((int) ($model['id'] ?? 0)),
            ],
        ] + self::prices($row);
    }

    /**
     * Suggeriti e alternativi come li ha scelti chi vende, anche quelli che
     * oggi il sito non mostra; vuoto il tipo con la funzionalità spenta.
     *
     * @return array{suggested: list<array{id: int, name: string}>, alternative: list<array{id: int, name: string}>}
     */
    public static function modelRelated(int $modelId): array
    {
        $out = [];

        foreach (RelatedProducts::TYPES as $type) {
            $out[$type] = [];

            if (!RelatedProducts::active($type)) {
                continue;
            }

            foreach (RelatedProducts::ids($modelId, $type) as $id) {
                $model = ProductModel::findById($id);
                $out[$type][] = ['id' => $id, 'name' => is_array($model) ? (string) ($model['name'] ?? '') : ''];
            }
        }

        return $out;
    }

    /** @return array{price: float|null, sale_price: float|null} */
    private static function prices(array $row): array
    {
        return ['price' => self::nullable($row['price'] ?? null), 'sale_price' => self::nullable($row['sale_price'] ?? null)];
    }

    /** @return list<array{id: int, name: string, main: bool}> */
    private static function modelCategories(int $modelId): array
    {
        if ($modelId <= 0) {
            return [];
        }

        $out = [];

        foreach (self::rows(ProductModelCategory::find(['product_model_id' => $modelId], null, 'position', 'ASC')) as $link) {
            $category = Category::findById((int) $link['category_id']);
            $out[] = [
                'id' => (int) $link['category_id'],
                'name' => is_array($category) ? (string) ($category['name'] ?? '') : '',
                'main' => (string) ($link['is_main'] ?? 'false') === 'true',
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function category(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'parent_id' => (int) ($row['parent_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'position' => (int) ($row['position'] ?? 0),
            'visible' => (string) ($row['visible'] ?? 'true') === 'true',
        ];
    }

    /** @return array<string, mixed> */
    private static function order(array $order): array
    {
        return [
            'id' => (int) $order['id'],
            'order_number' => (string) ($order['order_number'] ?? ''),
            'ordered_at' => (string) ($order['ordered_at'] ?? ''),
            'channel' => (string) ($order['channel'] ?? ''),
            'status' => (string) ($order['status'] ?? ''),
            'payment_status' => (string) ($order['payment_status'] ?? ''),
            'fulfillment_status' => (string) ($order['fulfillment_status'] ?? ''),
            'email' => (string) ($order['email'] ?? ''),
            'total' => (float) ($order['total'] ?? 0),
            'currency' => (string) ($order['currency'] ?? 'EUR'),
            'available_actions' => self::actions($order),
        ];
    }

    /**
     * Le azioni di stato che il backend offrirebbe ora su quest'ordine.
     *
     * @return list<string>
     */
    private static function actions(array $order): array
    {
        $features = [
            'returns' => Gestionale::feature('returns'),
            'shipping' => Gestionale::feature('shipping'),
            'delivery_notes' => Gestionale::feature('delivery_notes'),
        ];

        return array_values(array_intersect(OrderActions::available($order, $features), self::ORDER_ACTIONS));
    }

    // ── Argomenti ────────────────────────────────────────────────────────

    /** @return list<string> */
    private static function reasons(): array
    {
        return array_values(array_diff(array_keys(Reasons::all()), Reasons::internal()));
    }

    /** Il padre di una categoria: 0 è la radice (NULL nel database), mai sé stessa né un suo discendente. */
    private static function parent(array $args, int $self): int
    {
        $parent = (int) ($args['parent_id'] ?? 0);

        for ($current = $parent, $seen = []; $current > 0; $current = (int) (self::categoryRow($current)['parent_id'] ?? 0)) {
            if ($current === $self || isset($seen[$current])) {
                throw McpError::make('category_loop');
            }
            $seen[$current] = true;
        }

        return $parent;
    }
}
