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
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeValueResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\BrandResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\CategoryResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\PackageResource;
use Wonder\Plugin\Gestionale\Resources\Tax\TaxCategoryResource;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\CategoryTree;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Combinations;
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
use Wonder\Plugin\Gestionale\Support\Tax\TaxCategories;
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
    /**
     * Quante foto o video per area.
     *
     * È quanto usano le schede prodotto degli store veri: oltre, la galleria
     * diventa una striscia che nessuno guarda fino in fondo.
     */
    public const MAX_IMAGES = 10;

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
            // La domanda che governa tutto quello che le sta sotto. È una
            // risposta dell'articolo, non un conteggio delle sue righe: un
            // articolo appena creato ha già un figlio, e il conteggio direbbe
            // «no» proprio a chi le varianti le sta per aggiungere.
            FormField::key('has_variants')
                ->toggle()
                ->label('Questo articolo ha varianti')
                ->description('Colori, taglie, gusti: se ne ha, prezzo e codici si scrivono opzione per opzione.'),
            // L'ordine degli attributi scelti, scritto dal selettore qui
            // sotto: il primo raggruppa, gli altri compongono il nome.
            FormField::key('axes_order')->hidden(),
            FormField::key('brand_id')
                ->select(static::brandOptions())
                ->label('Marchio')
                ->quickCreate(BrandResource::class),
            // Parte dal tipo predefinito: quasi nessuno lo cambia. Con un tipo
            // solo non c'è niente da scegliere e il campo resta nascosto,
            // con quel valore dentro.
            static::taxCategoryField(),
            // Il codice di famiglia: da lì nascono quelli delle opzioni.
            // Con le varianti accese non si scrive qui, ma il valore resta
            // nel modulo — nascosto, non tolto — e continua a proporre i
            // codici delle righe.
            FormField::key('sku')->text()->label('SKU')->hiddenWhen('has_variants', 'true'),
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
            // Un albero solo: la principale è la stella di una voce spuntata,
            // non un secondo campo. La categoria nuova chiede anche il padre
            // e nasce sotto di lui.
            FormField::key('categories')
                ->checkTree(static::categoryTree(), true)
                ->label('Categorie')
                ->listsResource(CategoryResource::class)
                ->primaryField('main_category')
                ->quickCreate(CategoryResource::class, ['name', 'parent_id'], label: 'name', button: 'Aggiungi categoria'),
            // La scrive la stella dell'albero.
            FormField::key('main_category')->hidden(),
            FormField::key('tags')->selectSearch(static::tagOptions(), true)->label('Tag'),
            FormField::key('package_id')
                ->select(Packages::options())
                ->label('Imballaggio')
                ->quickCreate(PackageResource::class),
            FormField::key('weight')->number()->decimal(3)->label('Peso (kg)'),
            FormField::key('length')->number()->decimal(2)->label('Lunghezza (cm)'),
            FormField::key('width')->number()->decimal(2)->label('Larghezza (cm)'),
            FormField::key('height')->number()->decimal(2)->label('Altezza (cm)'),
            FormField::key('circumference')->number()->decimal(2)->label('Circonferenza (cm)'),
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
        $conVarianti = static::hasVariants($modelId);
        // Un articolo che ha già più opzioni non torna indietro da un
        // interruttore: quelle righe hanno movimenti, prenotazioni e foto. Si
        // cancellano dalla griglia, una per una, dove la cancellazione lo
        // dice.
        $bloccato = static::productCount($modelId) > 1;

        $domanda = static::getInput('has_variants')->columnSpan(12);

        if ($bloccato) {
            $domanda = $domanda->readonly()->disabled();
        }

        $cards = [
            (new Card)->components([
                SectionTitle::make('Prodotto')
                    ->tooltip($conVarianti
                        ? 'Questa casella è un comando, non un riepilogo: scrivici un prezzo e al salvataggio va su tutte le opzioni in vendita. Lasciala vuota e i prezzi delle righe restano come sono.'
                        : 'Il prezzo di questo articolo, IVA compresa: quale IVA lo dice il tipo fiscale, nel riquadro «Vendita».')
                    ->columnSpan(12),
                static::getInput('name')->columnSpan(12),
                $domanda,
                static::getInput('axes_order'),
                ...($bloccato ? [
                    RichText::make('<p class="small text-body-secondary mb-0">Questo articolo ha più opzioni in vendita: per tornare a un articolo singolo eliminale dalla griglia qui sotto.</p>')
                        ->tag('div')
                        ->columnSpan(12),
                ] : []),
                // In creazione la giacenza non c'è ancora: i due prezzi si
                // prendono la riga.
                static::getInput('product_price')->columnSpan($modelId > 0 ? 4 : 6),
                static::getInput('product_sale_price')->columnSpan($modelId > 0 ? 4 : 6),
                // Senza varianti la giacenza sta qui, accanto al prezzo: è la
                // scheda di quell'unico articolo, e la parola "opzione" non
                // compare da nessuna parte.
                // In creazione non c'è ancora niente da rettificare: la
                // giacenza compare dal primo salvataggio in poi.
                ...($modelId > 0 ? [
                    static::getInput('product_stock')->columnSpan(4),
                    RichText::make(static::adjustLink($modelId))
                        ->columnSpan(12)
                        ->hiddenWhen('has_variants', 'true'),
                ] : []),
            ])->columns(12)->columnSpan(12),
        ];

        $foto = [
            SectionTitle::make('Foto e video')
                ->tooltip('Le foto e i video caricati qui compaiono in tutte le opzioni dell\'articolo; quelli sotto un colore solo nelle opzioni di quel colore.')
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

        // Le misure sono del prodotto, non della spedizione: stavano in
        // «Spedizione» e sparivano con l'articolo che non si spedisce,
        // portandosi via anche l'unità di misura, che è obbligatoria.
        $cards[] = (new Card)->components([
            SectionTitle::make('Misure')
                ->tooltip('Le dimensioni vere del prodotto, quelle che servono a sapere se sta in una scatola. Un\'opzione con misure sue le usa al posto di queste.')
                ->columnSpan(12),
            static::getInput('unit')->columnSpan(6),
            static::getInput('weight')->columnSpan(6),
            static::getInput('length')->columnSpan(3),
            static::getInput('width')->columnSpan(3),
            static::getInput('height')->columnSpan(3),
            static::getInput('circumference')->columnSpan(3),
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
                // Titolo e selettore stanno sulla stessa riga: una riga a
                // testa era spazio che non diceva niente di nuovo.
                SectionTitle::make('Opzioni in vendita')
                    ->tooltip('Scegli un attributo — colore, taglia, gusto — e spunta i valori: le righe compaiono qui sotto e nascono al salvataggio, con codice, prezzo, giacenza e foto. Togliere una spunta non cancella niente che esista già.')
                    ->columnSpan(6),
                static::optionsPicker($modelId)->columnSpan(6),
                // I blocchi stanno in un contenitore loro: il riordino li
                // sposta con `order`, e dentro un riquadro condiviso
                // scavalcherebbero la griglia e il selettore.
                (new Container)->components($blocchi)->columns(12)->columnSpan(12),
                static::getInput('products')->columnSpan(12),
                static::optionsGridScript($modelId)->columnSpan(12),
            ])->columns(12)->columnSpan(12)
                // Il riquadro intero risponde alla domanda in cima alla
                // scheda: con «no» non c'è niente da scegliere.
                ->visibleWhen('has_variants', 'true'),
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

        $codici[] = static::getInput('product_ean')->columnSpan(12);

        $cards = [
            (new Card)->components([
                SectionTitle::make('Vendita')
                    ->tooltip('Una bozza non si vede da nessuna parte. Un articolo pubblicato che non si vende online resta in catalogo per il negozio e per i documenti. Il tipo fiscale decide l\'IVA: un articolo nuovo parte dal predefinito.')
                    ->columnSpan(12),
                static::getInput('visible')->columnSpan(12),
                static::getInput('visible_online')->columnSpan(12),
                static::getInput('tax_category_id')->columnSpan(12),
                static::getInput('returnable')->columnSpan(6),
                static::getInput('requires_shipping')->columnSpan(6),
            ])->columns(12)->columnSpan(12),

            // Con le varianti dentro non resta niente: un riquadro con il
            // solo titolo è peggio di nessun riquadro.
            (new Card)->components($codici)->columns(12)->columnSpan(12)
                ->hiddenWhen('has_variants', 'true'),

            (new Card)->components([
                SectionTitle::make('Dove si trova')
                    ->tooltip('Spunta tutte le categorie in cui deve comparire. La stella indica quella che compare nel percorso sopra la pagina (Home › Abbigliamento › Magliette): di solito la più precisa.')
                    ->columnSpan(12),
                static::getInput('brand_id')->columnSpan(12),
                static::getInput('tags')->columnSpan(12),
                static::getInput('categories')->columnSpan(12),
                static::getInput('main_category'),
            ])->columns(12)->columnSpan(12),
        ];

        // Un articolo che non si spedisce non ha niente da dire qui.
        if (static::shipsFrom($modelId)) {
            $cards[] = (new Card)->components([
                SectionTitle::make('Spedizione')
                    ->tooltip('La scatola in cui parte. Le misure del prodotto stanno nel loro riquadro: servono a sapere se ci sta.')
                    ->columnSpan(12),
                static::getInput('package_id')->columnSpan(12),
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

        // Un articolo che ha già più opzioni resta con varianti, qualunque
        // cosa arrivi: l'interruttore è spento e il campo nascosto manderebbe
        // «no» per conto suo.
        if (static::productCount($id) > 1) {
            $values['has_variants'] = 'true';
        }

        $values['has_variants'] = ($values['has_variants'] ?? 'false') === 'true' ? 'true' : 'false';
        $values['axes_order'] = static::axesFromPost((array) $_POST);

        // Il tipo fiscale non arriva mai vuoto: il campo nascosto di chi ne
        // ha uno solo, o un articolo nato prima del predefinito, prende quello.
        if (array_key_exists('tax_category_id', $values) && (int) $values['tax_category_id'] <= 0) {
            $values['tax_category_id'] = (string) (TaxCategories::defaultId() ?: '');
        }

        static::assertSoleProduct($id, $values);

        // Le caselle nascoste vengono postate lo stesso: se l'articolo dice
        // di non avere varianti, le spunte non si guardano nemmeno.
        if ($values['has_variants'] === 'true') {
            static::chosenAxes((array) $_POST);
        }

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
        if (!static::stockIsWritable()) {
            return;
        }

        foreach (static::postedRows((array) $_POST) as $riga) {
            $quantita = Stocktake::quantity($riga['stock'] ?? null);

            if ($quantita !== null && $quantita < 0) {
                throw UserError::make('product.stock_negative');
            }
        }
    }

    /**
     * L'ordine degli assi che arriva dal selettore, ripulito.
     *
     * Vale solo quello che è davvero spuntato: un attributo tolto dalla
     * scheda esce anche dall'ordine, e uno spuntato che l'ordine non nomina
     * va in coda invece di sparire.
     */
    protected static function axesFromPost(array $post): string
    {
        $preferito = [];

        foreach (explode('-', (string) ($post['axes_order'] ?? '')) as $pezzo) {
            $id = (int) trim($pezzo);

            if ($id > 0 && !in_array($id, $preferito, true)) {
                $preferito[] = $id;
            }
        }

        $spuntati = [];

        foreach (static::optionAttributes() as $attribute) {
            $id = (int) $attribute['id'];
            $valori = array_filter(
                (array) ($post['option_'.$id] ?? []),
                static fn ($valore): bool => trim((string) $valore) !== ''
            );

            if ($valori !== []) {
                $spuntati[] = $id;
            }
        }

        $ordinati = array_values(array_filter(
            $preferito,
            static fn (int $id): bool => in_array($id, $spuntati, true)
        ));

        foreach ($spuntati as $id) {
            if (!in_array($id, $ordinati, true)) {
                $ordinati[] = $id;
            }
        }

        return implode('-', $ordinati);
    }

    /**
     * La giacenza dell'articolo senza varianti.
     *
     * È lo stesso gesto della riga — si scrive quanti pezzi ci sono e il
     * pannello fa il movimento della differenza — ma la casella sta in alto,
     * accanto al prezzo, perché lì non c'è nessuna griglia da guardare.
     */
    protected static function saveSingleStock(int $modelId, array $post, bool $conVarianti): void
    {
        if ($conVarianti || !static::stockIsWritable()) {
            return;
        }

        $product = static::soleProduct($modelId);

        if (!is_array($product)) {
            return;
        }

        $quantita = Stocktake::quantity($post['product_stock'] ?? null);

        if ($quantita === null) {
            return;
        }

        $productId = (int) $product['id'];
        $attuale = (float) (Levels::of($productId)['quantity'] ?? 0);

        foreach (Stocktake::changes([$productId => $attuale], [$productId => $quantita]) as $id => $cambio) {
            Stock::apply([
                'product_id' => $id,
                'quantity' => $cambio['delta'],
                'reason' => Reasons::DEFAULT,
                'note' => 'Rettifica dalla scheda dell\'articolo',
            ]);
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

            // Il riquadro delle opzioni è nascosto, non tolto: le sue caselle
            // arrivano comunque. Chi ha detto di non avere varianti non deve
            // ritrovarsi delle combinazioni generate da spunte che non vede.
            $conVarianti = ($post['has_variants'] ?? 'false') === 'true'
                || static::productCount($modelId) > 1;

            $righe = static::postedRows($post);
            $nate = [];
            $scritte = [];
            $appena = [];

            if ($conVarianti) {
                $chosen = static::chosenAxes($post);
                // Le righe senza id sono le combinazioni spuntate che ancora
                // non esistono: la loro chiave è quella della combinazione.
                $scritte = static::newRows($righe);
                $nate = Generator::run($modelId, $chosen['variant'], $chosen['axes'], $fallbackSku, $scritte);

                // Quello che è stato scritto nella griglia, per riga appena
                // nata: il riquadro in alto non deve riscriverlo.
                foreach ($nate as $chiave => $riga) {
                    $appena[$riga['product_id']] = is_array($scritte[$chiave] ?? null) ? $scritte[$chiave] : [];
                }
            }

            static::savePrices($modelId, $post, $fallbackSku, $appena);
            static::saveNewVersions($modelId, $nate, $scritte, $files);
            static::saveRowExtras($modelId, $righe, $files, $conVarianti);
            static::saveSingleStock($modelId, $post, $conVarianti);
            static::saveImages($modelId, $post, $files);
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
    protected static function saveRowExtras(
        int $modelId,
        array $righe,
        array $files,
        bool $conVarianti = true
    ): void {
        $caricate = Repeater::filesFromRequest('products', $files);
        // La giacenza della griglia si guarda solo quando la griglia si vede
        // e la casella si scrive: altrove il numero arriva com'era, e un
        // movimento da zero non è un movimento.
        $leggiGiacenza = $conVarianti && static::stockIsWritable();
        $scritte = [];
        $foto = [];

        foreach ($righe as $chiave => $riga) {
            $productId = (int) ($riga['id'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            $quantita = $leggiGiacenza ? Stocktake::quantity($riga['stock'] ?? null) : null;

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

        static::createImageRow(
            $modelId,
            $variantId,
            $productId,
            $file,
            count(static::rowsOf(ProductImage::class, ['product_model_id' => $modelId])) + 1
        );
    }

    /**
     * Le foto di ogni area, dal campo unico che le tiene tutte.
     *
     * Il campo manda un manifesto: l'elenco dei file nell'ordine voluto, dove
     * una stringa è un file che c'era già e un numero è la posizione di un
     * file appena caricato. Da lì nascono, si riordinano e spariscono le
     * righe di `gst_product_images` — una per foto, perché è una riga per
     * foto che la coda delle misure sa lavorare.
     */
    protected static function saveImages(int $modelId, array $post, array $files): void
    {
        foreach (array_keys(static::imageTargets($modelId)) as $variantId) {
            $campo = 'images_'.(int) $variantId;

            // Il campo non era in pagina: non è un'area svuotata, è un'area
            // che nessuno ha mostrato. Toccarla cancellerebbe tutto.
            if (!array_key_exists($campo.'__wi_files', $post)) {
                continue;
            }

            $manifesto = json_decode((string) $post[$campo.'__wi_files'], true);

            if (!is_array($manifesto)) {
                continue;
            }

            static::syncAreaImages($modelId, (int) $variantId, $manifesto, $files[$campo] ?? null);
        }
    }

    /**
     * @param list<mixed> $manifesto
     * @param array<string, mixed>|null $caricati La busta di `$_FILES` del campo.
     */
    protected static function syncAreaImages(
        int $modelId,
        int $variantId,
        array $manifesto,
        mixed $caricati
    ): void {
        $esistenti = [];

        foreach (static::areaImages($modelId, $variantId) as $riga) {
            $nome = ProductImages::fileName($riga);

            if ($nome !== '') {
                $esistenti[$nome] = $riga;
            }
        }

        $tenute = [];
        $posizione = 0;

        foreach ($manifesto as $voce) {
            $posizione++;

            if (is_string($voce)) {
                $riga = $esistenti[$voce] ?? null;

                if (!is_array($riga)) {
                    continue;
                }

                $tenute[$voce] = true;

                if ((int) ($riga['position'] ?? 0) !== $posizione) {
                    ProductImage::update(['position' => $posizione], (int) $riga['id']);
                }

                continue;
            }

            $busta = static::uploadedFile($caricati, (int) $voce);

            if ($busta === null) {
                continue;
            }

            static::createImageRow($modelId, $variantId, null, $busta, $posizione);
        }

        // Quello che il manifesto non nomina non si vende più: la riga se ne
        // va. Il file resta sul disco — cancellarlo qui vorrebbe dire
        // fidarsi che nessun'altra riga lo usi.
        foreach ($esistenti as $nome => $riga) {
            if (!isset($tenute[$nome])) {
                ProductImage::delete((int) $riga['id']);
            }
        }
    }

    /**
     * Una busta di `$_FILES` a un solo file, presa da quella a più file del
     * campo.
     *
     * @return array<string, mixed>|null
     */
    protected static function uploadedFile(mixed $caricati, int $indice): ?array
    {
        if (!is_array($caricati) || !isset($caricati['name'])) {
            return null;
        }

        $nomi = (array) $caricati['name'];

        if (!array_key_exists($indice, $nomi)) {
            return null;
        }

        $errori = (array) ($caricati['error'] ?? []);
        $errore = (int) ($errori[$indice] ?? UPLOAD_ERR_NO_FILE);

        if (trim((string) $nomi[$indice]) === '' || $errore !== UPLOAD_ERR_OK) {
            return null;
        }

        // La stessa forma che il core si aspetta da un campo a un file solo:
        // ogni chiave è una lista di uno.
        $busta = [];

        foreach (['name', 'type', 'tmp_name', 'error', 'size', 'full_path'] as $chiave) {
            if (!isset($caricati[$chiave])) {
                continue;
            }

            $valori = (array) $caricati[$chiave];
            $busta[$chiave] = [$valori[$indice] ?? null];
        }

        return $busta;
    }

    /**
     * Una riga di `gst_product_images` con il suo file su disco.
     *
     * `Model::create()` non sa caricare niente: scriverebbe nel database la
     * busta di `$_FILES` invece del file. Chi sposta il file è la
     * preparazione del core — la stessa che usano i repeater — e vuole sapere
     * in quale cartella scrivere, che è quella del Model.
     */
    protected static function createImageRow(
        int $modelId,
        int $variantId,
        ?int $productId,
        array $file,
        int $posizione
    ): void {
        $riga = [
            'product_model_id' => $modelId,
            'product_variant_id' => $variantId > 0 ? $variantId : null,
            'product_id' => $productId,
            'file' => $file,
            'alt' => '',
            'position' => $posizione,
            // Le misure per il sito le farà la coda, come per ogni altra foto.
            'status' => 'pending',
            'attempts' => 0,
        ];

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

        if ((int) ($values['tax_category_id'] ?? 0) <= 0) {
            $values['tax_category_id'] = (string) (TaxCategories::defaultId() ?: '');
        }

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
                // Il primo asse raggruppa; quello che resta del nome si legge.
                $values['products'][$index]['group'] = $nomi[$productId]['group'] ?? '';
                $values['products'][$index]['option'] = $nomi[$productId]['label'] ?? '';
                $values['products'][$index]['photo'] = $foto[$productId] ?? '';
                $values['products'][$index]['combination'] = $nomi[$productId]['key'] ?? '';
            }
        }

        if ($unaVersione) {
            $values['product_stock'] = static::plainNumber(
                Levels::of((int) $product['id'])['quantity']
            );
        }

        // Un articolo che ha già più opzioni risponde «sì» comunque, anche se
        // la colonna dice altro: è nato prima che la domanda esistesse.
        $values['has_variants'] = static::hasVariants($modelId) ? 'true' : 'false';

        foreach (array_keys(static::imageTargets($modelId)) as $variantId) {
            $values['images_'.$variantId] = static::imageNames($modelId, (int) $variantId);
        }

        foreach (static::usedOptionValues($modelId) as $key => $ids) {
            $values[$key] = $ids;
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
     * Gli attributi che fanno nascere opzioni in vendita, con i loro valori.
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

    /** I valori di un attributo: etichetta per id. @return array<string, string> */
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
                    button: 'Aggiungi opzione',
                    layout: static fn (): Form => (new Form)->components([
                        (new Container)->components([
                            FormField::key('attribute_id')->hidden()->value((string) $id),
                            // Senza larghezza, in una griglia da 12 un campo ne prende una.
                            AttributeValueResource::getInput('label')->label('Nuovo '.$nome)->columnSpan(12),
                        ])->columns(12)->columnSpan(12),
                    ])->columns(12),
                );
        }

        return $fields;
    }

    /**
     * Il selettore degli attributi: prima si sceglie quale, poi compaiono i
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
    protected static function optionsPicker(int $modelId = 0): object
    {
        $ordine = static::escape(implode('-', static::axesOrder($modelId)));
        $voci = '';

        foreach (static::optionAttributes() as $attribute) {
            $voci .= '<option value="'.(int) $attribute['id'].'">'
                .static::escape((string) ($attribute['name'] ?? '')).'</option>';
        }

        // Un `div`, non il `p` di un testo: dentro c'è un altro `div`, che un
        // `p` chiuderebbe prima del tempo.
        return RichText::make(<<<HTML
<div class="wi-option-picker d-flex flex-column align-items-end gap-1 text-end">
    <select class="form-select form-select-sm w-auto wi-option-choose" aria-label="Aggiungi un attributo">
        <option value="">Aggiungi un attributo…</option>
        {$voci}
    </select>
    <span class="small text-body-secondary wi-option-full d-none">Tre attributi sono il massimo: per aggiungerne un altro togline uno.</span>
    <span class="small text-body-secondary wi-option-order d-none" data-wi-option-order="{$ordine}"></span>
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

        function titolo(nodo) {
            return nodo.getAttribute('data-wi-option-name') || 'Attributo';
        }

        // L'ordine vive in un campo nascosto: è quello che il server salva e
        // quello che la griglia rilegge per comporre i nomi.
        function campoOrdine() {
            return document.querySelector('[name="axes_order"]');
        }

        function ordine() {
            var campo = campoOrdine();
            var scritto = campo ? String(campo.value || '') : '';
            var lista = scritto.split('-').filter(function (id) { return id !== ''; });
            // Acceso vuol dire «scelto nel selettore» oppure «ha già un
            // valore spuntato»: le due cose devono dire la stessa cosa, o
            // l'ordine e la griglia raccontano due storie diverse.
            var accesi = Array.prototype.slice.call(document.querySelectorAll('[data-wi-option]'))
                .filter(function (nodo) {
                    return nodo.getAttribute('data-wi-option-on') === 'true' || inUso(nodo);
                })
                .map(function (nodo) { return nodo.getAttribute('data-wi-option'); });

            // Solo quello che è davvero acceso, e quello che l'ordine non
            // nomina in coda: un attributo appena scelto non deve sparire.
            var finale = lista.filter(function (id) { return accesi.indexOf(id) !== -1; });

            accesi.forEach(function (id) { if (finale.indexOf(id) === -1) finale.push(id); });

            return finale;
        }

        function scriviOrdine(lista) {
            var campo = campoOrdine();
            if (campo) campo.value = lista.join('-');

            lista.forEach(function (id, indice) {
                var nodo = document.querySelector('[data-wi-option="' + id + '"]');
                if (nodo) blocco(nodo).style.order = String(indice + 1);
            });

            riepilogo(lista);
            frecce(lista);
        }

        // Una frase che dice cosa succederà: «Le opzioni si raggruppano per
        // Colore, poi Taglia». Con un attributo solo non c'è niente da
        // raggruppare, e la frase non compare.
        function riepilogo(lista) {
            var riga = document.querySelector('.wi-option-order');
            if (!riga) return;

            var nomi = lista.map(function (id) {
                var nodo = document.querySelector('[data-wi-option="' + id + '"]');

                return nodo ? titolo(nodo) : '';
            }).filter(function (n) { return n !== ''; });

            riga.classList.toggle('d-none', nomi.length < 2);

            if (nomi.length < 2) return;

            riga.textContent = 'Le opzioni si raggruppano per ' + nomi[0] + ', poi ' + nomi.slice(1).join(', poi ') + '.';
        }

        function sposta(id, passo) {
            var lista = ordine();
            var da = lista.indexOf(id);
            var a = da + passo;

            if (da === -1 || a < 0 || a >= lista.length) return;

            lista.splice(a, 0, lista.splice(da, 1)[0]);
            scriviOrdine(lista);

            if (typeof window.wiOptionsGrid === 'function') window.wiOptionsGrid();
        }

        // Le frecce servono da due attributi in su: con uno solo non c'è
        // nessun ordine da cambiare.
        function frecce(lista) {
            lista.forEach(function (id, indice) {
                var nodo = document.querySelector('[data-wi-option="' + id + '"]');
                if (!nodo) return;

                var barra = nodo.querySelector('.wi-option-move');
                if (!barra) return;

                barra.classList.toggle('d-none', lista.length < 2);
                barra.querySelector('.wi-option-up').disabled = indice === 0;
                barra.querySelector('.wi-option-down').disabled = indice === lista.length - 1;
            });
        }

        // Le frecce stanno accanto alla maniglia: servono a chi non
        // trascina, e su una riga sola non rubano spazio ai valori.
        function bottoniOrdine(nodo) {
            var maniglia = nodo.querySelector('.wi-option-grip-box');
            if (!maniglia || maniglia.querySelector('.wi-option-move')) return;

            var id = nodo.getAttribute('data-wi-option');
            var barra = document.createElement('span');
            barra.className = 'd-flex flex-column wi-option-move d-none';

            [['up', 'bi-chevron-up', 'Sposta prima'], ['down', 'bi-chevron-down', 'Sposta dopo']]
                .forEach(function (voce) {
                    var bottone = document.createElement('button');
                    bottone.type = 'button';
                    bottone.className = 'btn btn-link p-0 lh-1 text-body-secondary wi-option-' + voce[0];
                    bottone.title = voce[2];
                    bottone.setAttribute('aria-label', voce[2] + ' ' + nome(nodo));
                    bottone.innerHTML = '<i class="bi ' + voce[1] + ' small"></i>';
                    bottone.addEventListener('click', function () {
                        sposta(id, voce[0] === 'up' ? -1 : 1);
                    });

                    barra.appendChild(bottone);
                });

            maniglia.appendChild(barra);
        }

        // Il trascinamento della riga: la maniglia porta il blocco, e chi la
        // riceve dice dove va a finire.
        var trascinato = null;

        function trascinamento(nodo) {
            var riga = blocco(nodo);
            var grip = nodo.querySelector('.wi-option-grip');
            if (!grip || riga.getAttribute('data-wi-drag') === 'true') return;

            riga.setAttribute('data-wi-drag', 'true');
            grip.setAttribute('draggable', 'true');

            grip.addEventListener('dragstart', function (ev) {
                trascinato = nodo.getAttribute('data-wi-option');
                riga.classList.add('opacity-50');
                if (ev.dataTransfer) {
                    ev.dataTransfer.effectAllowed = 'move';
                    try { ev.dataTransfer.setData('text/plain', trascinato); } catch (e) {}
                }
            });

            grip.addEventListener('dragend', function () {
                riga.classList.remove('opacity-50');
                trascinato = null;
            });

            riga.addEventListener('dragover', function (ev) {
                if (trascinato !== null) ev.preventDefault();
            });

            riga.addEventListener('drop', function (ev) {
                if (trascinato === null) return;
                ev.preventDefault();

                var lista = ordine();
                var da = lista.indexOf(trascinato);
                var a = lista.indexOf(nodo.getAttribute('data-wi-option'));

                if (da === -1 || a === -1 || da === a) return;

                lista.splice(a, 0, lista.splice(da, 1)[0]);
                scriviOrdine(lista);

                if (typeof window.wiOptionsGrid === 'function') window.wiOptionsGrid();
            });
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
            // Il muro si spiega solo quando c'è: con gli attributi finiti non
            // resta niente da aggiungere, e niente da dire.
            picker.querySelector('.wi-option-full').classList.toggle('d-none', !(restano && pieno));
        }

        // «Togli» solo sulle opzioni che l'articolo non sta usando: nascondere
        // un colore che ha già le sue versioni direbbe una bugia.
        function bottoneTogli(nodo) {
            if (nodo.querySelector('.wi-option-remove')) return;

            var pillole = nodo.querySelector('.wi-check-pills .d-flex');
            if (!pillole) return;

            var bottone = document.createElement('button');
            bottone.type = 'button';
            // In fondo alla fila, dopo il «+»: toglie l'attributo intero, non
            // un valore, e non deve sembrare una pillola in più.
            bottone.className = 'btn btn-sm btn-link text-body-secondary ms-auto wi-option-remove';
            bottone.title = 'Togli ' + nome(nodo);
            bottone.setAttribute('aria-label', 'Togli ' + nome(nodo));
            bottone.innerHTML = '<i class="bi bi-x-lg"></i>';
            bottone.addEventListener('click', function () {
                caselle(nodo).forEach(function (casella) { casella.checked = false; });
                mostra(nodo, false);
                scriviOrdine(ordine());
                aggiornaSelettore();
                if (typeof window.wiOptionsGrid === 'function') window.wiOptionsGrid();
            });

            pillole.appendChild(bottone);
        }

        function avvia() {
            var picker = document.querySelector('.wi-option-picker');
            if (!picker || picker.getAttribute('data-wi-ready') === 'true') return;
            picker.setAttribute('data-wi-ready', 'true');

            var riga = picker.querySelector('.wi-option-order');
            var campo = campoOrdine();

            if (campo && String(campo.value || '') === '' && riga) {
                campo.value = riga.getAttribute('data-wi-option-order') || '';
            }

            document.querySelectorAll('[data-wi-option]').forEach(function (nodo) {
                var acceso = inUso(nodo);
                mostra(nodo, acceso);
                bottoniOrdine(nodo);
                trascinamento(nodo);

                // «Togli» solo sulle opzioni che l'articolo non sta usando:
                // nascondere un colore che ha già le sue righe direbbe una
                // bugia.
                if (!acceso) bottoneTogli(nodo);
            });

            scriviOrdine(ordine());

            picker.querySelector('.wi-option-choose').addEventListener('change', function () {
                var nodo = this.value === ''
                    ? null
                    : document.querySelector('[data-wi-option="' + this.value + '"]');

                if (nodo && accesi() < MASSIMO) mostra(nodo, true);

                scriviOrdine(ordine());
                aggiornaSelettore();
            });

            // Spuntare o togliere un valore può accendere o spegnere un
            // attributo: l'ordine si rifà da sé.
            document.addEventListener('change', function (ev) {
                if (ev.target && /^option_\d+(\[\])?$/.test(String(ev.target.name || ''))) {
                    scriviOrdine(ordine());
                }
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
HTML)->tag('div');
    }

    /**
     * Un attributo per blocco: solo le spunte.
     *
     * `data-wi-option` è la maniglia del selettore qui sotto, che mostra un
     * blocco solo quando l'attributo viene scelto. Gli attributi finiscono sul
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
                // La maniglia e le frecce stanno in un dodicesimo a sinistra:
                // il resto della riga sono i valori, in linea. Prima erano
                // due riquadri affiancati alti quanto mezza pagina.
                RichText::make(
                    '<div class="wi-option-grip-box d-flex align-items-center gap-1 h-100 pt-3">'
                    .'<span class="wi-option-grip text-body-secondary" title="Trascina per cambiare ordine" style="cursor:grab">'
                    .'<i class="bi bi-grip-vertical"></i></span>'
                    .'</div>'
                )->tag('div')->columnSpan(1),
                static::getInput('option_'.$id)->pills()->columnSpan(11),
            ])
                ->attr('data-wi-option', (string) $id)
                ->attr('data-wi-option-name', (string) ($attribute['name'] ?? ''))
                ->columns(12)
                ->columnSpan(12);
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

        return RichText::make(<<<HTML
<div class="wi-options-grid" data-wi-existing="{$esistenti}"></div>
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

        // L'ordine scelto nel selettore: il primo asse raggruppa, gli altri
        // compongono il nome.
        function ordineAssi() {
            var campo = document.querySelector('[name="axes_order"]');
            var valore = campo ? String(campo.value || '') : '';

            return valore.split('-').filter(function (id) { return id !== ''; });
        }

        // Un asse per attributo spuntato, nell'ordine che l'articolo ha
        // scelto; quello che l'ordine non nomina va in coda.
        function assiSpuntati() {
            var gruppi = new Map();

            document.querySelectorAll('input[type="checkbox"][name^="option_"]').forEach(function (casella) {
                if (!casella.checked) return;

                // La chiave è l'id dell'attributo, non il nome della casella:
                // il nome porta le parentesi dell'array, e l'ordine parla di
                // attributi.
                var asse = (String(casella.getAttribute('name')).match(/option_(\d+)/) || [])[1] || '';
                if (asse === '') return;

                if (!gruppi.has(asse)) gruppi.set(asse, []);

                gruppi.get(asse).push({
                    id: String(casella.value),
                    label: etichettaDi(casella),
                    attribute: asse
                });
            });

            var ordine = ordineAssi();
            var assi = [];

            ordine.forEach(function (id) {
                if (gruppi.has(id)) { assi.push(gruppi.get(id)); gruppi.delete(id); }
            });

            gruppi.forEach(function (asse) { assi.push(asse); });

            return assi;
        }

        // Come si chiama un valore. Con le pillole la casella non sta
        // dentro la sua etichetta — le sta accanto — e chiedere al genitore
        // tornava tutte le etichette dell'attributo appiccicate insieme.
        function etichettaDi(casella) {
            var etichetta = casella.id
                ? document.querySelector('label[for="' + casella.id + '"]')
                : null;

            if (!etichetta) etichetta = casella.closest('label');
            if (!etichetta) etichetta = casella.parentElement;

            return etichetta ? etichetta.textContent.trim() : '';
        }

        // Ogni valore spuntabile della pagina: da quale attributo viene e
        // come si chiama. È la stessa tabella che il server ha nel database.
        function valori() {
            var mappa = {};

            document.querySelectorAll('input[type="checkbox"][name^="option_"]').forEach(function (casella) {
                var nome = casella.getAttribute('name');
                mappa[String(casella.value)] = {
                    attribute: (nome.match(/option_(\d+)/) || [])[1] || '',
                    label: etichettaDi(casella)
                };
            });

            return mappa;
        }

        // Le righe che esistono già portano il nome calcolato con l'ordine
        // di prima: cambiandolo, il server le riscriverà al salvataggio. Qui
        // si riscrivono subito, perché è la cosa che si guarda.
        function riallinea(righe) {
            var mappa = valori();
            var assi = ordineAssi();

            righe.querySelectorAll('.wi-repeater-row').forEach(function (riga) {
                // Una riga che esiste porta la sua combinazione; una appena
                // proposta ce l'ha solo nella chiave della riga.
                var chiave = campo(riga, 'combination');
                var valore = chiave && String(chiave.value || '') !== ''
                    ? String(chiave.value)
                    : String(riga.getAttribute('data-wi-row-key') || '');
                if (valore === '') return;

                var pezzi = valore.split('-').map(function (id) { return mappa[id]; })
                    .filter(function (p) { return !!p; });
                if (!pezzi.length) return;

                var messi = [];

                assi.forEach(function (asse) {
                    pezzi.forEach(function (p) {
                        if (p.attribute === asse && messi.indexOf(p) === -1) messi.push(p);
                    });
                });

                pezzi.forEach(function (p) { if (messi.indexOf(p) === -1) messi.push(p); });

                var etichette = messi.map(function (p) { return p.label; })
                    .filter(function (l) { return l !== ''; });
                if (!etichette.length) return;

                scrivi(riga, 'group', etichette[0]);
                scrivi(riga, 'option', etichette.slice(1).join(' / ') || etichette[0]);
            });
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
            Array.prototype.slice.call(righe.querySelectorAll('.wi-repeater-row[data-wi-new="true"]:not(.wi-repeater-row-deleted)')).forEach(function (riga) {
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

                // La combinazione arriva già nell'ordine scelto: il primo
                // valore è la testata del gruppo, gli altri sono il nome.
                var gruppo = combo.length ? combo[0].label : '';
                var resto = combo.slice(1).map(function (v) { return v.label; });

                scrivi(riga, 'group', gruppo);
                scrivi(riga, 'option', resto.join(' / ') || gruppo);
                scrivi(riga, 'sku', skuProposto(base, combo));

                var sku = campo(riga, 'sku');
                if (sku) sku.addEventListener('input', function () { sku.dataset.wiTouched = 'true'; });
            });

            riallinea(righe);
            mostra(box, righe);
            raggruppa(box, righe);
        }

        // La griglia compare quando c'è qualcosa da vedere: con una riga sola e
        // nessuna spunta, il prezzo e la giacenza stanno in alto e una griglia
        // che dice la stessa cosa confonderebbe.
        function mostra(box, righe) {
            var quante = righe.querySelectorAll('.wi-repeater-row:not(.wi-repeater-row-deleted)').length;
            var spuntate = document.querySelectorAll('input[type="checkbox"][name^="option_"]:checked').length;
            var colonna = box.closest('[class*="col-"]') || box;

            colonna.classList.toggle('d-none', quante <= 1 && spuntate === 0);
        }

        // Con un attributo solo ogni gruppo conterrebbe una riga e la testata
        // ripeterebbe il nome della riga: i gruppi cominciano da due
        // attributi in su, e senza niente su cui raggruppare la griglia resta
        // piatta invece di mostrare una testata «Senza scelta».
        function raggruppa(box, righe) {
            if (typeof window.wiRepeaterGroupApply !== 'function') return;
            if (righe.getAttribute('data-wi-group-fixed') !== 'true') return;

            var assi = assiSpuntati().length;
            var conGruppo = Array.prototype.slice.call(righe.querySelectorAll('[name\$="[group]"]'))
                .some(function (campo) { return String(campo.value || '').trim() !== ''; });

            // Sulla scheda di un articolo che esiste le spunte ci sono già:
            // se le righe portano un gruppo e gli assi sono più d'uno, si
            // raggruppa.
            // Il modello delle testate, non quello delle righe: sono due
            // template diversi, e sbagliarli vuol dire nessun gruppo.
            window.wiRepeaterGroupApply(
                righe.id,
                box.id + '-group-template',
                conGruppo && assi >= 2 ? 'group' : ''
            );
        }

        // Togliere una riga vuol dire «questa non la vendo»: le spunte che la
        // tenevano in piedi, e che non servono a nessun'altra riga, si
        // spengono. Senza, la spunta rimasta la farebbe rinascere al
        // salvataggio successivo.
        // Le spunte che tengono in piedi le righe rimaste. Una riga
        // annullata non conta: le sue spunte si spengono, o al salvataggio il
        // generatore la rifarebbe nascere.
        function dopoCancellazione(righe) {
            var usati = {};

            righe.querySelectorAll('.wi-repeater-row:not(.wi-repeater-row-deleted)').forEach(function (riga) {
                var chiave = riga.querySelector('[name\$="[combination]"]');
                var valore = chiave && chiave.value !== ''
                    ? chiave.value
                    : (riga.getAttribute('data-wi-row-key') || '');

                String(valore).split('-').forEach(function (id) {
                    if (id !== '') usati[id] = true;
                });
            });

            document.querySelectorAll('input[type="checkbox"][name^="option_"]:checked').forEach(function (casella) {
                if (!usati[String(casella.value)]) casella.checked = false;
            });
        }

        // Rimettere una riga vuol dire riaccendere le spunte che la
        // descrivono: senza, resterebbe a schermo e sparirebbe al
        // salvataggio successivo.
        function dopoRipristino(riga, righe) {
            var chiave = riga.querySelector('[name\$="[combination]"]');
            var valore = chiave && chiave.value !== ''
                ? chiave.value
                : (riga.getAttribute('data-wi-row-key') || '');

            String(valore).split('-').forEach(function (id) {
                if (id === '') return;

                var casella = document.querySelector('input[type="checkbox"][value="' + id + '"][name^="option_"]');
                if (casella) casella.checked = true;
            });

            if (typeof window.wiOptionPicker === 'function') window.wiOptionPicker();
        }

        function guardaLeRighe() {
            var box = griglia();
            if (!box) return;

            var righe = document.getElementById(box.id + '-rows');
            if (!righe || righe.getAttribute('data-wi-osservato') === 'true') return;

            righe.setAttribute('data-wi-osservato', 'true');

            // Le righe non spariscono più dal DOM: il repeater dice quando
            // una viene spenta e quando torna.
            righe.addEventListener('wi-repeater-row-delete', function () {
                dopoCancellazione(righe);
                aggiorna();
            });

            righe.addEventListener('wi-repeater-row-restore', function (ev) {
                var riga = ev.target && ev.target.closest
                    ? ev.target.closest('.wi-repeater-row')
                    : null;

                if (riga) dopoRipristino(riga, righe);

                aggiorna();
            });
        }

        document.addEventListener('change', function (ev) {
            var nome = ev.target && ev.target.name ? ev.target.name : '';
            if (nome.indexOf('option_') === 0 || nome === 'sku') aggiorna();
        });

        document.addEventListener('input', function (ev) {
            if (ev.target && ev.target.name === 'sku') aggiorna();
        });

        function avvia() {
            guardaLeRighe();
            aggiorna();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', avvia);
        } else {
            avvia();
        }

        return avvia;
    })();
</script>
HTML)->tag('div');
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
            // L'area dell'articolo non ha un'etichetta sua: sta sotto il
            // titolo del riquadro, che dice già cosa contiene.
            $fields[] = FormField::key('images_'.$variantId)
                ->fileDragDrop('gallery')
                ->maxFile(static::MAX_IMAGES)
                ->label($variantId === 0 ? '' : $titolo);
        }

        return $fields;
    }

    /**
     * I nomi dei file di un'area, nell'ordine in cui si vedono.
     *
     * @return list<string>
     */
    public static function imageNames(int $modelId, int $variantId): array
    {
        $nomi = [];

        foreach (static::areaImages($modelId, $variantId) as $riga) {
            $nome = ProductImages::fileName($riga);

            if ($nome !== '') {
                $nomi[] = $nome;
            }
        }

        return $nomi;
    }

    /**
     * Le righe di un'area: l'articolo intero, oppure un colore.
     *
     * Le foto della singola opzione non sono di nessuna area — hanno il loro
     * `product_id` — e vanno escluse, o il salvataggio dell'area se le
     * porterebbe via.
     *
     * @return list<array<string, mixed>>
     */
    protected static function areaImages(int $modelId, int $variantId): array
    {
        $righe = static::rowsOf(
            ProductImage::class,
            [
                'product_model_id' => $modelId,
                'product_variant_id' => $variantId > 0 ? $variantId : null,
                'product_id' => null,
            ],
            'position'
        );

        return $righe;
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
                // Il valore del primo attributo scelto. Non si scrive: serve
                // a raggruppare, e in chiaro lo dice la testata del gruppo,
                // una volta sola invece che su ogni riga.
                RepeaterColumn::key('group')->hidden()->label(static::pageOptionName()),
                // Le spunte che tengono in piedi questa riga: togliendo la
                // riga, il browser sa quali valori non servono più.
                RepeaterColumn::key('combination')->hidden(),
                // Quello che resta del nome una volta detto il colore: "S",
                // oppure "S / Gomma" con un terzo attributo. Non è la colonna
                // `name` del database — quella la scrive il sistema — perché
                // una casella di sola lettura viene postata lo stesso, e
                // scriverebbe "S" al posto di "Blu / S".
                // Quello che si compila sempre: come si chiama, quanto
                // costa, quanti ce ne sono. Undici dodicesimi, perché il
                // dodicesimo è del cestino.
                RepeaterColumn::key('option')->text()->readonly()->label('Opzione')->columnSpan(7),
                RepeaterColumn::key('price')->number()->decimal(2)->label('Prezzo')->columnSpan(2),
                // Scrivibile finché il magazzino ha una sede sola: si scrive
                // quanti pezzi ci sono, e il pannello fa il movimento della
                // differenza. Una casella lasciata com'era non muove niente.
                // Con due sedi il numero sarebbe ambiguo — mostra il totale e
                // scriverebbe sulla principale — e la casella si legge e
                // basta.
                RepeaterColumn::key('stock')
                    ->number()
                    ->decimal(3)
                    ->label('Giacenza')
                    ->readonly(!static::stockIsWritable())
                    ->columnSpan(2),
                // Dietro «Compila le informazioni avanzate»: chi carica un
                // articolo nuovo quasi mai ha già il codice a barre in mano.
                RepeaterColumn::key('sku')->text()->label('SKU')->columnSpan(4),
                RepeaterColumn::key('ean')->text()->label('EAN')->columnSpan(4),
                RepeaterColumn::key('active')
                    ->select(['true' => 'Attivo', 'false' => 'Fermo'])
                    ->label('Stato')
                    ->columnSpan(4),
                // Un rettangolo su cui si trascina un file non si legge
                // stretto: prende la riga intera del blocco.
                RepeaterColumn::key('photo')->fileDragDrop('gallery')->label('Foto o video')->columnSpan(12),
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
            ->repeaterAdvanced('sku', 'ean', 'active', 'photo')
            ->repeaterAdvancedLabel('Compila le informazioni avanzate')
            // Eliminare un'opzione è una decisione che si rimpiange: la riga
            // resta sbiadita, con il bottone per rimetterla.
            ->repeaterUndoDelete()
            ->repeaterUndoLabel('Annulla', 'Questa opzione verrà eliminata al salvataggio.')
            ->repeaterDeleteTitle('Elimina opzione')
            ->repeaterDeleteText('Confermi l\'eliminazione di questa opzione in vendita?')
            ->repeaterDeleteCancelLabel('Annulla')
            ->repeaterDeleteConfirmLabel('Elimina')
            ->repeaterDeleteConfirmClass('btn btn-danger')
            // Il titolo lo dà il riquadro: due titoli uguali di fila si
            // leggono male.
            ->label('');

        if (static::groupsByAxis($modelId)) {
            $campo = $campo
                ->repeaterGroupFixed('group')
                ->repeaterGroupCommand('price', 'Prezzo del gruppo')
                ->repeaterGroupCountLabel('opzione', 'opzioni');
        }

        return $campo;
    }

    /**
     * Se la griglia nasce raggruppata.
     *
     * Con un attributo solo ogni gruppo conterrebbe una riga e la testata
     * ripeterebbe il nome della riga: i gruppi cominciano da due attributi in
     * su. In creazione non c'è ancora niente da guardare, quindi la testata si
     * prepara se il negozio ha almeno due attributi da spuntare, e il browser
     * spegne i gruppi finché le righe non ne hanno bisogno.
     */
    protected static function groupsByAxis(int $modelId): bool
    {
        if ($modelId <= 0) {
            return count(static::optionAttributes()) >= 2;
        }

        return count(static::axesInUse($modelId)) >= 2;
    }

    /**
     * Gli attributi che questo articolo sta davvero usando.
     *
     * @return list<int>
     */
    public static function axesInUse(int $modelId): array
    {
        $assi = [];
        $asseVariante = static::variantAttributeId();

        foreach (static::variantValues($modelId) as $valueId) {
            if ($valueId > 0 && $asseVariante > 0) {
                $assi[$asseVariante] = true;
            }
        }

        try {
            foreach (static::products($modelId) as $product) {
                foreach (ProductAttributes::read('product', (int) $product['id']) as $attributeId => $link) {
                    if ((int) ($link['attribute_value_id'] ?? 0) > 0) {
                        $assi[(int) $attributeId] = true;
                    }
                }
            }
        } catch (Throwable) {
            // Senza database (i test degli schemi) restano gli assi che si
            // leggono dalle varianti: la griglia si raggrupperà lo stesso.
        }

        return array_map('intval', array_keys($assi));
    }

    /**
     * L'ordine degli assi di questo articolo: il primo raggruppa, gli altri
     * compongono il nome.
     *
     * Un articolo che non ha ancora scelto tiene l'ordine dell'anagrafica, con
     * l'attributo che ha pagina propria davanti: è quello che faceva la
     * scheda prima che l'ordine si potesse scegliere.
     *
     * @return list<int>
     */
    public static function axesOrder(int $modelId): array
    {
        $ordine = [];

        if ($modelId > 0) {
            $rows = static::rowsOf(ProductModel::class, ['id' => $modelId]);
            $row = $rows[0] ?? null;

            foreach (explode('-', (string) (is_array($row) ? ($row['axes_order'] ?? '') : '')) as $pezzo) {
                $id = (int) trim($pezzo);

                if ($id > 0 && !in_array($id, $ordine, true)) {
                    $ordine[] = $id;
                }
            }
        }

        if ($ordine !== []) {
            return $ordine;
        }

        $asseVariante = static::variantAttributeId();

        if ($asseVariante > 0) {
            $ordine[] = $asseVariante;
        }

        foreach (static::optionAttributes() as $attribute) {
            $id = (int) $attribute['id'];

            if ($id > 0 && !in_array($id, $ordine, true)) {
                $ordine[] = $id;
            }
        }

        return $ordine;
    }

    /**
     * Se questo articolo si vende in più opzioni.
     *
     * È una colonna, non un conteggio: un articolo appena creato ha già il suo
     * prodotto figlio, e contare direbbe «no» anche a chi le opzioni le sta
     * per aggiungere. Su un articolo che ne ha già più di una la risposta è sì
     * comunque, qualunque cosa dica la colonna.
     */
    public static function hasVariants(int $modelId): bool
    {
        if ($modelId <= 0) {
            return false;
        }

        if (static::productCount($modelId) > 1) {
            return true;
        }

        $rows = static::rowsOf(ProductModel::class, ['id' => $modelId]);
        $row = $rows[0] ?? null;

        return is_array($row) && ($row['has_variants'] ?? 'false') === 'true';
    }

    /**
     * Se la giacenza si può scrivere dalla scheda.
     *
     * La casella mostra il totale di tutte le sedi e scriverebbe sulla
     * principale: con una sede sola le due cose coincidono, con due no, e
     * riscrivere il numero sposterebbe la merce da una sede all'altra senza
     * dirlo. Dalla seconda in poi si legge e si rettifica dal magazzino.
     */
    public static function stockIsWritable(): bool
    {
        return count(static::rowsOf(Location::class, ['has_stock' => 'true'])) <= 1;
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

        foreach (static::variantValues($modelId) as $variantId => $valueId) {
            $etichette[$variantId] = $valueId > 0 ? ($valori[$valueId] ?? '') : '';
        }

        return $etichette;
    }

    /**
     * Il valore d'attributo di ogni variante, per id: `[variantId => valueId]`.
     *
     * Zero per la variante scheletro, che non rappresenta nessun colore.
     *
     * @return array<int, int>
     */
    public static function variantValues(int $modelId): array
    {
        $valori = [];

        foreach (static::variants($modelId) as $variant) {
            $id = (int) $variant['id'];
            $valori[$id] = 0;

            foreach (ProductAttributes::read('variant', $id) as $link) {
                $valueId = (int) ($link['attribute_value_id'] ?? 0);

                if ($valueId > 0) {
                    $valori[$id] = $valueId;
                    break;
                }
            }
        }

        return $valori;
    }

    /**
     * Come si chiama ogni opzione in vendita, intera e in breve.
     *
     * `full` è il nome che sta sul prodotto e che il cliente legge — "Blu / S";
     * `rest` è quello che la griglia mostra sotto la testata "Blu", cioè "S".
     * Nessuno dei due si scrive a mano: nascono dai collegamenti agli
     * attributi, che sono l'unica sorgente vera.
     *
     * @return array<int, array{variant: string, rest: string, full: string, label: string, key: string}>
     */
    public static function optionLabels(int $modelId): array
    {
        $etichetteValori = static::valueLabels();
        $varianti = static::variantLabels($modelId);
        $valoriVariante = static::variantValues($modelId);
        $asseVariante = static::variantAttributeId();
        // L'ordine è quello che l'articolo ha scelto: il primo asse
        // raggruppa, gli altri compongono il nome, su tutte le righe uguale.
        $ordine = static::axesOrder($modelId);

        $nomi = [];

        foreach (static::products($modelId) as $product) {
            $id = (int) $product['id'];
            $variantId = (int) ($product['product_variant_id'] ?? 0);
            $links = ProductAttributes::read('product', $id);

            // Un'etichetta per asse, da dovunque venga: il valore con pagina
            // propria sta sulla variante, gli altri sul prodotto.
            $perAsse = [];
            $valori = [];

            $colore = $varianti[$variantId] ?? '';
            $valoreColore = $valoriVariante[$variantId] ?? 0;

            if ($asseVariante > 0 && $colore !== '') {
                $perAsse[$asseVariante] = $colore;
            }

            if ($valoreColore > 0) {
                $valori[] = $valoreColore;
            }

            foreach ($links as $attributeId => $link) {
                $attributeId = (int) $attributeId;
                $valueId = (int) ($link['attribute_value_id'] ?? 0);

                if ($valueId > 0) {
                    $valori[] = $valueId;
                }

                if ($attributeId === $asseVariante) {
                    continue;
                }

                $label = $etichetteValori[$valueId] ?? '';

                if ($label !== '') {
                    $perAsse[$attributeId] = $label;
                }
            }

            $etichette = [];

            foreach ($ordine as $asse) {
                if (($perAsse[$asse] ?? '') !== '') {
                    $etichette[] = $perAsse[$asse];
                    unset($perAsse[$asse]);
                }
            }

            // Un asse che l'ordine non nomina — un attributo aggiunto dopo —
            // va in coda: sparire dal nome sarebbe peggio che stare fuori
            // posto.
            foreach ($perAsse as $label) {
                if ($label !== '') {
                    $etichette[] = $label;
                }
            }

            $gruppo = $etichette[0] ?? '';
            $resto = array_slice($etichette, 1);

            $nomi[$id] = [
                'group' => $gruppo,
                'rest' => implode(' / ', $resto),
                // La stessa chiave che calcola il browser: da lì sa quali
                // spunte tiene in piedi questa riga.
                'key' => Combinations::clientKey(0, $valori),
                // Senza nessun attributo non c'è niente da calcolare: è
                // l'articolo venduto così com'è, e il suo nome resta quello
                // che gli ha dato chi l'ha creato.
                'full' => $etichette === [] ? '' : VersionName::from($etichette),
                'label' => $etichette === []
                    ? (string) ($product['name'] ?? '')
                    : ($resto === [] ? $gruppo : implode(' / ', $resto)),
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
        // Prezzo, scontato, giacenza ed EAN sono dell'articolo solo finché
        // non ha varianti: con le varianti vivono nelle righe. Restano
        // dichiarati sempre — e nascosti dall'interruttore — perché la
        // risposta si cambia senza ricaricare, e un campo che non esiste non
        // può comparire.
        $fields = [
            FormField::key('product_price')
                ->number()
                ->decimal(2)
                ->label('Prezzo')
                ->hiddenWhen('has_variants', 'true'),
            FormField::key('product_sale_price')
                ->number()
                ->decimal(2)
                ->label('Prezzo scontato')
                ->hiddenWhen('has_variants', 'true'),
        ];

        if ($modelId !== null && $modelId > 0) {
            // Si scrive quanti pezzi ci sono: il movimento della differenza
            // lo fa il pannello. Con più sedi il numero sarebbe ambiguo, e la
            // casella si legge e basta.
            $fields[] = FormField::key('product_stock')
                ->number()
                ->decimal(3)
                ->readonly(!static::stockIsWritable())
                ->label('Giacenza')
                ->hiddenWhen('has_variants', 'true');
        }

        $fields[] = FormField::key('product_ean')
            ->text()
            ->label('EAN')
            ->hiddenWhen('has_variants', 'true');

        return $fields;
    }

    /** Toglie dai valori tutto ciò che non è una colonna del modello. */
    protected static function withoutExtras(array $values): array
    {
        unset(
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

            if (
                str_starts_with($key, 'attribute_')
                || str_starts_with($key, 'option_')
                || str_starts_with($key, 'images_')
            ) {
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
        // La principale è una delle spuntate: se arriva vuota, o se la sua
        // spunta è stata tolta, lo diventa la prima.
        $main = static::mainAmong($chosen, (int) ($post['main_category'] ?? 0));

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

    /**
     * Il tipo fiscale: parte dal predefinito, e con un tipo solo non si vede.
     *
     * Un campo obbligatorio che quasi nessuno cambia non merita una tendina;
     * nascosto, porta comunque il suo valore.
     */
    protected static function taxCategoryField(): Input
    {
        $options = TaxCategories::options();
        $default = (string) (TaxCategories::defaultId() ?: '');

        if (count($options) <= 1) {
            return FormField::key('tax_category_id')->hidden()->value($default);
        }

        return FormField::key('tax_category_id')
            ->select($options)
            ->value($default)
            ->label('Tipo fiscale')
            ->required()
            ->quickCreate(TaxCategoryResource::class);
    }

    /**
     * La categoria principale fra quelle spuntate.
     *
     * @param list<int> $chosen
     */
    public static function mainAmong(array $chosen, int $main): int
    {
        return in_array($main, $chosen, true) ? $main : (int) ($chosen[0] ?? 0);
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
