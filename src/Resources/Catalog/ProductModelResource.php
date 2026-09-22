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
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\Link;
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
use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeValueResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\BrandResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\CategoryResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\PackageResource;
use Wonder\Plugin\Gestionale\Resources\Tax\TaxCategoryResource;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\CategoryTree;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Ean;
use Wonder\Plugin\Gestionale\Support\Catalog\Generator;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Packages;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Sku;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\StockHistory;
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
    public static string $docsPage = 'catalogo/catalogo-prodotti';

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
            FormField::key('brand_id')
                ->select(static::brandOptions())
                ->label('Marchio')
                ->quickCreate(BrandResource::class),
            FormField::key('tax_category_id')
                ->select(static::taxCategoryOptions())
                ->label('Tipo fiscale')
                ->required()
                ->quickCreate(TaxCategoryResource::class),
            FormField::key('sku')->text()->label('SKU'),
            FormField::key('unit')->select(self::UNITS)->value('pz')->label('Unità di misura')->required(),
            // Due domande diverse, e devono suonare diverse: la prima dice se
            // l'articolo è finito, la seconda se si vende anche online.
            FormField::key('visible')
                ->select(['true' => 'Pubblicato', 'false' => 'Bozza'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('visible_online')
                ->select(['true' => 'Sì', 'false' => 'No'])
                ->value('true')
                ->label('Si vende online')
                ->required(),
            FormField::key('short_description')->textarea()->label('Descrizione breve'),
            FormField::key('description')->textarea()->label('Descrizione'),
            FormField::key('categories')->checkTree(static::categoryTree(), true)->label('Categorie'),
            FormField::key('main_category')
                ->select(static::categoryOptions())
                ->label('Categoria principale')
                ->quickCreate(CategoryResource::class),
            FormField::key('tags')->selectSearch(static::tagOptions(), true)->label('Tag'),
            FormField::key('package_id')
                ->select(Packages::options())
                ->label('Imballaggio')
                ->quickCreate(PackageResource::class),
            // Una frase da leggere, non un dato da scrivere: la somma la fa il
            // pannello, e vederla qui è il modo di accorgersi che la tara
            // manca.
            FormField::key('shipping_weight')->text()->label('Spedito')->readonly(),
            FormField::key('weight')->number()->decimal(3)->label('Peso del prodotto (kg)'),
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

        array_push($fields, ...static::optionFields());

        // Anche in creazione: il core sincronizza i repeater con l'id appena
        // inserito, quindi una foto trascinata qui nasce insieme al prodotto.
        array_push($fields, ...static::imageFields($modelId ?? 0));

        // I due repeater esistono solo quando c'è più di una riga da mostrare.
        // Non è solo estetica: un campo che non viene stampato non viene
        // nemmeno postato, e il sync dei repeater cancella le righe che non
        // ritrova.
        if (static::variantCount($modelId ?? 0) > 1) {
            $fields[] = static::variantsField();
        }

        if (static::productCount($modelId ?? 0) > 1) {
            $fields[] = static::productsField($modelId ?? 0);
        }

        array_push($fields, ...static::priceFields($modelId));

        return $fields;
    }

    /**
     * Due colonne: a sinistra quello che si compone, a destra quello che si
     * decide.
     *
     * A sinistra il lavoro lungo — nome e prezzo, le versioni, le foto, le
     * descrizioni. A destra, stretta, le caselle corte che si guardano in un
     * colpo d'occhio: stato, codici, dove sta nel sito, peso e misure. Erano
     * dieci riquadri a piena larghezza, uno sotto l'altro.
     */
    /**
     * La stessa scheda in creazione e in modifica.
     *
     * Prima la creazione era una schermata a sé, con cinque campi: si
     * compilava, si salvava, si riapriva la scheda e si salvava ancora. Chi
     * carica un prodotto vuole vedere in una volta tutto quello che gli sarà
     * chiesto — e il core sincronizza i repeater con l'id appena inserito,
     * quindi foto e collegamenti nascono nello stesso salvataggio.
     *
     * Quello che non può esistere prima del primo salvataggio semplicemente
     * non compare: le versioni da prezzare, le foto dei singoli colori, la
     * giacenza.
     */
    public static function formLayoutSchema(): ?Form
    {
        $modelId = static::currentId() ?? 0;

        // `columns(12)` sul Form, non solo sui contenitori: il renderer calcola
        // la larghezza di un figlio sulle colonne del **padre**, e un Form senza
        // colonne ne ha una sola — qualunque span diventerebbe piena larghezza.
        return (new Form)->components([
            (new Container)->components(static::mainColumn($modelId))->columns(12)->columnSpan(8),
            (new Container)->components(static::sideColumn($modelId))->columns(12)->columnSpan(4),
        ])->columns(12);
    }

    /**
     * La colonna larga: quello che si compone.
     *
     * @return list<object>
     */
    protected static function mainColumn(int $modelId): array
    {
        $unaVersione = static::productCount($modelId) <= 1;

        $cards = [
            (new Card)->components([
                SectionTitle::make('Prodotto')
                    ->tooltip($unaVersione
                        ? 'Il prezzo di questo articolo. Il tipo fiscale decide l\'IVA che gli si applica.'
                        : 'Questa casella è un comando, non un riepilogo: scrivici un prezzo e al salvataggio va su tutte le versioni. Lasciala vuota e i prezzi delle righe restano come sono.')
                    ->columnSpan(12),
                static::getInput('name')->columnSpan(12),
                static::getInput('product_price')->columnSpan($unaVersione ? 3 : 4),
                static::getInput('product_sale_price')->columnSpan($unaVersione ? 3 : 4),
                static::getInput('tax_category_id')->columnSpan($unaVersione ? 3 : 4),
                // Con una versione sola la giacenza sta qui, accanto al
                // prezzo: è la scheda di quell'unico articolo, e la parola
                // "versione" non compare da nessuna parte.
                // In creazione non c'è ancora niente da rettificare: la
                // giacenza compare dal primo salvataggio in poi.
                ...($unaVersione && $modelId > 0 ? [
                    static::getInput('product_stock')->columnSpan(3),
                    RichText::make(static::adjustLink($modelId))->columnSpan(12),
                ] : []),
            ])->columns(12)->columnSpan(12),
        ];

        $opzioni = static::optionBlocks();

        if (!$unaVersione) {
            $versioni = [
                SectionTitle::make('Versioni in vendita')
                    ->tooltip('Spunta i valori e salva: nascono le righe che mancano, con il nome e lo SKU proposti. Togliere una spunta non cancella niente; per eliminare una versione si elimina la sua riga.')
                    ->columnSpan(12),
                ...$opzioni,
                static::getInput('products')->columnSpan(12),
            ];

            if (static::variantCount($modelId) > 1) {
                $versioni[] = static::getInput('variants')->columnSpan(12);
            }

            $cards[] = (new Card)->components($versioni)->columns(12)->columnSpan(12);
        }

        $foto = [
            SectionTitle::make('Foto e video')
                ->tooltip('Si caricano dove appartengono: quelle dell\'articolo valgono per tutto, quelle di un colore solo per lui. Le misure per il sito arrivano poco dopo il salvataggio; i video si salvano come sono.')
                ->columnSpan(12),
        ];

        foreach (array_keys(static::imageTargets($modelId)) as $variantId) {
            $foto[] = static::getInput('images_'.$variantId)->columnSpan(12);
        }

        $cards[] = (new Card)->components($foto)->columns(12)->columnSpan(12);

        $cards[] = (new Card)->components([
            SectionTitle::make('Descrizione')->columnSpan(12),
            static::getInput('short_description')->columnSpan(12),
            static::getInput('description')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        // Finché la versione è una sola le opzioni stanno in coda: chi vende un
        // cappello non deve incontrarle, chi ne ha bisogno le trova.
        if ($unaVersione && $opzioni !== []) {
            $cards[] = static::foldable(
                'Si vende in più versioni? (colori, taglie…)',
                $opzioni,
                'Spunta i colori e le taglie in cui vendi questo articolo e salva: le righe nascono da sole, con il nome e lo SKU proposti.'
            );
        }

        $attributi = [];

        foreach (Attributes::byLevel(static::attributes(), 'model') as $attribute) {
            $attributi[] = static::getInput('attribute_'.(int) $attribute['id'])->columnSpan(6);
        }

        if ($attributi !== []) {
            $cards[] = static::foldable(
                'Scheda tecnica',
                $attributi,
                'Quello che descrive l\'articolo e non fa nascere versioni: materiale, composizione, paese.'
            );
        }

        return $cards;
    }

    /**
     * La colonna stretta: quello che si decide.
     *
     * @return list<object>
     */
    /**
     * Se l'articolo si spedisce.
     *
     * Alla creazione non c'è ancora niente da leggere e la risposta è sì: il
     * campo nasce con `true`, e un riquadro che compare solo al secondo
     * salvataggio confonderebbe.
     */
    protected static function shipsFrom(int $modelId): bool
    {
        if ($modelId <= 0) {
            return true;
        }

        $rows = static::rowsOf(ProductModel::class, ['id' => $modelId]);
        $row = $rows[0] ?? null;

        return !is_array($row) || ($row['requires_shipping'] ?? 'true') !== 'false';
    }

    protected static function sideColumn(int $modelId): array
    {
        $codici = [
            SectionTitle::make('Codici')
                ->tooltip('Lo SKU è il codice di famiglia: da lì il pannello propone quello delle singole versioni.')
                ->columnSpan(12),
            static::getInput('sku')->columnSpan(12),
        ];

        if (static::productCount($modelId) <= 1) {
            $codici[] = static::getInput('product_ean')->columnSpan(12);
        }

        $cards = [
            (new Card)->components([
                SectionTitle::make('Pubblicazione')
                    ->tooltip('Una bozza non si vede da nessuna parte. Un articolo pubblicato che non si vende online resta in catalogo per il negozio e per i documenti.')
                    ->columnSpan(12),
                static::getInput('visible')->columnSpan(12),
                static::getInput('visible_online')->columnSpan(12),
                static::getInput('returnable')->columnSpan(6),
                static::getInput('requires_shipping')->columnSpan(6),
            ])->columns(12)->columnSpan(12),

            (new Card)->components($codici)->columns(12)->columnSpan(12),

            (new Card)->components([
                SectionTitle::make('Dove si trova')
                    ->tooltip('La categoria principale è quella che la vetrina userà per l\'indirizzo della pagina: se la scegli e non l\'hai spuntata, viene aggiunta da sé.')
                    ->columnSpan(12),
                static::getInput('brand_id')->columnSpan(12),
                static::getInput('main_category')->columnSpan(12),
                static::getInput('tags')->columnSpan(12),
                static::getInput('categories')->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ];

        // Un articolo che non si spedisce non ha niente da dire qui.
        if (static::shipsFrom($modelId)) {
            $cards[] = (new Card)->components([
                SectionTitle::make('Spedizione')
                    ->tooltip('Quello che parte è prodotto più scatola. Le misure del prodotto servono ai fuori misura, quelli che nella scatola scelta non ci stanno; una versione con misure sue le usa al posto di queste.')
                    ->columnSpan(12),
                static::getInput('package_id')->columnSpan(12),
                static::getInput('weight')->columnSpan(6),
                static::getInput('shipping_weight')->columnSpan(6),
                static::getInput('unit')->columnSpan(12),
                static::getInput('length')->columnSpan(4),
                static::getInput('width')->columnSpan(4),
                static::getInput('height')->columnSpan(4),
            ])->columns(12)->columnSpan(12);
        }

        return $cards;
    }

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
            ])
            // Appena creato si atterra sulla sua scheda: la creazione chiede
            // quattro cose e il resto si scrive lì, non ritrovando la riga in
            // un elenco. Serve `wonder-image/app` con redirectUrl($action,$id).
            ->redirect('store', 'edit');

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
        static::chosenAxes((array) $_POST);
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

    /**
     * EAN e SKU della versione unica, quando la scheda li mostra.
     *
     * Lo SKU è quello dell'articolo: con una versione sola è la stessa cosa, e
     * finisce scritto anche sulla riga da vendere — quindi dev'essere libero
     * anche fra quelle.
     */
    public static function assertSoleProduct(int $modelId, array $values): void
    {
        $ean = trim((string) ($values['product_ean'] ?? ''));
        $sku = trim((string) ($values['sku'] ?? ''));
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
            $chosen = static::chosenAxes($post);
            Generator::run($modelId, $chosen['variant'], $chosen['axes'], $fallbackSku);
            static::savePrices($modelId, $post, $fallbackSku);
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
            $values['product_ean'] = (string) ($product['ean'] ?? '');
        }

        // Con una versione sola la casella è il prezzo di quella versione, e
        // si comporta come ci si aspetta. Con più versioni **resta vuota**:
        // non è uno specchio, è un comando — "metti questo prezzo su tutte".
        //
        // Precompilarla sarebbe un disastro silenzioso: il riquadro in alto si
        // salva dopo la griglia, quindi un prezzo rimasto lì dentro
        // riscriverebbe la riga che hai appena corretto.
        $unaVersione = $product !== null;

        $values['product_price'] = $unaVersione ? (string) ($product['price'] ?? '') : '';
        $values['product_sale_price'] = $unaVersione ? (string) ($product['sale_price'] ?? '') : '';

        // Le righe del repeater le ha già caricate il core
        // (`hydrateRepeaterFormValues()` gira prima di qui): la colonna
        // calcolata si aggiunge sopra. Al salvataggio `Model::prepare()` butta
        // via la chiave, che non è una colonna di `gst_products`.
        if (is_array($values['products'] ?? null)) {
            $levels = Levels::forProducts(array_map(
                static fn ($row): int => (int) (is_array($row) ? ($row['id'] ?? 0) : 0),
                $values['products']
            ));

            foreach ($values['products'] as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $level = $levels[(int) ($row['id'] ?? 0)] ?? null;
                $values['products'][$index]['stock'] = static::plainNumber(
                    $level === null ? 0.0 : $level['quantity']
                );
            }
        }

        if ($unaVersione) {
            $values['product_stock'] = static::plainNumber(
                Levels::of((int) $product['id'])['quantity']
            );
        }

        foreach (static::usedOptionValues($modelId) as $key => $ids) {
            $values[$key] = $ids;
        }

        $values['shipping_weight'] = Packages::describe(
            (float) ($values['weight'] ?? 0),
            Packages::forModel((int) ($values['package_id'] ?? 0))
        );

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
            if (str_starts_with($inputName, 'images_')
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

        if (str_starts_with($inputName, 'images_')) {
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
    /**
     * Un articolo che ha una storia di magazzino non si elimina.
     *
     * I movimenti sono la storia del magazzino e restano; cancellare
     * l'articolo li renderebbe righe che parlano di qualcosa che non esiste
     * più — e il database lo impedisce comunque, con una pagina di errore al
     * posto di una spiegazione. Chi non vende più un articolo lo mette su
     * "Nascosto": l'elenco resta pulito e la storia pure.
     */
    public static function assertDeletable(int|string $id): void
    {
        foreach (static::products((int) $id) as $product) {
            if (StockHistory::hasMovements((int) $product['id'])) {
                // `refusal()` e non `make()`: chi cancella dall'elenco
                // intercetta `RuntimeException` (vedi `UserError`).
                throw UserError::refusal('product.has_movements');
            }
        }
    }

    public static function deleteRecord(int|string $id): object
    {
        $modelId = (int) $id;

        static::assertDeletable($modelId);

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
     * Le opzioni che fanno nascere versioni, con i loro valori.
     *
     * @return list<array<string, mixed>>
     */
    public static function optionAttributes(): array
    {
        $options = [];

        foreach (static::attributes() as $attribute) {
            if (!Attributes::createsVersions((string) ($attribute['level'] ?? ''))) {
                continue;
            }

            if (!Attributes::usesValues((string) ($attribute['type'] ?? ''))) {
                continue;
            }

            if (static::valuesOf((int) $attribute['id']) !== []) {
                $options[] = $attribute;
            }
        }

        return $options;
    }

    /** I valori di un'opzione: etichetta per id. @return array<string, string> */
    public static function valuesOf(int $attributeId): array
    {
        $values = [];

        foreach (static::attributeValues() as $value) {
            if ((int) ($value['attribute_id'] ?? 0) === $attributeId) {
                $values[(string) $value['id']] = (string) ($value['label'] ?? '');
            }
        }

        return $values;
    }

    /**
     * Un gruppo di caselle per ogni opzione: "Colore" con i suoi colori.
     *
     * Era un albero solo, con le opzioni come cartelle. Due problemi: le
     * opzioni non sono una gerarchia — sono elenchi piatti, uno per opzione — e
     * la lib nasconde i quadratini dell'albero (`.jstree-checkbox` sta a
     * `display:none`), quindi si spuntava cliccando righe che non sembravano
     * cliccabili.
     *
     * @return list<Input>
     */
    public static function optionFields(): array
    {
        $fields = [];

        foreach (static::optionAttributes() as $attribute) {
            $id = (int) $attribute['id'];
            $nome = mb_strtolower((string) ($attribute['name'] ?? ''));

            $fields[] = FormField::key('option_'.$id)
                ->checkbox()
                ->options(static::valuesOf($id))
                ->label((string) ($attribute['name'] ?? ''))
                // Il valore nuovo entra nell'elenco del negozio, non in questo
                // articolo: per questo il bottone dice "aggiungi colore" e non
                // "aggiungi colore a questo prodotto".
                ->quickCreate(
                    AttributeValueResource::class,
                    label: 'label',
                    layout: static fn (): Form => (new Form)->components([
                        (new Container)->components([
                            FormField::key('attribute_id')->hidden()->value((string) $id),
                            AttributeValueResource::getInput('label')->label('Nuovo '.$nome),
                        ])->columns(12)->columnSpan(12),
                    ])->columns(12),
                );
        }

        return $fields;
    }

    /**
     * Un'opzione per blocco: le spunte e, sotto, il collegamento che porta a
     * modificarla.
     *
     * La matita apre la scheda dell'attributo — dove si rinominano i valori,
     * si riordinano e si scelgono i colori. Non un modal: la creazione rapida
     * del core sa creare, non modificare.
     *
     * @return list<object>
     */
    protected static function optionBlocks(): array
    {
        $blocks = [];

        foreach (static::optionAttributes() as $attribute) {
            $id = (int) $attribute['id'];
            $nome = (string) ($attribute['name'] ?? '');

            $blocks[] = (new Container)->components([
                static::getInput('option_'.$id)->columnSpan(12),
                // `Link` non ha `columnSpan()`: nel layout dei form finisce
                // senza wrapper e prende la riga, che qui è quello che serve.
                Link::to(AttributeResource::editUrlFor($id), 'Modifica '.mb_strtolower($nome))
                    ->icon('bi-pencil')
                    ->muted(),
            ])->columns(12)->columnSpan(6);
        }

        return $blocks;
    }

    /**
     * Le spunte, divise in assi.
     *
     * `variant` è l'asse con pagina propria — al massimo uno, altrimenti non si
     * saprebbe quale valore sia la pagina. Gli altri finiscono in `axes`, un
     * elemento per opzione, e si moltiplicano fra loro.
     *
     * @param array<string, mixed> $post quello che arriva dal form
     * @return array{variant: list<array{id: int, label: string}>, axes: list<list<array{id: int, label: string}>>}
     */
    public static function chosenAxes(array $post): array
    {
        $variant = [];
        $variantAttributes = [];
        $axes = [];

        foreach (static::optionAttributes() as $attribute) {
            $id = (int) $attribute['id'];
            $valori = static::valuesOf($id);
            $scelti = [];

            foreach ((array) ($post['option_'.$id] ?? []) as $valueId) {
                $valueId = (string) $valueId;

                if (isset($valori[$valueId])) {
                    $scelti[] = ['id' => (int) $valueId, 'label' => $valori[$valueId]];
                }
            }

            if ($scelti === []) {
                continue;
            }

            if (($attribute['level'] ?? '') === 'variant') {
                $variantAttributes[$id] = true;
                $variant = array_merge($variant, $scelti);
                continue;
            }

            $axes[] = $scelti;
        }

        if (count($variantAttributes) > 1) {
            throw UserError::make('product.one_page_option');
        }

        return ['variant' => $variant, 'axes' => $axes];
    }

    /**
     * La galleria: una riga per foto, con la variante a cui appartiene.
     *
     * Un repeater dentro un repeater non esiste, e le varianti stanno già in
     * un repeater: la variante si sceglie da un select, e l'ereditarietà la
     * applica `ProductImages::for()` quando qualcuno legge.
     */
    /**
     * Dove può stare una foto: l'articolo, o uno dei suoi colori.
     *
     * @return array<int, string> id della variante (0 = tutto l'articolo) => titolo
     */
    protected static function imageTargets(int $modelId): array
    {
        $targets = [0 => 'Foto dell\'articolo'];

        if (static::variantCount($modelId) > 1) {
            foreach (static::variants($modelId) as $variant) {
                $targets[(int) $variant['id']] = 'Foto '.mb_strtolower((string) ($variant['name'] ?? ''));
            }
        }

        return $targets;
    }

    /**
     * Un'area di caricamento per ogni posto in cui una foto può stare.
     *
     * Era una riga per foto con il menù "Vale per": per caricare un'immagine
     * bisognava sapere cos'è una variante. Ora si carica dove appartiene, e il
     * colore non si sceglie da nessuna parte.
     *
     * Ogni area è un repeater sulla stessa tabella, ristretto alla sua fetta
     * con `condition()`: senza, salvando una fetta il core cancellerebbe le
     * foto delle altre, che non trova fra quelle postate.
     *
     * @return list<Input>
     */
    protected static function imageFields(int $modelId): array
    {
        $fields = [];

        foreach (static::imageTargets($modelId) as $variantId => $titolo) {
            $fields[] = FormField::key('images_'.$variantId)
                ->repeater([
                    RepeaterColumn::key('id')->hidden(),
                    RepeaterColumn::key('file')->fileDragDrop('gallery')->label('File')->columnSpan(5),
                    RepeaterColumn::key('alt')->text()->label('Descrizione')->columnSpan(4),
                    // Lo stato non è una scelta: è quello che è successo alla
                    // foto. Si legge e basta.
                    RepeaterColumn::key('status')->text()->readonly()->label('Stato')->columnSpan(2),
                ])
                ->relation(
                    RepeaterRelation::make(ProductImage::$table, 'product_model_id')
                        ->model(ProductImage::class)
                        ->positionKey('position')
                        // "Vale per tutto l'articolo" è `NULL`, non zero: la
                        // colonna ha una chiave esterna, e nessuna variante ha
                        // id zero.
                        ->condition(['product_variant_id' => $variantId > 0 ? $variantId : null])
                )
                ->nested()
                ->repeaterSortable()
                ->repeaterAddLabel('Aggiungi')
                ->repeaterDeleteTitle('Elimina')
                ->repeaterDeleteText('Confermi l\'eliminazione di questo file?')
                ->repeaterDeleteCancelLabel('Annulla')
                ->repeaterDeleteConfirmLabel('Elimina')
                ->repeaterDeleteConfirmClass('btn btn-danger')
                ->label($titolo);
        }

        return $fields;
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
                RepeaterColumn::key('sale_price')->number()->decimal(2)->label('Scontato')->columnSpan(1),
                // Sola lettura: la giacenza si cambia dalla rettifica, che
                // chiede la causale e lascia un movimento. Qui è un numero da
                // leggere mentre si sistemano prezzi e codici.
                RepeaterColumn::key('stock')->text()->readonly()->label('Giacenza')->columnSpan(1),
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

    /**
     * Prezzo e codice a barre.
     *
     * Il prezzo si scrive qui **sempre**, anche con dodici versioni: scriverlo
     * riga per riga è lungo, e quasi sempre costano tutte uguale. Quello che si
     * scrive qui va su tutte le righe; chi vuole differenziarne una la corregge
     * nella griglia, e da lì in poi la casella resta vuota perché non c'è più
     * un prezzo solo da mostrare.
     *
     * L'EAN invece è di una versione sola per definizione: con più versioni
     * sparisce, e si scrive nella sua riga.
     *
     * @return list<Input>
     */
    protected static function priceFields(?int $modelId): array
    {
        $fields = [
            FormField::key('product_price')->number()->decimal(2)->label('Prezzo'),
            FormField::key('product_sale_price')->number()->decimal(2)->label('Prezzo scontato'),
        ];

        if ($modelId !== null && $modelId > 0 && static::productCount($modelId) <= 1) {
            $fields[] = FormField::key('product_stock')
                ->text()
                ->readonly()
                ->label('Giacenza');
        }

        if ($modelId === null || static::productCount($modelId) <= 1) {
            $fields[] = FormField::key('product_ean')->text()->label('EAN');
        }

        return $fields;
    }

    /** Toglie dai valori tutto ciò che non è una colonna del modello. */
    protected static function withoutExtras(array $values): array
    {
        unset(
            $values['shipping_weight'],
            $values['categories'],
            $values['main_category'],
            $values['tags'],
            $values['product_ean'],
            $values['product_price'],
            $values['product_sale_price'],
            $values['product_stock'],
        );

        foreach (array_keys($values) as $key) {
            $key = (string) $key;

            if (str_starts_with($key, 'attribute_') || str_starts_with($key, 'option_')) {
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

    /**
     * Prezzi, codice e codice a barre scritti dal riquadro in alto.
     *
     * Il prezzo vale per **tutte** le versioni: con dodici righe scriverlo
     * dodici volte è una scortesia, e quasi sempre costano uguale. La casella
     * vuota non tocca niente — è l'unico modo di avere prezzi diversi senza che
     * un salvataggio distratto li riallinei tutti.
     *
     * SKU ed EAN invece riguardano solo la versione unica: quando sono più di
     * una, ognuna ha i suoi nella griglia.
     */
    protected static function savePrices(int $modelId, array $post, string $fallbackSku = ''): void
    {
        $prezzo = Numbers::fromForm($post['product_price'] ?? null);
        $scontato = Numbers::fromForm($post['product_sale_price'] ?? null);
        $prodotti = static::products($modelId);

        if ($prodotti === []) {
            return;
        }

        if (count($prodotti) === 1) {
            $product = $prodotti[0];
            // La scheda non chiede lo SKU della versione quando è una sola: lo
            // prende da quello dell'articolo, che è la stessa cosa. Se
            // l'articolo non ne ha, resta quello che la versione aveva già.
            $sku = trim((string) ($post['sku'] ?? '')) ?: $fallbackSku;

            Product::update([
                'sku' => $sku !== '' ? $sku : (string) ($product['sku'] ?? ''),
                'ean' => trim((string) ($post['product_ean'] ?? '')),
                // I decimali arrivano con la virgola: MySQL non li accetta.
                'price' => $prezzo,
                'sale_price' => $scontato,
            ], (int) $product['id']);

            return;
        }

        $values = [];

        if ($prezzo !== null) {
            $values['price'] = $prezzo;
        }

        if ($scontato !== null) {
            $values['sale_price'] = $scontato;
        }

        if ($values === []) {
            return;
        }

        foreach ($prodotti as $product) {
            Product::update($values, (int) $product['id']);
        }
    }

    /**
     * I valori già in uso da questo articolo, per campo del form.
     *
     * Senza, chi apre la scheda di una maglietta blu e rossa trova le caselle
     * dei colori tutte vuote: sembra che non abbia colori, e invece ne ha due.
     *
     * Gli id tornano **numeri**, non stringhe: il gruppo di caselle confronta
     * con `in_array(..., true)` e le chiavi numeriche di un array PHP sono
     * numeri, quindi una stringa non spunterebbe niente.
     *
     * @return array<string, list<int>>
     */
    public static function usedOptionValues(int $modelId): array
    {
        $perAttributo = [];

        foreach (['variant' => static::variants($modelId), 'product' => static::products($modelId)] as $level => $rows) {
            foreach ($rows as $row) {
                foreach (ProductAttributes::read($level, (int) $row['id']) as $attributeId => $link) {
                    $valueId = (int) ($link['attribute_value_id'] ?? 0);

                    if ($valueId > 0) {
                        $perAttributo['option_'.(int) $attributeId][$valueId] = $valueId;
                    }
                }
            }
        }

        return array_map('array_values', $perAttributo);
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
    /**
     * I tipi fiscali. Niente "Predefinito": l'IVA si sceglie.
     *
     * Lasciarla indovinare al sistema vuol dire accorgersene in fattura.
     */
    protected static function taxCategoryOptions(): array
    {
        $options = ['' => 'Scegli…'];

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


    /** I pezzi interi si scrivono interi: "3", non "3,000". */
    protected static function plainNumber(float $value): string
    {
        $decimals = round($value, 3) === round($value, 0) ? 0 : 3;

        return number_format($value, $decimals, ',', '');
    }

    /** Il link alla rettifica della versione unica; vuoto se le versioni sono tante. */
    protected static function adjustLink(int $modelId): string
    {
        $product = static::soleProduct($modelId);

        if ($product === null) {
            return '';
        }

        return '<a href="'.static::escape(
            StockAdjustmentResource::urlFor((int) $product['id'])
        ).'">Rettifica la giacenza</a>';
    }
}
