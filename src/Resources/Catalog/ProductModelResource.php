<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use Throwable;
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
use Wonder\Plugin\Gestionale\Support\Catalog\VersionName;
use Wonder\Plugin\Gestionale\Support\Catalog\Sku;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\App\LegacyGlobals;
use Wonder\App\Support\Repeater;
use Wonder\App\Table;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\Stocktake;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
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
            'versions' => 'Opzioni',
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

        // La griglia c'è sempre, anche in creazione: aggiungere e modificare
        // devono essere la stessa schermata, con gli stessi campi. Le righe
        // che nascono dalle spunte non passano dal sync del core — le tiene
        // fuori `prepareRepeaterRows()` — perché il colore a cui appartengono
        // non esiste ancora quando il sync gira.
        $fields[] = static::productsField($modelId ?? 0);

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
            // Le opzioni in vendita prendono la pagina intera, sotto le due
            // colonne: una griglia con sette caselle per riga dentro due terzi
            // di schermo sono sette caselle da sessanta pixel.
            ...static::optionsCard($modelId),
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
                        : 'Questa casella è un comando, non un riepilogo: scrivici un prezzo e al salvataggio va su tutte le opzioni in vendita. Lasciala vuota e i prezzi delle righe restano come sono.')
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

        $attributi = [];

        foreach (Attributes::byLevel(static::attributes(), 'model') as $attribute) {
            $attributi[] = static::getInput('attribute_'.(int) $attribute['id'])->columnSpan(6);
        }

        if ($attributi !== []) {
            $cards[] = static::foldable(
                'Scheda tecnica',
                $attributi,
                'Quello che descrive l\'articolo e non fa nascere opzioni in vendita: materiale, composizione, paese.'
            );
        }

        return $cards;
    }

    /**
     * Il riquadro delle opzioni in vendita: a piena larghezza, in fondo.
     *
     * Dentro c'è tutto quello che riguarda il "in quante versioni lo vendo":
     * il selettore degli attributi, i valori da spuntare e la griglia. Sta
     * sotto le due colonne e non dentro quella larga perché la griglia ha
     * sette caselle per riga, e in due terzi di schermo diventano sette
     * caselle da sessanta pixel — è il disallineamento che si vedeva.
     *
     * @return list<object>
     */
    protected static function optionsCard(int $modelId): array
    {
        $blocchi = static::optionBlocks();

        if ($blocchi === []) {
            return [];
        }

        return [
            (new Card)->components([
                SectionTitle::make('Opzioni in vendita')
                    ->tooltip('Scegli un attributo — colore, taglia, gusto — e spunta i valori: le righe compaiono qui sotto e nascono al salvataggio, con codice, prezzo, giacenza e foto. Togliere una spunta non cancella niente che esista già.')
                    ->columnSpan(12),
                static::optionsPicker()->columnSpan(12),
                ...$blocchi,
                static::getInput('products')->columnSpan(12),
                static::optionsGridScript($modelId)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ];
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
                ->tooltip('Lo SKU è il codice di famiglia: da lì il pannello propone quello delle singole opzioni.')
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
                    ->tooltip('Quello che parte è prodotto più scatola. Le misure del prodotto servono ai fuori misura, quelli che nella scatola scelta non ci stanno; un\'opzione con misure sue le usa al posto di queste.')
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
                'label' => 'Dettagli delle opzioni',
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
        static::assertStockWritable();

        return static::withoutExtras($values);
    }

    /**
     * La giacenza scritta nella griglia dev'essere un numero di pezzi.
     *
     * Il rifiuto si calcola qui, prima che il salvataggio cominci: dopo
     * l'insert non c'è nessuna rete — il sync e `afterUpdate` girano fuori da
     * qualunque try — e un errore diventerebbe una pagina di guasto su un
     * articolo già scritto a metà.
     */
    public static function assertStockWritable(): void
    {
        foreach (static::postedRows((array) $_POST) as $riga) {
            $quantita = Stocktake::quantity($riga['stock'] ?? null);

            if ($quantita !== null && $quantita < 0) {
                throw UserError::make('product.stock_negative');
            }
        }
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
        static::saveExtras($id, (array) $_POST, $sku, (array) $_FILES);
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        static::saveExtras((int) $id, (array) $_POST, '', (array) $_FILES);
    }

    /**
     * Righe legate al modello: categorie, tag, attributi e prodotto unico.
     *
     * `$fallbackSku` è lo SKU del modello appena creato: se la casella del
     * prodotto è vuota, il prodotto tiene quello, invece di perdere il codice
     * che il modello gli ha appena dato.
     */
    public static function saveExtras(
        int $modelId,
        array $post,
        string $fallbackSku = '',
        array $files = []
    ): void {
        if ($modelId <= 0) {
            return;
        }

        Transaction::run(static function () use ($modelId, $post, $fallbackSku, $files): void {
            static::saveCategories($modelId, $post);
            static::saveTags($modelId, $post);
            static::saveModelAttributes($modelId, $post);
            $chosen = static::chosenAxes($post);
            $righe = static::postedRows($post);
            // Le righe senza id sono le combinazioni spuntate che ancora non
            // esistono: la loro chiave è quella della combinazione.
            $scritte = static::newRows($righe);
            $nate = Generator::run($modelId, $chosen['variant'], $chosen['axes'], $fallbackSku, $scritte);

            // Quello che è stato scritto nella griglia, per riga appena nata:
            // il riquadro in alto non deve riscriverlo.
            $appena = [];

            foreach ($nate as $chiave => $riga) {
                $appena[$riga['product_id']] = is_array($scritte[$chiave] ?? null) ? $scritte[$chiave] : [];
            }

            static::savePrices($modelId, $post, $fallbackSku, $appena);
            static::saveNewVersions($modelId, $nate, $scritte, $files);
            static::saveRowExtras($modelId, $righe, $files);
            // Il nome non lo scrive chi compila: nasce dagli attributi, e si
            // rimette in riga a ogni salvataggio.
            static::realignNames($modelId);
        });
    }

    /**
     * Le righe della griglia così come sono arrivate.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function postedRows(array $post): array
    {
        $righe = [];

        foreach ((array) ($post['products'] ?? []) as $chiave => $riga) {
            if (is_array($riga)) {
                $righe[(string) $chiave] = $riga;
            }
        }

        return $righe;
    }

    /**
     * Solo le righe che ancora non esistono, per chiave di combinazione.
     *
     * @param array<string, array<string, mixed>> $righe
     * @return array<string, array<string, mixed>>
     */
    protected static function newRows(array $righe): array
    {
        $nuove = [];

        foreach ($righe as $chiave => $riga) {
            if (trim((string) ($riga['id'] ?? '')) === '') {
                $nuove[$chiave] = $riga;
            }
        }

        return $nuove;
    }

    /**
     * Le righe che il core deve sincronizzare: quelle che esistono già.
     *
     * Una riga nata da una spunta non ha ancora il suo colore — lo crea
     * `Generator::run()` dopo, in `afterStore`/`afterUpdate` — e il sync gira
     * prima: le passerebbe al database con una variante che non c'è, e il
     * database rifiuterebbe lasciando l'articolo a metà.
     */
    public static function prepareRepeaterRows(
        string $inputName,
        array $rows,
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if ($inputName !== 'products') {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn ($row): bool => is_array($row) && trim((string) ($row['id'] ?? '')) !== ''
        ));
    }

    /**
     * Giacenza e foto delle righe che esistono già.
     *
     * La giacenza non è una colonna di `gst_products` e il salvataggio la
     * butta via prima di arrivare qui: si legge da quello che è stato postato.
     * Si scrive **quanti pezzi ci sono**, non di quanto cambiarli, e il
     * pannello registra il movimento della differenza — una casella riscritta
     * con lo stesso numero non muove niente.
     *
     * @param array<string, array<string, mixed>> $righe
     * @param array<string, mixed> $files
     */
    protected static function saveRowExtras(int $modelId, array $righe, array $files): void
    {
        $caricate = Repeater::filesFromRequest('products', $files);
        $scritte = [];
        $foto = [];

        foreach ($righe as $chiave => $riga) {
            $productId = (int) ($riga['id'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            $quantita = Stocktake::quantity($riga['stock'] ?? null);

            if ($quantita !== null) {
                $scritte[$productId] = $quantita;
            }

            $foto[$productId] = $caricate[$chiave]['photo'] ?? null;
        }

        $attuali = [];

        foreach (Levels::forProducts(array_keys($scritte)) as $productId => $livello) {
            $attuali[(int) $productId] = (float) ($livello['quantity'] ?? 0);
        }

        foreach (Stocktake::changes($attuali, $scritte) as $productId => $cambio) {
            Stock::apply([
                'product_id' => $productId,
                'quantity' => $cambio['delta'],
                'reason' => Reasons::DEFAULT,
                'note' => 'Rettifica dalla scheda dell\'articolo',
            ]);
        }

        foreach ($foto as $productId => $file) {
            static::saveOptionImage($modelId, $productId, $file);
        }
    }

    /**
     * Quello che una versione appena nata si porta dietro: la giacenza di
     * partenza e la sua foto.
     *
     * Prezzo, nome e codici li scrive il generatore mentre crea la riga; qui
     * restano le due cose che vivono altrove — un movimento di magazzino e un
     * file su disco — e che senza una riga a cui agganciarsi non si potevano
     * scrivere prima.
     *
     * @param array<string, array{product_id: int, variant_id: int, priced: bool}> $nate
     * @param array<string, mixed> $scritte
     * @param array<string, mixed> $files
     */
    protected static function saveNewVersions(
        int $modelId,
        array $nate,
        array $scritte,
        array $files
    ): void {
        if ($nate === []) {
            return;
        }

        $caricate = Repeater::filesFromRequest('products', $files);

        foreach ($nate as $chiave => $riga) {
            $scritto = is_array($scritte[$chiave] ?? null) ? $scritte[$chiave] : [];
            $quantita = Stocktake::quantity($scritto['stock'] ?? null);

            if ($quantita !== null && $quantita > 0) {
                // La giacenza non si scrive: si carica. Il movimento resta, con
                // la sua causale, come per ogni altro pezzo che entra.
                Stock::apply([
                    'product_id' => $riga['product_id'],
                    'quantity' => $quantita,
                    'reason' => 'initial_stock',
                    'note' => 'Giacenza iniziale, dalla scheda dell\'articolo',
                ]);
            }

            static::saveOptionImage(
                $modelId,
                $riga['product_id'],
                $caricate[$chiave]['photo'] ?? null
            );
        }
    }

    /**
     * La foto di una riga della griglia.
     *
     * Sta su quella singola opzione in vendita: «Blu / S» può avere la sua,
     * diversa da «Blu / M». Chi guarda un colore continua a vedere le foto del
     * colore — l'eredità la fa `ProductImages::for()` — e questa vale solo
     * dove è stata caricata.
     */
    protected static function saveOptionImage(int $modelId, int $productId, mixed $file): void
    {
        if (!is_array($file) || !isset($file['name'])) {
            return;
        }

        $nomi = (array) $file['name'];
        $errori = (array) ($file['error'] ?? []);

        // `UPLOAD_ERR_NO_FILE`: la casella è rimasta vuota, e va benissimo.
        if (trim((string) ($nomi[0] ?? '')) === '' || (int) ($errori[0] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return;
        }

        if ($productId <= 0) {
            return;
        }

        $prodotto = static::rowsOf(Product::class, ['id' => $productId])[0] ?? null;
        $variantId = (int) (is_array($prodotto) ? ($prodotto['product_variant_id'] ?? 0) : 0);

        $riga = [
            'product_model_id' => $modelId,
            'product_variant_id' => $variantId > 0 ? $variantId : null,
            'product_id' => $productId,
            'file' => $file,
            'alt' => '',
            'position' => count(static::rowsOf(ProductImage::class, ['product_model_id' => $modelId])) + 1,
            // Le misure per il sito le farà la coda, come per ogni altra foto.
            'status' => 'pending',
            'attempts' => 0,
        ];

        // `Model::create()` non sa caricare niente: scriverebbe nel database la
        // busta di `$_FILES` invece del file. Chi sposta il file su disco è la
        // preparazione del core — la stessa che usano i repeater — e vuole
        // sapere in quale cartella scrivere, che è quella del Model.
        $precedente = LegacyGlobals::get('NAME');

        LegacyGlobals::set('NAME', (object) [
            'table' => ProductImage::$table,
            'folder' => trim((string) ProductImage::$folder, '/'),
            'schema' => ProductImage::$table,
        ]);

        try {
            $pronta = Table::key(ProductImage::$table)->prepare($riga);
        } finally {
            LegacyGlobals::set('NAME', $precedente);
        }

        sqlInsert(ProductImage::$table, $pronta);
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
            $nomi = static::optionLabels($modelId);
            $foto = static::optionImages($modelId);

            $levels = Levels::forProducts(array_map(
                static fn ($row): int => (int) (is_array($row) ? ($row['id'] ?? 0) : 0),
                $values['products']
            ));

            foreach ($values['products'] as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $productId = (int) ($row['id'] ?? 0);
                $level = $levels[$productId] ?? null;

                $values['products'][$index]['stock'] = static::plainNumber(
                    $level === null ? 0.0 : $level['quantity']
                );
                // Il colore raggruppa; quello che resta del nome si legge.
                $values['products'][$index]['variant'] = $nomi[$productId]['variant'] ?? '';
                $values['products'][$index]['option'] = $nomi[$productId]['rest'] !== ''
                    ? $nomi[$productId]['rest']
                    : ($nomi[$productId]['variant'] ?? '');
                $values['products'][$index]['photo'] = $foto[$productId] ?? '';
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
     * Il selettore delle opzioni: prima si sceglie quale, poi compaiono i
     * valori.
     *
     * Prima stavano tutte aperte, una accanto all'altra: chi vende cappelli si
     * trovava davanti colori, taglie e gusti senza averne chiesto nessuno, e
     * la pagina diventava lunga il doppio. Ora la pagina ne mostra zero e
     * chiede quale serve — come fa Shopify.
     *
     * Le opzioni che questo articolo usa già partono aperte: nasconderle
     * direbbe che non ci sono, e invece ci sono.
     */
    protected static function optionsPicker(): object
    {
        $voci = '';

        foreach (static::optionAttributes() as $attribute) {
            $voci .= '<option value="'.(int) $attribute['id'].'">'
                .static::escape((string) ($attribute['name'] ?? '')).'</option>';
        }

        return RichText::make(<<<HTML
<div class="wi-option-picker w-100">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <select class="form-select form-select-sm w-auto wi-option-choose" aria-label="Aggiungi un attributo">
            <option value="">Aggiungi un attributo…</option>
            {$voci}
        </select>
        <span class="small text-body-secondary wi-option-hint">Colore, taglia, gusto: scegline uno e i suoi valori compaiono qui sotto.</span>
        <span class="small text-body-secondary wi-option-none d-none">Le opzioni sono tutte qui sotto.</span>
    </div>
</div>
<script>
    window.wiOptionPicker = window.wiOptionPicker || (function () {
        // Tre attributi fanno già decine di combinazioni: il quarto non si
        // aggiunge. È un muro del selettore, non del salvataggio: un articolo
        // che ne ha di più resta salvabile, o non si potrebbe più correggergli
        // nemmeno il prezzo.
        var MASSIMO = 3;

        function accesi() {
            return document.querySelectorAll('[data-wi-option][data-wi-option-on="true"]').length;
        }

        // Gli attributi stanno sul nodo interno del contenitore: il blocco da
        // mostrare o nascondere — quello con la sua larghezza — è il genitore.
        function blocco(nodo) {
            return nodo.parentElement || nodo;
        }

        function caselle(nodo) {
            return Array.prototype.slice.call(nodo.querySelectorAll('input[type="checkbox"]'));
        }

        function inUso(nodo) {
            return caselle(nodo).some(function (casella) { return casella.checked; });
        }

        function mostra(nodo, acceso) {
            blocco(nodo).classList.toggle('d-none', !acceso);
            nodo.setAttribute('data-wi-option-on', acceso ? 'true' : 'false');
        }

        function nome(nodo) {
            return (nodo.getAttribute('data-wi-option-name') || 'attributo').toLowerCase();
        }

        function aggiornaSelettore() {
            var picker = document.querySelector('.wi-option-picker');
            if (!picker) return;

            var scelta = picker.querySelector('.wi-option-choose');
            var restano = false;
            var pieno = accesi() >= MASSIMO;

            Array.prototype.slice.call(scelta.options).forEach(function (voce) {
                if (voce.value === '') return;

                var nodo = document.querySelector('[data-wi-option="' + voce.value + '"]');
                var acceso = !!nodo && nodo.getAttribute('data-wi-option-on') === 'true';

                voce.hidden = acceso;
                voce.disabled = acceso;

                if (!acceso) restano = true;
            });

            scelta.value = '';
            scelta.classList.toggle('d-none', !restano || pieno);
            picker.querySelector('.wi-option-hint').classList.toggle('d-none', !restano || pieno);
            picker.querySelector('.wi-option-none').classList.toggle('d-none', restano && !pieno);
            picker.querySelector('.wi-option-none').textContent = pieno
                ? 'Tre attributi sono il massimo: per aggiungerne un altro togline uno.'
                : 'Gli attributi sono tutti qui sotto.';
        }

        // «Togli» solo sulle opzioni che l'articolo non sta usando: nascondere
        // un colore che ha già le sue versioni direbbe una bugia.
        function bottoneTogli(nodo) {
            if (nodo.querySelector('.wi-option-remove')) return;

            var riga = document.createElement('div');
            riga.className = 'col-12';

            var bottone = document.createElement('button');
            bottone.type = 'button';
            bottone.className = 'btn btn-sm btn-link text-body-secondary p-0 wi-option-remove';
            bottone.textContent = 'Togli ' + nome(nodo);
            bottone.addEventListener('click', function () {
                caselle(nodo).forEach(function (casella) { casella.checked = false; });
                mostra(nodo, false);
                aggiornaSelettore();
                if (typeof window.wiNewVersions === 'function') window.wiNewVersions();
            });

            riga.appendChild(bottone);
            nodo.appendChild(riga);
        }

        function avvia() {
            var picker = document.querySelector('.wi-option-picker');
            if (!picker || picker.getAttribute('data-wi-ready') === 'true') return;
            picker.setAttribute('data-wi-ready', 'true');

            document.querySelectorAll('[data-wi-option]').forEach(function (nodo) {
                var acceso = inUso(nodo);
                mostra(nodo, acceso);

                if (!acceso) bottoneTogli(nodo);
            });

            picker.querySelector('.wi-option-choose').addEventListener('change', function () {
                var nodo = this.value === ''
                    ? null
                    : document.querySelector('[data-wi-option="' + this.value + '"]');

                if (nodo && accesi() < MASSIMO) mostra(nodo, true);

                aggiornaSelettore();
            });

            aggiornaSelettore();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', avvia);
        } else {
            avvia();
        }

        return avvia;
    })();
</script>
HTML);
    }

    /**
     * Un'opzione per blocco: solo le spunte.
     *
     * `data-wi-option` è la maniglia del selettore qui sotto, che mostra un
     * blocco solo quando l'opzione viene scelta. Gli attributi finiscono sul
     * nodo interno del contenitore: il blocco da nascondere è il suo genitore.
     *
     * @return list<object>
     */
    protected static function optionBlocks(): array
    {
        $blocks = [];

        foreach (static::optionAttributes() as $attribute) {
            $id = (int) $attribute['id'];

            $blocks[] = (new Container)->components([
                static::getInput('option_'.$id)->columnSpan(12),
            ])
                ->attr('data-wi-option', (string) $id)
                ->attr('data-wi-option-name', (string) ($attribute['name'] ?? ''))
                ->columns(12)
                ->columnSpan(6);
        }

        return $blocks;
    }

    /**
     * Il codice che tiene insieme le spunte e la griglia.
     *
     * Spuntare Blu e M non dice ancora niente al database: la riga esiste solo
     * dopo il salvataggio. Ma nella schermata c'è già, dentro la stessa
     * griglia delle opzioni che esistono, con le stesse caselle — codice,
     * prezzo, giacenza, foto — e al salvataggio nasce con i valori scritti.
     *
     * Le righe le aggiunge il repeater del core (`wiRepeaterAddRow`), con la
     * chiave della combinazione: il server ritrova quello che è stato scritto
     * senza doversi fidare dell'ordine.
     *
     * Il browser propone, il server dispone: `Generator::run()` ricalcola il
     * piano delle combinazioni e prende da qui solo quelle che tornano.
     */
    protected static function optionsGridScript(int $modelId): object
    {
        try {
            $chiavi = Generator::existingClientKeys($modelId);
        } catch (Throwable) {
            // Senza database (i test degli schemi) non c'è niente di esistente
            // da saltare: la griglia le proporrà tutte.
            $chiavi = [];
        }

        $esistenti = static::escape(json_encode($chiavi, JSON_THROW_ON_ERROR));
        $asseVariante = static::variantAttributeId();

        return RichText::make(<<<HTML
<div class="wi-options-grid" data-wi-existing="{$esistenti}" data-wi-variant-attribute="{$asseVariante}"></div>
<script>
    window.wiOptionsGrid = window.wiOptionsGrid || (function () {
        function radice() {
            return document.querySelector('.wi-options-grid');
        }

        function griglia() {
            return document.querySelector('[data-wi-repeater="products"]');
        }

        function parte(label) {
            var pulito = String(label || '').trim()
                .replace(/[àÀ]/g, 'a').replace(/[èéÈÉ]/g, 'e')
                .replace(/[ìÌ]/g, 'i').replace(/[òÒ]/g, 'o').replace(/[ùÙ]/g, 'u');

            return pulito.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
        }

        function skuProposto(base, combo) {
            base = String(base || '').trim();
            if (base === '') return '';

            var pezzi = [base];
            combo.forEach(function (v) { var p = parte(v.label); if (p !== '') pezzi.push(p); });

            return pezzi.join('-');
        }

        // Un asse per attributo spuntato, nell'ordine in cui stanno in pagina.
        function assiSpuntati() {
            var gruppi = new Map();

            document.querySelectorAll('input[type="checkbox"][name^="option_"]').forEach(function (casella) {
                if (!casella.checked) return;

                var nome = casella.getAttribute('name');
                if (!gruppi.has(nome)) gruppi.set(nome, []);

                var etichetta = casella.closest('label') || casella.parentElement;
                gruppi.get(nome).push({
                    id: String(casella.value),
                    label: etichetta ? etichetta.textContent.trim() : '',
                    attribute: (nome.match(/option_(\d+)/) || [])[1] || ''
                });
            });

            return Array.from(gruppi.values());
        }

        function cartesiano(assi) {
            if (!assi.length) return [];
            var righe = [[]];

            assi.forEach(function (asse) {
                var prossime = [];
                righe.forEach(function (riga) { asse.forEach(function (v) { prossime.push(riga.concat([v])); }); });
                righe = prossime;
            });

            return righe;
        }

        // La stessa chiave che calcola il server: tutti gli id dei valori, in
        // ordine, uniti da un trattino.
        function chiave(combo) {
            return combo.map(function (v) { return parseInt(v.id, 10); })
                .sort(function (a, b) { return a - b; })
                .join('-');
        }

        function campo(riga, colonna) {
            return riga.querySelector('[name\$="[' + colonna + ']"]');
        }

        function scrivi(riga, colonna, valore) {
            var elemento = campo(riga, colonna);
            if (elemento) elemento.value = valore;
        }

        function aggiorna() {
            var root = radice();
            var box = griglia();
            if (!root || !box) return;

            var esistenti = [];
            try { esistenti = JSON.parse(root.getAttribute('data-wi-existing') || '[]'); } catch (e) {}

            var asseVariante = String(root.getAttribute('data-wi-variant-attribute') || '');
            var righe = document.getElementById(box.id + '-rows');
            var templateId = box.id + '-template';
            var campoSku = document.querySelector('#resource-layout-form [name="sku"]');
            var base = campoSku ? campoSku.value : '';
            var volute = {};

            cartesiano(assiSpuntati()).forEach(function (combo) {
                var k = chiave(combo);
                if (k === '' || esistenti.indexOf(k) !== -1) return;
                volute[k] = combo;
            });

            // Le righe che il browser aveva proposto e che ora non servono più:
            // quelle che esistono davvero non si toccano.
            Array.prototype.slice.call(righe.querySelectorAll('.wi-repeater-row[data-wi-new="true"]')).forEach(function (riga) {
                if (!Object.prototype.hasOwnProperty.call(volute, riga.getAttribute('data-wi-row-key'))) {
                    riga.remove();
                }
            });

            Object.keys(volute).forEach(function (k) {
                var combo = volute[k];
                var gia = righe.querySelector('.wi-repeater-row[data-wi-row-key="' + k + '"]');

                if (gia) {
                    var sku = campo(gia, 'sku');
                    if (sku && sku.dataset.wiTouched !== 'true') sku.value = skuProposto(base, combo);
                    return;
                }

                var riga = window.wiRepeaterAddRow(righe.id, templateId, k);
                if (!riga) return;

                riga.setAttribute('data-wi-new', 'true');

                var colore = '';
                var resto = [];

                combo.forEach(function (v) {
                    if (asseVariante !== '' && v.attribute === asseVariante) {
                        colore = v.label;
                    } else {
                        resto.push(v.label);
                    }
                });

                scrivi(riga, 'variant', colore);
                scrivi(riga, 'option', resto.join(' / ') || colore);
                scrivi(riga, 'sku', skuProposto(base, combo));

                var sku = campo(riga, 'sku');
                if (sku) sku.addEventListener('input', function () { sku.dataset.wiTouched = 'true'; });
            });

            mostra(box, righe);
            raggruppa(box, righe);
        }

        // La griglia compare quando c'è qualcosa da vedere: con una riga sola e
        // nessuna spunta, il prezzo e la giacenza stanno in alto e una griglia
        // che dice la stessa cosa confonderebbe.
        function mostra(box, righe) {
            var quante = righe.querySelectorAll('.wi-repeater-row').length;
            var spuntate = document.querySelectorAll('input[type="checkbox"][name^="option_"]:checked').length;
            var colonna = box.closest('[class*="col-"]') || box;

            colonna.classList.toggle('d-none', quante <= 1 && spuntate === 0);
        }

        // Un articolo senza colore non ha niente su cui raggruppare: la griglia
        // resta piatta invece di mostrare una testata «Senza scelta».
        function raggruppa(box, righe) {
            if (typeof window.wiRepeaterGroupApply !== 'function') return;
            if (righe.getAttribute('data-wi-group-fixed') !== 'true') return;

            var conColore = Array.prototype.slice.call(righe.querySelectorAll('[name\$="[variant]"]'))
                .some(function (campo) { return String(campo.value || '').trim() !== ''; });

            // Il modello delle testate, non quello delle righe: sono due
            // template diversi, e sbagliarli vuol dire nessun gruppo.
            window.wiRepeaterGroupApply(righe.id, box.id + '-group-template', conColore ? 'variant' : '');
        }

        document.addEventListener('change', function (ev) {
            var nome = ev.target && ev.target.name ? ev.target.name : '';
            if (nome.indexOf('option_') === 0 || nome === 'sku') aggiorna();
        });

        document.addEventListener('input', function (ev) {
            if (ev.target && ev.target.name === 'sku') aggiorna();
        });

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', aggiorna);
        } else {
            aggiorna();
        }

        return aggiorna;
    })();
</script>
HTML);
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
                        //
                        // `product_id` nullo non è un dettaglio: la condizione
                        // di un repeater guida **anche la cancellazione**, e
                        // senza questa riga il primo salvataggio porterebbe via
                        // le foto delle singole opzioni, che quest'area non
                        // mostra e quindi non ripostà.
                        ->condition([
                            'product_variant_id' => $variantId > 0 ? $variantId : null,
                            'product_id' => null,
                        ])
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

    /**
     * La griglia di quello che si vende: una sola, uguale in aggiunta e in
     * modifica.
     *
     * Dentro ci sono insieme le opzioni che esistono — che il core sincronizza
     * da sé — e quelle che stanno per nascere dalle spunte, aggiunte dal
     * browser con la chiave della loro combinazione. Quelle senza id non
     * arrivano al sync (`prepareRepeaterRows()`): il colore a cui appartengono
     * non esiste ancora quando il sync gira, e il database rifiuterebbe.
     *
     * Le colonne stanno dentro undici: la dodicesima è quella dei bottoni, e
     * quello che sfora va a capo. È il conto che mancava.
     */
    protected static function productsField(int $modelId = 0): Input
    {
        $campo = FormField::key('products')
            ->repeater([
                RepeaterColumn::key('id')->hidden(),
                // Il colore della riga. Non si scrive: serve a raggruppare, e
                // in chiaro lo dice la testata del gruppo, una volta sola
                // invece che su ogni riga.
                RepeaterColumn::key('variant')->hidden()->label(static::pageOptionName()),
                // Quello che resta del nome una volta detto il colore: "S",
                // oppure "S / Gomma" con un terzo attributo. Non è la colonna
                // `name` del database — quella la scrive il sistema — perché
                // una casella di sola lettura viene postata lo stesso, e
                // scriverebbe "S" al posto di "Blu / S".
                RepeaterColumn::key('option')->text()->readonly()->label('Opzione')->columnSpan(2),
                RepeaterColumn::key('sku')->text()->label('SKU')->columnSpan(2),
                RepeaterColumn::key('ean')->text()->label('EAN')->columnSpan(2),
                RepeaterColumn::key('price')->number()->decimal(2)->label('Prezzo')->columnSpan(1),
                // Scrivibile: si scrive quanti pezzi ci sono, e il pannello fa
                // il movimento della differenza. Una casella lasciata com'era
                // non muove niente.
                RepeaterColumn::key('stock')->number()->decimal(3)->label('Giacenza')->columnSpan(1),
                RepeaterColumn::key('photo')->fileDragDrop('gallery')->label('Foto o video')->columnSpan(2),
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
            // Le righe nascono dalle spunte, non a mano: un bottone
            // «Aggiungi» darebbe una riga senza nessuna combinazione dietro.
            ->repeaterAddButton(false)
            ->repeaterStartEmpty()
            ->repeaterDeleteTitle('Elimina opzione')
            ->repeaterDeleteText('Confermi l\'eliminazione di questa opzione in vendita?')
            ->repeaterDeleteCancelLabel('Annulla')
            ->repeaterDeleteConfirmLabel('Elimina')
            ->repeaterDeleteConfirmClass('btn btn-danger')
            // Il titolo lo dà il riquadro: due titoli uguali di fila si
            // leggono male.
            ->label('');

        if (static::groupsByVariant($modelId)) {
            $campo = $campo
                ->repeaterGroupFixed('variant')
                ->repeaterGroupCommand('price', 'Prezzo del gruppo')
                ->repeaterGroupCountLabel('opzione', 'opzioni');
        }

        return $campo;
    }

    /**
     * Se la griglia nasce raggruppata.
     *
     * In creazione non c'è ancora niente da guardare: si raggruppa se il
     * negozio ha un attributo con pagina propria, e il browser spegne i gruppi
     * finché nessuna riga ne porta uno. Su un articolo che esiste decide
     * quello che c'è: un articolo venduto solo per taglia non ha un colore su
     * cui raggruppare, e la griglia resta piatta.
     */
    protected static function groupsByVariant(int $modelId): bool
    {
        if ($modelId <= 0) {
            return static::variantAttributeId() > 0;
        }

        foreach (static::variantLabels($modelId) as $label) {
            if ($label !== '') {
                return true;
            }
        }

        return false;
    }

    /** L'attributo con pagina propria, se il negozio ne ha uno. */
    public static function variantAttributeId(): int
    {
        foreach (static::attributes() as $attribute) {
            if (($attribute['level'] ?? '') === 'variant') {
                return (int) $attribute['id'];
            }
        }

        return 0;
    }

    /** L'etichetta di ogni valore d'attributo, per id. @return array<int, string> */
    public static function valueLabels(): array
    {
        $labels = [];

        foreach (static::attributeValues() as $value) {
            $labels[(int) $value['id']] = (string) ($value['label'] ?? '');
        }

        return $labels;
    }

    /**
     * Il valore d'attributo di ogni variante: "Blu", "Rosso".
     *
     * Vuoto per la variante scheletro, quella che nasce con l'articolo e non
     * rappresenta nessun colore.
     *
     * @return array<int, string>
     */
    public static function variantLabels(int $modelId): array
    {
        $valori = static::valueLabels();
        $etichette = [];

        foreach (static::variants($modelId) as $variant) {
            $id = (int) $variant['id'];
            $etichette[$id] = '';

            foreach (ProductAttributes::read('variant', $id) as $link) {
                $valueId = (int) ($link['attribute_value_id'] ?? 0);

                if ($valueId > 0) {
                    $etichette[$id] = $valori[$valueId] ?? '';
                    break;
                }
            }
        }

        return $etichette;
    }

    /**
     * Come si chiama ogni opzione in vendita, intera e in breve.
     *
     * `full` è il nome che sta sul prodotto e che il cliente legge — "Blu / S";
     * `rest` è quello che la griglia mostra sotto la testata "Blu", cioè "S".
     * Nessuno dei due si scrive a mano: nascono dai collegamenti agli
     * attributi, che sono l'unica sorgente vera.
     *
     * @return array<int, array{variant: string, rest: string, full: string}>
     */
    public static function optionLabels(int $modelId): array
    {
        $valori = static::valueLabels();
        $varianti = static::variantLabels($modelId);
        $asseVariante = static::variantAttributeId();
        $posizione = [];

        foreach (static::attributes() as $attribute) {
            $posizione[(int) $attribute['id']] = (int) ($attribute['position'] ?? 0);
        }

        $nomi = [];

        foreach (static::products($modelId) as $product) {
            $id = (int) $product['id'];
            $colore = $varianti[(int) ($product['product_variant_id'] ?? 0)] ?? '';
            $links = ProductAttributes::read('product', $id);

            // L'ordine è quello degli attributi nell'anagrafica: "S / Gomma"
            // e non "Gomma / S", su tutte le righe uguale.
            uksort(
                $links,
                static fn ($a, $b): int => ($posizione[(int) $a] ?? 0) <=> ($posizione[(int) $b] ?? 0)
            );

            $resto = [];

            foreach ($links as $attributeId => $link) {
                if ((int) $attributeId === $asseVariante) {
                    continue;
                }

                $label = $valori[(int) ($link['attribute_value_id'] ?? 0)] ?? '';

                if ($label !== '') {
                    $resto[] = $label;
                }
            }

            $nomi[$id] = [
                'variant' => $colore,
                'rest' => implode(' / ', $resto),
                'full' => VersionName::from(
                    array_values(array_filter(array_merge([$colore], $resto), static fn (string $l): bool => $l !== '')),
                    (string) ($product['sku'] ?? '')
                ),
            ];
        }

        return $nomi;
    }

    /**
     * La foto di ogni opzione in vendita, nella forma che legge il campo.
     *
     * Solo quelle davvero sue: le foto del colore e quelle dell'articolo si
     * caricano nel riquadro delle foto, e mostrarle qui farebbe credere che
     * appartengano a questa riga.
     *
     * @return array<int, string>
     */
    protected static function optionImages(int $modelId): array
    {
        $foto = [];

        foreach (static::rowsOf(ProductImage::class, ['product_model_id' => $modelId], 'position') as $image) {
            $productId = (int) ($image['product_id'] ?? 0);

            if ($productId > 0 && !isset($foto[$productId])) {
                $foto[$productId] = (string) ($image['file'] ?? '');
            }
        }

        return $foto;
    }

    /**
     * Rimette in riga i nomi: li scrive il sistema, non chi compila.
     *
     * Il colore si chiama come il suo valore nell'anagrafica — rinominare
     * "Blu" in "Blu notte" lì lo rinomina anche qui — e l'opzione si chiama
     * come i valori che la compongono. Gira a ogni salvataggio: è l'unico modo
     * perché quello che si legge nella griglia e quello che il cliente legge
     * nel negozio siano la stessa cosa.
     */
    protected static function realignNames(int $modelId): void
    {
        foreach (static::variantLabels($modelId) as $variantId => $label) {
            if ($label !== '') {
                ProductVariant::update(['name' => $label], (int) $variantId);
            }
        }

        foreach (static::optionLabels($modelId) as $productId => $nomi) {
            if ($nomi['full'] !== '') {
                Product::update(['name' => $nomi['full']], (int) $productId);
            }
        }
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
    /**
     * @param list<int> $skip versioni appena nate con un prezzo scritto a
     *        mano: la casella in alto non le tocca
     */
    protected static function savePrices(int $modelId, array $post, string $fallbackSku = '', array $appena = []): void
    {
        $prezzo = Numbers::fromForm($post['product_price'] ?? null);
        $scontato = Numbers::fromForm($post['product_sale_price'] ?? null);
        $prodotti = static::products($modelId);

        if ($prodotti === []) {
            return;
        }

        /** Quello che la griglia ha scritto per questa riga, se è nata adesso. */
        $scritto = static function (array $product) use ($appena): array {
            $riga = $appena[(int) ($product['id'] ?? 0)] ?? null;

            return is_array($riga) ? $riga : [];
        };

        if (count($prodotti) === 1) {
            $product = $prodotti[0];
            $riga = $scritto($product);
            // La scheda non chiede lo SKU della versione quando è una sola: lo
            // prende da quello dell'articolo, che è la stessa cosa. Se
            // l'articolo non ne ha, resta quello che la versione aveva già.
            // Ma se la riga è nata adesso con i suoi codici, quelli vincono:
            // sono stati scritti più in basso nella stessa schermata.
            $sku = trim((string) ($riga['sku'] ?? '')) ?: (trim((string) ($post['sku'] ?? '')) ?: $fallbackSku);
            $ean = trim((string) ($riga['ean'] ?? '')) ?: trim((string) ($post['product_ean'] ?? ''));

            Product::update([
                'sku' => $sku !== '' ? $sku : (string) ($product['sku'] ?? ''),
                'ean' => $ean,
                // I decimali arrivano con la virgola: MySQL non li accetta.
                'price' => Numbers::fromForm($riga['price'] ?? null) ?? $prezzo,
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
            // Una versione appena nata con il suo prezzo non si tocca: la
            // casella in alto è un comando per le altre, non per quella.
            if (Numbers::fromForm($scritto($product)['price'] ?? null) !== null) {
                continue;
            }

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
