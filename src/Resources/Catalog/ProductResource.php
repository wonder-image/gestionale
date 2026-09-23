<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Ean;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Sku;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Stock\Alerts;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\StockHistory;

/**
 * "Opzioni in vendita": l'elenco piatto di quello che si vende davvero.
 *
 * Serve a **trovare** — uno SKU, un EAN, un prezzo da correggere — non a
 * creare: un'opzione nasce nella griglia della scheda del prodotto, dove si
 * vede insieme alle sorelle. Per questo la pagina non ha il pulsante
 * "Aggiungi".
 *
 * La scheda della singola opzione tiene quello che nella griglia non
 * entrerebbe: MPN, misure proprie e gli attributi di livello `product`.
 *
 * Estende la Resource dei prodotti per riusarne le letture del catalogo —
 * attributi, valori, campi degli attributi — e ne riscrive tutto il resto:
 * qui non nasce nessuno scheletro e non si elimina nessun prodotto.
 *
 * Non è `final`: i test la estendono con una classe anonima.
 */
class ProductResource extends ProductModelResource
{
    public static string $model = Product::class;
    public static string $orderColumn = 'sku';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'catalogo/catalogo-prodotti';

    public static function path(): string
    {
        return 'app/gestionale/versioni';
    }

    public static function icon(): string
    {
        return 'bi-upc-scan';
    }

    public static function titleLabel(): string
    {
        return 'Opzioni in vendita';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'opzione',
            'plural_label' => 'opzioni',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'full' => 'attiva',
            'empty' => 'ferma',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'sku' => 'SKU',
            'ean' => 'EAN',
            'mpn' => 'Codice del produttore',
            'price' => 'Prezzo',
            'sale_price' => 'Prezzo scontato',
            'active' => 'Stato',
            'name' => 'Opzione',
            'product_model_id' => 'Prodotto',
        ];
    }

    public static function formSchema(): array
    {
        $fields = [
            FormField::key('sku')->text()->label('SKU'),
            FormField::key('ean')->text()->label('EAN'),
            FormField::key('mpn')->text()->label('Codice del produttore'),
            FormField::key('price')->number()->decimal(2)->label('Prezzo'),
            FormField::key('sale_price')->number()->decimal(2)->label('Prezzo scontato'),
            FormField::key('active')
                ->select(['true' => 'Attiva', 'false' => 'Ferma'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('weight')->number()->decimal(3)->label('Peso (kg)'),
            FormField::key('length')->number()->decimal(2)->label('Lunghezza (cm)'),
            FormField::key('width')->number()->decimal(2)->label('Larghezza (cm)'),
            FormField::key('height')->number()->decimal(2)->label('Altezza (cm)'),
        ];

        if (Gestionale::feature('low_stock_alerts')) {
            $fields[] = FormField::key('min_stock_quantity')->number()->decimal(3)->label('Scorta minima');
        }

        foreach (Attributes::byLevel(static::attributes(), 'product') as $attribute) {
            $fields[] = static::attributeField($attribute);
        }

        return $fields;
    }

    public static function formLayoutSchema(): ?Form
    {
        $cards = [
            (new Card)->components([
                SectionTitle::make(static::currentTitle())
                    ->tooltip('Questa opzione nasce nella scheda del prodotto: nome, descrizioni e categorie si cambiano di là, e il nome della riga segue gli attributi che la compongono. Qui c\'è quello che vale solo per lei.')
                    ->columnSpan(12),
                static::getInput('sku')->columnSpan(4),
                static::getInput('ean')->columnSpan(4),
                static::getInput('mpn')->columnSpan(4),
                static::getInput('price')->columnSpan(4),
                static::getInput('sale_price')->columnSpan(4),
                static::getInput('active')->columnSpan(4),
            ])->columns(12)->columnSpan(12),
        ];

        $attributeInputs = [];

        foreach (Attributes::byLevel(static::attributes(), 'product') as $attribute) {
            $attributeInputs[] = static::getInput('attribute_'.(int) $attribute['id'])->columnSpan(4);
        }

        if ($attributeInputs !== []) {
            $cards[] = (new Card)->components([
                SectionTitle::make('Attributi')
                    ->tooltip('Gli attributi che cambiano da un\'opzione all\'altra — taglia, misura, gusto — e non quelli che descrivono tutto l\'articolo.')
                    ->columnSpan(12),
                ...$attributeInputs,
            ])->columns(12)->columnSpan(12);
        }

        $cards[] = (new Card)->components([
            SectionTitle::make('Misure')
                ->tooltip('Lasciando vuoto valgono peso e misure dell\'articolo.')
                ->columnSpan(12),
            static::getInput('weight')->columnSpan(3),
            static::getInput('length')->columnSpan(3),
            static::getInput('width')->columnSpan(3),
            static::getInput('height')->columnSpan(3),
        ])->columns(12)->columnSpan(12);

        $soglia = Gestionale::feature('low_stock_alerts');

        $cards[] = (new Card)->components([
            SectionTitle::make(static::stockCardTitle())
                ->tooltip('Qui la giacenza si legge. Si scrive nella riga della griglia delle opzioni in vendita, oppure con una rettifica quando serve lasciare una causale e una nota.'
                    .($soglia ? ' La scorta minima è la soglia sotto cui arriva l\'avviso: vale sul disponibile di tutte le sedi, e con zero non arriva niente.' : ''))
                ->columnSpan(12),
            ...($soglia ? [static::getInput('min_stock_quantity')->columnSpan(4)] : []),
            RichText::make(static::stockSummary())->columnSpan(12),
            RichText::make(static::stockHistoryTable())->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        return (new Form)->components([
            (new Container)->components($cards)->columns(12)->columnSpan(12),
        ]);
    }

    public static function stockCardTitle(): string
    {
        return 'Magazzino';
    }

    /** Quanti pezzi, con il link alla rettifica. */
    protected static function stockSummary(): string
    {
        $productId = static::currentId() ?? 0;

        if ($productId <= 0) {
            return '';
        }

        $levels = Levels::of($productId);
        $parts = ['<b>Giacenza:</b> '.static::escape(static::plainNumber($levels['quantity'])).' pezzi'];

        if (Gestionale::feature('orders')) {
            $parts[] = 'impegnati '.static::escape(static::plainNumber($levels['reserved']));
            $parts[] = 'disponibili '.static::escape(static::plainNumber($levels['available']));
        }

        $parts[] = '<a href="'.static::escape(StockAdjustmentResource::urlFor($productId)).'">Rettifica</a>';

        return implode(' · ', $parts);
    }

    /** Gli ultimi dieci movimenti di questa opzione. */
    protected static function stockHistoryTable(): string
    {
        $productId = static::currentId() ?? 0;
        $rows = $productId > 0 ? StockHistory::latest($productId, 10) : [];

        if ($rows === []) {
            return '<p class="text-muted mb-0">Nessun movimento: la giacenza di questa opzione non è mai cambiata.</p>';
        }

        $html = '<table class="table table-sm mb-2"><thead><tr>'
            .'<th>Quando</th><th>Tipo</th><th>Causale</th><th>Pezzi</th><th>Dopo</th><th>Nota</th>'
            .'</tr></thead><tbody>';

        foreach ($rows as $row) {
            $quantity = (float) ($row['quantity'] ?? 0);
            $html .= '<tr>'
                .'<td>'.static::escape((string) ($row['creation'] ?? '')).'</td>'
                .'<td>'.static::escape(StockMovement::typeLabels()[(string) ($row['type'] ?? '')] ?? '').'</td>'
                .'<td>'.static::escape(Reasons::label((string) ($row['reason'] ?? ''))).'</td>'
                .'<td>'.static::escape(($quantity > 0 ? '+' : '').static::plainNumber($quantity)).'</td>'
                .'<td>'.static::escape(static::plainNumber((float) ($row['quantity_after'] ?? 0))).'</td>'
                .'<td>'.static::escape((string) ($row['note'] ?? '')).'</td>'
                .'</tr>';
        }

        $html .= '</tbody></table><a href="'
            .static::escape(StockMovementResource::listUrlFor($productId))
            .'">Vedi tutti i movimenti</a>';

        return $html;
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')
                ->text()
                ->link('edit')
                // Le righe nate prima che il nome esistesse mostrano lo SKU:
                // meglio un codice di una casella vuota.
                ->formatter(static fn (array $row): string => static::escape(
                    trim((string) ($row['name'] ?? '')) !== ''
                        ? (string) $row['name']
                        : (string) ($row['sku'] ?? '')
                )),
            TableColumn::key('product_model_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(
                    static::modelNames()[(int) ($row['product_model_id'] ?? 0)] ?? '—'
                )),
            TableColumn::key('sku')->text()->size('little'),
            TableColumn::key('ean')->text()->size('little'),
            TableColumn::key('price')->text()->size('little'),
            // "Attiva" e "Ferma": qui non si parla di vetrina ma di magazzino.
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attiva', 'bi-check-circle', 'success')
                ->badgeOff('Ferma', 'bi-pause-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions(['edit']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        // Niente creazione: un'opzione nasce nella scheda del suo prodotto.
        return parent::pageSchema()
            ->only(['list', 'edit', 'update'])
            ->titles([
                'list' => 'Opzioni in vendita',
                'edit' => 'Modifica opzione',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /**
     * Fuori dal menu: un'opzione si apre dalla scheda del suo prodotto.
     *
     * Un elenco piatto di quello che si vende avrà senso con le giacenze, e
     * allora sarà quello del magazzino, con le sue colonne.
     */
    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('catalogo')
            ->title('Opzioni in vendita')
            ->order(39)
            ->authority(['admin', 'administrator'])
            ->enabled(false);
    }

    /**
     * L'indirizzo dell'elenco filtrato su un articolo.
     *
     * Lo usa il pulsante nella scheda del prodotto. Gli indirizzi delle
     * Resource nascono da rotte con un nome, non da percorsi scritti a mano:
     * `__r()` è la strada giusta, il percorso è solo il ripiego per quando il
     * framework non è avviato (test, comandi).
     */
    public static function listUrlFor(int $modelId): string
    {
        $base = '/backend/'.static::path();

        if (function_exists('__r')) {
            try {
                $named = (string) __r('backend.resource.'.static::slug().'.list');
                $base = $named !== '' ? $named : $base;
            } catch (\Throwable) {
                // Rotta non registrata: resta il percorso.
            }
        }

        return $base.'?prodotto='.$modelId;
    }

    /**
     * L'elenco, filtrato sull'articolo quando l'indirizzo lo dice.
     *
     * Il core rivaluta `querySchema()` a ogni richiesta, quindi leggere la
     * query string qui è sicuro: è la stessa strada di `currentId()`.
     */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $modelId = (int) ($_GET['prodotto'] ?? 0);

        if ($modelId > 0) {
            $schema['condition'] = array_merge(
                (array) ($schema['condition'] ?? []),
                ['product_model_id' => $modelId]
            );
        }

        return $schema;
    }

    /** SKU ed EAN unici, e i numeri nella forma che il database accetta. */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $id = (int) ($oldValues['id'] ?? 0);
        $sku = trim((string) ($values['sku'] ?? ''));
        $ean = trim((string) ($values['ean'] ?? ''));

        if ($sku !== '' && !Sku::isFree(Product::class, $sku, $id > 0 ? $id : null)) {
            throw UserError::make('product.sku_taken');
        }

        if (!Ean::isValid($ean)) {
            throw UserError::make('product.ean_invalid');
        }

        if (!Ean::isFree($ean, $id > 0 ? $id : null)) {
            throw UserError::make('product.ean_taken');
        }

        foreach (['price', 'sale_price', 'weight', 'length', 'width', 'height'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = Numbers::fromForm($values[$key]);
            }
        }

        foreach (array_keys($values) as $key) {
            if (str_starts_with((string) $key, 'attribute_')) {
                unset($values[$key]);
            }
        }

        // Senza la funzionalità la colonna non si scrive: un form aperto prima
        // di bloccarla non deve azzerare la soglia.
        if (!Gestionale::feature('low_stock_alerts')) {
            unset($values['min_stock_quantity']);
        } elseif (array_key_exists('min_stock_quantity', $values)) {
            $values['min_stock_quantity'] = static::minStockValue($values['min_stock_quantity']);
        }

        return $values;
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        $attributes = Attributes::byLevel(static::attributes(), 'product');
        $input = [];

        foreach ($attributes as $attribute) {
            $attributeId = (int) $attribute['id'];
            $input[$attributeId] = $_POST['attribute_'.$attributeId] ?? null;
        }

        ProductAttributes::save('product', (int) $id, $attributes, $input);

        // La soglia può essere appena cambiata senza nessun movimento:
        // l'avviso si apre o si chiude adesso, non alla prossima vendita.
        if (Gestionale::feature('low_stock_alerts')) {
            Alerts::refresh((int) $id);
        }
    }

    /** Riempie il form con gli attributi dell'opzione. */
    public static function mutateFormValues(
        array $values,
        string $mode,
        string $context = 'backend'
    ): array {
        $productId = (int) ($values['id'] ?? 0);

        if ($mode !== 'edit' || $productId === 0) {
            return $values;
        }

        $links = ProductAttributes::read('product', $productId);

        foreach (Attributes::byLevel(static::attributes(), 'product') as $attribute) {
            $attributeId = (int) $attribute['id'];
            $values['attribute_'.$attributeId] = static::attributeValue($attribute, $links[$attributeId] ?? null);
        }

        return $values;
    }

    /** Eliminare un'opzione porta via i suoi attributi. */
    public static function deleteRecord(int|string $id): object
    {
        foreach (ProductAttributes::read('product', (int) $id) as $link) {
            ProductAttributes::modelClass('product')::delete((int) $link['id']);
        }

        StockHistory::dropAlerts([(int) $id]);

        return Product::delete($id);
    }

    /** Il titolo della scheda dice di che articolo e di che colore si tratta. */
    public static function currentTitle(): string
    {
        $id = static::currentId();

        if ($id === null) {
            return 'Prodotto';
        }

        $product = static::rowsOf(Product::class, ['id' => $id])[0] ?? null;

        if ($product === null) {
            return 'Prodotto';
        }

        $model = static::modelNames()[(int) ($product['product_model_id'] ?? 0)] ?? '';
        $variant = static::variantNames()[(int) ($product['product_variant_id'] ?? 0)] ?? '';

        return trim($model.($variant === '' || $variant === $model ? '' : ' · '.$variant)) ?: 'Prodotto';
    }

    /** @return array<int, string> */
    public static function modelNames(): array
    {
        static $names = null;

        if ($names === null) {
            $names = [];

            foreach (static::rowsOf(ProductModel::class, [], 'name') as $row) {
                $names[(int) $row['id']] = (string) ($row['name'] ?? '');
            }
        }

        return $names;
    }

    /** @return array<int, string> */
    public static function variantNames(): array
    {
        static $names = null;

        if ($names === null) {
            $names = [];

            foreach (static::rowsOf(ProductVariant::class, [], 'position') as $row) {
                $names[(int) $row['id']] = (string) ($row['name'] ?? '');
            }
        }

        return $names;
    }

    /** Un'opzione non si crea da qui: non c'è niente da preparare. */
    public static function afterStore(object $result, array $values = []): void
    {
    }

    protected static function attributeField(array $attribute): Input
    {
        return parent::attributeField($attribute);
    }
}
