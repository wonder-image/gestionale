<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelTag;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\CategoryTree;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Ean;
use Wonder\Plugin\Gestionale\Support\Catalog\Generator;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Sku;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Positions;
use Wonder\Sql\Transaction;

/**
 * "Modelli": la scheda dell'articolo, e l'unico posto dove si lavora.
 *
 * Varianti e prodotti stanno qui dentro, come righe: è l'unico modo per
 * vederli insieme. L'elenco "Prodotti" serve a trovare uno SKU, non a
 * modificare in massa.
 *
 * La scheda si adatta a chi la usa (G2a.2): finché la variante è una sola non
 * viene nemmeno nominata, e finché il prodotto è uno solo il suo SKU, il suo
 * EAN e il suo prezzo si scrivono nel riquadro principale invece che in una
 * tabella di una riga.
 *
 * Non è `final`: i test la estendono con una classe anonima per provare le
 * regole senza database.
 */
class ProductModelResource extends GestionaleResource
{
    public static string $model = ProductModel::class;
    public static string $orderColumn = 'name';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'catalogo/catalogo-modelli';

    /** @var list<array<string, mixed>>|null attributi letti una volta per richiesta */
    private static ?array $catalogAttributes = null;

    /** @var array<int, array<string, mixed>>|null valori letti una volta per richiesta */
    private static ?array $catalogValues = null;

    /** Unità di misura: quelle che un negozio usa davvero. */
    private const UNITS = [
        'pz' => 'Pezzi',
        'conf' => 'Confezioni',
        'kg' => 'Chilogrammi',
        'g' => 'Grammi',
        'l' => 'Litri',
        'ml' => 'Millilitri',
        'm' => 'Metri',
    ];

    public static function path(): string
    {
        return 'app/gestionale/prodotti';
    }

    public static function icon(): string
    {
        return 'bi-box';
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
            'full' => 'visibile',
            'empty' => 'nascosto',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'name' => 'Nome',
            'slug' => 'Url pubblico',
            'brand_id' => 'Marchio',
            'tax_category_id' => 'Tipo fiscale',
            'photo' => 'Foto',
            'sku' => 'SKU',
            'price' => 'Prezzo',
            'versions' => 'Versioni',
            'unit' => 'Unità di misura',
            'visible' => 'Stato',
            'visible_online' => 'In vetrina',
        ];
    }

    public static function formSchema(): array
    {
        $modelId = static::currentId();

        $fields = [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('brand_id')->select(static::brandOptions())->label('Marchio'),
            FormField::key('tax_category_id')->select(static::taxCategoryOptions())->label('Tipo fiscale'),
            FormField::key('sku')->text()->label('SKU del modello'),
            FormField::key('unit')->select(self::UNITS)->value('pz')->label('Unità di misura')->required(),
            FormField::key('visible')
                ->select(['true' => 'Visibile', 'false' => 'Nascosto'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('visible_online')
                ->select(['true' => 'In vetrina', 'false' => 'Solo in ufficio'])
                ->value('true')
                ->label('Vetrina')
                ->required(),
            FormField::key('short_description')->textarea()->label('Descrizione breve'),
            FormField::key('description')->textarea()->label('Descrizione'),
            FormField::key('categories')->checkTree(static::categoryTree(), true)->label('Categorie'),
            FormField::key('main_category')->select(static::categoryOptions())->label('Categoria principale'),
            FormField::key('tags')->selectSearch(static::tagOptions(), true)->label('Tag'),
            FormField::key('weight')->number()->decimal(3)->label('Peso (kg)'),
            FormField::key('length')->number()->decimal(2)->label('Lunghezza (cm)'),
            FormField::key('width')->number()->decimal(2)->label('Larghezza (cm)'),
            FormField::key('height')->number()->decimal(2)->label('Altezza (cm)'),
            FormField::key('returnable')
                ->select(['true' => 'Sì', 'false' => 'No'])
                ->value('true')
                ->label('Si può rendere'),
            FormField::key('requires_shipping')
                ->select(['true' => 'Sì', 'false' => 'No'])
                ->value('true')
                ->label('Si spedisce'),
        ];

        foreach (Attributes::byLevel(static::attributes(), 'model') as $attribute) {
            $fields[] = static::attributeField($attribute);
        }

        if (static::optionTree() !== []) {
            $fields[] = FormField::key('option_values')
                ->checkTree(static::optionTree(), true)
                ->label('In quali versioni si vende');
        }

        if ($modelId !== null) {
            $fields[] = static::imagesField($modelId);
        }

        // I due repeater esistono solo quando c'è più di una riga da mostrare.
        // Non è solo estetica: un campo che non viene stampato non viene
        // nemmeno postato, e il sync dei repeater cancella le righe che non
        // ritrova.
        if ($modelId !== null && static::variantCount($modelId) > 1) {
            $fields[] = static::variantsField();
        }

        if ($modelId !== null && static::productCount($modelId) > 1) {
            $fields[] = static::productsField();
        } else {
            array_push($fields, ...static::soleProductFields());
        }

        return $fields;
    }

    /**
     * Sette riquadri, e due in fondo.
     *
     * Erano dieci, tutti aperti e tutti con lo stesso peso: un cappello di lana
     * ne usava tre e doveva scorrere gli altri sette. L'ordine adesso segue la
     * compilazione — chi è, com'è fatto in vendita, come si vede, cosa si
     * legge, dove sta — e quello che si tocca una volta l'anno sta in coda.
     */
    public static function formLayoutSchema(): ?Form
    {
        $modelId = static::currentId();
        $unaVersione = $modelId === null || static::productCount($modelId) <= 1;

        $prodotto = [
            SectionTitle::make('Prodotto')
                ->tooltip('Lo SKU è il codice di famiglia: da lì il pannello propone quello delle singole versioni. L\'url pubblico nasce dal nome alla creazione e non cambia più.')
                ->columnSpan(12),
            static::getInput('name')->columnSpan(6),
            static::getInput('sku')->columnSpan(3),
            static::getInput('visible')->columnSpan(3),
        ];

        // Con una versione sola prezzo e codici stanno qui: aprire una tabella
        // di una riga per scrivere un prezzo è una scortesia.
        if ($unaVersione) {
            $prodotto[] = static::getInput('product_price')->columnSpan(3);
            $prodotto[] = static::getInput('product_sale_price')->columnSpan(3);
            $prodotto[] = static::getInput('product_sku')->columnSpan(3);
            $prodotto[] = static::getInput('product_ean')->columnSpan(3);
        }

        $cards = [(new Card)->components($prodotto)->columns(12)->columnSpan(12)];

        // Finché la versione è una sola il riquadro non serve a nessuno: va in
        // fondo, e chi ne ha bisogno lo trova là.
        if (!$unaVersione) {
            $versioni = [
                SectionTitle::make('Versioni in vendita')
                    ->tooltip('Spunta i valori e salva: nascono le righe che mancano, con il nome e lo SKU proposti. Togliere una spunta non cancella niente; per eliminare una versione si elimina la sua riga.')
                    ->columnSpan(12),
            ];

            if (static::optionTree() !== []) {
                $versioni[] = static::getInput('option_values')->columnSpan(12);
            }

            $versioni[] = static::getInput('products')->columnSpan(12);

            if (static::variantCount((int) $modelId) > 1) {
                $versioni[] = static::getInput('variants')->columnSpan(12);
            }

            $cards[] = (new Card)->components($versioni)->columns(12)->columnSpan(12);
        }

        if ($modelId !== null) {
            $cards[] = (new Card)->components([
                SectionTitle::make('Foto')
                    ->tooltip('Carica e salva: le foto si vedono subito, le misure per il sito arrivano poco dopo. Una foto senza versione vale per tutto l\'articolo; con la versione vale solo per quella.')
                    ->columnSpan(12),
                static::getInput('images')->columnSpan(12),
            ])->columns(12)->columnSpan(12);
        }

        $cards[] = (new Card)->components([
            SectionTitle::make('Descrizione')->columnSpan(12),
            static::getInput('short_description')->columnSpan(12),
            static::getInput('description')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        $cards[] = (new Card)->components([
            SectionTitle::make('Dove si trova')
                ->tooltip('La categoria principale è quella che la vetrina userà per l\'indirizzo della pagina: se la scegli e non l\'hai spuntata, viene aggiunta da sé.')
                ->columnSpan(12),
            static::getInput('brand_id')->columnSpan(4),
            static::getInput('main_category')->columnSpan(4),
            static::getInput('tags')->columnSpan(4),
            static::getInput('categories')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        // In fondo, quello che si tocca di rado. Prima però le versioni, se
        // l'articolo non ne ha ancora: è lì che si va a cercarle.
        if ($unaVersione && static::optionTree() !== []) {
            $cards[] = static::foldable(
                'Si vende in più versioni? (colori, taglie…)',
                [static::getInput('option_values')->columnSpan(12)],
                'Spunta i colori e le taglie in cui vendi questo articolo e salva: le righe nascono da sole, con il nome e lo SKU proposti.'
            );
        }

        $attributeInputs = [];

        foreach (Attributes::byLevel(static::attributes(), 'model') as $attribute) {
            $attributeInputs[] = static::getInput('attribute_'.(int) $attribute['id'])->columnSpan(4);
        }

        if ($attributeInputs !== []) {
            $cards[] = static::foldable(
                'Scheda tecnica',
                $attributeInputs,
                'Quello che descrive l\'articolo e non fa nascere versioni: materiale, composizione, paese.'
            );
        }

        $cards[] = static::foldable('Spedizione e fisco', [
            static::getInput('unit')->columnSpan(3),
            static::getInput('tax_category_id')->columnSpan(3),
            static::getInput('visible_online')->columnSpan(3),
            static::getInput('weight')->columnSpan(3),
            static::getInput('length')->columnSpan(3),
            static::getInput('width')->columnSpan(3),
            static::getInput('height')->columnSpan(3),
            static::getInput('returnable')->columnSpan(6),
            static::getInput('requires_shipping')->columnSpan(6),
        ], 'Peso e misure dell\'articolo, unità di vendita e tipo fiscale. Una versione con misure sue le usa al posto di queste.');

        return (new Form)->components([
            (new Container)->components($cards)->columns(12)->columnSpan(12),
        ]);
    }

    /**
     * L'elenco deve rispondere da solo a "quale dei due maglioni blu è questo".
     *
     * Per questo la miniatura e il prezzo: con nome, SKU e marchio due articoli
     * simili si distinguono solo aprendoli.
     */
    public static function tableSchema(): array
    {
        return [
            TableColumn::key('photo')
                ->image()
                ->size('little')
                ->formatter(static fn (array $row): string => static::firstImage((int) ($row['id'] ?? 0))),
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('sku')->text()->size('little'),
            TableColumn::key('price')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    static::priceRange((int) ($row['id'] ?? 0))
                )),
            TableColumn::key('versions')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => (string) static::productCount((int) ($row['id'] ?? 0))),
            TableColumn::key('brand_id')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    static::brandOptions()[(string) ($row['brand_id'] ?? '')] ?? ''
                )),
            TableColumn::key('visible')->visibleBadge()->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    /**
     * Il prezzo dell'articolo, come lo legge chi scorre l'elenco.
     *
     * Un prezzo solo quando le versioni costano uguale, "da 19,90" quando no:
     * scrivere il minimo e basta farebbe credere che costino tutte così.
     */
    public static function priceRange(int $modelId): string
    {
        $prices = [];

        foreach (static::products($modelId) as $product) {
            $price = (float) ($product['price'] ?? 0);

            if ($price > 0) {
                $prices[] = $price;
            }
        }

        if ($prices === []) {
            return '';
        }

        $minimo = number_format(min($prices), 2, ',', '.');

        return min($prices) === max($prices) ? $minimo : 'da '.$minimo;
    }

    /** La prima foto dell'articolo, per la miniatura dell'elenco. */
    public static function firstImage(int $modelId): string
    {
        foreach (static::rowsOf(ProductImage::class, ['product_model_id' => $modelId], 'position') as $row) {
            $url = ProductImages::url($row);

            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    public static function pageSchema(): PageSchema
    {
        $schema = parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Prodotti',
                'create' => 'Nuovo prodotto',
                'edit' => 'Modifica prodotto',
            ]);

        // I campi rari di una singola versione — codice del produttore, misure
        // proprie, ordinabile su richiesta — non stanno in una riga di griglia.
        // Il pulsante c'è solo quando le versioni sono più di una: con una
        // sola, quei campi non li cerca nessuno.
        return $schema->actions('edit', static function (array $item): array {
            $modelId = (int) ($item['id'] ?? 0);

            if ($modelId === 0 || static::productCount($modelId) <= 1) {
                return [];
            }

            return [[
                'label' => 'Dettagli delle versioni',
                'href' => ProductResource::listUrlFor($modelId),
                'class' => 'btn-outline-primary',
                'icon' => 'bi-upc-scan',
            ]];
        });
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
            ->order(38)
            ->authority(['admin', 'administrator']);
    }

    /**
     * Slug e posizione alla creazione; codici e spunte controllati sempre.
     *
     * I rifiuti stanno tutti qui, e non in `saveExtras()`: là si è già dentro
     * una transazione e il modello è già salvato, quindi un rifiuto lascerebbe
     * il lavoro a metà.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $id = (int) ($oldValues['id'] ?? 0);

        if ($action === 'store') {
            $values['slug'] = Slug::make((string) ($values['name'] ?? ''), ProductModel::$table);
            $values['position'] = Positions::next(ProductModel::$table);
        } else {
            unset($values['slug'], $values['position']);
        }

        $sku = trim((string) ($values['sku'] ?? ''));

        if ($sku !== '' && !Sku::isFree(ProductModel::class, $sku, $id > 0 ? $id : null)) {
            throw UserError::make('product.sku_taken');
        }

        static::assertSoleProduct($id, $values);
        static::chosenAxes($_POST['option_values'] ?? []);
        static::assertSomeVersionLeft($id);

        return static::withoutExtras($values);
    }

    /**
     * La griglia lascia eliminare le righe una per una, fino all'ultima.
     *
     * Un articolo senza niente da vendere non esiste, e il database non lo
     * direbbe mai. Il repeater c'è solo quando le righe sono più di una: se
     * c'è e torna vuoto, vuol dire che le hanno cancellate tutte.
     */
    public static function assertSomeVersionLeft(int $modelId): void
    {
        if ($modelId <= 0 || !isset($_POST['products']) || static::productCount($modelId) <= 1) {
            return;
        }

        if (array_filter((array) $_POST['products'], 'is_array') === []) {
            throw UserError::make('product.no_versions');
        }
    }

    /** SKU ed EAN del prodotto unico, quando la scheda li mostra. */
    public static function assertSoleProduct(int $modelId, array $values): void
    {
        $ean = trim((string) ($values['product_ean'] ?? ''));
        $sku = trim((string) ($values['product_sku'] ?? ''));
        $product = $modelId > 0 ? static::soleProduct($modelId) : null;
        $productId = $product === null ? null : (int) $product['id'];

        if (!Ean::isValid($ean)) {
            throw UserError::make('product.ean_invalid');
        }

        if (!Ean::isFree($ean, $productId)) {
            throw UserError::make('product.ean_taken');
        }

        if ($sku !== '' && !Sku::isFree(Product::class, $sku, $productId)) {
            throw UserError::make('product.sku_taken');
        }
    }

    /** Un modello nuovo nasce con la sua variante e il suo prodotto (G2a.2). */
    public static function afterStore(object $result, array $values = []): void
    {
        $id = (int) ($result->insert_id ?? 0);

        if ($id === 0) {
            return;
        }

        $sku = (string) ($values['sku'] ?? '');

        Skeleton::forModel($id, (string) ($values['name'] ?? ''), $sku);
        static::saveExtras($id, (array) $_POST, $sku);
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        static::saveExtras((int) $id, (array) $_POST);
    }

    /**
     * Righe legate al modello: categorie, tag, attributi e prodotto unico.
     *
     * `$fallbackSku` è lo SKU del modello appena creato: se la casella del
     * prodotto è vuota, il prodotto tiene quello, invece di perdere il codice
     * che il modello gli ha appena dato.
     */
    public static function saveExtras(int $modelId, array $post, string $fallbackSku = ''): void
    {
        if ($modelId <= 0) {
            return;
        }

        Transaction::run(static function () use ($modelId, $post, $fallbackSku): void {
            static::saveCategories($modelId, $post);
            static::saveTags($modelId, $post);
            static::saveModelAttributes($modelId, $post);
            $chosen = static::chosenAxes($post['option_values'] ?? []);
            Generator::run($modelId, $chosen['variant'], $chosen['axes'], $fallbackSku);
            static::saveSoleProduct($modelId, $post, $fallbackSku);
        });
    }

    /** Riempie il form con ciò che non sta nella tabella del modello. */
    public static function mutateFormValues(
        array $values,
        string $mode,
        string $context = 'backend'
    ): array {
        $modelId = (int) ($values['id'] ?? 0);

        if ($mode !== 'edit' || $modelId === 0) {
            return $values;
        }

        $values['categories'] = array_map('strval', static::categoryIds($modelId));
        $values['main_category'] = (string) (static::mainCategoryId($modelId) ?: '');
        $values['tags'] = array_map('strval', static::tagIds($modelId));

        $links = ProductAttributes::read('model', $modelId);

        foreach (Attributes::byLevel(static::attributes(), 'model') as $attribute) {
            $id = (int) $attribute['id'];
            $values['attribute_'.$id] = static::attributeValue($attribute, $links[$id] ?? null);
        }

        $product = static::soleProduct($modelId);

        if ($product !== null) {
            $values['product_sku'] = (string) ($product['sku'] ?? '');
            $values['product_ean'] = (string) ($product['ean'] ?? '');
            $values['product_price'] = (string) ($product['price'] ?? '');
            $values['product_sale_price'] = (string) ($product['sale_price'] ?? '');
        }

        return $values;
    }

    /** Una riga nuova del repeater deve avere codice e indirizzo. */
    public static function prepareRepeaterRelationRow(
        string $inputName,
        array $payload,
        array $row,
        ?array $existingRow = null,
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if ($existingRow !== null) {
            // Rimettere una foto "in lavorazione" vuol dire riprovarci: i
            // tentativi ripartono da zero, altrimenti si arrenderebbe subito.
            if ($inputName === 'images'
                && ($payload['status'] ?? '') === 'pending'
                && ($existingRow['status'] ?? '') === 'failed') {
                $payload['attempts'] = 0;
                $payload['error'] = '';
            }

            return $payload;
        }

        if ($inputName === 'variants') {
            $name = (string) ($payload['name'] ?? '');
            $payload['code'] = Code::make(ProductVariant::class, Codes::VARIANT);
            $payload['slug'] = Slug::make($name.'-'.uniqid());
        }

        if ($inputName === 'images') {
            // La riga nasce in attesa: le misure le farà la coda.
            $payload['status'] = 'pending';
            $payload['attempts'] = 0;
        }

        if ($inputName === 'products') {
            $modelId = (int) ($payload['product_model_id'] ?? 0);
            $payload['code'] = Code::make(Product::class, Codes::PRODUCT);
            // Un prodotto senza variante non esiste: una riga aggiunta a mano
            // finisce sulla prima variante del modello.
            $payload['product_variant_id'] = (int) ($payload['product_variant_id'] ?? 0)
                ?: static::firstVariantId($modelId);
        }

        return $payload;
    }

    /** Eliminare un modello porta via le sue righe: da solo il database rifiuta. */
    public static function deleteRecord(int|string $id): object
    {
        $modelId = (int) $id;

        Transaction::run(static function () use ($modelId): void {
            foreach (static::products($modelId) as $product) {
                foreach (ProductAttributes::read('product', (int) $product['id']) as $link) {
                    ProductAttributes::modelClass('product')::delete((int) $link['id']);
                }

                Product::delete((int) $product['id']);
            }

            foreach (static::variants($modelId) as $variant) {
                foreach (ProductAttributes::read('variant', (int) $variant['id']) as $link) {
                    ProductAttributes::modelClass('variant')::delete((int) $link['id']);
                }

                ProductVariant::delete((int) $variant['id']);
            }

            foreach (ProductAttributes::read('model', $modelId) as $link) {
                ProductAttributes::modelClass('model')::delete((int) $link['id']);
            }

            // Le foto se ne vanno con l'articolo, file compresi: lasciarle sul
            // disco vuol dire ritrovarsele fra un anno senza sapere di chi sono.
            foreach (static::rowsOf(ProductImage::class, ['product_model_id' => $modelId]) as $row) {
                foreach (glob(static::imageFiles($row)) ?: [] as $file) {
                    @unlink($file);
                }

                ProductImage::delete((int) $row['id']);
            }

            foreach (static::rowsOf(ProductModelCategory::class, ['product_model_id' => $modelId]) as $row) {
                ProductModelCategory::delete((int) $row['id']);
            }

            foreach (static::rowsOf(ProductModelTag::class, ['product_model_id' => $modelId]) as $row) {
                ProductModelTag::delete((int) $row['id']);
            }
        });

        return parent::deleteRecord($id);
    }

    /**
     * L'albero delle opzioni da spuntare: un nodo per opzione, i valori sotto.
     *
     * Uno solo, non due: chi compila non deve sapere quali opzioni abbiano
     * pagina propria e quali no. Lo smistamento lo fa `chosenAxes()` al
     * salvataggio, leggendo il livello dell'attributo.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function optionTree(): array
    {
        $tree = [];

        foreach (static::attributes() as $attribute) {
            if (!Attributes::createsVersions((string) ($attribute['level'] ?? ''))) {
                continue;
            }

            if (!Attributes::usesValues((string) ($attribute['type'] ?? ''))) {
                continue;
            }

            $id = (int) $attribute['id'];
            $children = [];

            foreach (static::attributeValues() as $value) {
                if ((int) ($value['attribute_id'] ?? 0) === $id) {
                    $children[(string) $value['id']] = [
                        'name' => (string) ($value['label'] ?? ''),
                        'child' => [],
                    ];
                }
            }

            if ($children !== []) {
                // La chiave dell'opzione non è un valore: chi legge le spunte
                // tiene solo gli id che sono davvero dei valori.
                $tree['attr_'.$id] = [
                    'name' => (string) ($attribute['name'] ?? ''),
                    'child' => $children,
                ];
            }
        }

        return $tree;
    }

    /**
     * Le spunte, divise in assi.
     *
     * `variant` è l'asse con pagina propria — al massimo uno, altrimenti non si
     * saprebbe quale valore sia la pagina. Gli altri finiscono in `axes`, un
     * elemento per opzione, e si moltiplicano fra loro.
     *
     * @return array{variant: list<array{id: int, label: string}>, axes: list<list<array{id: int, label: string}>>}
     */
    public static function chosenAxes(mixed $chosen): array
    {
        $ids = array_filter(array_map('intval', (array) $chosen), static fn (int $id): bool => $id > 0);

        if ($ids === []) {
            return ['variant' => [], 'axes' => []];
        }

        $levels = [];

        foreach (static::attributes() as $attribute) {
            $levels[(int) $attribute['id']] = (string) ($attribute['level'] ?? '');
        }

        $variant = [];
        $variantAttributes = [];
        $byAttribute = [];

        foreach (static::attributeValues() as $value) {
            $id = (int) ($value['id'] ?? 0);

            if (!in_array($id, $ids, true)) {
                continue;
            }

            $attributeId = (int) ($value['attribute_id'] ?? 0);
            $entry = ['id' => $id, 'label' => (string) ($value['label'] ?? '')];

            if (($levels[$attributeId] ?? '') === 'variant') {
                $variantAttributes[$attributeId] = true;
                $variant[] = $entry;
            } elseif (($levels[$attributeId] ?? '') === 'product') {
                $byAttribute[$attributeId][] = $entry;
            }
        }

        if (count($variantAttributes) > 1) {
            throw UserError::make('product.one_page_option');
        }

        return ['variant' => $variant, 'axes' => array_values($byAttribute)];
    }

    /**
     * La galleria: una riga per foto, con la variante a cui appartiene.
     *
     * Un repeater dentro un repeater non esiste, e le varianti stanno già in
     * un repeater: la variante si sceglie da un select, e l'ereditarietà la
     * applica `ProductImages::for()` quando qualcuno legge.
     */
    protected static function imagesField(int $modelId): Input
    {
        return FormField::key('images')
            ->repeater([
                RepeaterColumn::key('id')->hidden(),
                RepeaterColumn::key('file')->fileDragDrop('image')->label('Foto')->columnSpan(4),
                RepeaterColumn::key('alt')->text()->label('Descrizione')->columnSpan(4),
                RepeaterColumn::key('product_variant_id')
                    ->select(static::variantOptions($modelId))
                    ->label('Vale per')
                    ->columnSpan(2),
                // Lo stato si può rimettere a "In attesa": è il modo di dire
                // «riprova» a una foto che non è riuscita.
                RepeaterColumn::key('status')
                    ->select([
                        'pending' => 'In lavorazione',
                        'ready' => 'Pronta',
                        'failed' => 'Non riuscita',
                    ])
                    ->value('pending')
                    ->label('Stato')
                    ->columnSpan(2),
            ])
            ->relation(
                RepeaterRelation::make(ProductImage::$table, 'product_model_id')
                    ->model(ProductImage::class)
                    ->positionKey('position')
            )
            ->nested()
            ->repeaterSortable()
            ->repeaterAddLabel('Aggiungi foto')
            ->repeaterDeleteTitle('Elimina foto')
            ->repeaterDeleteText('Confermi l\'eliminazione di questa foto?')
            ->repeaterDeleteCancelLabel('Annulla')
            ->repeaterDeleteConfirmLabel('Elimina')
            ->repeaterDeleteConfirmClass('btn btn-danger')
            ->label('Una riga per foto');
    }

    /** Le varianti di un modello, più la voce che vale per tutte. */
    public static function variantOptions(int $modelId): array
    {
        $options = ['' => 'Tutto l\'articolo'];

        foreach (static::variants($modelId) as $variant) {
            $options[(string) $variant['id']] = (string) ($variant['name'] ?? '');
        }

        return $options;
    }

    /** Il file di una foto e tutte le misure che ne sono nate. */
    public static function imageFiles(array $image): string
    {
        $path = ProductImages::path($image);

        if ($path === '') {
            return '';
        }

        // L'originale e tutte le misure nate da lui: `foto.jpg`, `foto.webp`,
        // `foto-480.jpg` e compagnia.
        return dirname($path).'/'.pathinfo($path, PATHINFO_FILENAME).'*';
    }

    /** Quante varianti ha quel modello. */
    public static function variantCount(int $modelId): int
    {
        return count(static::variants($modelId));
    }

    /** Quanti prodotti ha quel modello. */
    public static function productCount(int $modelId): int
    {
        return count(static::products($modelId));
    }

    /** Il prodotto, quando è uno solo: è lì che finiscono SKU, EAN e prezzo. */
    public static function soleProduct(int $modelId): ?array
    {
        $products = static::products($modelId);

        return count($products) === 1 ? $products[0] : null;
    }

    /** @return list<array<string, mixed>> */
    public static function variants(int $modelId): array
    {
        return static::rowsOf(ProductVariant::class, ['product_model_id' => $modelId], 'position');
    }

    /** @return list<array<string, mixed>> */
    public static function products(int $modelId): array
    {
        return static::rowsOf(Product::class, ['product_model_id' => $modelId], 'position');
    }

    /** Gli attributi visibili, letti una volta per richiesta. */
    public static function attributes(): array
    {
        return static::$catalogAttributes ??= static::rowsOf(Attribute::class, ['is_visible' => 'true'], 'position');
    }

    /**
     * Butta via la lettura di attributi e valori.
     *
     * In una richiesta il catalogo non cambia sotto i piedi, e leggerlo una
     * volta basta. Nei test, dove si creano attributi e poi si genera, serve
     * dirlo.
     */
    public static function forgetCatalogCache(): void
    {
        static::$catalogAttributes = null;
        static::$catalogValues = null;
    }

    /** I valori degli attributi a elenco, per id. @return array<int, array<string, mixed>> */
    public static function attributeValues(): array
    {
        if (static::$catalogValues === null) {
            static::$catalogValues = [];

            foreach (static::rowsOf(AttributeValue::class, [], 'position') as $row) {
                static::$catalogValues[(int) $row['id']] = $row;
            }
        }

        return static::$catalogValues;
    }

    protected static function firstVariantId(int $modelId): int
    {
        $variants = static::variants($modelId);

        return (int) ($variants[0]['id'] ?? 0);
    }

    /** Il campo di un attributo di modello, secondo il suo tipo. */
    protected static function attributeField(array $attribute): Input
    {
        $id = (int) $attribute['id'];
        $name = (string) ($attribute['name'] ?? '');
        $unit = trim((string) ($attribute['unit'] ?? ''));
        $label = $unit === '' ? $name : $name.' ('.$unit.')';
        $field = FormField::key('attribute_'.$id);

        if (Attributes::usesValues((string) ($attribute['type'] ?? ''))) {
            return $field->select(static::valueOptions($id))->label($label);
        }

        if (($attribute['type'] ?? '') === 'number') {
            return $field->number()->decimal(3)->label($label);
        }

        return $field->text()->label($label);
    }

    /** Il valore scritto nel form per quell'attributo. */
    protected static function attributeValue(array $attribute, ?array $link): string
    {
        if ($link === null) {
            return '';
        }

        if (Attributes::usesValues((string) ($attribute['type'] ?? ''))) {
            return (string) ($link['attribute_value_id'] ?? '');
        }

        if (($attribute['type'] ?? '') === 'number') {
            return (string) ($link['value_number'] ?? '');
        }

        return (string) ($link['value_text'] ?? '');
    }

    /** Voci di un attributo a elenco. @return array<string, string> */
    protected static function valueOptions(int $attributeId): array
    {
        $options = ['' => '—'];

        foreach (static::attributeValues() as $value) {
            if ((int) ($value['attribute_id'] ?? 0) === $attributeId) {
                $options[(string) $value['id']] = (string) ($value['label'] ?? '');
            }
        }

        return $options;
    }

    /**
     * Come si chiama, in questo negozio, l'opzione con pagina propria.
     *
     * "Colore" per chi vende magliette, "Gusto" per una gelateria. Serve a non
     * far mai leggere a nessuno la parola "variante".
     */
    public static function pageOptionName(): string
    {
        foreach (static::attributes() as $attribute) {
            if (($attribute['level'] ?? '') === 'variant') {
                return (string) ($attribute['name'] ?? '');
            }
        }

        return 'Versioni con pagina propria';
    }

    protected static function variantsField(): Input
    {
        return FormField::key('variants')
            ->repeater([
                RepeaterColumn::key('id')->hidden(),
                RepeaterColumn::key('name')->text()->label('Nome')->columnSpan(8),
                RepeaterColumn::key('visible')
                    ->select(['true' => 'Visibile', 'false' => 'Nascosta'])
                    ->label('Stato')
                    ->columnSpan(3),
            ])
            ->relation(
                RepeaterRelation::make(ProductVariant::$table, 'product_model_id')
                    ->model(ProductVariant::class)
                    ->positionKey('position')
            )
            ->nested()
            ->repeaterSortable()
            ->repeaterAddLabel('Aggiungi '.mb_strtolower(static::pageOptionName()))
            ->repeaterDeleteTitle('Elimina')
            ->repeaterDeleteText('Le versioni che stanno qui sotto restano senza: confermi?')
            ->repeaterDeleteCancelLabel('Annulla')
            ->repeaterDeleteConfirmLabel('Elimina')
            ->repeaterDeleteConfirmClass('btn btn-danger')
            // Il nome dell'opzione che le genera: in un negozio di magliette si
            // legge "Colore", in una gelateria "Gusto". La parola "variante"
            // non la deve incontrare nessuno.
            ->label(static::pageOptionName());
    }

    protected static function productsField(): Input
    {
        return FormField::key('products')
            ->repeater([
                RepeaterColumn::key('id')->hidden(),
                // Per prima: è l'unica colonna che dice di quale riga si tratti.
                RepeaterColumn::key('name')->text()->label('Versione')->columnSpan(3),
                RepeaterColumn::key('sku')->text()->label('SKU')->columnSpan(2),
                RepeaterColumn::key('ean')->text()->label('EAN')->columnSpan(2),
                RepeaterColumn::key('price')->number()->decimal(2)->label('Prezzo')->columnSpan(2),
                RepeaterColumn::key('sale_price')->number()->decimal(2)->label('Scontato')->columnSpan(2),
                RepeaterColumn::key('active')
                    ->select(['true' => 'Attivo', 'false' => 'Fermo'])
                    ->label('Stato')
                    ->columnSpan(1),
            ])
            ->relation(
                RepeaterRelation::make(Product::$table, 'product_model_id')
                    ->model(Product::class)
                    ->positionKey('position')
            )
            ->nested()
            ->repeaterSortable()
            ->repeaterAddLabel('Aggiungi versione')
            ->repeaterDeleteTitle('Elimina versione')
            ->repeaterDeleteText('Confermi l\'eliminazione di questa versione?')
            ->repeaterDeleteCancelLabel('Annulla')
            ->repeaterDeleteConfirmLabel('Elimina')
            ->repeaterDeleteConfirmClass('btn btn-danger')
            ->label('Quello che si vende');
    }

    /** @return list<Input> */
    protected static function soleProductFields(): array
    {
        return [
            FormField::key('product_sku')->text()->label('SKU'),
            FormField::key('product_ean')->text()->label('EAN'),
            FormField::key('product_price')->number()->decimal(2)->label('Prezzo'),
            FormField::key('product_sale_price')->number()->decimal(2)->label('Prezzo scontato'),
        ];
    }

    /** Toglie dai valori tutto ciò che non è una colonna del modello. */
    protected static function withoutExtras(array $values): array
    {
        unset(
            $values['categories'],
            $values['main_category'],
            $values['tags'],
            $values['product_sku'],
            $values['product_ean'],
            $values['product_price'],
            $values['product_sale_price'],
            $values['option_values'],
        );

        foreach (array_keys($values) as $key) {
            if (str_starts_with((string) $key, 'attribute_')) {
                unset($values[$key]);
            }
        }

        return $values;
    }

    protected static function saveCategories(int $modelId, array $post): void
    {
        $chosen = array_values(array_unique(array_filter(
            array_map('intval', (array) ($post['categories'] ?? [])),
            static fn (int $id): bool => $id > 0
        )));
        $main = (int) ($post['main_category'] ?? 0);

        // Scegliere la principale senza spuntarla è una svista, non un errore:
        // la si aggiunge invece di chiedere due volte la stessa cosa.
        if ($main > 0 && !in_array($main, $chosen, true)) {
            $chosen[] = $main;
        }

        $existing = [];

        foreach (static::rowsOf(ProductModelCategory::class, ['product_model_id' => $modelId]) as $row) {
            $existing[(int) $row['category_id']] = $row;
        }

        foreach ($existing as $categoryId => $row) {
            if (!in_array($categoryId, $chosen, true)) {
                ProductModelCategory::delete((int) $row['id']);
            }
        }

        $position = 1;

        foreach ($chosen as $categoryId) {
            $values = [
                'product_model_id' => $modelId,
                'category_id' => $categoryId,
                'is_main' => $categoryId === $main ? 'true' : 'false',
                'position' => $position++,
            ];

            isset($existing[$categoryId])
                ? ProductModelCategory::update($values, (int) $existing[$categoryId]['id'])
                : ProductModelCategory::create($values);
        }
    }

    protected static function saveTags(int $modelId, array $post): void
    {
        $chosen = array_values(array_unique(array_filter(
            array_map('intval', (array) ($post['tags'] ?? [])),
            static fn (int $id): bool => $id > 0
        )));
        $existing = [];

        foreach (static::rowsOf(ProductModelTag::class, ['product_model_id' => $modelId]) as $row) {
            $existing[(int) $row['tag_id']] = $row;
        }

        foreach ($existing as $tagId => $row) {
            if (!in_array($tagId, $chosen, true)) {
                ProductModelTag::delete((int) $row['id']);
            }
        }

        foreach ($chosen as $tagId) {
            if (!isset($existing[$tagId])) {
                ProductModelTag::create(['product_model_id' => $modelId, 'tag_id' => $tagId]);
            }
        }
    }

    protected static function saveModelAttributes(int $modelId, array $post): void
    {
        $attributes = Attributes::byLevel(static::attributes(), 'model');
        $input = [];

        foreach ($attributes as $attribute) {
            $id = (int) $attribute['id'];
            $input[$id] = $post['attribute_'.$id] ?? null;
        }

        ProductAttributes::save('model', $modelId, $attributes, $input);
    }

    /** SKU, EAN e prezzi del prodotto unico, scritti sul prodotto. */
    protected static function saveSoleProduct(int $modelId, array $post, string $fallbackSku = ''): void
    {
        if (static::productCount($modelId) > 1) {
            return;
        }

        $product = static::soleProduct($modelId);

        if ($product === null) {
            return;
        }

        $sku = trim((string) ($post['product_sku'] ?? ''));

        Product::update([
            'sku' => $sku !== '' ? $sku : $fallbackSku,
            'ean' => trim((string) ($post['product_ean'] ?? '')),
            // I decimali arrivano con la virgola: MySQL non li accetta.
            'price' => Numbers::fromForm($post['product_price'] ?? null),
            'sale_price' => Numbers::fromForm($post['product_sale_price'] ?? null),
        ], (int) $product['id']);
    }

    /** @return list<int> */
    protected static function categoryIds(int $modelId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['category_id'],
            static::rowsOf(ProductModelCategory::class, ['product_model_id' => $modelId], 'position')
        );
    }

    protected static function mainCategoryId(int $modelId): int
    {
        foreach (static::rowsOf(ProductModelCategory::class, ['product_model_id' => $modelId]) as $row) {
            if (($row['is_main'] ?? 'false') === 'true') {
                return (int) $row['category_id'];
            }
        }

        return 0;
    }

    /** @return list<int> */
    protected static function tagIds(int $modelId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['tag_id'],
            static::rowsOf(ProductModelTag::class, ['product_model_id' => $modelId])
        );
    }

    /** @return array<string, string> */
    protected static function brandOptions(): array
    {
        $options = ['' => 'Nessuno'];

        foreach (static::rowsOf(Brand::class, [], 'name') as $row) {
            $options[(string) $row['id']] = (string) ($row['name'] ?? '');
        }

        return $options;
    }

    /** @return array<string, string> */
    protected static function taxCategoryOptions(): array
    {
        $options = ['' => 'Predefinito'];

        foreach (static::rowsOf(TaxCategory::class, [], 'position') as $row) {
            $options[(string) $row['id']] = (string) ($row['name'] ?? '');
        }

        return $options;
    }

    /** @return array<string, string> */
    protected static function tagOptions(): array
    {
        $options = [];

        foreach (static::rowsOf(Tag::class, [], 'name') as $row) {
            $options[(string) $row['id']] = (string) ($row['name'] ?? '');
        }

        return $options;
    }

    /** @return array<string, string> */
    protected static function categoryOptions(): array
    {
        $options = CategoryTree::options(static::categories());
        $options[''] = 'Nessuna';

        return $options;
    }

    protected static function categoryTree(): array
    {
        $tree = CategoryTree::treeOptions(static::categories());

        // Qui non si sceglie un padre: la voce "Nessuna" non ha senso.
        return $tree['0']['child'] ?? [];
    }

    /** @return list<array<string, mixed>> */
    protected static function categories(): array
    {
        return static::rowsOf(Category::class, [], 'position');
    }

    protected static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
