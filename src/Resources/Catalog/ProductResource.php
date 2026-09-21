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
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Ean;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Sku;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;

/**
 * "Prodotti": l'elenco piatto di quello che si vende.
 *
 * Serve a **trovare** — uno SKU, un EAN, un prezzo da correggere — non a
 * creare: un prodotto nasce dentro il suo modello, dove si vede insieme ai
 * fratelli. Per questo la pagina non ha il pulsante "Aggiungi".
 *
 * La scheda del singolo prodotto tiene quello che nel modello non entrerebbe:
 * MPN, misure proprie e gli attributi di livello `product`.
 *
 * Estende la Resource dei modelli per riusarne le letture del catalogo —
 * attributi, valori, campi degli attributi — e ne riscrive tutto il resto:
 * qui non nasce nessuno scheletro e non si elimina nessun modello.
 *
 * Non è `final`: i test la estendono con una classe anonima.
 */
class ProductResource extends ProductModelResource
{
    public static string $model = Product::class;
    public static string $orderColumn = 'sku';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'catalogo/catalogo-modelli';

    public static function path(): string
    {
        return 'app/gestionale/prodotti';
    }

    public static function icon(): string
    {
        return 'bi-upc-scan';
    }

    public static function titleLabel(): string
    {
        return 'Prodotti';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'prodotto',
            'plural_label' => 'prodotti',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'full' => 'attivo',
            'empty' => 'fermo',
            'this' => 'questo',
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
            'product_model_id' => 'Modello',
            'product_variant_id' => 'Variante',
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
                ->select(['true' => 'Attivo', 'false' => 'Fermo'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('weight')->number()->decimal(3)->label('Peso (kg)'),
            FormField::key('length')->number()->decimal(2)->label('Lunghezza (cm)'),
            FormField::key('width')->number()->decimal(2)->label('Larghezza (cm)'),
            FormField::key('height')->number()->decimal(2)->label('Altezza (cm)'),
        ];

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
                    ->tooltip('Questo prodotto nasce dal suo modello: nome, descrizioni e categorie si cambiano di là. Qui c\'è quello che vale per il singolo articolo a magazzino.')
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
                    ->tooltip('Gli attributi di livello "Prodotto": quelli che distinguono questo articolo dagli altri della stessa variante.')
                    ->columnSpan(12),
                ...$attributeInputs,
            ])->columns(12)->columnSpan(12);
        }

        $cards[] = (new Card)->components([
            SectionTitle::make('Misure')
                ->tooltip('Lasciando vuoto valgono peso e misure del modello.')
                ->columnSpan(12),
            static::getInput('weight')->columnSpan(3),
            static::getInput('length')->columnSpan(3),
            static::getInput('width')->columnSpan(3),
            static::getInput('height')->columnSpan(3),
        ])->columns(12)->columnSpan(12);

        return (new Form)->components([
            (new Container)->components($cards)->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('sku')->text()->link('edit'),
            TableColumn::key('product_model_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(
                    static::modelNames()[(int) ($row['product_model_id'] ?? 0)] ?? '—'
                )),
            TableColumn::key('product_variant_id')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    static::variantNames()[(int) ($row['product_variant_id'] ?? 0)] ?? '—'
                )),
            TableColumn::key('ean')->text()->size('little'),
            TableColumn::key('price')->text()->size('little'),
            // "Attivo" e "Fermo": qui non si parla di vetrina ma di magazzino.
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attivo', 'bi-check-circle', 'success')
                ->badgeOff('Fermo', 'bi-pause-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions(['edit']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        // Niente creazione: un prodotto nasce dentro il suo modello.
        return parent::pageSchema()
            ->only(['list', 'edit', 'update'])
            ->titles([
                'list' => 'Prodotti',
                'edit' => 'Modifica prodotto',
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

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('catalogo')
            ->title('Prodotti')
            ->order(39)
            ->authority(['admin', 'administrator']);
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
    }

    /** Riempie il form con gli attributi del prodotto. */
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

    /** Eliminare un prodotto porta via i suoi attributi. */
    public static function deleteRecord(int|string $id): object
    {
        foreach (ProductAttributes::read('product', (int) $id) as $link) {
            ProductAttributes::modelClass('product')::delete((int) $link['id']);
        }

        return Product::delete($id);
    }

    /** Il titolo della scheda dice di che articolo si tratta. */
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

    /** Un prodotto non si crea da qui: non c'è niente da preparare. */
    public static function afterStore(object $result, array $values = []): void
    {
    }

    protected static function attributeField(array $attribute): Input
    {
        return parent::attributeField($attribute);
    }
}
