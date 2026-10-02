<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use Closure;
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
use Wonder\App\ResourceSchema\Inputs\InputCheckbox;
use Wonder\App\ResourceSchema\Inputs\InputNumber;
use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Backend\Table\Badge\BooleanBadge;
use Wonder\Elements\Components\Accordion;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\Modal;
use Wonder\Elements\Components\QuickCreateButton;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleComponent;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleGroup;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleGroupOption;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCustomization;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelTag;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeValueResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\BrandResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\CategoryResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\PackageResource;
use Wonder\Plugin\Gestionale\Resources\Tax\TaxCategoryResource;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;
use Wonder\Plugin\Gestionale\Support\Catalog\CategoryTree;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Combinations;
use Wonder\Plugin\Gestionale\Support\Catalog\Ean;
use Wonder\Plugin\Gestionale\Support\Catalog\Generator;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Packages;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Catalog\SaleUnits;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\VersionName;
use Wonder\Plugin\Gestionale\Support\Catalog\Sku;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Purchasing\ProductSuppliers;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\App\LegacyGlobals;
use Wonder\App\Support\Repeater;
use Wonder\App\Table;
use Wonder\Plugin\Gestionale\Support\Stock\Alerts;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\LocationRows;
use Wonder\Plugin\Gestionale\Support\Stock\LocationStock;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\Stocktake;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Stock\StockHistory;
use Wonder\Plugin\Gestionale\Support\Stock\Thresholds;
use Wonder\Plugin\Gestionale\Support\Tax\TaxCategories;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Positions;
use Wonder\Sql\Transaction;
use Wonder\Support\Html\Entity;

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

    /** @var array<int, array<int, string>> i fornitori proponibili, per articolo */
    private static array $supplierChoices = [];

    /** @var array<int, array<int, list<array{supplier_id: int, supplier_sku: string, cost: ?float}>>> i fornitori legati, per articolo e per opzione */
    private static array $supplierLinks = [];

    /** @var array<int, list<int>> i fornitori proposti solo perché già legati, per articolo */
    private static array $inactiveSuppliers = [];

    /**
     * Quante foto o video per area.
     *
     * È quanto usano le schede prodotto degli store veri: oltre, la galleria
     * diventa una striscia che nessuno guarda fino in fondo.
     */
    public const MAX_IMAGES = 10;

    /** L'id del bottone «Nuova caratteristica»: lo script della scheda lo cerca. */
    protected const TECHNICAL_BUTTON = 'wi-technical-new';

    /** L'id del bottone «Nuova personalizzazione»: lo script del riquadro lo cerca. */
    public const CUSTOMIZATION_BUTTON = 'wi-customization-new';

    /**
     * L'id della finestra «Giacenza» della griglia, con più sedi (P103): il
     * bottone di ogni riga la apre, e lo script la cerca.
     */
    protected const LOCATIONS_MODAL = 'wi-location-stock';

    /**
     * L'id della finestra «Fornitori», da due fornitori in su (P109, P110):
     * la aprono i bottoni della griglia, del riquadro «Prodotto» e della
     * scheda dell'opzione, e lo script la cerca.
     */
    protected const SUPPLIERS_MODAL = 'wi-product-suppliers';

    /** La finestra delle opzioni di un gruppo di scelta (G5). */
    protected const BUNDLE_OPTIONS_MODAL = 'wi-bundle-options';

    /**
     * Quante righe ha la finestra «Fornitori», al massimo: lo stesso
     * fornitore non si scrive due volte, e un'opzione che si compra da più
     * di dieci non si è ancora vista. Chi ne ha già di più le ha tutte.
     */
    protected const SUPPLIER_ROWS = 10;

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
            // Parte dal tipo predefinito: quasi nessuno lo cambia, ma il
            // campo si vede sempre — nascosto, il giorno in cui serve non si
            // trova.
            static::taxCategoryField((int) ($modelId ?? 0)),
            // Il codice di famiglia: da lì nascono quelli delle opzioni.
            // Con le varianti accese non si scrive qui, ma il valore resta
            // nel modulo — nascosto, non tolto — e continua a proporre i
            // codici delle righe.
            FormField::key('sku')->text()->label('SKU')->hiddenWhen('has_variants', 'true'),
            // Le unità e i loro decimali stanno in un posto solo: cambiarla
            // qui cambia al volo i decimali della giacenza (unitScript).
            FormField::key('unit')->select(SaleUnits::all())->value('pz')->label('Unità di misura')->required(),
            // Due domande diverse, e devono suonare diverse: la prima dice se
            // l'articolo è finito, la seconda se si vende anche online.
            FormField::key('visible')
                ->select(['true' => 'Pubblicato', 'false' => 'Bozza'])
                ->value('true')
                ->label('Stato')
                ->required(),
            // Etichetta corta e una riga sotto che dice cosa succede da
            // spento: «Si vende online» lasciava il dubbio sul resto.
            FormField::key('visible_online')
                ->toggle()
                ->value('true')
                ->label('Acquistabile online')
                ->description('Spento, resta per il negozio e per i documenti.'),
            // Una riga sola, quella che sta sotto il nome in vetrina: 255
            // caratteri bastano, la colonna resta TEXT per quelle di prima.
            FormField::key('short_description')->text()->maxLength(255)->label('Descrizione breve'),
            // Il testo lungo si formatta: grassetti, elenchi, paragrafi. Una
            // descrizione scritta prima, senza tag, arriva a paragrafi
            // (editorHtml).
            FormField::key('description')->textarea('plus')->label('Descrizione'),
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
                ->toggle()
                ->value('true')
                ->label('Accetta resi')
                ->description('Il cliente può restituirlo dopo l\'acquisto.'),
            FormField::key('requires_shipping')
                ->toggle()
                ->value('true')
                ->label('Da spedire')
                ->description('Spento per servizi, buoni regalo e prodotti digitali.'),
        ];

        // Solo con `backorders`: senza, i due campi non esistono.
        array_push($fields, ...static::backorderFields());

        foreach (static::technicalAttributes() as $attribute) {
            $fields[] = static::technicalField($attribute);
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

        // Con più sedi giacenza e scorta minima dell'articolo senza varianti
        // si scrivono sede per sede (P102): il repeater non ha relazione, le
        // righe le compone e le legge la scheda.
        if (static::hasManyLocations()) {
            $fields[] = static::stockRowsField($modelId ?? 0);
        }

        // Da chi si compra l'articolo senza varianti: i due campi del
        // fornitore unico, o il bottone della finestra (P109). Quelli delle
        // opzioni sono colonne della griglia.
        array_push($fields, ...static::supplierInputs($modelId ?? 0, 'product_'));

        // Le personalizzazioni dell'articolo (G5): una sola lista per tutte le
        // sue varianti. Spenta la funzionalità il campo non c'è, e salvare
        // l'articolo non tocca i collegamenti.
        if (Gestionale::feature('customizations')) {
            $fields[] = FormField::key('customizations')
                ->repeater([
                    RepeaterColumn::key('id')->hidden(),
                    RepeaterColumn::key('customization_id')
                        ->select(static::customizationOptions((int) ($modelId ?? 0)))
                        ->label('Personalizzazione')
                        ->columnFill(),
                    RepeaterColumn::key('is_required')
                        ->select(['false' => 'No', 'true' => 'Sì'])
                        ->label('Obbligatoria')
                        ->columnSpan(2),
                ])
                ->relation(
                    RepeaterRelation::make(ProductModelCustomization::$table, 'product_model_id')
                        ->model(ProductModelCustomization::class)
                        ->positionKey('position')
                        ->softDelete(false)
                )
                ->nested()
                ->repeaterSortable()
                ->repeaterStartEmpty()
                ->repeaterAddLabel('Aggiungi personalizzazione')
                ->label('');
        }

        if (Gestionale::feature('bundles')) {
            array_push($fields, ...static::bundleFields((int) ($modelId ?? 0)));
        }

        return $fields;
    }

    /**
     * I campi del multiprodotto: il tipo, come si compone, i componenti e i
     * gruppi di scelta. Spenta la funzionalità non esistono, e salvare
     * l'articolo non li tocca.
     *
     * I due elenchi non hanno una relazione: le righe le compone e le legge
     * la scheda (`withBundleFormValues`), le scrive `saveBundle()`. Le
     * opzioni di un gruppo stanno in un JSON nascosto che la finestra
     * riscrive, come i fornitori.
     *
     * @return list<Input>
     */
    protected static function bundleFields(int $modelId): array
    {
        $prodotti = ['' => '—'] + static::bundleOptionProducts($modelId);

        $tipo = FormField::key('type')
            ->select(['simple' => 'Articolo singolo', 'bundle' => 'Multiprodotto'])
            ->value('simple')
            ->label('Tipo');

        // Il tipo si sceglie alla nascita: dopo, i componenti e la giacenza
        // non si scambiano da una casella.
        if ($modelId > 0) {
            $tipo = $tipo->readonly()->disabled();
        }

        return [
            $tipo,
            FormField::key('bundle_mode')
                ->select(['fixed' => 'Fissa', 'choice' => 'A scelta del cliente', 'mixed' => 'Fissa e a scelta'])
                ->value('fixed')
                ->label('Come si compone'),
            FormField::key('show_components_value')
                ->toggle()
                ->label('Mostra il valore dei componenti'),
            FormField::key('bundle_components')
                ->repeater([
                    RepeaterColumn::key('id')->hidden(),
                    RepeaterColumn::key('product_id')->select($prodotti)->label('Prodotto')->columnFill(),
                    RepeaterColumn::key('quantity')->number()->decimal(3)->label('Quantità')->columnSpan(3),
                ])
                ->nested()
                ->repeaterSortable()
                ->repeaterStartEmpty()
                ->repeaterAddLabel('Aggiungi componente')
                ->label(''),
            FormField::key('bundle_groups')
                ->repeater([
                    RepeaterColumn::key('id')->hidden(),
                    RepeaterColumn::key('name')->text()->label('Gruppo')->columnFill(),
                    RepeaterColumn::key('min')->number()->decimal(0)->label('Da')->columnSpan(1),
                    RepeaterColumn::key('max')->number()->decimal(0)->label('A')->columnSpan(1),
                    RepeaterColumn::key('options')->hidden(),
                    RepeaterColumn::key('options_button')
                        ->button('Opzioni')
                        ->opensModal(static::BUNDLE_OPTIONS_MODAL)
                        ->emptyCaption('Nessuna opzione')
                        ->columnSpan(3),
                ])
                ->nested()
                ->repeaterSortable()
                ->repeaterStartEmpty()
                ->repeaterAddLabel('Aggiungi gruppo')
                ->label(''),
        ];
    }

    /**
     * Due colonne: a sinistra quello che si compone, a destra quello che si
     * decide.
     *
     * A sinistra il lavoro lungo — nome, descrizioni, prezzo e codici, le
     * opzioni in vendita, la scheda tecnica. A destra, stretta, le foto e le
     * caselle corte che si guardano in un colpo d'occhio: come si vende e in
     * che scatola, l'IVA, dove sta nel sito, peso e misure. Erano dieci
     * riquadri a piena larghezza, uno sotto l'altro.
     *
     * La stessa scheda in creazione e in modifica. Prima la creazione era una schermata a sé, con cinque campi: si
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
        $conVarianti = static::hasVariants($modelId);
        // Un articolo che ha già più opzioni non torna indietro da un
        // interruttore: quelle righe hanno movimenti, prenotazioni e foto. Si
        // cancellano dalla griglia, una per una, dove la cancellazione lo
        // dice.
        $bloccato = static::productCount($modelId) > 1;

        $domanda = static::getInput('has_variants')->columnSpan(12);

        // Un multiprodotto non ha varianti: il suo prezzo e la sua
        // composizione bastano.
        $nascondiMulti = static fn (object $c): object => Gestionale::feature('bundles')
            ? $c->hiddenWhen('type', 'bundle')
            : $c;

        // Quello che ha già la sua condizione (le varianti) e il tipo deve
        // nasconderlo lo stesso sta in un contenitore: un elemento ha una
        // regola sola. Senza la funzionalità il contenitore non serve.
        $senzaMulti = static fn (array $componenti, int $span = 12): array => Gestionale::feature('bundles')
            ? [(new Container)->components($componenti)->columns(12)->columnSpan($span)->hiddenWhen('type', 'bundle')]
            : $componenti;

        if ($bloccato) {
            $domanda = $domanda->readonly()->disabled();
        }

        // Senza attributi da cui far nascere opzioni, «sì» porterebbe a un
        // riquadro vuoto: la domanda non si fa. Un articolo che ha già le
        // varianti la tiene, o non potrebbe più tornare indietro.
        $senzaOpzioni = !$conVarianti && static::optionAttributes() === [];

        if ($senzaOpzioni) {
            $domanda = FormField::key('has_variants')->hidden()->value('false');
        }

        $domanda = $nascondiMulti($domanda);

        // Con più sedi giacenza e scorta minima stanno nel riquadro
        // «Magazzino», sede per sede (P102): qui restano prezzo e codici.
        $sedi = static::hasManyLocations();
        // La scorta minima sta nella tendina, accanto ai codici: la riga del
        // prezzo resta di tre caselle.
        $soglia = Gestionale::feature('low_stock_alerts') && !$sedi;
        // Da chi si compra, dopo i codici (P108): due campi con un fornitore
        // solo, il bottone della finestra da due in su.
        $fornitori = static::supplierMode($modelId);
        $conFornitori = $fornitori === 'flat' || $fornitori === 'modal';
        $avanzate = match (true) {
            $conFornitori => 'SKU, EAN'.($soglia ? ', scorta minima' : '').' e fornitori',
            $soglia => 'SKU, EAN e scorta minima',
            default => 'SKU, EAN',
        };

        $cards = [
            (new Card)->components([
                SectionTitle::make('Prodotto')
                    ->tooltip($conVarianti
                        ? 'Con le varianti prezzo, codici'.($conFornitori ? ', fornitori' : '').' e giacenza sono di ogni opzione: si scrivono riga per riga in «Opzioni in vendita», qui sotto.'
                        : 'Il prezzo di questo articolo, IVA compresa: quale IVA lo dice il riquadro «Tipo fiscale». Lo SKU è anche il codice di famiglia: se aggiungi le varianti, da lì nascono quelli delle opzioni.'
                            .($modelId > 0 || $sedi ? '' : ' La giacenza scritta alla creazione entra come giacenza iniziale, nella sede principale.')
                            .' '.$avanzate.' stanno in «Compila le informazioni avanzate».'
                            .($soglia ? ' La scorta minima è la soglia sotto cui arriva l\'avviso: vale sul disponibile, e con zero non arriva niente.' : '')
                            .($conFornitori ? ' Dei fornitori si scrive il codice che usano loro e il costo d\'acquisto: un costo vuoto vuol dire «non lo so», non zero.' : '')
                            .($sedi ? ' Giacenza e scorta minima stanno nel riquadro «Magazzino», sede per sede.' : '')
                            .($senzaOpzioni ? ' Per vendere colori o taglie serve un attributo con uso «Opzione da scegliere» o «Opzione con foto proprie», e dei valori: si crea in Catalogo → Attributi.' : ''))
                    ->columnSpan(12),
                static::getInput('name')->columnSpan(Gestionale::feature('bundles') ? 6 : 9),
                ...(Gestionale::feature('bundles') ? [static::getInput('type')->columnSpan(3)] : []),
                static::getInput('visible')->columnSpan(3),
                // Niente titolo «Descrizione»: le etichette dei due campi lo
                // dicono già.
                static::getInput('short_description')->columnSpan(12),
                static::getInput('description')->columnSpan(12),
                $domanda,
                static::getInput('axes_order'),
                ...($bloccato ? [
                    RichText::make('<p class="small text-body-secondary mb-0">Questo articolo ha più opzioni in vendita: per tornare a un articolo singolo eliminale dalla griglia qui sotto.</p>')
                        ->tag('div')
                        ->columnSpan(12),
                ] : []),
                static::getInput('product_price')->columnSpan($sedi ? 6 : 4),
                static::getInput('product_sale_price')->columnSpan($sedi ? 6 : 4),
                // Senza varianti la giacenza sta qui, accanto al prezzo: è la
                // scheda di quell'unico articolo, e la parola "opzione" non
                // compare da nessuna parte. In creazione c'è già: chi crea
                // l'articolo ha la merce davanti. Con più sedi la casella non
                // c'è: i pezzi si scrivono sede per sede, nel riquadro sotto.
                // La giacenza di un multiprodotto è quella dei suoi componenti:
                // la casella sta in un contenitore che il tipo nasconde,
                // perché la sua condizione, quella delle varianti, è già
                // sua.
                ...($sedi ? [] : (Gestionale::feature('bundles')
                    ? $senzaMulti([static::getInput('product_stock')->columnSpan(12)], 4)
                    : [static::getInput('product_stock')->columnSpan(4)])),
                // Codici, scorta minima e fornitori sotto il prezzo, chiusi
                // come le informazioni avanzate delle righe della griglia:
                // servono di rado. Con le varianti spariscono insieme al
                // prezzo — ognuna ha i suoi nella griglia — ma lo SKU resta
                // nel modulo, solo nascosto, e continua a proporre quelli
                // delle righe.
                ...$senzaMulti([Accordion::make('Compila le informazioni avanzate')
                    ->link()
                    ->columns(12)
                    ->columnSpan(12)
                    ->hiddenWhen('has_variants', 'true')
                    ->components([
                        static::getInput('sku')->columnSpan($soglia ? 4 : 6),
                        static::getInput('product_ean')->columnSpan($soglia ? 4 : 6),
                        ...($soglia ? [static::getInput('product_min_stock')->columnSpan(4)] : []),
                        ...($fornitori === 'flat' ? [
                            static::getInput('product_supplier_sku')->columnSpan(6),
                            static::getInput('product_supplier_cost')->columnSpan(6),
                        ] : []),
                        ...($fornitori === 'modal' ? [
                            static::getInput('product_suppliers'),
                            static::getInput('product_suppliers_button')->columnSpan(12),
                        ] : []),
                    ])]),
                // In creazione non c'è ancora niente da rettificare; con più
                // sedi il link sta nel riquadro «Magazzino».
                ...($modelId > 0 && !$sedi ? $senzaMulti([
                    RichText::make(static::adjustLink($modelId))
                        ->columnSpan(12)
                        ->hiddenWhen('has_variants', 'true'),
                ]) : []),
            ])->columns(12)->columnSpan(12),
            // Con più sedi le righe per sede, subito dopo i codici (P102).
            ...($sedi ? $senzaMulti([static::stockCard($modelId)]) : []),
            // La composizione di un multiprodotto, al posto di giacenza e
            // opzioni: due riquadri che il tipo apre e chiude.
            ...(Gestionale::feature('bundles') ? [static::bundleCard()] : []),
            // Subito sotto la domanda «ha varianti?»: chi risponde sì trova
            // qui le opzioni. In due terzi di
            // schermo la griglia ci sta: le righe raggruppate hanno tre
            // caselle, il resto si apre con «informazioni avanzate».
            ...static::optionsCard($modelId),
        ];

        $cards[] = static::technicalSheetCard();

        if (Gestionale::feature('customizations')) {
            $cards[] = static::customizationsCard();
        }

        // La finestra «Giacenza» della griglia (P103) e il suo script: una
        // sola per la pagina, qui e non nel riquadro delle opzioni, che senza
        // varianti sparisce — e la griglia c'è sempre.
        if ($sedi) {
            $cards[] = static::locationStockModal($modelId);
            $cards[] = static::locationStockScript();
        }

        // Lo stesso per la finestra «Fornitori» (P110): la aprono le righe
        // della griglia e il riquadro «Prodotto».
        if ($fornitori === 'modal') {
            $cards[] = static::suppliersModal($modelId);
            $cards[] = static::suppliersScript($modelId);
        }

        // La finestra delle opzioni di un gruppo: una per la pagina.
        if (Gestionale::feature('bundles')) {
            $cards[] = static::bundleOptionsModal();
            $cards[] = static::bundleOptionsScript($modelId);
        }

        return $cards;
    }

    /**
     * Il riquadro «Magazzino» dell'articolo senza varianti, con più sedi
     * (P102): una riga per sede, con i pezzi e la scorta minima di quella.
     *
     * Sta al posto delle due caselle del riquadro «Prodotto»: con due sedi il
     * totale non dice dove stanno i pezzi, e una soglia sola non dice quando
     * avvisare. Con le varianti sparisce insieme al prezzo: ogni opzione ha le
     * sue sedi nella griglia, dietro il bottone «Giacenza».
     */
    /**
     * Il riquadro «Composizione»: la modalità, il valore dei componenti e
     * i due elenchi, ognuno aperto dalla modalità che lo vuole.
     */
    protected static function bundleCard(): object
    {
        return (new Card)->components([
            SectionTitle::make('Composizione')
                ->tooltip('Fissa: i pezzi che stanno sempre nella confezione, con la quantità di ognuno. A scelta: gruppi di prodotti da cui chi compra sceglie «da» «a» quanti; ogni opzione può avere un sovrapprezzo. Fissa e a scelta le fa tutte e due. Il prezzo qui sopra è quello della confezione; la giacenza è quella dei componenti, e la confezione ne vale quante ne reggono.')
                ->columnSpan(12),
            static::getInput('bundle_mode')->columnSpan(8),
            static::getInput('show_components_value')->columnSpan(4),
            (new Container)->columns(12)->columnSpan(12)->visibleWhen('bundle_mode', ['fixed', 'mixed'])->components([
                SectionTitle::make('Componenti fissi')->columnSpan(12),
                static::getInput('bundle_components')->columnSpan(12),
            ]),
            (new Container)->columns(12)->columnSpan(12)->visibleWhen('bundle_mode', ['choice', 'mixed'])->components([
                SectionTitle::make('Gruppi di scelta')
                    ->tooltip('«Da» e «A» sono quante opzioni chi compra deve scegliere, al meno e al più: «A» non può superare le opzioni del gruppo.')
                    ->columnSpan(12),
                static::getInput('bundle_groups')->columnSpan(12),
            ]),
        ])->columns(12)->columnSpan(12)->visibleWhen('type', 'bundle');
    }

    /**
     * La finestra delle opzioni di un gruppo: una riga per prodotto, con il
     * suo sovrapprezzo. Le righe le disegna lo script (`bundleOptionsScript`)
     * dal JSON del gruppo che l'ha aperta; non hanno `name`, quindi il form
     * non le manda: quello che conta è il JSON.
     */
    protected static function bundleOptionsModal(): Modal
    {
        return Modal::make('Opzioni del gruppo')
            ->id(static::BUNDLE_OPTIONS_MODAL)
            ->size('lg')
            ->columns(12)
            ->components([
                RichText::make(
                    '<div class="alert alert-danger small" data-wi-bundle-error role="alert" hidden></div>'
                    .'<div class="row g-2 small text-body-secondary mb-1"><div class="col-8">Prodotto</div><div class="col-3">Sovrapprezzo</div><div class="col-1"></div></div>'
                    .'<div data-wi-bundle-rows></div>'
                )->tag('div')->columnSpan(12),
            ])
            ->footer([
                Button::make('Aggiungi opzione')->variant('secondary')->outline()->attr('data-wi-bundle-add', 'true'),
                Button::make('Annulla')->variant('secondary')->attr('data-bs-dismiss', 'modal'),
                Button::make('Salva')->attr('data-wi-bundle-save', 'true'),
            ]);
    }

    /**
     * Il codice della finestra delle opzioni.
     *
     * All'apertura legge il JSON del gruppo che l'ha aperta e disegna una
     * riga per opzione; «Salva» riscrive il JSON nel campo nascosto di quel
     * gruppo — con gli id che c'erano, per non perdere le opzioni già
     * comprate — e il riassunto accanto al bottone. I controlli veri li fa il
     * server al salvataggio della scheda; qui solo i prodotti doppi o
     * mancanti, che sono quelli che una persona si accorge subito di aver
     * scritto male.
     */
    protected static function bundleOptionsScript(int $modelId): RichText
    {
        $voci = [];

        foreach (static::bundleOptionProducts($modelId) as $id => $nome) {
            $voci[] = ['id' => (int) $id, 'name' => $nome];
        }

        $messaggi = [
            'missing' => UserError::make('bundle.component_unavailable', ['name' => '—'])->getMessage(),
            'duplicate' => UserError::make('bundle.duplicate_product')->getMessage(),
        ];

        $json = static fn (array $valore): string => static::escape(json_encode(
            $valore,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ));

        return RichText::make(
            '<div class="wi-bundle-options"'
            .' data-wi-bundle-products="'.$json($voci).'"'
            .' data-wi-bundle-messages="'.$json($messaggi).'"></div>'
            .<<<'HTML'
<script>
    (function () {
        if (window.wiBundleOptionsReady) {
            return;
        }

        window.wiBundleOptionsReady = true;

        var FINESTRA = 'wi-bundle-options';
        // Il campo nascosto e il bottone del gruppo che ha aperto la finestra.
        var aperta = null;

        function dato(nome, vuoto) {
            var radice = document.querySelector('.wi-bundle-options');

            try {
                var valore = JSON.parse(radice ? radice.getAttribute(nome) || '' : '');

                return valore === null || valore === undefined ? vuoto : valore;
            } catch (errore) {
                return vuoto;
            }
        }

        function testo(valore) {
            var nodo = document.createElement('span');

            nodo.textContent = String(valore);

            return nodo.innerHTML;
        }

        function righe(finestra) {
            return Array.prototype.slice.call(finestra.querySelectorAll('[data-wi-bundle-line]'));
        }

        function aggiungi(finestra, voce) {
            var prodotti = dato('data-wi-bundle-products', []);
            var elenco = '<option value="">—</option>' + prodotti.map(function (prodotto) {
                var scelto = voce && String(voce.product_id) === String(prodotto.id) ? ' selected' : '';

                return '<option value="' + prodotto.id + '"' + scelto + '>' + testo(prodotto.name) + '</option>';
            }).join('');
            var sovrapprezzo = voce && voce.surcharge !== undefined && voce.surcharge !== '' ? String(voce.surcharge).replace('.', ',') : '';
            var linea = document.createElement('div');

            linea.className = 'row g-2 mb-2 align-items-center';
            linea.setAttribute('data-wi-bundle-line', voce && voce.id ? String(voce.id) : '0');
            linea.innerHTML = '<div class="col-8"><select class="form-select form-select-sm" data-wi-bundle-product>' + elenco + '</select></div>'
                + '<div class="col-3"><input type="text" inputmode="decimal" class="form-control form-control-sm" data-wi-bundle-surcharge value="' + testo(sovrapprezzo) + '"></div>'
                + '<div class="col-1"><button type="button" class="btn btn-sm btn-outline-secondary" data-wi-bundle-remove title="Togli l\'opzione" aria-label="Togli l\'opzione"><i class="bi bi-x-lg"></i></button></div>';

            finestra.querySelector('[data-wi-bundle-rows]').appendChild(linea);
        }

        function avviso(finestra, frase) {
            var posto = finestra.querySelector('[data-wi-bundle-error]');

            if (posto) {
                posto.textContent = frase;
                posto.hidden = frase === '';
            }
        }

        function leggi(finestra) {
            var elenco = [];
            var visti = {};
            var errore = '';
            var testi = dato('data-wi-bundle-messages', {});

            righe(finestra).forEach(function (linea) {
                var prodotto = linea.querySelector('[data-wi-bundle-product]').value;
                var valore = linea.querySelector('[data-wi-bundle-surcharge]').value.replace(/[^0-9.,-]/g, '');

                if (valore.indexOf(',') !== -1) {
                    valore = valore.replace(/\./g, '').replace(',', '.');
                }

                if (prodotto === '') {
                    errore = errore || String(testi.missing || 'Scegli il prodotto di ogni riga.');

                    return;
                }

                if (visti[prodotto]) {
                    errore = errore || String(testi.duplicate || 'Lo stesso prodotto non si scrive due volte.');

                    return;
                }

                visti[prodotto] = true;
                elenco.push({
                    id: parseInt(linea.getAttribute('data-wi-bundle-line'), 10) || 0,
                    product_id: parseInt(prodotto, 10),
                    surcharge: valore === '' || isNaN(Number(valore)) ? '0.00' : Number(valore).toFixed(2)
                });
            });

            return { elenco: elenco, errore: errore };
        }

        function riassunto(quante) {
            return quante === 0 ? '' : (quante === 1 ? '1 opzione' : quante + ' opzioni');
        }

        function scrivi(campo, bottone, elenco) {
            campo.value = JSON.stringify(elenco);
            campo.dispatchEvent(new Event('change', { bubbles: true }));

            var posto = bottone && bottone.parentElement ? bottone.parentElement.querySelector('[data-wi-button-caption]') : null;

            if (posto) {
                posto.textContent = riassunto(elenco.length) || 'Nessuna opzione';
            }
        }

        document.addEventListener('show.bs.modal', function (evento) {
            var finestra = evento.target;
            var bottone = evento.relatedTarget || null;

            if (!finestra || finestra.id !== FINESTRA) {
                return;
            }

            var riga = bottone && bottone.closest ? bottone.closest('.wi-repeater-row') : null;
            var campo = riga ? riga.querySelector('input[name$="[options]"]') : null;
            var nome = riga ? riga.querySelector('input[name$="[name]"]') : null;
            var titolo = finestra.querySelector('[data-wi-modal-title]');
            var presenti = [];

            aperta = campo ? { bottone: bottone, campo: campo } : null;

            try {
                presenti = JSON.parse(campo && campo.value ? campo.value : '[]') || [];
            } catch (errore) {
                presenti = [];
            }

            if (titolo) {
                titolo.textContent = nome && nome.value.trim() !== '' ? 'Opzioni · ' + nome.value.trim() : 'Opzioni del gruppo';
            }

            finestra.querySelector('[data-wi-bundle-rows]').innerHTML = '';
            avviso(finestra, '');
            (Array.isArray(presenti) && presenti.length > 0 ? presenti : [null]).forEach(function (voce) {
                aggiungi(finestra, voce);
            });
        });

        document.addEventListener('click', function (evento) {
            var bersaglio = evento.target && evento.target.closest ? evento.target : null;

            if (!bersaglio) {
                return;
            }

            var finestra = bersaglio.closest('#' + FINESTRA);

            if (!finestra) {
                return;
            }

            if (bersaglio.closest('[data-wi-bundle-add]')) {
                evento.preventDefault();
                aggiungi(finestra, null);

                return;
            }

            var togli = bersaglio.closest('[data-wi-bundle-remove]');

            if (togli) {
                evento.preventDefault();
                togli.closest('[data-wi-bundle-line]').remove();

                return;
            }

            if (!bersaglio.closest('[data-wi-bundle-save]')) {
                return;
            }

            evento.preventDefault();

            var letto = leggi(finestra);

            if (letto.errore !== '' || !aperta) {
                avviso(finestra, letto.errore);

                return;
            }

            scrivi(aperta.campo, aperta.bottone, letto.elenco);

            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(finestra).hide();
            }
        });
    })();
</script>
HTML
        )->tag('div');
    }

    protected static function stockCard(int $modelId): Card
    {
        $soglia = Gestionale::feature('low_stock_alerts');

        return (new Card)->components([
            SectionTitle::make('Magazzino')
                ->tooltip('Quanti pezzi ci sono in ogni sede'.($soglia ? ', e sotto quanti arriva l\'avviso' : '').'.'
                    .' Si scrive quanti ce ne sono, e il movimento della differenza lo fa il magazzino: casella vuota = non toccare, zero scritto = zero.'
                    .($modelId > 0 ? '' : ' Su un articolo che nasce i pezzi entrano come giacenza iniziale.')
                    .' Una sede tolta dalle righe perde la sua scorta minima, non i pezzi: finché ne ha, la riga ricompare.')
                ->columnSpan(12),
            static::getInput('locations')->columnSpan(12),
            ...($modelId > 0 ? [RichText::make(static::adjustLink($modelId))->columnSpan(12)] : []),
        ])->columns(12)->columnSpan(12)->hiddenWhen('has_variants', 'true');
    }

    /**
     * Il riquadro delle opzioni in vendita: nella colonna larga, subito sotto
     * «Prodotto».
     *
     * Dentro c'è tutto quello che riguarda il "in quante versioni lo vendo":
     * gli attributi con i valori da spuntare, il bottone che ne aggiunge un
     * altro e la griglia.
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
                    ->tooltip('Aggiungi un attributo — colore, taglia, gusto — e spunta i valori: le righe compaiono qui sotto e nascono al salvataggio, con codice, prezzo, giacenza e foto. Togliere una spunta non cancella niente che esista già. Nell\'elenco ci sono gli attributi visibili, con uso «Opzione da scegliere» o «Opzione con foto proprie», di tipo Elenco, Colore, Fantasia o Icona, e con almeno un valore: si sistemano in Catalogo → Attributi.')
                    ->columnSpan(12),
                // I blocchi stanno in un contenitore loro: il riordino li
                // sposta con `order`, e dentro un riquadro condiviso
                // scavalcherebbero la griglia e il bottone.
                (new Container)->components($blocchi)->columns(12)->columnSpan(12),
                // Il bottone sta sotto l'ultimo attributo, largo quanto il
                // riquadro: l'attributo nuovo compare proprio lì sopra.
                static::optionsPicker($modelId)->columnSpan(12),
                static::getInput('products')->columnSpan(12),
                static::optionsGridScript($modelId)->columnSpan(12),
            ])->columns(12)->columnSpan(12)
                // Il riquadro intero risponde alla domanda in cima alla
                // scheda: con «no» non c'è niente da scegliere.
                ->visibleWhen('has_variants', 'true'),
        ];
    }

    /**
     * La colonna stretta: le foto e quello che si decide.
     *
     * @return list<object>
     */
    protected static function sideColumn(int $modelId): array
    {
        $foto = [
            SectionTitle::make('Foto e video')
                ->tooltip('Le foto e i video caricati qui compaiono in tutte le opzioni dell\'articolo; quelli sotto un colore solo nelle opzioni di quel colore.')
                ->columnSpan(12),
        ];

        foreach (array_keys(static::imageTargets($modelId)) as $variantId) {
            $foto[] = static::getInput('images_'.$variantId)->columnSpan(12);
        }

        return [
            (new Card)->components($foto)->columns(12)->columnSpan(12),

            // Gli interruttori uno sotto l'altro, ognuno con la sua riga che
            // dice cosa cambia: affiancati, le spiegazioni andavano a capo
            // dopo due parole. Con `backorders` se ne aggiunge un quarto.
            (new Card)->components([
                SectionTitle::make('Come si vende')
                    ->tooltip('Una bozza non si vede da nessuna parte, nemmeno se è acquistabile online: lo stato si sceglie accanto al nome. La scatola in cui parte si sceglie sotto «Da spedire»; le misure del prodotto, in fondo, servono a sapere se ci sta.')
                    ->columnSpan(12),
                static::getInput('visible_online')->columnSpan(12),
                static::getInput('returnable')->columnSpan(12),
                static::getInput('requires_shipping')->columnSpan(12),
                // La scatola segue l'interruttore sopra, senza ricaricare:
                // un articolo che non si spedisce non ha niente da dire qui.
                static::getInput('package_id')
                    ->visibleWhen('requires_shipping', 'true')
                    ->columnSpan(12),
                ...static::backorderInputs(),
            ])->columns(12)->columnSpan(12),

            // Un riquadro solo per il tipo fiscale: decide l'IVA, e in mezzo
            // agli interruttori si perdeva. Il titolo dice già cos'è, la
            // select non lo ripete.
            (new Card)->components([
                SectionTitle::make('Tipo fiscale')
                    ->tooltip('Decide l\'IVA dell\'articolo. Un articolo nuovo parte dal tipo predefinito; i tipi si gestiscono in Set Up → IVA → Tipi fiscali.')
                    ->columnSpan(12),
                // Un'etichetta corta anche qui: la select è «floating» e senza
                // etichetta mostrerebbe solo l'asterisco dell'obbligatorio.
                static::getInput('tax_category_id')->label('IVA')->columnSpan(12),
            ])->columns(12)->columnSpan(12),

            (new Card)->components([
                SectionTitle::make('Dove si trova')
                    ->tooltip('Spunta tutte le categorie in cui deve comparire. La stella indica quella che compare nel percorso sopra la pagina (Home › Abbigliamento › Magliette): di solito la più precisa.')
                    ->columnSpan(12),
                static::getInput('brand_id')->columnSpan(12),
                static::getInput('tags')->columnSpan(12),
                static::getInput('categories')->columnSpan(12),
                static::getInput('main_category'),
            ])->columns(12)->columnSpan(12),

            // Le misure sono del prodotto, non della spedizione: stavano in
            // «Spedizione» e sparivano con l'articolo che non si spedisce,
            // portandosi via anche l'unità di misura, che è obbligatoria. In
            // un terzo di schermo due per riga: quattro affiancate non si
            // leggono.
            (new Card)->components([
                SectionTitle::make('Misure')
                    ->tooltip('Le dimensioni vere del prodotto, quelle che servono a sapere se sta in una scatola. Un\'opzione con misure sue le usa al posto di queste.')
                    ->columnSpan(12),
                static::getInput('unit')->columnSpan(6),
                static::getInput('weight')->columnSpan(6),
                static::getInput('length')->columnSpan(6),
                static::getInput('width')->columnSpan(6),
                static::getInput('height')->columnSpan(6),
                static::getInput('circumference')->columnSpan(6),
                static::unitScript()->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('photo')
                ->image()
                ->size('little')
                ->formatter(static fn (array $row): string => static::firstImage((int) ($row['id'] ?? 0)))
                ->link('view'),
            TableColumn::key('name')
                ->text()
                ->formatter(static fn (array $row): string => static::nameCell($row))
                ->link('view'),
            TableColumn::key('sku')->text(),
            TableColumn::key('price')
                ->text()
                ->formatter(static fn (array $row): string => static::priceCell((int) ($row['id'] ?? 0))),
            TableColumn::key('versions')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => (string) static::productCount((int) ($row['id'] ?? 0))),
            TableColumn::key('visible')->visibleBadge()->size('little'),
            TableColumn::key('actions')->button()->actions(['view', 'edit', 'visible', 'delete']),
        ];
    }

    /** Il nome nell'elenco, col badge dei multiprodotti accanto. */
    public static function nameCell(array $row): string
    {
        $nome = static::escapeStored((string) ($row['name'] ?? ''));
        $badge = static::bundleBadge((int) ($row['id'] ?? 0));

        return $badge === '' ? $nome : $nome.' '.$badge;
    }

    /** «Multiprodotto» per un articolo composto, niente per un semplice. */
    public static function bundleBadge(int $modelId): string
    {
        return static::isBundleModel($modelId)
            ? '<span class="badge text-bg-light">'.static::escape('Multiprodotto').'</span>'
            : '';
    }

    /**
     * Il prezzo dell'articolo, come lo legge chi scorre l'elenco (P126).
     *
     * Conta quello che si paga: dove c'è lo sconto vale lo scontato. Un
     * prezzo solo quando le opzioni costano uguale, «da 19,90 €» quando no —
     * scrivere il minimo e basta farebbe credere che costino tutte così — e
     * il pieno barrato accanto allo scontato, che è l'informazione che conta
     * scorrendo un listino.
     */
    public static function priceCell(int $modelId): string
    {
        $daPagare = [];
        $pieno = 0.0;

        foreach (static::products($modelId) as $product) {
            $intero = (float) ($product['price'] ?? 0);
            $sconto = (float) ($product['sale_price'] ?? 0);
            $prezzo = $sconto > 0 ? $sconto : $intero;

            if ($prezzo > 0) {
                $daPagare[] = $prezzo;
                $pieno = max($pieno, $intero);
            }
        }

        if ($daPagare === []) {
            return '';
        }

        $minimo = min($daPagare);

        if ($minimo !== max($daPagare)) {
            return 'da '.static::euro($minimo);
        }

        return $pieno > $minimo
            ? '<s>'.static::euro($pieno).'</s> '.static::euro($minimo)
            : static::euro($minimo);
    }

    /** Un prezzo come si scrive in italiano: `19,90 €`. */
    protected static function euro(float $prezzo): string
    {
        return number_format($prezzo, 2, ',', '.').' €';
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
        return parent::pageSchema()
            // La scheda si apre in lettura (P123): guardare un articolo non
            // deve voler dire aprirne il cantiere. La modifica resta dov'era,
            // dietro al bottone «Modifica».
            ->enable(['view'])
            ->titles([
                'list' => 'Prodotti',
                'create' => 'Nuovo prodotto',
                'view' => 'Scheda prodotto',
                'edit' => 'Modifica prodotto',
            ])
            ->view('show', Gestionale::viewPath('pages/product-model-show.php'))
            // Giallo e piccolo: è l'unico bottone che porta fuori dalla
            // lettura, e in testata sta accanto alla guida, che è della
            // stessa misura.
            ->actions('view', static fn (array $item): array => [[
                'label' => 'Modifica',
                'icon' => 'bi-pencil',
                'class' => 'btn-warning btn-sm',
                'href' => static::editUrlFor((int) ($item['id'] ?? 0)),
            ]])
            // Appena creato si atterra sulla sua scheda: la creazione chiede
            // quattro cose e il resto si scrive lì, non ritrovando la riga in
            // un elenco. Serve `wonder-image/app` con redirectUrl($action,$id).
            ->redirect('store', 'edit');
    }

    /** L'indirizzo della modifica di questo articolo. */
    public static function editUrlFor(int $modelId): string
    {
        $fallback = '/backend/'.static::path().'/'.$modelId.'/edit/';

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.static::slug().'.edit', ['id' => $modelId]);
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }

    /**
     * La scheda in lettura (P123).
     *
     * Stesso disegno della modifica — due colonne, otto e quattro — perché è
     * la stessa scheda vista dall'altra parte: chi arriva dall'elenco ritrova
     * i riquadri dov'erano. Sotto la colonna stretta resta spazio libero: lì
     * andranno le statistiche dell'articolo, quando ci saranno gli ordini.
     */
    public static function showLayoutSchema(array $item): Container
    {
        $modelId = (int) ($item['id'] ?? 0);

        return (new Container)->components([
            (new Container)->components(static::showMainColumn($modelId, $item))->columns(12)->columnSpan(8),
            (new Container)->components(static::showSideColumn($modelId, $item))->columns(12)->columnSpan(4),
        ])->columns(12);
    }

    /**
     * La colonna larga in lettura: cos'è e cosa si vende.
     *
     * @return list<object>
     */
    protected static function showMainColumn(int $modelId, array $item): array
    {
        $nome = trim((string) ($item['name'] ?? ''));
        $opzioni = static::productCount($modelId);

        return [
            (new Card)->components([
                SectionTitle::make('Prodotto')->columnSpan(12),
                RichText::make('<h5 class="mb-0">'.static::escape($nome !== '' ? $nome : 'Senza nome').'</h5>')
                    ->tag('div')
                    ->columnSpan(12),
                static::showRow('SKU', (string) ($item['sku'] ?? ''))->columnSpan(4),
                // Il prezzo è già scritto come lo legge l'elenco: scontato,
                // barrato, «da» quando le opzioni costano diverso.
                static::showRow('Prezzo', static::priceCell($modelId), true)->columnSpan(4),
                static::showRow('Opzioni', $opzioni > 0 ? (string) $opzioni : '')->columnSpan(4),
                static::showRow('Descrizione breve', (string) ($item['short_description'] ?? ''))->columnSpan(12),
            ])->columns(12)->columnSpan(12),
            (new Card)->components([
                SectionTitle::make('Opzioni in vendita')
                    ->tooltip('Le righe che si vendono davvero, con il loro codice e il loro prezzo. Lo stato si cambia da qui con un click; il resto dai tre puntini, nella scheda dell\'opzione.')
                    ->columnSpan(9),
                // La guida sta in riga col titolo, non sotto: prima la
                // portava la tabella incorporata e apriva il riquadro con
                // una fascia vuota.
                RichText::make(static::optionsDocsButton())->tag('div')->columnSpan(3),
                RichText::make(static::optionsTable($modelId))->tag('div')->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ];
    }

    /**
     * La colonna stretta in lettura: la foto, lo stato, dove sta.
     *
     * @return list<object>
     */
    protected static function showSideColumn(int $modelId, array $item): array
    {
        $foto = static::firstImage($modelId);
        $marchio = (string) (static::brandOptions()[(string) ($item['brand_id'] ?? '')] ?? '');
        $principale = static::mainCategoryId($modelId);
        $altre = array_values(array_filter(
            static::categoryIds($modelId),
            static fn (int $id): bool => $id !== $principale
        ));

        return [
            (new Card)->components([
                SectionTitle::make('Foto e video')->columnSpan(12),
                RichText::make($foto !== ''
                    ? '<img src="'.static::escape($foto).'" alt="'.static::escape((string) ($item['name'] ?? '')).'" class="img-fluid rounded">'
                    : '<p class="text-muted mb-0">Nessuna foto: si caricano dalla modifica.</p>')
                    ->tag('div')
                    ->columnSpan(12),
            ])->columns(12)->columnSpan(12),
            (new Card)->components([
                SectionTitle::make('Stato')
                    ->tooltip('«Pubblicato» vuol dire che l\'articolo si vede nel negozio. Si cambia da qui, con un click.')
                    ->columnSpan(12),
                RichText::make(static::statusBadge($item))->tag('div')->columnSpan(12),
            ])->columns(12)->columnSpan(12),
            (new Card)->components([
                SectionTitle::make('Dove si trova')
                    ->tooltip('Marchio, categorie e tag si cambiano dalla modifica.')
                    ->columnSpan(12),
                static::showRow('Marchio', $marchio === 'Nessuno' ? '' : $marchio)->columnSpan(12),
                static::showRow('Categoria principale', static::categoryNames([$principale]))->columnSpan(12),
                static::showRow('Altre categorie', static::categoryNames($altre))->columnSpan(12),
                static::showRow('Tag', static::tagNames($modelId))->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ];
    }

    /**
     * Una riga della scheda in lettura: l'etichetta piccola, sotto il valore.
     *
     * Quello che non c'è si dice con un trattino, non con il vuoto: una
     * casella bianca lascia il dubbio che manchi la pagina, non il dato.
     */
    protected static function showRow(string $etichetta, string $valore, bool $html = false): RichText
    {
        $valore = trim($valore) !== ''
            ? ($html ? $valore : static::escape($valore))
            : '<span class="text-muted">—</span>';

        return RichText::make(
            '<div class="small text-muted">'.static::escape($etichetta).'</div>'
            .'<div>'.$valore.'</div>'
        )->tag('div')->columnSpan(12);
    }

    /**
     * Le colonne delle opzioni nella scheda in lettura (P124).
     *
     * Sono quelle che `ProductResource` dichiara per il suo elenco, scelte:
     * la colonna del modello ripeterebbe il titolo della pagina.
     *
     * @return list<string>
     */
    public static function optionsColumns(): array
    {
        return ['name', 'sku', 'price', 'active', 'actions'];
    }

    /**
     * Le opzioni di questo articolo (P124, P128).
     *
     * Nasce dalle colonne che `ProductResource` dichiara, ristretta
     * all'articolo: etichette, prezzi e la pillola dello stato si scrivono
     * una volta sola, di là. Titolo e filtri restano spenti — il riquadro ha
     * già il suo titolo, e fra due taglie non c'è niente da cercare.
     *
     * Senza database la tabella non nasce: resta la frase, e la scheda si
     * legge lo stesso.
     */
    public static function optionsTable(int $modelId): string
    {
        $vuoto = '<p class="text-muted mb-0">Nessuna opzione: questo articolo non ha ancora niente da vendere.</p>';

        if ($modelId <= 0) {
            return $vuoto;
        }

        try {
            // Senza il bottone «Guida»: in questa scheda ce l'ha già il
            // titolo del riquadro, e porterebbe alla stessa pagina.
            $tabella = ProductResource::backendTable(
                static::optionsColumns(),
                ProductResource::tableLayoutSchema()->docs(false)
            );
            $tabella->title(false);
            $tabella->titleNResult(false);
            $tabella->filterSearch(false);
            $tabella->filterDate(false);
            $tabella->filterLimit(false);
            $tabella->filterCustom(false);
            $tabella->query('`product_model_id` = '.$modelId." AND `deleted` = 'false'");
            $tabella->queryOrder('id', 'ASC');

            $html = (string) $tabella->generate(false);
        } catch (Throwable) {
            $html = '';
        }

        return trim($html) !== '' ? $html : $vuoto;
    }

    /**
     * Il bottone «Guida» del riquadro delle opzioni.
     *
     * È lo stesso dell'intestazione di un elenco — stessa pagina, stesso
     * colore, stessa misura — ma qui lo mette il riquadro, in riga col suo
     * titolo, e non la tabella che ci sta dentro.
     */
    public static function optionsDocsButton(): string
    {
        $url = ProductResource::pageSchema()->docsUrl('list');

        if ($url === '') {
            return '';
        }

        return '<div class="text-end"><a class="btn btn-info btn-sm" href="'.static::escape($url)
            .'" target="_blank" rel="noopener"><i class="bi bi-question-circle"></i> Guida</a></div>';
    }

    /**
     * La pillola dello stato dell'articolo, che si commuta con un click (P124).
     *
     * Fuori da una tabella non c'è nessun elenco da ricaricare: `ajaxRequest`
     * con il solo indirizzo ricarica la pagina, ed è quello che serve.
     */
    public static function statusBadge(array $item): string
    {
        $modelId = (int) ($item['id'] ?? 0);

        $pillola = BooleanBadge::make((string) ($item['visible'] ?? 'false'))
            ->on('Pubblicato', 'bi bi-eye', 'success', 'Metti in bozza')
            ->off('Bozza', 'bi bi-eye-slash', 'secondary', 'Pubblica');

        if ($modelId <= 0) {
            return $pillola->badge();
        }

        $url = static::booleanToggleUrl(ProductModel::$table, 'visible', $modelId);

        return $pillola->action('onclick="ajaxRequest(\''.$url.'\')"')->clickable()->badge();
    }

    /** L'indirizzo che commuta una colonna sì/no di una riga. */
    protected static function booleanToggleUrl(string $table, string $column, int $id): string
    {
        try {
            $path = LegacyGlobals::get('PATH');
            $api = is_object($path) ? (string) ($path->api ?? '') : '';
        } catch (Throwable) {
            $api = '';
        }

        return $api.'/backend/change/boolean/?table='.$table.'&column='.$column.'&id='.$id;
    }

    /**
     * I nomi di queste categorie, nell'ordine in cui arrivano.
     *
     * @param list<int> $ids
     */
    protected static function categoryNames(array $ids): string
    {
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return '';
        }

        $mappa = [];

        foreach (static::categories() as $row) {
            $mappa[(int) ($row['id'] ?? 0)] = (string) ($row['name'] ?? '');
        }

        $nomi = [];

        foreach ($ids as $id) {
            if (($mappa[$id] ?? '') !== '') {
                $nomi[] = $mappa[$id];
            }
        }

        return implode(', ', $nomi);
    }

    /** I tag di questo articolo, scritti di fila. */
    protected static function tagNames(int $modelId): string
    {
        $mappa = static::tagOptions();
        $nomi = [];

        foreach (static::tagIds($modelId) as $id) {
            $nome = (string) ($mappa[(string) $id] ?? '');

            if ($nome !== '') {
                $nomi[] = $nome;
            }
        }

        return implode(', ', $nomi);
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

        // Il tipo si sceglie alla creazione e non cambia più: un multiprodotto
        // non ha giacenza né varianti, e un articolo che le ha non diventa una
        // confezione da un campo. Senza la funzionalità nasce sempre singolo.
        $multiprodotto = $action === 'store'
            ? Gestionale::feature('bundles') && ($values['type'] ?? '') === 'bundle'
            : ($oldValues['type'] ?? 'simple') === 'bundle';

        if ($action === 'store') {
            $values['type'] = $multiprodotto ? 'bundle' : 'simple';
        } else {
            unset($values['type']);
        }

        if ($multiprodotto) {
            // La scheda tace sui pannelli nascosti, ma i loro campi arrivano:
            // di un multiprodotto non si guardano.
            $values['has_variants'] = 'false';

            if (Gestionale::feature('bundles')) {
                $modo = (string) ($values['bundle_mode'] ?? $oldValues['bundle_mode'] ?? '');

                // Prima di toccare qualunque cosa: composizione e prodotti, con
                // la frase di chi sbaglia.
                static::bundleComposition((array) $_POST, $modo);
                $values['bundle_mode'] = $modo;
                $values['show_components_value'] = ($values['show_components_value'] ?? 'false') === 'true' ? 'true' : 'false';
            } else {
                // A funzionalità spenta il form non mostra la composizione: i
                // suoi campi non arrivano e quello che c'è non si tocca.
                unset($values['bundle_mode'], $values['show_components_value']);
            }
        } else {
            unset($values['bundle_mode'], $values['show_components_value']);
        }

        // Il tipo fiscale non arriva mai vuoto: il campo nascosto di chi ne
        // ha uno solo, o un articolo nato prima del predefinito, prende quello.
        if (array_key_exists('tax_category_id', $values) && (int) $values['tax_category_id'] <= 0) {
            $values['tax_category_id'] = (string) (TaxCategories::defaultId() ?: '');
        }

        // Con «Da spedire» spento l'imballaggio è solo nascosto, e arriva lo
        // stesso: una scatola ferma non è tra le scelte, la select manderebbe
        // vuoto e la scatola si perderebbe senza che nessuno l'abbia toccata.
        if (($values['requires_shipping'] ?? null) === 'false') {
            unset($values['package_id']);
        }

        // Anche i giorni di attesa sono solo nascosti con l'interruttore
        // spento: lì tornano a zero, e si controllano solo quando contano.
        static::backorderChoice($values);

        static::assertSoleProduct($id, $values);

        // Le caselle nascoste vengono postate lo stesso: se l'articolo dice
        // di non avere varianti, le spunte non si guardano nemmeno.
        if ($values['has_variants'] === 'true') {
            static::chosenAxes((array) $_POST);
        }

        static::assertSomeVersionLeft($id);
        static::assertVersionsKept($id);

        // Un multiprodotto non ha giacenza, scorta minima, sedi né fornitori:
        // i loro pannelli sono nascosti e quello che mandano non vale.
        if ($multiprodotto) {
            return static::withoutExtras($values);
        }

        static::assertStockWritable($id, $values['has_variants'] === 'true');

        // La scorta minima si controlla adesso, come la giacenza: dopo
        // l'insert un rifiuto lascerebbe l'articolo scritto a metà.
        static::assertMinStocks((array) $_POST, $values['has_variants'] === 'true');
        // E le righe per sede (P102, P103): una sede doppia, una fuori
        // dall'elenco, una soglia che non è un numero o una giacenza sotto
        // zero si fermano qui.
        static::assertLocationRows((array) $_POST, $values['has_variants'] === 'true', $id);

        // Anche i fornitori, opzione per opzione (P114): un doppione, un
        // fornitore che la pagina non propone o un costo che non è un numero
        // si fermano qui, non a metà del salvataggio.
        static::assertSupplierRows($id, (array) $_POST, $values['has_variants'] === 'true');

        return static::withoutExtras($values);
    }

    /**
     * Scrive i fornitori di un'opzione come sono arrivati: dalla finestra
     * sono tutti i suoi, dai due campi è il fornitore unico, e gli altri
     * legami restano (P114).
     *
     * Il costo si scrive con due decimali e si tiene con quattro: se la
     * casella dice lo stesso numero arrotondato resta quello salvato.
     *
     * @param array{0: string, 1: list<array<string, mixed>>} $postate quello di `postedSuppliers()`
     * @param list<array<string, mixed>> $prima i legami salvati dell'opzione
     */
    protected static function writeSuppliers(int $productId, array $postate, array $prima, int $unico): void
    {
        [$come, $rows] = $postate;
        $dopo = $come === 'flat'
            ? ProductSuppliers::replaceOne($prima, $unico, $rows)
            : ProductSuppliers::normalize($rows);

        ProductSuppliers::sync($productId, ProductSuppliers::keepStoredCosts($dopo, $prima));
    }

    /**
     * I fornitori che un'opzione ha mandato: `['json', righe]` dal campo
     * nascosto della finestra, `['flat', righe]` dai due campi del fornitore
     * unico, `null` se non è arrivato niente — e allora i suoi legami restano
     * quelli che sono (P114).
     *
     * Il JSON vince: c'è solo dove c'è la finestra. I due campi scrivono il
     * fornitore unico, e se quando arrivano la scheda non ne propone più uno
     * solo — ne è nato un altro, o è stato tolto — compilati non si sa di chi
     * siano: si rifiuta, e la pagina ricaricata mostra quello giusto.
     *
     * @param array<string, mixed> $posted la riga della griglia, o tutto il form
     * @return array{0: string, 1: list<array<string, mixed>>}|null
     */
    protected static function postedSuppliers(array $posted, string $prefix, int $modelId): ?array
    {
        if (!Gestionale::feature('purchasing')) {
            return null;
        }

        $righe = ProductSuppliers::fromJson($posted[$prefix.'suppliers'] ?? null);

        if ($righe !== null) {
            return ['json', $righe];
        }

        if (
            !array_key_exists($prefix.'supplier_sku', $posted)
            && !array_key_exists($prefix.'supplier_cost', $posted)
        ) {
            return null;
        }

        $codice = $posted[$prefix.'supplier_sku'] ?? '';
        $costo = $posted[$prefix.'supplier_cost'] ?? '';
        $unico = static::soleSupplierId($modelId);

        if ($unico > 0) {
            return ['flat', ProductSuppliers::fromFields($unico, $codice, $costo)];
        }

        // Vuoti non dicono niente, di chiunque fossero.
        if (ProductSuppliers::fromFields(1, $codice, $costo) === []) {
            return null;
        }

        throw UserError::make('product.supplier_invalid');
    }

    /**
     * I fornitori postati devono essere fra quelli che l'opzione può avere,
     * una volta sola, con un codice corto e un costo che sia un numero non
     * negativo (P114).
     *
     * Senza varianti contano i campi del riquadro «Prodotto», con le
     * varianti quelli delle righe della griglia: gli altri sono nascosti, ma
     * arrivano lo stesso, e un valore rimasto lì non deve bloccare il
     * salvataggio. Fa eccezione l'articolo con un prodotto solo che ha le
     * varianti accese: quello scritto nel riquadro «Prodotto» va al suo
     * prodotto, che le opzioni riprendono (P115), e si controlla anche lui.
     * Senza `purchasing` i campi non ci sono, e quello che arriva non si
     * guarda.
     */
    public static function assertSupplierRows(int $modelId, array $post, bool $conVarianti): void
    {
        if (!Gestionale::feature('purchasing')) {
            return;
        }

        $gruppi = [];

        if ($conVarianti) {
            foreach (static::postedRows($post) as $riga) {
                $gruppi[] = [
                    is_numeric($riga['id'] ?? null) ? (int) $riga['id'] : 0,
                    static::postedSuppliers($riga, '', $modelId),
                ];
            }

            if ($modelId > 0 && static::productCount($modelId) === 1) {
                $gruppi[] = [
                    (int) (static::soleProduct($modelId)['id'] ?? 0),
                    static::postedSuppliers($post, 'product_', $modelId),
                ];
            }
        } else {
            $product = $modelId > 0 ? static::soleProduct($modelId) : null;

            $gruppi[] = [
                (int) ($product['id'] ?? 0),
                static::postedSuppliers($post, 'product_', $modelId),
            ];
        }

        foreach ($gruppi as [$productId, $postate]) {
            if ($postate === null) {
                continue;
            }

            $scelte = static::supplierChoicesFor($modelId, $productId);
            ProductSuppliers::assertValid($postate[1], array_keys($scelte), $scelte);
        }
    }

    /**
     * Le scorte minime postate devono essere numeri non negativi.
     *
     * Senza varianti conta la casella del riquadro Prodotto, con le varianti
     * quelle delle righe della griglia: le altre sono nascoste, ma arrivano
     * lo stesso, e un valore rimasto lì non deve bloccare il salvataggio.
     * Con gli avvisi bloccati non si scrive niente, e non si guarda niente;
     * con più sedi le soglie viaggiano nelle righe per sede, e le controlla
     * `assertLocationRows()`.
     */
    public static function assertMinStocks(array $post, bool $conVarianti): void
    {
        if (!Gestionale::feature('low_stock_alerts') || static::hasManyLocations()) {
            return;
        }

        if (!$conVarianti) {
            if (array_key_exists('product_min_stock', $post)) {
                static::minStockValue($post['product_min_stock']);
            }

            return;
        }

        foreach (static::postedRows($post) as $riga) {
            if (array_key_exists('min_stock', $riga)) {
                static::minStockValue($riga['min_stock']);
            }
        }
    }

    /**
     * Le righe per sede postate devono reggere (P102, P103).
     *
     * Senza varianti sono quelle del repeater «Giacenza per sede»; con le
     * varianti il JSON di ogni riga della griglia, e `null` — finestra mai
     * aperta — non ha niente da controllare. Le altre arrivano lo stesso,
     * nascoste, e non si guardano. Con una sede sola non ci sono righe.
     *
     * La giacenza sotto zero scritta a mano si ferma qui come nella casella
     * della sede sola (P59): `saveLocationStock()` gira dopo l'insert, e lì
     * un rifiuto sarebbe una pagina di guasto su un articolo scritto a metà.
     */
    public static function assertLocationRows(array $post, bool $conVarianti, int $modelId = 0): void
    {
        if (!static::hasManyLocations()) {
            return;
        }

        $sedi = Locations::shown();

        if (!$conVarianti) {
            $righe = LocationRows::normalize(Repeater::rowsFromRequest('locations', $post), $sedi);

            // L'opzione si cerca solo se c'è un numero negativo da confrontare.
            if (LocationStock::hasNegative($righe)) {
                $product = $modelId > 0 ? static::soleProduct($modelId) : null;
                LocationStock::assertNotNegative((int) ($product['id'] ?? 0), $righe);
            }

            return;
        }

        foreach (static::postedRows($post) as $riga) {
            $perSede = LocationRows::fromForm($riga['locations'] ?? null);

            if ($perSede !== null) {
                LocationStock::assertNotNegative(
                    (int) ($riga['id'] ?? 0),
                    LocationRows::normalize($perSede, $sedi)
                );
            }
        }
    }

    /**
     * La giacenza scritta a mano dev'essere un numero di pezzi.
     *
     * Il rifiuto si calcola qui, prima che il salvataggio cominci: dopo
     * l'insert non c'è nessuna rete — il sync e `afterUpdate` girano fuori da
     * qualunque try — e un errore diventerebbe una pagina di guasto su un
     * articolo già scritto a metà.
     *
     * Una giacenza già sotto zero per le vendite in arretrato, lasciata com'era,
     * passa: non l'ha scritta nessuno. Con più sedi la casella non si scrive,
     * nemmeno in creazione: lì si controllano le righe per sede
     * ({@see assertLocationRows()}).
     */
    public static function assertStockWritable(int $modelId = 0, bool $conVarianti = true): void
    {
        if (!static::stockIsWritable()) {
            return;
        }

        if (!$conVarianti) {
            $product = $modelId > 0 ? static::soleProduct($modelId) : null;

            if (static::negativeWritten($_POST['product_stock'] ?? null, (int) ($product['id'] ?? 0))) {
                throw UserError::make('product.stock_negative');
            }

            return;
        }

        foreach (static::postedRows((array) $_POST) as $riga) {
            if (static::negativeWritten($riga['stock'] ?? null, (int) ($riga['id'] ?? 0))) {
                throw UserError::make('product.stock_negative');
            }
        }
    }

    /**
     * La scorta minima scritta nella scheda, pronta per la colonna.
     *
     * Vuota vale zero, che vuol dire "non avvisarmi". Il numero si legge come
     * la giacenza (`Stocktake::quantity()`): `2,5` e `2.5` sono la stessa
     * cosa, e `20.000` sono venti pezzi, come li mostra il campo del backend.
     */
    public static function minStockValue(mixed $raw): string
    {
        if (is_array($raw)) {
            throw UserError::make('product.min_stock_invalid');
        }

        if (trim((string) ($raw ?? '')) === '') {
            return '0.000';
        }

        $quantity = Stocktake::quantity($raw);

        if ($quantity === null || $quantity < 0) {
            throw UserError::make('product.min_stock_invalid');
        }

        return number_format($quantity, 3, '.', '');
    }

    /** Un numero sotto zero diverso da quello che il prodotto ha già. */
    protected static function negativeWritten(mixed $value, int $productId): bool
    {
        $quantita = Stocktake::quantity($value);

        if ($quantita === null || $quantita >= 0) {
            return false;
        }

        return $productId <= 0 || abs($quantita - (float) (Levels::of($productId)['quantity'] ?? 0)) > 0.0005;
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
     * I fornitori delle opzioni (P107, P114): il JSON della finestra, o i
     * due campi del fornitore unico.
     *
     * Senza varianti sono i campi del riquadro «Prodotto»; con le varianti
     * quelli di ogni riga della griglia, e i primi non si guardano. Il JSON
     * sostituisce i legami dell'opzione; i due campi riscrivono solo quello
     * del fornitore unico, e vuoti lo tolgono. Un'opzione che non ha mandato
     * niente resta com'è. Il costo non toccato resta quello salvato, con i
     * suoi quattro decimali.
     *
     * Accendendo le varianti le opzioni che nascono prendono i fornitori
     * dell'articolo singolo, come il prezzo (P115): lo scheletro ripreso
     * tiene i suoi — o quelli scritti nel riquadro «Prodotto» un attimo
     * prima di accenderle — e chi nasce senza niente di scritto nella sua
     * riga li copia. Dove la riga nuova dello scheletro ha qualcosa di
     * scritto vince lei, come per la giacenza.
     *
     * @param array<string, array<string, mixed>> $righe
     * @param array<string, array{product_id: int, variant_id: int, priced: bool}> $nate
     * @param array<string, array<string, mixed>> $scritte
     * @param array<int, string> $riprese
     */
    protected static function saveSuppliers(
        int $modelId,
        array $post,
        array $righe,
        array $nate,
        array $scritte,
        bool $conVarianti,
        array $riprese
    ): void {
        if (!Gestionale::feature('purchasing')) {
            return;
        }

        $volute = [];
        $copie = [];

        if (!$conVarianti) {
            $product = static::soleProduct($modelId);
            $postate = is_array($product) ? static::postedSuppliers($post, 'product_', $modelId) : null;

            if ($postate !== null) {
                $volute[(int) $product['id']] = $postate;
            }
        } else {
            // Solo le opzioni dell'articolo: un id di un altro non si scrive.
            $vive = [];

            foreach (static::products($modelId) as $product) {
                $vive[(int) $product['id']] = true;
            }

            foreach ($righe as $riga) {
                $productId = is_numeric($riga['id'] ?? null) ? (int) $riga['id'] : 0;

                if (!isset($vive[$productId])) {
                    continue;
                }

                $postate = static::postedSuppliers($riga, '', $modelId);

                if ($postate !== null) {
                    $volute[$productId] = $postate;
                }
            }

            // Quello scritto nel riquadro «Prodotto» è dello scheletro, come
            // il prezzo (P115): vince sulla sua riga vecchia, che era
            // nascosta, e la sua riga nuova, se dice altro, lo riscrive.
            if ($riprese !== []) {
                $postate = static::postedSuppliers($post, 'product_', $modelId);

                if ($postate !== null) {
                    $volute[(int) array_key_first($riprese)] = $postate;
                }
            }

            foreach ($nate as $chiave => $riga) {
                $productId = (int) $riga['product_id'];
                $scritto = is_array($scritte[$chiave] ?? null) ? $scritte[$chiave] : [];
                $postate = static::postedSuppliers($scritto, '', $modelId);

                // I due campi lasciati vuoti su una riga nuova non dicono
                // «nessun fornitore»: dicono che nessuno li ha compilati.
                if ($postate !== null && ($postate[0] === 'json' || $postate[1] !== [])) {
                    $volute[$productId] = $postate;
                } elseif ($riprese !== [] && !isset($riprese[$productId])) {
                    $copie[] = $productId;
                }
            }
        }

        $salvati = $volute === [] ? [] : ProductSuppliers::linksFor(array_keys($volute));
        $unico = static::soleSupplierId($modelId);

        foreach ($volute as $productId => $postate) {
            static::writeSuppliers($productId, $postate, $salvati[$productId] ?? [], $unico);
        }

        if ($copie !== [] && $riprese !== []) {
            $scheletro = (int) array_key_first($riprese);
            $suoi = ProductSuppliers::linksFor([$scheletro])[$scheletro] ?? [];

            if ($suoi !== []) {
                foreach ($copie as $productId) {
                    ProductSuppliers::sync($productId, $suoi);
                }
            }
        }

        // Quello che la richiesta ha letto prima non vale più.
        self::$supplierLinks = [];
        self::$supplierChoices = [];
        self::$inactiveSuppliers = [];
    }

    /**
     * La giacenza dell'articolo senza varianti.
     *
     * È lo stesso gesto della riga — si scrive quanti pezzi ci sono e il
     * pannello fa il movimento della differenza — ma la casella sta in alto,
     * accanto al prezzo, perché lì non c'è nessuna griglia da guardare.
     */
    protected static function saveSingleStock(
        int $modelId,
        array $post,
        bool $conVarianti,
        bool $appenaNato = false
    ): void {
        // Con più sedi la casella non si scrive, nemmeno in creazione: i
        // pezzi arrivano dalle righe per sede.
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

        // Su un articolo che nasce non c'è niente da rettificare: il numero
        // è un carico.
        if ($appenaNato) {
            static::loadInitialStock((int) $product['id'], $quantita);

            return;
        }

        static::adjustStock([(int) $product['id'] => $quantita]);
    }

    /**
     * Giacenza e scorta minima per sede, con più sedi (P102, P103).
     *
     * Senza varianti sono le righe del repeater «Giacenza per sede»; con le
     * varianti il JSON che la finestra «Giacenza» ha scritto nel campo
     * nascosto `locations` di ogni riga della griglia, e `null` — finestra
     * mai aperta — lascia tutto com'è. Per ogni prodotto `LocationStock`
     * scrive prima le soglie e poi i movimenti, una rettifica per sede
     * cambiata, o il carico iniziale se il prodotto nasce adesso. Lo
     * scheletro ripreso per la prima combinazione non nasce ora, e dove la
     * sua riga nuova ha un JSON vince lei, come per la giacenza.
     *
     * @param array<string, array<string, mixed>> $righe
     * @param array<string, array{product_id: int, variant_id: int, priced: bool}> $nate
     * @param array<string, array<string, mixed>> $scritte
     * @param array<int, string> $riprese
     */
    protected static function saveLocationStock(
        int $modelId,
        array $post,
        array $righe,
        array $nate,
        array $scritte,
        bool $conVarianti,
        bool $appenaNato,
        array $riprese
    ): void {
        if (!static::hasManyLocations()) {
            return;
        }

        $sedi = Locations::shown();

        if (!$conVarianti) {
            $product = static::soleProduct($modelId);

            if (is_array($product)) {
                static::applyLocationRows(
                    (int) $product['id'],
                    LocationRows::normalize(Repeater::rowsFromRequest('locations', $post), $sedi),
                    $appenaNato
                );
            }

            return;
        }

        foreach ($righe as $riga) {
            $productId = (int) ($riga['id'] ?? 0);
            $nuova = $riprese[$productId] ?? null;

            if (
                $productId <= 0
                || ($nuova !== null && LocationRows::fromForm($righe[$nuova]['locations'] ?? null) !== null)
            ) {
                continue;
            }

            $perSede = LocationRows::fromForm($riga['locations'] ?? null);

            if ($perSede !== null) {
                static::applyLocationRows($productId, LocationRows::normalize($perSede, $sedi), false);
            }
        }

        foreach ($nate as $chiave => $riga) {
            $productId = (int) $riga['product_id'];
            $scritto = is_array($scritte[$chiave] ?? null) ? $scritte[$chiave] : [];
            $perSede = LocationRows::fromForm($scritto['locations'] ?? null);

            if ($perSede !== null) {
                static::applyLocationRows(
                    $productId,
                    LocationRows::normalize($perSede, $sedi),
                    !isset($riprese[$productId])
                );
            }
        }
    }

    /**
     * Scrive le righe per sede di un prodotto e rinfresca il suo avviso.
     *
     * Con gli avvisi bloccati le righe non hanno la casella della scorta
     * minima e arriverebbero a zero: le soglie salvate restano quelle, e una
     * sede che ha una soglia ma nessuna riga la tiene senza toccare i pezzi.
     * È quello che fa la scheda dell'opzione (`ProductResource`).
     *
     * @param list<array{location_id: int, stock: ?float, min_stock: float}> $rows righe pulite
     */
    protected static function applyLocationRows(int $productId, array $rows, bool $appenaNato): void
    {
        $avvisi = Gestionale::feature('low_stock_alerts');

        if (!$avvisi) {
            $salvate = Thresholds::forProduct($productId);

            foreach ($rows as $index => $row) {
                $rows[$index]['min_stock'] = (float) ($salvate[$row['location_id']] ?? 0);
                unset($salvate[$row['location_id']]);
            }

            foreach ($salvate as $locationId => $soglia) {
                $rows[] = ['location_id' => (int) $locationId, 'stock' => null, 'min_stock' => (float) $soglia];
            }
        }

        LocationStock::apply($productId, $rows, $appenaNato);

        // Anche una soglia cambiata senza un pezzo che si muove: alzarla
        // sopra il disponibile è già una notizia.
        if ($avvisi) {
            Alerts::refresh($productId);
        }
    }

    /**
     * Porta ogni prodotto al numero di pezzi scritto nella scheda.
     *
     * Si scrive **quanti pezzi ci sono**, non di quanto cambiarli: il
     * movimento è la differenza, e un numero riscritto uguale non muove
     * niente.
     *
     * @param array<int, float> $quantita pezzi voluti, per id del prodotto
     */
    protected static function adjustStock(array $quantita): void
    {
        $attuali = [];

        foreach (Levels::forProducts(array_keys($quantita)) as $productId => $livello) {
            $attuali[(int) $productId] = (float) ($livello['quantity'] ?? 0);
        }

        foreach (Stocktake::changes($attuali, $quantita) as $productId => $cambio) {
            Stock::apply([
                'product_id' => $productId,
                'quantity' => $cambio['delta'],
                'reason' => Reasons::DEFAULT,
                'note' => 'Rettifica dalla scheda dell\'articolo',
            ]);
        }
    }

    /**
     * Le scorte minime scritte nella scheda: nella griglia, per riga, o in
     * alto accanto a SKU ed EAN quando l'articolo non ha varianti. Sono le
     * soglie della sede principale (`Thresholds`, P100): con una sede sola è
     * l'unica, e con più sedi la scheda non passa da qui ma dalle righe per
     * sede (`saveLocationStock()`).
     *
     * Si scrivono **prima** di muovere un pezzo. Ogni movimento rinfresca
     * l'avviso del suo prodotto con la soglia che trova nel database: con
     * quella vecchia, giacenza e soglia cambiate insieme aprirebbero e
     * chiuderebbero avvisi finti — e chi li riceve per email se li vede
     * arrivare lo stesso.
     *
     * Accendendo le varianti il generatore riprende lo scheletro per la
     * prima combinazione, e la sua riga vecchia può essere ancora nella
     * griglia: dove la riga nuova è scritta vince lei, dove è vuota — nasce
     * dal modello della griglia, con le caselle vuote — vale la vecchia.
     *
     * @param array<string, array<string, mixed>> $righe
     * @param array<string, array{product_id: int, variant_id: int, priced: bool}> $nate
     * @param array<string, array<string, mixed>> $scritte
     * @return list<int> i prodotti il cui avviso va rinfrescato alla fine
     */
    protected static function saveMinStocks(
        int $modelId,
        array $post,
        array $righe,
        array $nate,
        array $scritte,
        bool $conVarianti
    ): array {
        if (!Gestionale::feature('low_stock_alerts') || static::hasManyLocations()) {
            return [];
        }

        if (!$conVarianti) {
            $product = static::soleProduct($modelId);

            if (!is_array($product) || !array_key_exists('product_min_stock', $post)) {
                return [];
            }

            $productId = (int) $product['id'];
            static::writeMinStock(
                $productId,
                $post['product_min_stock'],
                static::mainThresholds([$productId])[$productId] ?? 0.0
            );

            // Anche se la soglia è rimasta quella: alzarla sopra il
            // disponibile è già una notizia, e l'avviso va guardato ora.
            return [$productId];
        }

        $soglie = static::mainThresholds(array_map(
            static fn (array $product): int => (int) $product['id'],
            static::products($modelId)
        ));

        $volute = [];

        foreach ($righe as $riga) {
            $productId = (int) ($riga['id'] ?? 0);

            if (isset($soglie[$productId]) && array_key_exists('min_stock', $riga)) {
                $volute[$productId] = $riga['min_stock'];
            }
        }

        foreach ($nate as $chiave => $riga) {
            $productId = (int) $riga['product_id'];
            $scritto = is_array($scritte[$chiave] ?? null) ? $scritte[$chiave] : [];

            if (isset($soglie[$productId]) && trim((string) ($scritto['min_stock'] ?? '')) !== '') {
                $volute[$productId] = $scritto['min_stock'];
            }
        }

        $cambiate = [];

        foreach ($volute as $productId => $raw) {
            if (static::writeMinStock($productId, $raw, $soglie[$productId])) {
                $cambiate[] = $productId;
            }
        }

        return $cambiate;
    }

    /**
     * Scrive la scorta minima di un prodotto sulla sede principale, se è
     * cambiata: zero è una soglia in meno. L'avviso non lo tocca: lo
     * rinfresca il movimento che viene dopo, o `saveExtras()` alla fine.
     *
     * @return bool se la soglia è cambiata
     */
    protected static function writeMinStock(int $productId, mixed $raw, float $attuale): bool
    {
        $soglia = static::minStockValue($raw);

        if ($productId <= 0 || abs((float) $soglia - $attuale) <= 0.0005) {
            return false;
        }

        Thresholds::save($productId, [Locations::mainId() => (float) $soglia]);

        return true;
    }

    /**
     * «Vendita senza giacenza» e «Giorni di attesa»: solo con `backorders`.
     *
     * Non sono colonne del modello ma di ogni opzione: le scrive
     * `saveBackorders()`, e `withoutExtras()` le toglie dalla query.
     *
     * @return list<object>
     */
    protected static function backorderFields(): array
    {
        if (!Gestionale::feature('backorders')) {
            return [];
        }

        return [
            FormField::key('allow_backorder')
                ->toggle()
                ->label('Vendita senza giacenza')
                ->description('Il cliente può ordinarlo anche a giacenza finita.'),
            FormField::key('backorder_lead_days')
                ->number()
                ->integer()
                ->suffix(' giorni')
                ->label('Giorni di attesa'),
        ];
    }

    /**
     * I due campi per «Come si vende»: i giorni seguono l'interruttore, come
     * l'imballaggio segue «Da spedire».
     *
     * @return list<object>
     */
    protected static function backorderInputs(): array
    {
        if (!Gestionale::feature('backorders')) {
            return [];
        }

        return [
            // Un multiprodotto non ha ordini a fornitore suoi: il campo lo
            // tace il tipo.
            (Gestionale::feature('bundles')
                ? static::getInput('allow_backorder')->hiddenWhen('type', 'bundle')
                : static::getInput('allow_backorder'))->columnSpan(12),
            static::getInput('backorder_lead_days')
                ->visibleWhen('allow_backorder', 'true')
                ->columnSpan(12),
        ];
    }

    /**
     * Quello che si scrive sulle opzioni, già controllato.
     *
     * `null` vuol dire non scrivere niente: senza la funzionalità, o senza
     * l'interruttore nel post. Spento, i giorni tornano a zero anche se la
     * casella nascosta ne porta ancora; acceso, sono un intero da 0 a 365, e
     * una casella vuota vale zero.
     *
     * @return array{allow_backorder: 'true'|'false', backorder_lead_days: int}|null
     */
    public static function backorderChoice(array $values): ?array
    {
        if (!Gestionale::feature('backorders') || !array_key_exists('allow_backorder', $values)) {
            return null;
        }

        if ($values['allow_backorder'] !== 'true') {
            return ['allow_backorder' => 'false', 'backorder_lead_days' => 0];
        }

        $raw = $values['backorder_lead_days'] ?? '';

        if (is_string($raw) && trim($raw) === '') {
            return ['allow_backorder' => 'true', 'backorder_lead_days' => 0];
        }

        $giorni = Numbers::fromForm($raw);

        if (
            $giorni === null
            || !is_numeric($giorni)
            || (float) $giorni !== floor((float) $giorni)
            || (float) $giorni < 0
            || (float) $giorni > 365
        ) {
            throw UserError::make('product.backorder_lead_days_invalid');
        }

        return ['allow_backorder' => 'true', 'backorder_lead_days' => (int) $giorni];
    }

    /**
     * Scrive la scelta su tutte le opzioni vive, anche su quelle appena nate:
     * l'interruttore è dell'articolo. Tocca solo le righe che cambiano.
     */
    protected static function saveBackorders(int $modelId, array $post): void
    {
        $scelta = static::backorderChoice($post);

        if ($scelta === null) {
            return;
        }

        foreach (static::products($modelId) as $product) {
            $productId = (int) ($product['id'] ?? 0);

            if (
                $productId <= 0
                || (
                    (string) ($product['allow_backorder'] ?? 'false') === $scelta['allow_backorder']
                    && (int) ($product['backorder_lead_days'] ?? 0) === $scelta['backorder_lead_days']
                )
            ) {
                continue;
            }

            Product::update([
                'allow_backorder' => $scelta['allow_backorder'],
                'backorder_lead_days' => (string) $scelta['backorder_lead_days'],
            ], $productId);
        }
    }

    /**
     * Come si riapre la scheda: l'interruttore è acceso solo se lo è su tutte
     * le opzioni, così un'opzione rimasta indietro si vede. I giorni sono i
     * più lunghi fra le opzioni accese.
     *
     * @param list<array<string, mixed>> $products
     * @return array{allow_backorder: 'true'|'false', backorder_lead_days: string}
     */
    public static function backorderSummary(array $products): array
    {
        $tutte = $products !== [];
        $giorni = 0;

        foreach ($products as $product) {
            if ((string) ($product['allow_backorder'] ?? 'false') !== 'true') {
                $tutte = false;

                continue;
            }

            $giorni = max($giorni, (int) ($product['backorder_lead_days'] ?? 0));
        }

        return [
            'allow_backorder' => $tutte ? 'true' : 'false',
            'backorder_lead_days' => (string) $giorni,
        ];
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
        static::saveExtras($id, (array) $_POST, $sku, (array) $_FILES, true);
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        static::saveExtras((int) $id, (array) $_POST, '', (array) $_FILES);
    }

    /** Questo articolo è un multiprodotto? */
    public static function isBundleModel(int $modelId): bool
    {
        $model = $modelId > 0 ? ProductModel::findById($modelId) : null;

        return is_array($model) && isset($model['id']) && ($model['type'] ?? 'simple') === 'bundle';
    }

    /**
     * I prodotti che una composizione può contenere: attivi, non cancellati e
     * non multiprodotti, per nome. Un componente già scelto e poi spento resta
     * nell'elenco, segnato, o la riga perderebbe il prodotto al salvataggio.
     *
     * @return array<int, string>
     */
    public static function bundleOptionProducts(int $modelId = 0): array
    {
        $scelti = [];

        if ($modelId > 0) {
            $forma = Bundles::forModel($modelId);

            foreach ($forma['components'] as $componente) {
                $scelti[(int) $componente['product_id']] = true;
            }

            foreach ($forma['groups'] as $gruppo) {
                foreach ($gruppo['options'] as $opzione) {
                    $scelti[(int) $opzione['product_id']] = true;
                }
            }
        }

        $prodotti = static::rowsOf(Product::class, [], 'id');
        $nomi = ProductNames::models($prodotti);
        $voci = [];

        foreach ($prodotti as $prodotto) {
            $id = (int) $prodotto['id'];
            $attivo = ($prodotto['active'] ?? 'false') === 'true';

            if ((!$attivo && !isset($scelti[$id])) || Bundles::isBundle($id)) {
                continue;
            }

            $nome = ProductNames::full($prodotto, $nomi);
            $voci[$id] = $attivo ? $nome : $nome.' (disattivato)';
        }

        asort($voci, SORT_NATURAL | SORT_FLAG_CASE);

        return $voci;
    }

    /**
     * La composizione che la scheda manda, letta e controllata.
     *
     * Dei due riquadri si legge solo quello che la modalità tiene: i campi di
     * quello nascosto arrivano lo stesso e non contano.
     *
     * @return array{components: list<array<string, mixed>>, groups: list<array<string, mixed>>}
     *
     * @throws UserError
     */
    public static function bundleComposition(array $post, string $mode): array
    {
        if (!in_array($mode, Bundles::MODES, true)) {
            throw UserError::make('bundle.unknown_mode');
        }

        $componenti = $mode !== 'choice' ? static::postedBundleComponents($post) : [];
        $gruppi = $mode !== 'fixed' ? static::postedBundleGroups($post) : [];

        Bundles::assertComposition($mode, $componenti, $gruppi);
        Bundles::assertProducts($mode, $componenti, $gruppi);

        return ['components' => $componenti, 'groups' => $gruppi];
    }

    /** @return list<array{id: int, product_id: int, quantity: float}> */
    protected static function postedBundleComponents(array $post): array
    {
        $righe = [];

        foreach ((array) ($post['bundle_components'] ?? []) as $riga) {
            // Una riga aggiunta e lasciata senza prodotto non è una scelta.
            if (!is_array($riga) || (int) ($riga['product_id'] ?? 0) <= 0) {
                continue;
            }

            $righe[] = [
                'id' => (int) ($riga['id'] ?? 0),
                'product_id' => (int) $riga['product_id'],
                'quantity' => static::bundleNumber($riga['quantity'] ?? ''),
            ];
        }

        return $righe;
    }

    /** @return list<array{id: int, name: string, min: int, max: int, options: list<array<string, mixed>>}> */
    protected static function postedBundleGroups(array $post): array
    {
        $righe = [];

        foreach ((array) ($post['bundle_groups'] ?? []) as $riga) {
            if (!is_array($riga)) {
                continue;
            }

            $opzioni = static::postedBundleOptions($riga['options'] ?? '');
            $nome = trim((string) ($riga['name'] ?? ''));

            // Un gruppo aggiunto e lasciato vuoto non è un gruppo.
            if ($nome === '' && $opzioni === []) {
                continue;
            }

            $righe[] = [
                'id' => (int) ($riga['id'] ?? 0),
                'name' => $nome,
                'min' => (int) ($riga['min'] ?? 0),
                'max' => (int) ($riga['max'] ?? 0),
                'options' => $opzioni,
            ];
        }

        return $righe;
    }

    /**
     * Le opzioni di un gruppo, dal JSON che la finestra scrive.
     *
     * @return list<array{id: int, product_id: int, surcharge: float}>
     *
     * @throws UserError
     */
    protected static function postedBundleOptions(mixed $json): array
    {
        $testo = trim((string) $json);

        if ($testo === '') {
            return [];
        }

        $righe = json_decode($testo, true);

        if (!is_array($righe) || !array_is_list($righe)) {
            throw UserError::make('bundle.options_invalid');
        }

        $opzioni = [];

        foreach ($righe as $riga) {
            if (!is_array($riga)) {
                throw UserError::make('bundle.options_invalid');
            }

            if ((int) ($riga['product_id'] ?? 0) <= 0) {
                continue;
            }

            $opzioni[] = [
                'id' => (int) ($riga['id'] ?? 0),
                'product_id' => (int) $riga['product_id'],
                'surcharge' => static::bundleNumber($riga['surcharge'] ?? ''),
            ];
        }

        return $opzioni;
    }

    /** Un numero scritto da una persona: «1,5» e «1.5» sono lo stesso, vuoto è zero. */
    protected static function bundleNumber(mixed $valore): float
    {
        return (float) str_replace(',', '.', trim((string) $valore));
    }

    /**
     * Scrive la composizione di un multiprodotto: i componenti e i gruppi
     * con le loro opzioni, come li dice la modalità.
     *
     * Le righe che c'erano si riconoscono per id e tengono il loro: un ordine
     * già fatto punta alle opzioni. Quello che la scheda non manda più si
     * elimina; quello che non cambia non si riscrive. La modalità cambiata
     * toglie davvero l'altro riquadro, non lo nasconde.
     */
    protected static function saveBundle(int $modelId, array $post): void
    {
        $modello = ProductModel::findById($modelId);
        $modo = (string) ($modello['bundle_mode'] ?? '');
        $composizione = static::bundleComposition($post, $modo);

        static::syncBundleRows(
            BundleComponent::class,
            ['product_model_id' => $modelId],
            array_map(static fn (array $riga): array => [
                'id' => $riga['id'],
                'data' => [
                    'product_id' => $riga['product_id'],
                    'quantity' => number_format($riga['quantity'], 3, '.', ''),
                ],
            ], $composizione['components'])
        );

        // I gruppi, e per ognuno le sue opzioni: quelle dei gruppi tolti se
        // ne vanno prima, la chiave esterna non lascerebbe togliere il gruppo.
        $esistenti = static::bundleRows(BundleGroup::class, ['product_model_id' => $modelId]);
        $posti = array_flip(array_map(static fn (array $riga): int => (int) $riga['id'], $composizione['groups']));

        foreach ($esistenti as $gruppo) {
            if (!isset($posti[(int) $gruppo['id']])) {
                static::syncBundleRows(BundleGroupOption::class, ['bundle_group_id' => (int) $gruppo['id']], []);
            }
        }

        $ids = static::syncBundleRows(
            BundleGroup::class,
            ['product_model_id' => $modelId],
            array_map(static fn (array $riga): array => [
                'id' => $riga['id'],
                'data' => ['name' => $riga['name'], 'min_choices' => $riga['min'], 'max_choices' => $riga['max']],
            ], $composizione['groups'])
        );

        foreach ($composizione['groups'] as $indice => $gruppo) {
            static::syncBundleRows(
                BundleGroupOption::class,
                ['bundle_group_id' => $ids[$indice]],
                array_map(static fn (array $riga): array => [
                    'id' => $riga['id'],
                    'data' => [
                        'product_id' => $riga['product_id'],
                        'surcharge' => number_format($riga['surcharge'], 2, '.', ''),
                    ],
                ], $gruppo['options'])
            );
        }
    }

    /**
     * Rimette in riga un elenco di righe figlie: le conosciute per id si
     * aggiornano solo se cambiano, le nuove si scrivono, le altre si
     * eliminano. Torna gli id, nell'ordine in cui sono arrivate.
     *
     * @param class-string $classe
     * @param array<string, int> $padre colonna e id del padre
     * @param list<array{id: int, data: array<string, mixed>}> $righe
     * @return list<int>
     */
    protected static function syncBundleRows(string $classe, array $padre, array $righe): array
    {
        $esistenti = [];

        foreach (static::bundleRows($classe, $padre) as $riga) {
            $esistenti[(int) $riga['id']] = $riga;
        }

        $ids = [];
        $tenuti = [];

        foreach ($righe as $indice => $riga) {
            $dati = $riga['data'] + ['position' => $indice + 1];
            $id = (int) $riga['id'];

            if ($id > 0 && isset($esistenti[$id]) && !isset($tenuti[$id])) {
                $tenuti[$id] = true;
                $ids[] = $id;

                if (static::bundleRowChanged($esistenti[$id], $dati)) {
                    $classe::update($dati + ['deleted' => 'false'], $id);
                }

                continue;
            }

            $ids[] = (int) ($classe::create($dati + $padre)->insert_id ?? 0);
        }

        foreach ($esistenti as $id => $riga) {
            if (!isset($tenuti[$id])) {
                $classe::delete($id);
            }
        }

        return $ids;
    }

    /** @param array<string, mixed> $riga @param array<string, mixed> $dati */
    protected static function bundleRowChanged(array $riga, array $dati): bool
    {
        if (($riga['deleted'] ?? 'false') === 'true') {
            return true;
        }

        foreach ($dati as $colonna => $valore) {
            $prima = $riga[$colonna] ?? null;

            if (is_numeric($valore) && is_numeric($prima) ? abs((float) $prima - (float) $valore) > 0.0000001 : (string) $prima !== (string) $valore) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tutte le righe figlie, anche quelle nel cestino: vanno rimesse in riga
     * o tolte come le altre.
     *
     * @param class-string $classe
     * @return list<array<string, mixed>>
     */
    protected static function bundleRows(string $classe, array $padre): array
    {
        return static::rowsOf($classe, $padre + ['deleted' => ['true', 'false']], 'position');
    }

    /**
     * La composizione per riempire il form: le righe dei due elenchi, e per
     * ogni gruppo il JSON delle sue opzioni con il riassunto del bottone.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    protected static function withBundleFormValues(int $modelId, array $values): array
    {
        $forma = Bundles::forModel($modelId);

        $values['bundle_components'] = array_map(static fn (array $riga): array => [
            'id' => (string) $riga['id'],
            'product_id' => (string) $riga['product_id'],
            'quantity' => static::rawNumber((float) $riga['quantity']),
        ], $forma['components']);

        $values['bundle_groups'] = array_map(static function (array $gruppo): array {
            $opzioni = array_map(static fn (array $riga): array => [
                'id' => $riga['id'],
                'product_id' => $riga['product_id'],
                'surcharge' => $riga['surcharge'],
            ], $gruppo['options']);

            return [
                'id' => (string) $gruppo['id'],
                'name' => $gruppo['name'],
                'min' => (string) $gruppo['min'],
                'max' => (string) $gruppo['max'],
                'options' => json_encode($opzioni, JSON_UNESCAPED_UNICODE),
                'options_button' => static::bundleOptionsCaption(count($opzioni)),
            ];
        }, $forma['groups']);

        return $values;
    }

    /** Il riassunto accanto al bottone «Opzioni». */
    protected static function bundleOptionsCaption(int $quante): string
    {
        return match (true) {
            $quante === 0 => '',
            $quante === 1 => '1 opzione',
            default => $quante.' opzioni',
        };
    }

    /**
     * Righe legate al modello: categorie, tag, attributi e prodotto unico.
     *
     * `$fallbackSku` è lo SKU del modello appena creato: se la casella del
     * prodotto è vuota, il prodotto tiene quello, invece di perdere il codice
     * che il modello gli ha appena dato. `$appenaNato` dice che il modello è
     * stato creato adesso: la giacenza scritta in alto è un carico iniziale.
     */
    public static function saveExtras(
        int $modelId,
        array $post,
        string $fallbackSku = '',
        array $files = [],
        bool $appenaNato = false
    ): void {
        if ($modelId <= 0) {
            return;
        }

        Transaction::run(static function () use ($modelId, $post, $fallbackSku, $files, $appenaNato): void {
            static::saveCategories($modelId, $post);
            static::saveTags($modelId, $post);
            static::saveModelAttributes($modelId, $post);

            // Un multiprodotto ha un prodotto solo, senza magazzino proprio:
            // il prezzo, la composizione e le foto, e basta.
            if (static::isBundleModel($modelId)) {
                static::savePrices($modelId, $post, $fallbackSku);

                if (Gestionale::feature('bundles')) {
                    static::saveBundle($modelId, $post);
                }

                static::saveImages($modelId, $post, $files, static::saveGroupImages($modelId, $post, $files));
                static::realignNames($modelId);

                return;
            }

            // Il riquadro delle opzioni è nascosto, non tolto: le sue caselle
            // arrivano comunque. Chi ha detto di non avere varianti non deve
            // ritrovarsi delle combinazioni generate da spunte che non vede.
            // Vale anche quello che `mutateRequestValues()` ha già scritto sul
            // modello: eliminate le righe fino a una, l'interruttore spento
            // manda «no», ma chi ha salvato stava guardando la griglia.
            $conVarianti = ($post['has_variants'] ?? 'false') === 'true'
                || static::hasVariants($modelId);

            $righe = static::postedRows($post);
            $nate = [];
            $scritte = [];
            $appena = [];
            $esistenti = [];

            // I prodotti di prima: quello che il generatore riprende non nasce
            // adesso, e i suoi pezzi non sono un carico iniziale. Su un
            // articolo appena creato lo scheletro è nato in questa richiesta:
            // lì è tutto nuovo.
            if ($conVarianti && !$appenaNato) {
                foreach (static::products($modelId) as $product) {
                    $esistenti[(int) $product['id']] = true;
                }
            }

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

            // Lo scheletro ripreso, con la chiave della sua riga nuova.
            $riprese = [];

            foreach ($nate as $chiave => $riga) {
                if (isset($esistenti[$riga['product_id']])) {
                    $riprese[$riga['product_id']] = (string) $chiave;
                }
            }

            // Dopo il generatore, perché vale anche per le opzioni appena
            // nate; prima dei pezzi, perché un movimento guarda l'interruttore.
            static::saveBackorders($modelId, $post);

            static::savePrices($modelId, $post, $fallbackSku, $appena);
            // Prima le soglie, poi i pezzi: ogni movimento rinfresca l'avviso
            // con la soglia che trova.
            $daRinfrescare = static::saveMinStocks($modelId, $post, $righe, $nate, $scritte, $conVarianti);
            static::saveNewVersions($modelId, $nate, $scritte, $files, $riprese);
            static::saveRowExtras($modelId, $righe, $files, $conVarianti, $riprese);
            // Via i legami delle opzioni tolte dalla griglia: il repeater
            // del core le ha già messe nel cestino, e di un'opzione tolta non
            // serve sapere quanto costava (P99). Anche senza `purchasing`,
            // come in `deleteRecord()`: la chiave esterna non guarda le
            // funzionalità.
            ProductSuppliers::dropRemovedOptions($modelId);
            // I fornitori di quelle che restano e di quelle appena nate
            // (P107): dopo il generatore, che dice chi è nato.
            static::saveSuppliers($modelId, $post, $righe, $nate, $scritte, $conVarianti, $riprese);
            static::saveSingleStock($modelId, $post, $conVarianti, $appenaNato);
            // Con più sedi: le righe del riquadro «Magazzino» o della finestra
            // della griglia, soglie prima dei pezzi, prodotto per prodotto.
            static::saveLocationStock($modelId, $post, $righe, $nate, $scritte, $conVarianti, $appenaNato, $riprese);

            // Le soglie cambiate senza un pezzo che si muove.
            foreach ($daRinfrescare as $productId) {
                Alerts::refresh($productId);
            }

            // Dopo il generatore: le foto di un colore appena spuntato hanno
            // bisogno della sua variante.
            $colori = static::saveGroupImages($modelId, $post, $files);
            static::saveImages($modelId, $post, $files, $colori);
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
     * Le righe che il core deve sincronizzare.
     *
     * Della griglia, quelle che esistono già: una riga nata da una spunta
     * non ha ancora il suo colore — lo crea `Generator::run()` dopo, in
     * `afterStore`/`afterUpdate` — e il sync gira prima: le passerebbe al
     * database con una variante che non c'è, e il database rifiuterebbe
     * lasciando l'articolo a metà.
     */
    public static function prepareRepeaterRows(
        string $inputName,
        array $rows,
        string $action = 'store',
        string $context = 'backend'
    ): array {
        // Una personalizzazione si collega una volta sola: senza scelta o già
        // vista, la riga non conta.
        if ($inputName === 'customizations') {
            $viste = [];
            return array_values(array_filter($rows, static function ($row) use (&$viste): bool {
                $id = is_array($row) ? trim((string) ($row['customization_id'] ?? '')) : '';

                if ($id === '' || isset($viste[$id])) {
                    return false;
                }

                $viste[$id] = true;

                return true;
            }));
        }

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
        bool $conVarianti = true,
        array $riprese = []
    ): void {
        // `$riprese`: lo scheletro ripreso dal generatore, con la chiave della
        // sua riga nuova. Dove quella è scritta vince lei.
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

            $nuova = $riprese[$productId] ?? null;
            $quantita = $leggiGiacenza ? Stocktake::quantity($riga['stock'] ?? null) : null;

            if ($nuova !== null && Stocktake::quantity($righe[$nuova]['stock'] ?? null) !== null) {
                $quantita = null;
            }

            if ($quantita !== null) {
                $scritte[$productId] = $quantita;
            }

            if ($nuova === null || !static::isUpload($caricate[$nuova]['photo'] ?? null)) {
                $foto[$productId] = $caricate[$chiave]['photo'] ?? null;
            }
        }

        static::adjustStock($scritte);

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
     * scrivere prima. La scorta minima è già scritta: `saveMinStocks()` gira
     * prima, e il carico rinfresca l'avviso con quella giusta.
     *
     * Lo scheletro ripreso per la prima combinazione (`$riprese`) ha già i
     * suoi pezzi: il numero scritto è quanti devono essercene, come in ogni
     * riga che esiste, non un carico da sommare.
     *
     * @param array<string, array{product_id: int, variant_id: int, priced: bool}> $nate
     * @param array<string, mixed> $scritte
     * @param array<string, mixed> $files
     * @param array<int, string> $riprese
     */
    protected static function saveNewVersions(
        int $modelId,
        array $nate,
        array $scritte,
        array $files,
        array $riprese = []
    ): void {
        if ($nate === []) {
            return;
        }

        $caricate = Repeater::filesFromRequest('products', $files);

        foreach ($nate as $chiave => $riga) {
            $scritto = is_array($scritte[$chiave] ?? null) ? $scritte[$chiave] : [];
            $productId = (int) $riga['product_id'];
            // Con più sedi la colonna è il totale da leggere: i pezzi
            // arrivano dalle righe per sede, anche per un'opzione che nasce.
            $quantita = static::stockIsWritable()
                ? Stocktake::quantity($scritto['stock'] ?? null)
                : null;

            if ($quantita !== null && isset($riprese[$productId])) {
                static::adjustStock([$productId => $quantita]);
            } elseif ($quantita !== null) {
                static::loadInitialStock($productId, $quantita);
            }

            static::saveOptionImage(
                $modelId,
                $riga['product_id'],
                $caricate[$chiave]['photo'] ?? null
            );
        }
    }

    /**
     * La giacenza di un prodotto appena nato.
     *
     * Non si scrive: si carica. Il movimento resta, con la sua causale, come
     * per ogni altro pezzo che entra; zero o niente non lasciano traccia.
     */
    protected static function loadInitialStock(int $productId, float $quantita): void
    {
        if ($productId <= 0 || $quantita <= 0) {
            return;
        }

        Stock::apply([
            'product_id' => $productId,
            'quantity' => $quantita,
            'reason' => 'initial_stock',
            'note' => 'Giacenza iniziale, dalla scheda dell\'articolo',
        ]);
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
        if (!static::isUpload($file) || $productId <= 0) {
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

    /** Se nella casella c'è davvero un file caricato. */
    protected static function isUpload(mixed $file): bool
    {
        if (!is_array($file) || !isset($file['name'])) {
            return false;
        }

        $nomi = (array) $file['name'];
        $errori = (array) ($file['error'] ?? []);

        // `UPLOAD_ERR_NO_FILE`: la casella è rimasta vuota, e va benissimo.
        return trim((string) ($nomi[0] ?? '')) !== ''
            && (int) ($errori[0] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    }

    /**
     * Le foto di ogni area, dal campo unico che le tiene tutte.
     *
     * Il campo manda un manifesto: l'elenco dei file nell'ordine voluto, dove
     * una stringa è un file che c'era già e un numero è la posizione di un
     * file appena caricato. Da lì nascono, si riordinano e spariscono le
     * righe di `gst_product_images` — una per foto, perché è una riga per
     * foto che la coda delle misure sa lavorare.
     *
     * @param list<int> $giaSalvate varianti le cui foto le ha già scritte la
     *        testata del gruppo: una seconda area le riscriverebbe
     */
    protected static function saveImages(int $modelId, array $post, array $files, array $giaSalvate = []): void
    {
        foreach (array_keys(static::imageTargets($modelId)) as $variantId) {
            $campo = 'images_'.(int) $variantId;

            if (in_array((int) $variantId, $giaSalvate, true)) {
                continue;
            }

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
     * Le foto che le testate dei gruppi hanno postato, colore per colore.
     *
     * La chiave del gruppo è l'id del valore; la variante si cerca dopo il
     * generatore, così un colore appena spuntato ha già la sua. Un colore
     * che non c'è — in creazione, quello di cui si sono annullate tutte le
     * righe — non ha variante, e le sue foto si lasciano cadere.
     *
     * @return list<int> le varianti le cui foto sono state scritte
     */
    protected static function saveGroupImages(int $modelId, array $post, array $files): array
    {
        $gruppi = Repeater::groupFilesFromRequest('group_images', $post, $files);

        if ($gruppi === []) {
            return [];
        }

        $varianteDi = [];

        foreach (static::variantValues($modelId) as $variantId => $valueId) {
            if ($valueId > 0) {
                $varianteDi[$valueId] = (int) $variantId;
            }
        }

        $salvate = [];

        foreach ($gruppi as $chiave => $gruppo) {
            $variantId = $varianteDi[(int) $chiave] ?? 0;

            if ($variantId <= 0 || in_array($variantId, $salvate, true)) {
                continue;
            }

            static::syncAreaImages($modelId, $variantId, $gruppo['manifest'], $gruppo['files']);
            $salvate[] = $variantId;
        }

        return $salvate;
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

    /**
     * Dopo un errore il core ridà al form quello che è arrivato, senza `id`:
     * le righe della griglia portano ancora il JSON scritto nella finestra
     * «Giacenza», ma il bottone non si invia e il suo riassunto va rifatto
     * da lì (P103). Senza più sedi, o senza JSON, non tocca niente.
     */
    protected static function withFormStockButtons(array $values): array
    {
        if (!is_array($values['products'] ?? null) || !static::hasManyLocations()) {
            return $values;
        }

        $mostrate = Locations::shown();

        foreach ($values['products'] as $index => $row) {
            $json = is_array($row) ? ($row['locations'] ?? null) : null;

            if (is_string($json) && trim($json) !== '') {
                $values['products'][$index]['stock_button'] = LocationRows::summaryOfRows(
                    $mostrate,
                    LocationRows::fromForm($json) ?? []
                );
            }
        }

        return $values;
    }

    /**
     * Dopo un errore il core ridà al form quello che è arrivato, senza `id`:
     * il JSON scritto nella finestra «Fornitori» c'è ancora, ma il bottone
     * non si invia e il suo riassunto va rifatto da lì (P110). I due campi
     * del fornitore unico tornano da soli.
     */
    protected static function withFormSupplierButtons(array $values): array
    {
        $modelId = static::currentId() ?? 0;

        if (static::supplierMode($modelId) !== 'modal') {
            return $values;
        }

        $scelte = static::supplierChoices($modelId);
        $riassunto = static fn (mixed $json): ?string => is_string($json) && trim($json) !== ''
            ? ProductSuppliers::summary(ProductSuppliers::fromJson($json) ?? [], $scelte)
            : null;

        foreach (is_array($values['products'] ?? null) ? $values['products'] : [] as $index => $row) {
            $testo = is_array($row) ? $riassunto($row['suppliers'] ?? null) : null;

            if ($testo !== null) {
                $values['products'][$index]['suppliers_button'] = $testo;
            }
        }

        $testo = $riassunto($values['product_suppliers'] ?? null);

        if ($testo !== null) {
            $values['product_suppliers_button'] = $testo;
        }

        return $values;
    }

    /**
     * I fornitori delle opzioni, per il form (P109): i due campi del
     * fornitore unico, o il JSON della finestra con il suo riassunto.
     *
     * Quelli delle righe della griglia, e quelli dell'articolo senza
     * varianti con il prefisso `product_`. Una lettura sola per tutte le
     * opzioni.
     *
     * @param array<string, mixed>|null $product il prodotto unico, se c'è
     */
    protected static function supplierFormValues(int $modelId, array $values, ?array $product): array
    {
        $modo = static::supplierMode($modelId);

        if ($modo !== 'flat' && $modo !== 'modal') {
            return $values;
        }

        $legami = static::supplierLinks($modelId);
        $scelte = static::supplierChoices($modelId);
        $unico = static::soleSupplierId($modelId);

        foreach (is_array($values['products'] ?? null) ? $values['products'] : [] as $index => $row) {
            if (is_array($row)) {
                $values['products'][$index] = static::supplierFields(
                    $row,
                    '',
                    $modo,
                    $legami[(int) ($row['id'] ?? 0)] ?? [],
                    $scelte,
                    $unico
                );
            }
        }

        if ($product !== null) {
            $values = static::supplierFields(
                $values,
                'product_',
                $modo,
                $legami[(int) $product['id']] ?? [],
                $scelte,
                $unico
            );
        }

        return $values;
    }

    /**
     * I campi dei fornitori di un'opzione. Dopo un salvataggio rifiutato la
     * riga torna con quello scritto, e quello resta: si riempie solo quello
     * che manca. Il riassunto accanto al bottone «Fornitori» segue il campo
     * nascosto, cioè quello che il salvataggio terrebbe.
     *
     * @param array<string, mixed> $row la riga della griglia, o tutto il form
     * @param list<array<string, mixed>> $links i legami dell'opzione
     * @param array<int, string> $choices
     * @return array<string, mixed>
     */
    protected static function supplierFields(
        array $row,
        string $prefix,
        string $mode,
        array $links,
        array $choices,
        int $soleSupplierId = 0
    ): array {
        if ($mode === 'flat') {
            if (array_intersect([$prefix.'supplier_sku', $prefix.'supplier_cost'], array_keys($row)) !== []) {
                return $row;
            }

            $suo = null;

            foreach ($links as $link) {
                if ((int) ($link['supplier_id'] ?? 0) === $soleSupplierId) {
                    $suo = $link;
                    break;
                }
            }

            $row[$prefix.'supplier_sku'] = (string) ($suo['supplier_sku'] ?? '');
            $row[$prefix.'supplier_cost'] = static::formCost($suo['cost'] ?? null);

            return $row;
        }

        // Vuoto è una finestra mai salvata: i legami sono quelli di prima.
        $scritto = $row[$prefix.'suppliers'] ?? null;

        if (!is_string($scritto) || trim($scritto) === '') {
            $row[$prefix.'suppliers'] = (string) json_encode(array_map(
                static fn (array $link): array => [
                    'supplier_id' => (int) ($link['supplier_id'] ?? 0),
                    'supplier_sku' => (string) ($link['supplier_sku'] ?? ''),
                    'cost' => static::formCost($link['cost'] ?? null),
                ],
                array_values($links)
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $row[$prefix.'suppliers_button'] = ProductSuppliers::summary(
            ProductSuppliers::fromJson($row[$prefix.'suppliers']) ?? [],
            $choices
        );

        return $row;
    }

    /**
     * Il costo per la casella: grezzo, col punto e due decimali. Le cifre e
     * la valuta le mette lei, e vuoto resta vuoto.
     */
    protected static function formCost(mixed $cost): string
    {
        return is_numeric($cost) ? number_format(round((float) $cost, 2), 2, '.', '') : '';
    }

    /** Riempie il form con ciò che non sta nella tabella del modello. */
    public static function mutateFormValues(
        array $values,
        string $mode,
        string $context = 'backend'
    ): array {
        $modelId = (int) ($values['id'] ?? 0);

        // Le descrizioni scritte prima dell'editor: quella lunga era testo
        // semplice e arriva a paragrafi, quella breve era un'area di testo e
        // ora è una riga sola.
        if (array_key_exists('description', $values)) {
            $values['description'] = static::editorHtml((string) ($values['description'] ?? ''));
        }

        if (array_key_exists('short_description', $values)) {
            $values['short_description'] = static::oneLine((string) ($values['short_description'] ?? ''));
        }

        // Con più sedi un articolo nuovo parte con una riga sulla sede
        // principale, vuota (P102): il repeater non ha relazione, e il core
        // non ha niente da caricare. Dopo un errore le righe tornano dal form.
        if ($mode !== 'edit' && static::hasManyLocations() && !is_array($values['locations'] ?? null)) {
            $values['locations'] = [[
                'location_id' => (string) Locations::mainId(),
                'stock' => '',
                'min_stock' => '',
            ]];
        }

        if ($mode !== 'edit' || $modelId === 0) {
            return static::withFormSupplierButtons(static::withFormStockButtons($values));
        }

        if (static::isBundleModel($modelId)) {
            $values = static::withBundleFormValues($modelId, $values);
        }

        $values['categories'] = array_map('strval', static::categoryIds($modelId));
        $values['main_category'] = (string) (static::mainCategoryId($modelId) ?: '');

        if ((int) ($values['tax_category_id'] ?? 0) <= 0) {
            $values['tax_category_id'] = (string) (TaxCategories::defaultId() ?: '');
        }

        $values['tags'] = array_map('strval', static::tagIds($modelId));

        $rows = ProductAttributes::rows('model', $modelId);

        foreach (static::technicalAttributes() as $attribute) {
            $id = (int) $attribute['id'];
            $values['attribute_'.$id] = static::technicalValue($attribute, $rows[$id] ?? []);
        }

        $product = static::soleProduct($modelId);

        if ($product !== null) {
            $values['product_ean'] = (string) ($product['ean'] ?? '');
        }

        // Con una versione sola la casella è il prezzo di quella versione, e
        // si comporta come ci si aspetta. Con più versioni è nascosta e
        // **resta vuota**: non c'è un prezzo solo da mostrare.
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

            $ids = array_map(
                static fn ($row): int => (int) (is_array($row) ? ($row['id'] ?? 0) : 0),
                $values['products']
            );
            $levels = Levels::forProducts($ids);
            $sedi = static::hasManyLocations();
            // Con una sede sola la soglia della principale, per la colonna
            // «Scorta minima»; con più sedi pezzi e soglie per sede, per il
            // JSON e il riassunto del bottone «Giacenza» (P103).
            $soglie = $sedi ? [] : static::mainThresholds($ids);
            $perSede = $sedi ? Levels::byLocationForProducts($ids) : [];
            $soglieSede = $sedi ? Thresholds::forProducts($ids) : [];
            $mostrate = $sedi ? Locations::shown() : [];

            foreach ($values['products'] as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $productId = (int) ($row['id'] ?? 0);
                $level = $levels[$productId] ?? null;

                // Il numero grezzo, col punto: le cifre e l'unità le mette
                // AutoNumeric, che una virgola la leggerebbe come migliaia.
                // Con più sedi la colonna non c'è (P113): i pezzi stanno nel
                // JSON della finestra.
                if (!$sedi) {
                    $values['products'][$index]['stock'] = static::rawNumber(
                        $level === null ? 0.0 : $level['quantity']
                    );
                }
                // Il primo asse raggruppa; quello che resta del nome si legge.
                $values['products'][$index]['group'] = $nomi[$productId]['group'] ?? '';
                $values['products'][$index]['group_value'] = $nomi[$productId]['group_value'] ?? '';
                $values['products'][$index]['option'] = $nomi[$productId]['label'] ?? '';
                $values['products'][$index]['photo'] = $foto[$productId] ?? '';
                $values['products'][$index]['combination'] = $nomi[$productId]['key'] ?? '';

                // La soglia della riga, grezza come la giacenza: quella della
                // sede principale, e vuota se non c'è. Una riga tornata dal
                // form dopo un errore ha già quella scritta.
                if (!$sedi && !array_key_exists('min_stock', $row)) {
                    $soglia = (float) ($soglie[$productId] ?? 0);
                    $values['products'][$index]['min_stock'] = $soglia > 0 ? static::rawNumber($soglia) : '';
                }

                // Con più sedi: il JSON delle righe per sede nel campo
                // nascosto — quello tornato dal form dopo un errore resta —
                // e il riassunto «Milano 12 · Roma 3» accanto al bottone.
                if ($sedi) {
                    $json = $row['locations'] ?? null;

                    if (!is_string($json) || trim($json) === '') {
                        $values['products'][$index]['locations'] = json_encode(LocationRows::compose(
                            $mostrate,
                            $perSede[$productId] ?? [],
                            $soglieSede[$productId] ?? []
                        ), JSON_THROW_ON_ERROR);
                    }

                    // Il riassunto dice quello che c'è nel JSON: i pezzi
                    // letti, o quelli scritti e tornati dal form.
                    $values['products'][$index]['stock_button'] = is_string($json) && trim($json) !== ''
                        ? LocationRows::summaryOfRows($mostrate, LocationRows::fromForm($json) ?? [])
                        : LocationRows::summary($mostrate, $perSede[$productId] ?? []);
                }
            }
        }

        if ($unaVersione && static::hasManyLocations()) {
            $productId = (int) $product['id'];

            // Le righe per sede (P102): quelle tornate dal form dopo un errore
            // restano; le altre le compone `LocationRows`, una per sede con
            // pezzi o soglia. I numeri vanno grezzi, col punto — le cifre e
            // l'unità le mette AutoNumeric — e una soglia a zero è una
            // casella vuota.
            if (!is_array($values['locations'] ?? null)) {
                $values['locations'] = LocationRows::compose(
                    Locations::shown(),
                    Levels::byLocation($productId),
                    Thresholds::forProduct($productId)
                );
            }

            foreach ($values['locations'] as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                if (is_numeric($row['stock'] ?? null)) {
                    $values['locations'][$index]['stock'] = static::rawNumber((float) $row['stock']);
                }

                if (is_numeric($row['min_stock'] ?? null)) {
                    $soglia = (float) $row['min_stock'];
                    $values['locations'][$index]['min_stock'] = $soglia > 0 ? static::rawNumber($soglia) : '';
                }
            }
        } elseif ($unaVersione) {
            $values['product_stock'] = static::rawNumber(
                Levels::of((int) $product['id'])['quantity']
            );
            // Grezza come la giacenza: le cifre e l'unità le mette
            // AutoNumeric. È la soglia della sede principale (P100), e vuota
            // se non c'è.
            $soglia = (float) (static::mainThresholds([(int) $product['id']])[(int) $product['id']] ?? 0);
            $values['product_min_stock'] = $soglia > 0 ? static::rawNumber($soglia) : '';
        }

        // I fornitori, opzione per opzione (P109): i due campi o il JSON
        // della finestra, nelle righe della griglia e nel riquadro «Prodotto».
        $values = static::supplierFormValues($modelId, $values, $product);

        // Un articolo che ha già più opzioni risponde «sì» comunque, anche se
        // la colonna dice altro: è nato prima che la domanda esistesse.
        $values['has_variants'] = static::hasVariants($modelId) ? 'true' : 'false';

        // L'interruttore riassume le opzioni. Dopo un errore il form torna
        // con quello scritto, e quello resta.
        if (Gestionale::feature('backorders')) {
            $values += static::backorderSummary(static::products($modelId));
        }

        foreach (array_keys(static::imageTargets($modelId)) as $variantId) {
            $values['images_'.$variantId] = static::imageNames($modelId, (int) $variantId);
        }

        foreach (static::usedOptionValues($modelId) as $key => $ids) {
            $values[$key] = $ids;
        }

        return $values;
    }

    /**
     * Una riga nuova del repeater deve avere codice e indirizzo; una riga
     * vecchia tiene quello che non è cambiato davvero.
     */
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
     *
     * Vale anche per un'opzione tolta dalla griglia: è nel cestino, ma i suoi
     * movimenti sono ancora lì.
     */
    public static function assertDeletable(int|string $id): void
    {
        foreach (static::allProducts((int) $id) as $product) {
            Bundles::assertNotUsed($product);

            if (StockHistory::hasMovements((int) $product['id'])) {
                // `refusal()` e non `make()`: chi cancella dall'elenco
                // intercetta `RuntimeException` (vedi `UserError`).
                throw UserError::refusal('product.has_movements');
            }
        }

        // Un multiprodotto non ha movimenti suoi (li hanno i componenti): se è
        // già in un ordine, quell'ordine parla di lui e non si lascia solo.
        foreach (static::allProducts((int) $id) as $product) {
            if (Bundles::isBundle((int) $product['id']) && static::soldInOrders((int) $product['id'])) {
                throw UserError::refusal('product.in_orders');
            }
        }
    }

    private static function soldInOrders(int $productId): bool
    {
        $row = OrderItem::find(['product_id' => $productId, 'deleted' => 'false'], 1);

        return is_array($row) && $row !== [];
    }

    /**
     * Dalla griglia delle opzioni non si toglie e non si ferma una versione
     * che sta dentro un multiprodotto. La griglia c'è solo quando le versioni
     * sono più d'una: senza, non c'è niente da guardare.
     */
    public static function assertVersionsKept(int $modelId): void
    {
        if ($modelId <= 0 || !isset($_POST['products'])) {
            return;
        }

        $arrivate = [];

        foreach (static::postedRows((array) $_POST) as $riga) {
            $productId = (int) ($riga['id'] ?? 0);

            if ($productId > 0) {
                $arrivate[$productId] = $riga;
            }
        }

        foreach (static::products($modelId) as $product) {
            $riga = $arrivate[(int) $product['id']] ?? null;

            if ($riga === null || ($riga['active'] ?? 'true') === 'false') {
                Bundles::assertNotUsed($product);
            }
        }
    }

    public static function deleteRecord(int|string $id): object
    {
        $modelId = (int) $id;

        static::assertDeletable($modelId);

        Transaction::run(static function () use ($modelId): void {
            // I fornitori prima delle opzioni, anche di quelle già tolte dalla
            // griglia e senza `purchasing`: la chiave esterna non lascerebbe
            // eliminare il prodotto, e i legami senza articolo non servono.
            // Con loro le soglie per sede (P100), che senza prodotto non dicono
            // più niente.
            $productIds = array_map(
                static fn (array $product): int => (int) $product['id'],
                static::rowsOf(Product::class, ['product_model_id' => $modelId, 'deleted' => ['true', 'false']])
            );
            ProductSuppliers::dropFor($productIds);
            Thresholds::dropFor($productIds);

            // La composizione di un multiprodotto, anche a funzionalità spenta:
            // le opzioni prima dei gruppi, la chiave esterna le lega.
            foreach (static::bundleRows(BundleGroup::class, ['product_model_id' => $modelId]) as $gruppo) {
                static::syncBundleRows(BundleGroupOption::class, ['bundle_group_id' => (int) $gruppo['id']], []);
                BundleGroup::delete((int) $gruppo['id']);
            }

            static::syncBundleRows(BundleComponent::class, ['product_model_id' => $modelId], []);

            // I collegamenti alle personalizzazioni, anche a funzionalità
            // spenta: la chiave esterna non lascerebbe eliminare l'articolo.
            foreach (static::rowsOf(ProductModelCustomization::class, ['product_model_id' => $modelId, 'deleted' => ['true', 'false']]) as $link) {
                ProductModelCustomization::delete((int) $link['id']);
            }

            // Anche le opzioni tolte dalla griglia, prima delle varianti: una
            // riga nel cestino tiene ferma la sua variante come le altre.
            foreach (static::allProducts($modelId) as $product) {
                foreach (ProductAttributes::read('product', (int) $product['id']) as $link) {
                    ProductAttributes::modelClass('product')::delete((int) $link['id']);
                }

                StockHistory::dropAlerts([(int) $product['id']]);
                Product::delete((int) $product['id']);
            }

            foreach (static::variants($modelId) as $variant) {
                foreach (ProductAttributes::read('variant', (int) $variant['id']) as $link) {
                    ProductAttributes::modelClass('variant')::delete((int) $link['id']);
                }

                ProductVariant::delete((int) $variant['id']);
            }

            // Tutte le righe: un attributo a valori ne ha una per valore.
            foreach (ProductAttributes::rows('model', $modelId) as $links) {
                foreach ($links as $link) {
                    ProductAttributes::modelClass('model')::delete((int) $link['id']);
                }
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
                ->options(static::valueChoices($attribute))
                ->label((string) ($attribute['name'] ?? ''))
                // Il valore nuovo entra nell'elenco del negozio, non in questo
                // articolo: per questo il bottone dice "aggiungi colore" e non
                // "aggiungi colore a questo prodotto".
                ->quickCreate(
                    AttributeValueResource::class,
                    label: 'label',
                    button: 'Aggiungi opzione',
                    layout: static::newValueLayout($id, 'Nuovo '.$nome),
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
            $voci .= '<li><button type="button" class="dropdown-item" data-wi-option-add="'.(int) $attribute['id'].'">'
                .static::escape((string) ($attribute['name'] ?? '')).'</button></li>';
        }

        // Un bottone largo quanto il riquadro, sotto l'ultimo attributo: la
        // select in alto a destra si notava poco, e l'attributo scelto
        // compariva lontano da dove si era cliccato. Il bordo tratteggiato
        // dice «qui si aggiunge», come uno spazio vuoto.
        //
        // Un `div`, non il `p` di un testo: dentro c'è un altro `div`, che un
        // `p` chiuderebbe prima del tempo.
        return RichText::make(<<<HTML
<div class="wi-option-picker d-flex flex-column gap-1">
    <div class="dropdown wi-option-add">
        <button type="button" class="btn btn-outline-secondary w-100 wi-option-choose" style="border-style:dashed" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-plus-lg me-1"></i>Aggiungi un attributo
        </button>
        <ul class="dropdown-menu w-100">
            {$voci}
        </ul>
    </div>
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

            var restano = false;
            var pieno = accesi() >= MASSIMO;

            picker.querySelectorAll('[data-wi-option-add]').forEach(function (voce) {
                var nodo = document.querySelector('[data-wi-option="' + voce.getAttribute('data-wi-option-add') + '"]');
                var acceso = !!nodo && nodo.getAttribute('data-wi-option-on') === 'true';

                // Il menu propone solo quelli non ancora aggiunti.
                voce.parentElement.classList.toggle('d-none', acceso);
                voce.disabled = acceso;

                if (!acceso) restano = true;
            });

            picker.querySelector('.wi-option-add').classList.toggle('d-none', !restano || pieno);
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
                var togli = function () {
                    caselle(nodo).forEach(function (casella) { casella.checked = false; });
                    mostra(nodo, false);
                    scriviOrdine(ordine());
                    aggiornaSelettore();
                    if (typeof window.wiOptionsGrid === 'function') window.wiOptionsGrid();
                };

                // Si chiede sempre: un clic storto sulla X si porterebbe via
                // le spunte e le righe che hanno fatto nascere.
                var spuntati = caselle(nodo).filter(function (casella) { return casella.checked; }).length;
                var conferma = {
                    title: "Togliere l'attributo " + titolo(nodo) + '?',
                    text: spuntati > 0
                        ? 'Spariscono le spunte di ' + nome(nodo) + ' e le righe che hanno aggiunto alla griglia.'
                        : 'Il blocco si chiude: lo riapri da «Aggiungi un attributo».',
                    cancelLabel: 'Annulla',
                    confirmLabel: 'Togli',
                    confirmClass: 'btn btn-danger',
                };

                // La finestra è quella del repeater, che sta nella stessa
                // pagina; senza, la conferma del browser.
                if (typeof window.wiRepeaterConfirmDelete === 'function') {
                    window.wiRepeaterConfirmDelete(togli, conferma);
                } else if (window.confirm(conferma.title + ' ' + conferma.text)) {
                    togli();
                }
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

            picker.querySelectorAll('[data-wi-option-add]').forEach(function (voce) {
                voce.addEventListener('click', function () {
                    var nodo = document.querySelector('[data-wi-option="' + voce.getAttribute('data-wi-option-add') + '"]');

                    if (nodo && accesi() < MASSIMO) mostra(nodo, true);

                    scriviOrdine(ordine());
                    aggiornaSelettore();

                    // La voce cliccata ora è nascosta, e il fuoco cadrebbe
                    // sulla pagina: va sul primo valore del blocco comparso,
                    // che è la cosa da fare dopo.
                    var primo = nodo ? caselle(nodo)[0] : null;
                    if (primo) primo.focus();
                });
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
     * Il codice che tiene la giacenza al passo con l'unità di misura.
     *
     * Passare da pezzi a chili in «Misure» cambia subito i decimali e l'unità
     * in coda della giacenza: quella dell'articolo, quelle della griglia e il
     * modello delle righe che nasceranno. Una giacenza che ha già dei
     * decimali ne tiene tre, come la disegna il server, e una casella di sola
     * lettura resta di sola lettura.
     *
     * I decimali di ogni unità arrivano da {@see SaleUnits}: lo script non ne
     * sa niente di suo.
     */
    protected static function unitScript(): object
    {
        $decimali = static::escape(json_encode(SaleUnits::decimalsMap(), JSON_THROW_ON_ERROR));

        return RichText::make(<<<HTML
<div class="wi-stock-unit" data-wi-unit-decimals="{$decimali}"></div>
<script>
    window.wiStockUnit = window.wiStockUnit || (function () {
        function mappa() {
            var radice = document.querySelector('.wi-stock-unit');

            try {
                return JSON.parse(radice ? radice.getAttribute('data-wi-unit-decimals') || '{}' : '{}');
            } catch (errore) {
                return {};
            }
        }

        function autoNumeric(campo) {
            return window.AutoNumeric && typeof window.AutoNumeric.getAutoNumericElement === 'function'
                ? window.AutoNumeric.getAutoNumericElement(campo)
                : null;
        }

        // Il numero nella casella: quello di AutoNumeric quando c'è, se no
        // letto con pazienza — «2,500 kg», «12 pz», «1.234,5».
        function numero(campo) {
            var an = autoNumeric(campo);
            var valore;

            if (an) {
                valore = Number(an.getNumber());

                return isNaN(valore) ? 0 : valore;
            }

            var testo = String(campo.value || '')
                .replace(/[\s  ]/g, '')
                .replace(/[^0-9.,]+\$/, '');

            if (testo.indexOf(',') !== -1) {
                testo = testo.replace(/\./g, '').replace(',', '.');
            }

            valore = parseFloat(testo);

            return isNaN(valore) ? 0 : valore;
        }

        function frazionario(valore) {
            return Math.round(valore * 1000) / 1000 !== Math.round(valore);
        }

        function formatta(campo, cifre, simbolo) {
            campo.setAttribute('data-wi-number-decimal', String(cifre));
            campo.setAttribute('data-wi-number-symbol', simbolo);

            var an = autoNumeric(campo);

            if (an) {
                // Senza readOnly, update() rimetterebbe scrivibile una
                // casella che con più sedi si legge e basta.
                an.update({
                    decimalPlaces: cifre,
                    decimalPlacesShownOnFocus: cifre,
                    currencySymbol: simbolo,
                    readOnly: campo.readOnly
                });
            }
        }

        function applica(unita) {
            unita = String(unita || '').trim().toLowerCase();

            var decimali = mappa();
            var cifre = Object.prototype.hasOwnProperty.call(decimali, unita) ? Number(decimali[unita]) : 3;
            var simbolo = unita !== '' ? ' ' + unita : '';

            // La giacenza e la scorta minima dell'articolo senza varianti.
            ['product_stock', 'product_min_stock'].forEach(function (nome) {
                document.querySelectorAll('[name="' + nome + '"]').forEach(function (campo) {
                    formatta(campo, frazionario(numero(campo)) ? 3 : cifre, simbolo);
                });
            });

            // Le stesse due colonne della griglia, delle righe per sede e
            // della finestra «Giacenza» (P102, P103). `[stock]` non prende
            // `[min_stock]`: ognuna ha il suo selettore.
            ['stock', 'min_stock'].forEach(function (chiave) {
                var fine = '[name\$="[' + chiave + ']"]';

                // Una colonna ha un formato solo: se una riga ha già dei
                // decimali, li mostrano tutte.
                var righe = Array.prototype.slice.call(document.querySelectorAll(
                    '[data-wi-repeater="products"] input[name^="products["]' + fine
                    + ', [data-wi-repeater="locations"] input[name^="locations["]' + fine
                    + ', input[name^="wi_location_stock["]' + fine
                ));
                var colonna = righe.some(function (campo) { return frazionario(numero(campo)); }) ? 3 : cifre;

                righe.forEach(function (campo) { formatta(campo, colonna, simbolo); });

                // Le righe che nasceranno: AutoNumeric le legge dal modello.
                document.querySelectorAll('[data-wi-repeater="products"] template, [data-wi-repeater="locations"] template').forEach(function (modello) {
                    modello.content.querySelectorAll('input' + fine).forEach(function (campo) {
                        campo.setAttribute('data-wi-number-decimal', String(colonna));
                        campo.setAttribute('data-wi-number-symbol', simbolo);
                    });
                });
            });
        }

        // Con jQuery basta la sua delega: sente anche il change nativo, e
        // una select rifatta da un plugin lo lancia da jQuery.
        if (window.jQuery) {
            window.jQuery(document).on('change', '[name="unit"]', function () { applica(this.value); });
        } else {
            document.addEventListener('change', function (evento) {
                if (evento.target && evento.target.name === 'unit') {
                    applica(evento.target.value);
                }
            });
        }

        return { applica: applica };
    })();
</script>
HTML)->tag('div');
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
        // L'attributo con foto proprie: quando raggruppa lui, la testata
        // porta le foto del colore.
        $asseColore = static::variantAttributeId($modelId);

        return RichText::make(<<<HTML
<div class="wi-options-grid" data-wi-existing="{$esistenti}" data-wi-photo-attribute="{$asseColore}"></div>
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
                    id: String(casella.value),
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
                scrivi(riga, 'group_value', chiaveFoto(messi[0]));
                scrivi(riga, 'option', etichette.slice(1).join(' / ') || etichette[0]);
            });
        }

        function asseFoto() {
            var root = radice();

            return root ? String(root.getAttribute('data-wi-photo-attribute') || '0') : '0';
        }

        // La chiave delle foto della testata: l'id del valore che raggruppa,
        // se è un colore. Vuota, la testata non ha il bottone delle foto.
        function chiaveFoto(valore) {
            return valore && asseFoto() !== '0' && String(valore.attribute) === asseFoto()
                ? String(valore.id)
                : '';
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
                scrivi(riga, 'group_value', chiaveFoto(combo[0]));
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
        // piatta invece di mostrare una testata «Senza scelta». Fa eccezione
        // il colore: la sua testata è il posto delle sue foto.
        function raggruppa(box, righe) {
            if (typeof window.wiRepeaterGroupApply !== 'function') return;
            if (righe.getAttribute('data-wi-group-fixed') !== 'true') return;

            var spuntati = assiSpuntati();
            var assi = spuntati.length;
            var colore = assi === 1 && chiaveFoto(spuntati[0][0]) !== '';
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
                conGruppo && (assi >= 2 || colore) ? 'group' : ''
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

        // Le foto del colore si caricano nella testata del suo gruppo: qui
        // resterebbero due posti per la stessa cosa.
        if (static::variantCount($modelId) > 1 && !static::colorPhotosInGroups($modelId)) {
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

    /**
     * I prodotti del modello, anche quelli tolti dalla griglia.
     *
     * La griglia li mette in `deleted = 'true'` e basta: restano attaccati
     * alla loro variante, con i loro avvisi e i loro movimenti. Chi elimina
     * l'articolo deve vederli tutti.
     *
     * @return list<array<string, mixed>>
     */
    protected static function allProducts(int $modelId): array
    {
        return static::rowsOf(Product::class, ['product_model_id' => $modelId, 'deleted' => ['true', 'false']], 'position');
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
        self::$supplierChoices = [];
        self::$supplierLinks = [];
        self::$inactiveSuppliers = [];
    }

    /**
     * I fornitori che la scheda propone, id => nome: gli attivi, più quelli
     * già legati a un'opzione dell'articolo anche se non lo sono più (P92).
     * Senza, un fornitore messo su «Non attivo» sparirebbe dalla finestra, e
     * il salvataggio dopo lo staccherebbe.
     *
     * Dal loro numero dipende come si compilano (P109): nessuno, niente; uno,
     * due campi; due o più, il bottone e la finestra.
     *
     * Schema, layout e controllo leggono la stessa risposta, una volta per
     * articolo e per richiesta, come attributi e valori.
     *
     * @return array<int, string>
     */
    protected static function supplierChoices(int $modelId): array
    {
        if (!array_key_exists($modelId, self::$supplierChoices)) {
            $legati = [];

            foreach (static::supplierLinks($modelId) as $legami) {
                foreach ($legami as $legame) {
                    $legati[] = (int) $legame['supplier_id'];
                }
            }

            self::$supplierChoices[$modelId] = Contacts::supplierOptions(array_values(array_unique($legati)));
        }

        return self::$supplierChoices[$modelId];
    }

    /**
     * I fornitori delle opzioni dell'articolo, per prodotto: solo le opzioni
     * che ne hanno, nell'ordine in cui si leggono (P107). Una lettura per
     * articolo e per richiesta.
     *
     * @return array<int, list<array{supplier_id: int, supplier_sku: string, cost: ?float}>>
     */
    protected static function supplierLinks(int $modelId): array
    {
        if ($modelId <= 0) {
            return [];
        }

        if (!array_key_exists($modelId, self::$supplierLinks)) {
            $prodotti = array_map(
                static fn (array $product): int => (int) ($product['id'] ?? 0),
                static::products($modelId)
            );

            self::$supplierLinks[$modelId] = ProductSuppliers::linksFor(array_values(array_filter($prodotti)));
        }

        return self::$supplierLinks[$modelId];
    }

    /**
     * I fornitori che la scheda propone solo perché già legati: sono su
     * «Non attivo». Restano nella scelta delle opzioni che li usano, e non
     * compaiono per le altre (P92).
     *
     * Il database si guarda solo se l'articolo ha dei legami.
     *
     * @return list<int>
     */
    protected static function inactiveSupplierIds(int $modelId): array
    {
        if (!array_key_exists($modelId, self::$inactiveSuppliers)) {
            $inattivi = [];

            if (static::supplierLinks($modelId) !== []) {
                $attivi = Contacts::supplierOptions();

                foreach (array_keys(static::supplierChoices($modelId)) as $id) {
                    if (!isset($attivi[$id])) {
                        $inattivi[] = (int) $id;
                    }
                }
            }

            self::$inactiveSuppliers[$modelId] = $inattivi;
        }

        return self::$inactiveSuppliers[$modelId];
    }

    /**
     * I fornitori che un'opzione può avere: quelli della scheda, meno i non
     * attivi che non sono già suoi (P92).
     *
     * Un'opzione che non è dell'articolo — una riga nuova, un id di un altro
     * articolo — ha solo quelli attivi. Il modo, due campi o finestra, resta
     * dell'articolo: lo sceglie `supplierChoices()`.
     *
     * @return array<int, string>
     */
    protected static function supplierChoicesFor(int $modelId, int $productId): array
    {
        $scelte = static::supplierChoices($modelId);
        $suoi = array_map(
            'intval',
            array_column(static::supplierLinks($modelId)[$productId] ?? [], 'supplier_id')
        );

        foreach (static::inactiveSupplierIds($modelId) as $id) {
            if (!in_array($id, $suoi, true)) {
                unset($scelte[$id]);
            }
        }

        return $scelte;
    }

    /**
     * Come si compilano i fornitori (P109): `null` senza `purchasing`,
     * `none` senza fornitori da proporre, `flat` con uno solo — due campi,
     * codice e costo — e `modal` da due in su, il bottone e la finestra.
     */
    protected static function supplierMode(int $modelId): ?string
    {
        if (!Gestionale::feature('purchasing')) {
            return null;
        }

        return match (count(static::supplierChoices($modelId))) {
            0 => 'none',
            1 => 'flat',
            default => 'modal',
        };
    }

    /** Il fornitore dei due campi, quando la scheda ne propone uno solo: zero altrimenti. */
    protected static function soleSupplierId(int $modelId): int
    {
        $scelte = static::supplierChoices($modelId);

        return count($scelte) === 1 ? (int) array_key_first($scelte) : 0;
    }

    /**
     * Il nome del fornitore unico, per il tooltip dei due campi: la tendina
     * non c'è, e di chi sono codice e costo va detto lo stesso.
     */
    protected static function soleSupplierTitle(int $modelId): string
    {
        $nome = (string) (static::supplierChoices($modelId)[static::soleSupplierId($modelId)] ?? '');

        return $nome === '' ? '' : 'title="'.static::escape($nome).', l\'unico fornitore"';
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

        if (Attributes::usesValues((string) ($attribute['type'] ?? ''))) {
            return FormField::key('attribute_'.$id)->select(static::valueOptions($attribute))->label($label);
        }

        return static::writtenField('attribute_'.$id, (string) ($attribute['type'] ?? ''), $label);
    }

    /** Il campo di un attributo che si scrive: un Numero o, altrimenti, un Testo. */
    protected static function writtenField(string $key, string $type, string $label): Input
    {
        $field = FormField::key($key);

        if ($type === 'number') {
            return $field->number()->decimal(3)->label($label);
        }

        return $field->text()->label($label);
    }

    /**
     * Le caratteristiche della scheda tecnica: gli attributi dell'articolo.
     *
     * Un attributo a valori che non ha ancora valori resta fuori: senza voci
     * le spunte del core diventano una casella sola, che salverebbe «on» come
     * se fosse un valore.
     *
     * @return list<array<string, mixed>>
     */
    protected static function technicalAttributes(): array
    {
        return array_values(array_filter(
            Attributes::byLevel(static::attributes(), 'model'),
            static fn (array $attribute): bool => !Attributes::usesValues((string) ($attribute['type'] ?? ''))
                || static::valueChoices($attribute) !== []
        ));
    }

    /**
     * Il campo di una caratteristica della scheda tecnica.
     *
     * Testo e Numero si scrivono come ovunque. Un attributo a valori si
     * spunta, perché i simboli di lavaggio sono più d'uno, e ha il «+» per un
     * valore nuovo come le opzioni in vendita. Le opzioni restano a un valore
     * per attributo: il loro campo è `attributeField()`, che serve anche alla
     * pagina dell'opzione.
     */
    protected static function technicalField(array $attribute): Input
    {
        if (!Attributes::usesValues((string) ($attribute['type'] ?? ''))) {
            return static::attributeField($attribute);
        }

        $id = (int) $attribute['id'];
        $name = (string) ($attribute['name'] ?? '');

        return FormField::key('attribute_'.$id)
            ->checkbox()
            ->pills()
            ->options(static::valueChoices($attribute))
            ->label($name)
            ->quickCreate(
                AttributeValueResource::class,
                label: 'label',
                button: 'Aggiungi valore',
                layout: static::newValueLayout($id, 'Nuovo valore di '.$name),
            );
    }

    /**
     * Il modal del «+» di un attributo a valori: il nome del valore nuovo e,
     * nascosto, l'attributo a cui appartiene.
     */
    protected static function newValueLayout(int $attributeId, string $label): Closure
    {
        return static fn (): Form => (new Form)->components([
            (new Container)->components([
                FormField::key('attribute_id')->hidden()->value((string) $attributeId),
                // Senza larghezza, in una griglia da 12 un campo ne prende una.
                AttributeValueResource::getInput('label')->label($label)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ])->columns(12);
    }

    /**
     * Il valore di una caratteristica nel form: la lista dei valori spuntati
     * per un attributo a valori, il testo o il numero della prima riga per
     * gli altri.
     *
     * @param array<string, mixed> $attribute
     * @param list<array<string, mixed>> $rows
     * @return string|list<string>
     */
    protected static function technicalValue(array $attribute, array $rows): string|array
    {
        if (!Attributes::usesValues((string) ($attribute['type'] ?? ''))) {
            return static::attributeValue($attribute, $rows[0] ?? null);
        }

        $ids = [];

        foreach ($rows as $row) {
            $id = (int) ($row['attribute_value_id'] ?? 0);

            if ($id > 0) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Le personalizzazioni che si possono scegliere per l'articolo: quelle
     * attive per nome, e le disattivate che l'articolo ha già collegate, con
     * la scritta (altrimenti il collegamento sparirebbe dalla lista senza che
     * nessuno l'abbia tolto).
     *
     * @return array<string, string>
     */
    public static function customizationOptions(int $modelId): array
    {
        $collegate = [];

        if ($modelId > 0) {
            foreach (static::rowsOf(ProductModelCustomization::class, ['product_model_id' => $modelId]) as $link) {
                $collegate[(int) $link['customization_id']] = true;
            }
        }

        $voci = ['' => '—'];

        foreach (static::rowsOf(Customization::class, [], 'name') as $row) {
            $id = (int) $row['id'];
            $nome = (string) ($row['name'] ?? '');

            if (($row['active'] ?? 'true') === 'true') {
                $voci[(string) $id] = $nome;
            } elseif (isset($collegate[$id])) {
                $voci[(string) $id] = $nome.' (disattivata)';
            }
        }

        return $voci;
    }

    /**
     * Il riquadro «Personalizzazioni»: la lista e, sotto, «Nuova
     * personalizzazione», che ne crea una al volo senza lasciare la scheda.
     */
    protected static function customizationsCard(): object
    {
        return static::foldable(
            'Personalizzazioni',
            [
                static::getInput('customizations')->columnSpan(12),
                QuickCreateButton::make(CustomizationResource::class)
                    ->text('Nuova personalizzazione')
                    ->label('name')
                    ->layout(static fn (): Container => (new Container)
                        ->columns(12)
                        ->components(CustomizationResource::quickCreateFields()))
                    ->size('sm')
                    ->id(static::CUSTOMIZATION_BUTTON)
                    ->columnSpan(12),
                static::customizationScript()->columnSpan(12),
            ],
            'Campi che il cliente compila comprando questo articolo, per tutte le sue varianti. Il sovrapprezzo, che si imposta dalla personalizzazione, si somma al prezzo.'
        );
    }

    /**
     * Alla nascita di una personalizzazione dal bottone la mette in tutti gli
     * elenchi del riquadro — e nel modello del repeater, da cui nascono le
     * righe nuove — e la sceglie nell'ultima riga ancora vuota.
     *
     * Il nome arriva da chi vende: si scrive come testo, mai come HTML.
     */
    protected static function customizationScript(): RichText
    {
        $risorsa = json_encode(CustomizationResource::slug());

        return RichText::make(<<<HTML
<script>
    (function () {
        if (window.wiCustomizationsReady) {
            return;
        }

        window.wiCustomizationsReady = true;

        var RISORSA = {$risorsa};

        function aggiungi(select, id, nome) {
            var voce = document.createElement('option');

            voce.value = String(id);
            voce.textContent = nome;
            select.appendChild(voce);
        }

        document.addEventListener('wi:quick-create:created', function (evento) {
            var dettaglio = evento.detail || {};

            if (dettaglio.family !== 'button' || dettaglio.resource !== RISORSA) {
                return;
            }

            var riga = dettaglio.item || {};
            var id = parseInt(dettaglio.id || riga.id, 10);
            var nome = String(riga.name || dettaglio.label || '');

            if (!(id > 0)) {
                return;
            }

            var selettore = 'select[name$="[customization_id]"], select[name$="customization_id"]';
            var elenchi = Array.prototype.slice.call(document.querySelectorAll(selettore));

            // Il modello del repeater: dentro un <template> i select stanno nel
            // suo contenuto, e `querySelectorAll` del documento non ci arriva.
            Array.prototype.slice.call(document.querySelectorAll('template')).forEach(function (modello) {
                Array.prototype.slice.call(modello.content.querySelectorAll(selettore)).forEach(function (select) {
                    aggiungi(select, id, nome);
                });
            });

            elenchi.forEach(function (select) {
                aggiungi(select, id, nome);
            });

            var vuoti = elenchi.filter(function (select) {
                return select.value === '' && !select.closest('template');
            });
            var ultimo = vuoti[vuoti.length - 1];

            if (ultimo) {
                ultimo.value = String(id);
                ultimo.dispatchEvent(new Event('change', {bubbles: true}));
            }
        });
    })();
</script>
HTML)->tag('div');
    }

    /**
     * Il riquadro «Scheda tecnica»: c'è sempre, anche vuoto.
     *
     * Un posto che compare solo dopo averlo preparato in Catalogo → Attributi
     * non si trova. Il riquadro sta in fondo alla colonna larga anche senza
     * campi e dice a cosa serve.
     *
     * Si vede solo quello che l'articolo ha: ogni caratteristica sta nel suo
     * blocco, e lo script nasconde quelli vuoti. Le altre aspettano nel menu
     * «Aggiungi caratteristica», che in fondo ha «Nuova caratteristica…»:
     * Testo e Numero nascono da qui, con il campo che compare subito. Elenchi
     * e Icone hanno valori e immagini da preparare, e il menu dice dove.
     */
    protected static function technicalSheetCard(): object
    {
        $campi = [];
        $voci = [];

        foreach (static::technicalAttributes() as $attribute) {
            $id = (int) $attribute['id'];
            $nome = (string) ($attribute['name'] ?? '');
            $input = static::getInput('attribute_'.$id);

            $campi[] = static::technicalBlock($input, (string) $id, $nome, $input instanceof InputCheckbox ? 12 : 6);
            $voci[$id] = $nome;
        }

        $nuova = static::technicalNew();

        // La frase del riquadro vuoto c'è solo quando serve: nascosta lascerebbe
        // la sua colonna, e un buco fra i campi e il bottone.
        if ($campi === []) {
            $campi[] = RichText::make(
                '<p class="text-body-secondary mb-0 wi-technical-empty">'
                .static::escape(static::technicalEmptyText())
                .'</p>'
            )->tag('div')->columnSpan(12);
        }

        return static::foldable(
            'Scheda tecnica',
            [
                ...$campi,
                // Il bottone vero del modal: lo apre la voce del menu, e lo
                // script — che viene dopo — ne nasconde la colonna appena la
                // legge. Il modal intanto se n'è andato in fondo alla pagina.
                ...($nuova ? [
                    QuickCreateButton::make(AttributeResource::class)
                        ->text('Nuova caratteristica')
                        ->label('name')
                        ->layout(static fn (): Container => (new Container)
                            ->columns(12)
                            ->components(AttributeResource::quickCreateFields()))
                        ->size('sm')
                        ->id(static::TECHNICAL_BUTTON)
                        ->columnSpan(12),
                ] : []),
                static::technicalScript($voci, $nuova)->columnSpan(12),
            ],
            static::technicalTooltip()
        );
    }

    /**
     * Da qui nasce una caratteristica nuova?
     *
     * Nella scheda dell'articolo sì; in quella dell'opzione no, perché
     * nascerebbe sull'articolo e l'opzione non la vedrebbe.
     */
    protected static function technicalNew(): bool
    {
        return true;
    }

    /** La frase del riquadro senza nessuna caratteristica compilata. */
    protected static function technicalEmptyText(): string
    {
        return 'Qui vanno le caratteristiche che l\'articolo ha qualunque opzione si scelga: materiale, composizione, lavaggio.';
    }

    /** Il tooltip del titolo «Scheda tecnica». */
    protected static function technicalTooltip(): string
    {
        return 'Quello che descrive l\'articolo e non fa nascere opzioni in vendita: materiale, composizione, lavaggio. Si vede solo quello che è compilato: il resto si aggiunge da «Aggiungi caratteristica», dove nasce anche una caratteristica nuova di testo o di numero. Gli elenchi con i loro valori, come i simboli di lavaggio, si preparano in Catalogo → Attributi.';
    }

    /**
     * Il blocco di una caratteristica: il campo, e sul contenitore l'id e il
     * nome che lo script usa per mostrarlo, nasconderlo e rimetterlo nel menu.
     *
     * Come i blocchi delle opzioni, gli attributi finiscono sul nodo interno:
     * quello da nascondere è il suo genitore, la colonna.
     */
    protected static function technicalBlock(object $campo, string $id, string $name, int $span): Container
    {
        return (new Container)
            ->components([$campo->columnSpan(12)])
            ->attr('data-wi-technical', $id)
            ->attr('data-wi-technical-name', $name)
            ->columns(12)
            ->columnSpan($span);
    }

    /**
     * Il menu «Aggiungi caratteristica», i modelli dei campi di testo e di
     * numero e lo script del riquadro.
     *
     * Lo script mostra solo i blocchi compilati, mette la × per toglierne uno
     * — che lo svuota: nascosto verrebbe salvato lo stesso — e alla nascita
     * di una caratteristica ne copia il modello. Il modello è il campo vero,
     * renderizzato dal core con un segnaposto al posto dell'id: lo script ci
     * scrive l'id e il nome, e il campo si salva con l'articolo come gli
     * altri, perché al salvataggio gli attributi si rileggono dal database.
     *
     * Nella scheda dell'opzione una caratteristica non nasce da qui: nascerebbe
     * sull'articolo, dove l'opzione non la vedrebbe. Con `$nuova` a `false` il
     * menu perde «Nuova caratteristica…» e i modelli dei campi, e al loro
     * posto dice dove si prepara.
     *
     * @param array<int, string> $voci le caratteristiche, per id
     */
    protected static function technicalScript(array $voci = [], bool $nuova = true): RichText
    {
        $modelli = '';

        foreach ($nuova ? AttributeResource::QUICK_TYPES : [] as $type) {
            $campo = static::writtenField('attribute___WI_ID__', $type, 'Caratteristica');
            $modelli .= '<template data-wi-technical-template="'.static::escape($type).'">'
                .ResourceFormLayoutRenderer::renderLayout((new Container)->columns(12)->components([
                    static::technicalBlock($campo, '__WI_ID__', '', 6),
                ]))
                .'</template>';
        }

        $menu = '';

        foreach ($voci as $id => $nome) {
            $menu .= '<li><button type="button" class="dropdown-item" data-wi-technical-add="'.(int) $id.'">'
                .static::escape($nome).'</button></li>';
        }

        $elenco = static::escape(static::attributesUrl());
        $risorsa = json_encode(AttributeResource::slug());
        $bottone = json_encode(static::TECHNICAL_BUTTON);

        // Senza «Nuova caratteristica…» sotto, il divisore non divide niente.
        $coda = $nuova
            ? '<li class="wi-technical-divider"><hr class="dropdown-divider"></li>'
                .'<li><button type="button" class="dropdown-item" data-wi-technical-new="true"><i class="bi bi-plus-lg me-1"></i>Nuova caratteristica…</button></li>'
                .'<li><span class="dropdown-item-text small text-body-secondary">Elenchi e simboli con le loro immagini, come quelli di lavaggio, si preparano in <a href="'.$elenco.'">Catalogo → Attributi</a>.</span></li>'
            : '<li><span class="dropdown-item-text small text-body-secondary">Una caratteristica nuova si prepara in <a href="'.$elenco.'">Catalogo → Attributi</a>, scegliendo «Scheda tecnica di ogni opzione».</span></li>';

        // Un `div`, non il `p` di un testo: dentro ci sono il menu, i modelli
        // e lo script. Il bordo tratteggiato dice «qui si aggiunge», come in
        // «Opzioni in vendita».
        return RichText::make(<<<HTML
<div class="dropdown wi-technical-picker">
    <button type="button" class="btn btn-outline-secondary w-100 wi-technical-choose" style="border-style:dashed" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-plus-lg me-1"></i>Aggiungi caratteristica
    </button>
    <ul class="dropdown-menu w-100">
        {$menu}
        {$coda}
    </ul>
</div>
{$modelli}
<script>
    window.wiTechnicalSheet = window.wiTechnicalSheet || (function () {
        var RISORSA = {$risorsa};
        var BOTTONE = {$bottone};

        // La colonna della griglia che contiene il nodo: è quella che si
        // sposta, si copia o si nasconde.
        function colonna(nodo) {
            while (nodo && nodo.parentElement && !nodo.parentElement.classList.contains('row')) {
                nodo = nodo.parentElement;
            }

            return nodo;
        }

        function blocchi() {
            return Array.prototype.slice.call(document.querySelectorAll('[data-wi-technical]'));
        }

        // Le caselle di una caratteristica, per nome: il modal del «+» di un
        // elenco ha le sue, e non contano.
        function caselle(nodo) {
            var id = nodo.getAttribute('data-wi-technical');

            return Array.prototype.slice.call(nodo.querySelectorAll(
                '[name="attribute_' + id + '"], [name="attribute_' + id + '[]"]'
            ));
        }

        function spunta(casella) {
            return casella.type === 'checkbox' || casella.type === 'radio';
        }

        function scritto(nodo) {
            return caselle(nodo).some(function (casella) {
                return spunta(casella) ? casella.checked : String(casella.value || '').trim() !== '';
            });
        }

        function nome(nodo) {
            return nodo.getAttribute('data-wi-technical-name') || 'Caratteristica';
        }

        // Il divisore del menu serve solo se sopra c'è qualcosa, e la frase
        // del riquadro vuoto solo se non si vede nessun campo.
        function riordina() {
            var restano = Array.prototype.slice.call(document.querySelectorAll('[data-wi-technical-add]'))
                .some(function (voce) { return !voce.parentElement.classList.contains('d-none'); });
            var divisore = document.querySelector('.wi-technical-divider');
            var vuoto = document.querySelector('.wi-technical-empty');

            if (divisore) {
                divisore.classList.toggle('d-none', !restano);
            }

            if (vuoto) {
                colonna(vuoto).classList.toggle('d-none', blocchi().some(function (nodo) {
                    return nodo.getAttribute('data-wi-technical-on') === 'true';
                }));
            }
        }

        // Un blocco acceso si vede e sparisce dal menu; spento, il contrario.
        function mostra(nodo, acceso) {
            nodo.parentElement.classList.toggle('d-none', !acceso);
            nodo.setAttribute('data-wi-technical-on', acceso ? 'true' : 'false');

            var voce = document.querySelector('[data-wi-technical-add="' + nodo.getAttribute('data-wi-technical') + '"]');

            if (voce) {
                voce.parentElement.classList.toggle('d-none', acceso);
            }

            riordina();
        }

        function autoNumeric(casella) {
            return window.AutoNumeric && typeof window.AutoNumeric.getAutoNumericElement === 'function'
                ? window.AutoNumeric.getAutoNumericElement(casella)
                : null;
        }

        // Nascosta, una caratteristica verrebbe salvata lo stesso: toglierla
        // vuol dire svuotarla, e al salvataggio la sua riga se ne va.
        function svuota(nodo) {
            caselle(nodo).forEach(function (casella) {
                if (spunta(casella)) {
                    casella.checked = false;
                } else {
                    var an = autoNumeric(casella);

                    if (an) {
                        an.clear(true);
                    }

                    casella.value = '';
                }

                casella.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        // La casella da scrivere, per il cursore: l'input nascosto di un
        // elenco c'è solo per mandare la lista vuota.
        function primaCasella(nodo) {
            return caselle(nodo).filter(function (casella) { return casella.type !== 'hidden'; })[0] || null;
        }

        // Il primo elemento del campo, fuori dai modal: quando lo script gira
        // il modal del «+» di un elenco è ancora dentro il blocco.
        function nelCampo(nodo, selettore) {
            return Array.prototype.slice.call(nodo.querySelectorAll(selettore))
                .filter(function (elemento) { return !elemento.closest('.modal'); })[0] || null;
        }

        // La × in alto a destra, all'altezza dell'etichetta: dentro il campo
        // flottante, o sopra le spunte di un elenco. Non sul blocco, che è
        // una riga e allargherebbe la × a tutta la sua larghezza.
        function bottoneTogli(nodo) {
            if (nelCampo(nodo, '.wi-technical-remove')) {
                return;
            }

            var flottante = nelCampo(nodo, '.form-floating');
            var dove = flottante || nelCampo(nodo, '.wi-container-checkbox') || nodo.firstElementChild;

            if (!dove) {
                return;
            }

            var bottone = document.createElement('button');
            bottone.type = 'button';
            bottone.className = 'btn btn-sm btn-link text-body-secondary p-0 lh-1 position-absolute top-0 end-0 me-2 wi-technical-remove'
                + (flottante ? ' mt-2' : '');
            bottone.style.zIndex = '3';

            // Una colonna qualunque ha il suo margine interno: la × ci sta dentro.
            if (dove === nodo.firstElementChild) {
                bottone.style.right = 'calc(var(--bs-gutter-x) * .5)';
            }

            // Il testo lungo non ci passa sotto.
            if (flottante) {
                var casella = flottante.querySelector('input, textarea');

                if (casella) {
                    casella.style.paddingRight = '2.25rem';
                }
            }
            bottone.title = 'Togli ' + nome(nodo);
            bottone.setAttribute('aria-label', 'Togli ' + nome(nodo));
            bottone.innerHTML = '<i class="bi bi-x-lg"></i>';
            bottone.addEventListener('click', function () {
                var togli = function () {
                    svuota(nodo);
                    mostra(nodo, false);

                    var scelta = document.querySelector('.wi-technical-choose');

                    if (scelta) {
                        scelta.focus();
                    }
                };

                // Vuota se ne va e basta; scritta si chiede, perché un clic
                // storto si porterebbe via quello che c'era.
                if (!scritto(nodo)) {
                    togli();

                    return;
                }

                var conferma = {
                    title: 'Togliere ' + nome(nodo) + '?',
                    text: "Quello che c'è scritto si cancella quando salvi l'articolo.",
                    cancelLabel: 'Annulla',
                    confirmLabel: 'Togli',
                    confirmClass: 'btn btn-danger',
                };

                if (typeof window.wiRepeaterConfirmDelete === 'function') {
                    window.wiRepeaterConfirmDelete(togli, conferma);
                } else if (window.confirm(conferma.title + ' ' + conferma.text)) {
                    togli();
                }
            });

            dove.classList.add('position-relative');
            dove.appendChild(bottone);
        }

        function prepara(nodo, acceso) {
            bottoneTogli(nodo);
            mostra(nodo, acceso);
        }

        function aggiungi(id) {
            var nodo = document.querySelector('[data-wi-technical="' + id + '"]');

            if (!nodo) {
                return;
            }

            mostra(nodo, true);

            // La voce cliccata ora è nascosta: il cursore va nel campo, che
            // è la cosa da fare dopo.
            var prima = primaCasella(nodo);

            if (prima) {
                prima.focus();
            }
        }

        document.addEventListener('click', function (evento) {
            var voce = evento.target && evento.target.closest ? evento.target.closest('[data-wi-technical-add], [data-wi-technical-new]') : null;

            if (!voce) {
                return;
            }

            if (voce.hasAttribute('data-wi-technical-new')) {
                var bottone = document.getElementById(BOTTONE);

                if (bottone) {
                    bottone.click();
                }

                return;
            }

            aggiungi(voce.getAttribute('data-wi-technical-add'));
        });

        document.addEventListener('wi:quick-create:created', function (evento) {
            var dettaglio = evento.detail || {};

            if (dettaglio.family !== 'button' || dettaglio.resource !== RISORSA) {
                return;
            }

            var riga = dettaglio.item || {};
            var id = parseInt(dettaglio.id || riga.id, 10);
            var tipo = riga.type === 'number' ? 'number' : 'text';
            var modello = document.querySelector('template[data-wi-technical-template="' + tipo + '"]');
            var bottone = document.getElementById(BOTTONE);

            if (!(id > 0) || !modello || !bottone) {
                return;
            }

            var scatola = document.createElement('div');
            scatola.innerHTML = modello.innerHTML.split('__WI_ID__').join(String(id));

            var campo = scatola.querySelector('.row > *');
            var nodo = campo ? campo.querySelector('[data-wi-technical]') : null;

            if (!nodo) {
                return;
            }

            // Il nome arriva da chi vende: si scrive come testo, mai come HTML.
            var titolo = String(riga.name || dettaglio.label || '');
            var unita = String(riga.unit || '').trim();
            var etichetta = campo.querySelector('label');

            nodo.setAttribute('data-wi-technical-name', titolo);

            if (etichetta) {
                etichetta.textContent = unita === '' ? titolo : titolo + ' (' + unita + ')';
            }

            // L'id del modello è uno per pagina: due caratteristiche nuove
            // avrebbero la stessa etichetta.
            var casella = campo.querySelector('input:not([type="hidden"]), textarea');

            if (casella) {
                casella.id = 'attribute_' + id + '_field';

                if (etichetta) {
                    etichetta.htmlFor = casella.id;
                }
            }

            // Dopo i campi che ci sono già, prima del bottone nascosto.
            var prima = colonna(bottone);
            prima.parentElement.insertBefore(campo, prima);

            // La sua voce nel menu, per quando la si toglie.
            var divisore = document.querySelector('.wi-technical-divider');

            if (divisore) {
                var voce = document.createElement('li');
                var tasto = document.createElement('button');
                tasto.type = 'button';
                tasto.className = 'dropdown-item';
                tasto.setAttribute('data-wi-technical-add', String(id));
                tasto.textContent = titolo;
                voce.appendChild(tasto);
                divisore.parentElement.insertBefore(voce, divisore);
            }

            prepara(nodo, true);

            if (typeof setAutonumeric === 'function') {
                setAutonumeric(campo);
            }

            // Chiudendosi, il modale rimette il cursore sul bottone che l'ha
            // aperto, che è nascosto: il campo nuovo lo prende dopo.
            var modale = document.querySelector('.modal.show');

            if (casella && modale) {
                modale.addEventListener('hidden.bs.modal', function () {
                    casella.focus();
                }, { once: true });
            } else if (casella) {
                casella.focus();
            }
        });

        // I blocchi e il bottone stanno prima di questo script: si sistemano
        // subito, senza aspettare la pagina intera e senza farli lampeggiare.
        var vero = document.getElementById(BOTTONE);

        if (vero) {
            colonna(vero).classList.add('d-none');
        }

        blocchi().forEach(function (nodo) { prepara(nodo, scritto(nodo)); });
        riordina();

        return true;
    })();
</script>
HTML)->tag('div');
    }

    /** Catalogo → Attributi, dove si preparano Elenchi e Icone. */
    protected static function attributesUrl(): string
    {
        if (function_exists('__r')) {
            try {
                $url = (string) __r('backend.resource.'.AttributeResource::slug().'.list');

                if ($url !== '') {
                    return $url;
                }
            } catch (Throwable) {
                // Rotta non registrata (test, comandi): resta il percorso.
            }
        }

        return '/backend/'.AttributeResource::path().'/';
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

    /** Voci di un attributo a elenco, con la voce vuota in cima. @return array<string, mixed> */
    protected static function valueOptions(array $attribute): array
    {
        return ['' => '—'] + static::valueChoices($attribute);
    }

    /**
     * I valori di un attributo come voci di un campo: il nome e, accanto, il
     * pallino, la fantasia o l'icona che il suo tipo prevede.
     *
     * Un valore senza segno resta una stringa: un Elenco si legge come prima.
     *
     * @param array<string, mixed> $attribute
     * @return array<string, string|array<string, string>>
     */
    public static function valueChoices(array $attribute): array
    {
        $id = (int) ($attribute['id'] ?? 0);
        $type = (string) ($attribute['type'] ?? '');
        $choices = [];

        foreach (static::attributeValues() as $value) {
            if ((int) ($value['attribute_id'] ?? 0) !== $id) {
                continue;
            }

            $label = (string) ($value['label'] ?? '');
            $imageUrl = in_array($type, ['pattern', 'icon'], true) ? AttributeValue::imageUrl($value) : '';
            $visual = Attributes::valueVisual($type, $value, $imageUrl);
            $choices[(string) $value['id']] = $visual === [] ? $label : ['name' => $label] + $visual;
        }

        return $choices;
    }

    /**
     * Come si chiama, in questo negozio, l'opzione con foto proprie.
     *
     * "Colore" per chi vende magliette, "Gusto" per una gelateria. Serve a non
     * far mai leggere a nessuno la parola "variante"; un negozio che non ne ha
     * una legge "Opzioni".
     */
    public static function pageOptionName(): string
    {
        foreach (static::attributes() as $attribute) {
            if (($attribute['level'] ?? '') === 'variant') {
                return (string) ($attribute['name'] ?? '');
            }
        }

        return 'Opzioni';
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
     * quello che sfora va a capo. È il conto che mancava. Con più sedi la
     * giacenza non c'è (P113) — i pezzi si scrivono dalla finestra — e il suo
     * posto lo prende il nome dell'opzione.
     */
    protected static function productsField(int $modelId = 0): Input
    {
        // Una colonna ha un formato solo: se una riga ha già dei decimali,
        // li mostrano tutte, per non arrotondare quella.
        $formato = static::stockFormat($modelId, $modelId > 0 ? static::products($modelId) : []);

        $giacenza = RepeaterColumn::key('stock')
            ->number()
            ->decimal($formato['decimals']);

        if ($formato['suffix'] !== '') {
            $giacenza->suffix($formato['suffix']);
        }

        // La soglia dell'avviso di ogni opzione, scritta come la giacenza:
        // i decimali dell'unità e l'unità in coda. Senza la funzionalità non
        // esiste; con più sedi è una per sede, e sta nella finestra
        // «Giacenza» (P103).
        $soglia = null;
        $sedi = static::hasManyLocations();

        if (Gestionale::feature('low_stock_alerts') && !$sedi) {
            $soglia = static::minStockInput(
                RepeaterColumn::key('min_stock'),
                $modelId,
                $modelId > 0 ? static::products($modelId) : []
            )->label('Scorta minima')->columnSpan(3);
        }

        $larghezza = $soglia === null ? 4 : 3;
        // I fornitori dell'opzione (P108): due colonne, o il campo nascosto
        // e il bottone della finestra.
        $fornitori = static::supplierColumns($modelId, $sedi);

        $campo = FormField::key('products')
            ->repeater([
                RepeaterColumn::key('id')->hidden(),
                // Il valore del primo attributo scelto. Non si scrive: serve
                // a raggruppare, e in chiaro lo dice la testata del gruppo,
                // una volta sola invece che su ogni riga.
                RepeaterColumn::key('group')->hidden()->label(static::pageOptionName()),
                // L'id del valore che raggruppa, quando è un colore: è la
                // chiave delle foto della testata. Il nome non basta — si
                // rinomina — e con la taglia davanti resta vuota: una foto
                // della taglia S non vuol dire niente.
                RepeaterColumn::key('group_value')->hidden(),
                // Le spunte che tengono in piedi questa riga: togliendo la
                // riga, il browser sa quali valori non servono più.
                RepeaterColumn::key('combination')->hidden(),
                // Quello che resta del nome una volta detto il colore: "S",
                // oppure "S / Gomma" con un terzo attributo. Non è la colonna
                // `name` del database — quella la scrive il sistema — perché
                // una casella di sola lettura viene postata lo stesso, e
                // scriverebbe "S" al posto di "Blu / S".
                // Quello che si compila sempre: come si chiama, quanto
                // costa, a quanto è scontato, quanti ce ne sono. Undici
                // dodicesimi, perché il dodicesimo è del cestino.
                RepeaterColumn::key('option')->text()->readonly()->label('Opzione')->columnSpan($sedi ? 7 : 5),
                RepeaterColumn::key('price')->price()->decimal(2)->label('Prezzo')->columnSpan(2),
                // Lo scontato accanto al prezzo, come nel riquadro in alto
                // (P105): vuoto vuol dire che non c'è sconto. È una colonna
                // di `gst_products`, e la scrive il repeater del core.
                RepeaterColumn::key('sale_price')->price()->decimal(2)->label('Scontato')->columnSpan(2),
                // Si scrive quanti pezzi ci sono, e il pannello fa il
                // movimento della differenza. Una casella lasciata com'era
                // non muove niente. Con più sedi la colonna non c'è (P113):
                // un totale che non si può scrivere confonde, e i pezzi si
                // scrivono sede per sede dalla finestra «Giacenza».
                ...($sedi ? [] : [
                    $giacenza
                        ->label('Giacenza')
                        ->readonly(!static::stockIsWritable())
                        ->columnSpan(2),
                ]),
                // Dietro «Compila le informazioni avanzate»: chi carica un
                // articolo nuovo quasi mai ha già il codice a barre in mano.
                // Con la scorta minima le caselle sono quattro in fila.
                RepeaterColumn::key('sku')->text()->label('SKU')->columnSpan($larghezza),
                RepeaterColumn::key('ean')->text()->label('EAN')->columnSpan($larghezza),
                ...($soglia === null ? [] : [$soglia]),
                RepeaterColumn::key('active')
                    ->select(['true' => 'Attivo', 'false' => 'Fermo'])
                    ->label('Stato')
                    ->columnSpan($larghezza),
                ...$fornitori['fields'],
                // Con più sedi (P103): il JSON delle righe per sede, che la
                // finestra «Giacenza» legge e riscrive, e il bottone che la
                // apre, con accanto «Milano 12 · Roma 3». Prende il posto
                // della scorta minima, che con più sedi è una per sede.
                ...($sedi ? [
                    RepeaterColumn::key('locations')->hidden(),
                    RepeaterColumn::key('stock_button')
                        ->button('Giacenza')
                        ->opensModal(static::LOCATIONS_MODAL)
                        ->emptyCaption('Nessun pezzo')
                        // In fila con «Fornitori», quando c'è.
                        ->columnSpan($fornitori['buttons'] === [] ? 12 : 6),
                ] : []),
                ...$fornitori['buttons'],
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
            ->repeaterAdvanced(...[
                ...($soglia === null ? ['sku', 'ean', 'active'] : ['sku', 'ean', 'min_stock', 'active']),
                ...($fornitori['buttons'] === [] ? $fornitori['advanced'] : []),
                ...($sedi ? ['stock_button'] : []),
                ...($fornitori['buttons'] === [] ? [] : $fornitori['advanced']),
                'photo',
            ])
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

            if (static::variantAttributeId($modelId) > 0) {
                $campo = $campo->repeaterGroupFiles(
                    FormField::key('group_images')
                        ->fileDragDrop('gallery')
                        ->maxFile(static::MAX_IMAGES)
                        ->label('')
                        ->value(static::groupImageNames($modelId)),
                    'group_value',
                    'Foto del colore'
                );
            }
        }

        return $campo;
    }

    /**
     * Le righe «Giacenza per sede» dell'articolo senza varianti, con più
     * sedi (P102): sede, pezzi e scorta minima di quella.
     *
     * È un repeater senza relazione: le righe le compone `mutateFormValues()`
     * — una per sede con pezzi o soglia — e le legge `saveLocationStock()`,
     * che passa da `LocationRows` e `LocationStock` come la scheda
     * dell'opzione. La giacenza si scrive come nella casella di prima: quanti
     * ce ne sono, e il magazzino fa il movimento della differenza; vuota vuol
     * dire non toccare. Togliere una riga chiede conferma: la sede perde la
     * sua soglia, non i pezzi.
     */
    protected static function stockRowsField(int $modelId): Input
    {
        $soglia = Gestionale::feature('low_stock_alerts');
        $products = $modelId > 0 ? static::products($modelId) : [];
        $formato = static::locationFormat($modelId, $products);

        $giacenza = RepeaterColumn::key('stock')
            ->number()
            ->decimal($formato['stock'])
            ->label('Giacenza')
            ->columnSpan($soglia ? 3 : 4);
        $minima = RepeaterColumn::key('min_stock')
            ->number()
            ->decimal($formato['min_stock'])
            ->label('Scorta minima')
            ->columnSpan(3);

        if ($formato['suffix'] !== '') {
            $giacenza->suffix($formato['suffix']);
            $minima->suffix($formato['suffix']);
        }

        // Undici dodicesimi: il dodicesimo è del cestino.
        $colonne = [
            RepeaterColumn::key('location_id')
                ->select(['' => '—'] + static::locationChoices())
                ->label('Sede')
                ->columnSpan($soglia ? 5 : 7),
            $giacenza,
        ];

        if ($soglia) {
            $colonne[] = $minima;
        }

        return FormField::key('locations')
            ->repeater($colonne)
            ->nested()
            ->repeaterAddLabel('Aggiungi sede')
            ->repeaterDeleteTitle('Togli sede')
            ->repeaterDeleteText('Questa sede perde la sua scorta minima al salvataggio. I pezzi restano dove sono: finché ne ha, la riga ricompare.')
            ->repeaterDeleteCancelLabel('Annulla')
            ->repeaterDeleteConfirmLabel('Togli')
            ->repeaterDeleteConfirmClass('btn btn-danger')
            ->label('Giacenza per sede');
    }

    /**
     * La finestra «Giacenza» della griglia, con più sedi (P103): una riga
     * per sede, con la sede, i pezzi e la scorta minima di quella.
     *
     * Non è legata a nessuna riga della griglia: il bottone che la apre le
     * passa il JSON della sua opzione, e «Salva» glielo riporta nel campo
     * nascosto `locations` (`locationStockScript()`). Le righe sono tante
     * quante le sedi — più di così non se ne possono scegliere — e stanno
     * nascoste finché non servono: «Aggiungi sede» ne mostra una, la «x» la
     * svuota e la nasconde. I suoi campi escono dal form con la finestra, e
     * `withoutExtras()` li scarta: quello che conta è il JSON.
     */
    protected static function locationStockModal(int $modelId): Modal
    {
        $soglia = Gestionale::feature('low_stock_alerts');
        $sedi = static::locationChoices();
        $products = $modelId > 0 ? static::products($modelId) : [];
        $formato = static::locationFormat($modelId, $products);

        // Le intestazioni una volta sola, sopra le righe.
        $components = [
            RichText::make(
                '<div class="row g-3 small text-body-secondary">'
                .'<div class="col-'.($soglia ? 5 : 7).'">Sede</div>'
                .'<div class="col-'.($soglia ? 3 : 4).'">Giacenza</div>'
                .($soglia ? '<div class="col-3">Scorta minima</div>' : '')
                .'<div class="col-1"></div>'
                .'</div>'
            )->tag('div')->columnSpan(12),
        ];

        for ($i = 0, $n = count($sedi); $i < $n; $i++) {
            $giacenza = FormField::key('wi_location_stock['.$i.'][stock]')
                ->number()
                ->decimal($formato['stock'])
                ->label('')
                ->columnSpan($soglia ? 3 : 4);
            $minima = FormField::key('wi_location_stock['.$i.'][min_stock]')
                ->number()
                ->decimal($formato['min_stock'])
                ->label('')
                ->columnSpan(3);

            if ($formato['suffix'] !== '') {
                $giacenza->suffix($formato['suffix']);
                $minima->suffix($formato['suffix']);
            }

            $campi = [
                FormField::key('wi_location_stock['.$i.'][location_id]')
                    ->select(['' => '—'] + $sedi)
                    ->label('')
                    ->columnSpan($soglia ? 5 : 7),
                $giacenza,
            ];

            if ($soglia) {
                $campi[] = $minima;
            }

            $campi[] = FormField::key('wi_location_stock_remove')
                ->button('')
                ->icon('bi bi-x-lg')
                ->size('sm')
                ->attribute('data-wi-location-remove="'.$i.'" title="Togli la sede" aria-label="Togli la sede"')
                ->columnSpan(1);

            $components[] = (new Container)->components($campi)
                ->attr('data-wi-location-line', (string) $i)
                ->columns(12)
                ->columnSpan(12);
        }

        return Modal::make('Giacenza')
            ->id(static::LOCATIONS_MODAL)
            ->size('lg')
            ->columns(12)
            ->components($components)
            ->footer([
                Button::make('Aggiungi sede')->variant('secondary')->outline()->attr('data-wi-location-add', 'true'),
                Button::make('Annulla')->variant('secondary')->attr('data-bs-dismiss', 'modal'),
                Button::make('Salva')->attr('data-wi-location-stock-save', 'true'),
            ]);
    }

    /**
     * Il codice della finestra «Giacenza» (P103).
     *
     * All'apertura legge il JSON della riga che l'ha aperta — la colonna
     * nascosta `locations` — e riempie una riga della finestra per ogni
     * sede scritta; senza righe ne mostra una vuota sulla sede principale.
     * «Salva» riscrive il JSON con le righe che hanno una sede, nello stesso
     * formato del server (`location_id`, `stock`, `min_stock`; casella vuota
     * = `""`), e aggiorna il riassunto accanto al bottone, «Milano 12 ·
     * Roma 3». La scheda si salva con il suo Salva: è `saveLocationStock()`
     * che legge il JSON, e un JSON mai toccato rettifica zero pezzi.
     */
    protected static function locationStockScript(): RichText
    {
        $sedi = [];

        foreach (static::locationChoices() as $id => $nome) {
            $sedi[] = ['id' => (int) $id, 'name' => $nome];
        }

        $dati = static::escape(json_encode($sedi, JSON_THROW_ON_ERROR));
        $principale = (int) Locations::mainId();

        return RichText::make(
            '<div class="wi-location-stock" data-wi-location-names="'.$dati.'"'
            .' data-wi-location-main="'.$principale.'"></div>'
            .<<<'HTML'
<script>
    window.wiLocationStock = window.wiLocationStock || (function () {
        var FINESTRA = 'wi-location-stock';
        // Il campo nascosto e il bottone della riga che ha aperto la finestra.
        var aperta = null;

        function radice() {
            return document.querySelector('.wi-location-stock');
        }

        function sedi() {
            var elemento = radice();

            try {
                var valori = JSON.parse(elemento ? elemento.getAttribute('data-wi-location-names') || '[]' : '[]');

                return Array.isArray(valori) ? valori : [];
            } catch (errore) {
                return [];
            }
        }

        function principale() {
            var elemento = radice();

            return elemento ? String(elemento.getAttribute('data-wi-location-main') || '') : '';
        }

        function nomeSede(id) {
            var nome = '';

            sedi().forEach(function (sede) {
                if (String(sede.id) === String(id)) {
                    nome = String(sede.name || '');
                }
            });

            return nome !== '' ? nome : 'Sede n. ' + id;
        }

        function autoNumeric(campo) {
            return window.AutoNumeric && typeof window.AutoNumeric.getAutoNumericElement === 'function'
                ? window.AutoNumeric.getAutoNumericElement(campo)
                : null;
        }

        // Il numero nella casella, col punto: «» se vuota. AutoNumeric
        // quando c'è, se no letto come lo scrive una persona — «2,5 kg»,
        // «1.234,5».
        function numeroDi(campo) {
            var an = autoNumeric(campo);
            var testo;

            if (an) {
                testo = String(an.getNumericString() || '');

                return testo === '' || isNaN(Number(testo)) ? '' : testo;
            }

            testo = String(campo.value || '').replace(/[^0-9.,-]/g, '');

            if (testo.indexOf(',') !== -1) {
                testo = testo.replace(/\./g, '').replace(',', '.');
            }

            return testo === '' || isNaN(Number(testo)) ? '' : String(Number(testo));
        }

        function scrivi(campo, valore) {
            var vuoto = valore === null || valore === undefined || valore === '' || isNaN(Number(valore));
            var an = autoNumeric(campo);

            if (an) {
                if (vuoto) {
                    an.clear();
                } else {
                    an.set(Number(valore));
                }

                return;
            }

            campo.value = vuoto ? '' : String(Number(valore));
        }

        // «12» o «2,5», come il riassunto che scrive il server.
        function quantita(valore) {
            var numero = Math.round(Number(valore) * 1000) / 1000;

            return String(numero).replace('.', ',');
        }

        function linee(finestra) {
            return Array.prototype.slice.call(finestra.querySelectorAll('[data-wi-location-line]'));
        }

        function caselle(linea) {
            var i = linea.getAttribute('data-wi-location-line');

            return {
                sede: linea.querySelector('[name="wi_location_stock[' + i + '][location_id]"]'),
                pezzi: linea.querySelector('[name="wi_location_stock[' + i + '][stock]"]'),
                soglia: linea.querySelector('[name="wi_location_stock[' + i + '][min_stock]"]')
            };
        }

        // La cella che contiene la riga, quando il tema la incolonna.
        function cella(linea) {
            var padre = linea.parentElement;

            return padre && /(^|\s)col-/.test(padre.className || '') ? padre : linea;
        }

        function nascosta(linea) {
            return cella(linea).hidden === true;
        }

        function mostra(linea, si) {
            cella(linea).hidden = !si;
        }

        function svuota(linea) {
            var c = caselle(linea);

            if (c.sede) {
                c.sede.value = '';
            }

            if (c.pezzi) {
                scrivi(c.pezzi, null);
            }

            if (c.soglia) {
                scrivi(c.soglia, null);
            }
        }

        // «Aggiungi sede» si spegne quando le righe sono tutte fuori.
        function aggiorna(finestra) {
            var bottone = finestra.querySelector('[data-wi-location-add]');

            if (bottone) {
                bottone.disabled = linee(finestra).filter(nascosta).length === 0;
            }
        }

        // Il campo nascosto della riga del bottone.
        function campoDi(bottone) {
            var riga = bottone.closest('.wi-repeater-row');

            return riga ? riga.querySelector('input[name$="[locations]"]') : null;
        }

        // Il nome dell'opzione, per il titolo: «Blu / S».
        function nomeDi(bottone) {
            var riga = bottone.closest('.wi-repeater-row');

            if (!riga) {
                return '';
            }

            var gruppo = riga.querySelector('input[name$="[group]"]');
            var opzione = riga.querySelector('input[name$="[option]"]');
            var g = gruppo ? String(gruppo.value || '').trim() : '';
            var o = opzione ? String(opzione.value || '').trim() : '';

            return g !== '' && o !== '' && g !== o ? g + ' / ' + o : (o || g);
        }

        function righe(campo) {
            try {
                var elenco = JSON.parse(campo && campo.value ? campo.value : '[]');

                return Array.isArray(elenco) ? elenco : [];
            } catch (errore) {
                return [];
            }
        }

        function riempi(finestra, campo) {
            var elenco = righe(campo);
            var tutte = linee(finestra);

            tutte.forEach(function (linea, i) {
                var riga = elenco[i] && typeof elenco[i] === 'object' ? elenco[i] : null;
                var c = caselle(linea);

                svuota(linea);

                if (riga === null) {
                    mostra(linea, false);

                    return;
                }

                if (c.sede) {
                    c.sede.value = String(riga.location_id === undefined || riga.location_id === null ? '' : riga.location_id);
                }

                if (c.pezzi) {
                    scrivi(c.pezzi, riga.stock);
                }

                // Una soglia a zero è una casella vuota: «non avvisarmi».
                if (c.soglia) {
                    scrivi(c.soglia, Number(riga.min_stock) > 0 ? riga.min_stock : null);
                }

                mostra(linea, true);
            });

            // Senza righe se ne mostra una, sulla sede principale: qualcosa
            // da compilare.
            if (elenco.length === 0 && tutte.length > 0) {
                var prima = caselle(tutte[0]);

                if (prima.sede) {
                    prima.sede.value = principale();
                }

                mostra(tutte[0], true);
            }

            aggiorna(finestra);
        }

        // La prima riga nascosta esce, con la prima sede non ancora scelta.
        function aggiungi(finestra) {
            var tutte = linee(finestra);
            var libera = tutte.filter(nascosta)[0] || null;

            if (libera === null) {
                return;
            }

            var scelte = tutte.filter(function (linea) { return !nascosta(linea); }).map(function (linea) {
                var c = caselle(linea);

                return c.sede ? String(c.sede.value || '') : '';
            });
            var proposta = sedi().filter(function (sede) { return scelte.indexOf(String(sede.id)) === -1; })[0] || null;
            var c = caselle(libera);

            svuota(libera);

            if (c.sede && proposta !== null) {
                c.sede.value = String(proposta.id);
            }

            mostra(libera, true);
            aggiorna(finestra);
        }

        function didascalia(bottone, elenco) {
            var posto = bottone && bottone.parentElement ? bottone.parentElement.querySelector('[data-wi-button-caption]') : null;

            if (!posto) {
                return;
            }

            var parti = [];

            elenco.forEach(function (riga) {
                if (riga.stock !== '' && Number(riga.stock) !== 0) {
                    parti.push(nomeSede(riga.location_id) + ' ' + quantita(riga.stock));
                }
            });

            posto.textContent = parti.length > 0 ? parti.join(' · ') : 'Nessun pezzo';
        }

        function salva(finestra) {
            if (!aperta || !aperta.campo) {
                return;
            }

            var elenco = [];

            // Nell'ordine della finestra, solo le righe con una sede: una
            // riga senza sede non dice niente. Casella vuota = «», che per
            // il server vuol dire non toccare i pezzi, o nessuna soglia.
            linee(finestra).forEach(function (linea) {
                if (nascosta(linea)) {
                    return;
                }

                var c = caselle(linea);
                var sede = c.sede ? String(c.sede.value || '') : '';

                if (sede === '') {
                    return;
                }

                var pezzi = c.pezzi ? numeroDi(c.pezzi) : '';
                var soglia = c.soglia ? numeroDi(c.soglia) : '';

                elenco.push({
                    location_id: Number(sede),
                    stock: pezzi === '' ? '' : Number(pezzi),
                    min_stock: soglia === '' ? '' : Number(soglia)
                });
            });

            aperta.campo.value = JSON.stringify(elenco);
            aperta.campo.dispatchEvent(new Event('change', { bubbles: true }));
            didascalia(aperta.bottone, elenco);
        }

        function avvia() {
            if (window.wiLocationStockReady) {
                return;
            }

            window.wiLocationStockReady = true;

            document.addEventListener('show.bs.modal', function (evento) {
                var finestra = evento.target;
                var bottone = evento.relatedTarget || null;

                if (!finestra || finestra.id !== FINESTRA) {
                    return;
                }

                aperta = bottone ? { bottone: bottone, campo: campoDi(bottone) } : null;

                var titolo = finestra.querySelector('[data-wi-modal-title]');
                var nome = bottone ? nomeDi(bottone) : '';

                if (titolo) {
                    titolo.textContent = nome !== '' ? 'Giacenza · ' + nome : 'Giacenza';
                }

                if (typeof setAutonumeric === 'function') {
                    setAutonumeric(finestra);
                }

                riempi(finestra, aperta ? aperta.campo : null);
            });

            document.addEventListener('click', function (evento) {
                var bersaglio = evento.target && evento.target.closest ? evento.target : null;

                if (!bersaglio) {
                    return;
                }

                var togli = bersaglio.closest('[data-wi-location-remove]');

                // La «x»: la riga si svuota e torna nascosta.
                if (togli) {
                    var linea = togli.closest('[data-wi-location-line]');
                    var dentro = togli.closest('#' + FINESTRA);

                    evento.preventDefault();

                    if (linea && dentro) {
                        svuota(linea);
                        mostra(linea, false);
                        aggiorna(dentro);
                    }

                    return;
                }

                var aggiungiSede = bersaglio.closest('[data-wi-location-add]');

                if (aggiungiSede) {
                    var finestraDaAllargare = aggiungiSede.closest('#' + FINESTRA);

                    evento.preventDefault();

                    if (finestraDaAllargare) {
                        aggiungi(finestraDaAllargare);
                    }

                    return;
                }

                var conferma = bersaglio.closest('[data-wi-location-stock-save]');

                if (!conferma) {
                    return;
                }

                var finestra = conferma.closest('.modal');

                evento.preventDefault();
                salva(finestra);

                if (window.bootstrap && window.bootstrap.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(finestra).hide();
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', avvia);
        } else {
            avvia();
        }

        return avvia;
    })();
</script>
HTML
        )->tag('div');
    }

    /**
     * Le foto di ogni colore, per id del valore: il valore del campo della
     * testata.
     *
     * @return array<string, list<string>>
     */
    protected static function groupImageNames(int $modelId): array
    {
        if ($modelId <= 0) {
            return [];
        }

        $nomi = [];

        foreach (static::variantValues($modelId) as $variantId => $valueId) {
            if ($valueId > 0) {
                $nomi[(string) $valueId] = static::imageNames($modelId, (int) $variantId);
            }
        }

        return $nomi;
    }

    /**
     * Se le foto del colore stanno nella testata del gruppo.
     *
     * Ci stanno quando il negozio ha un attributo con foto proprie e questo
     * articolo raggruppa per lui. Con «Taglia, poi Colore» i gruppi sono
     * taglie: le foto del colore restano nel riquadro «Foto e video», un'area
     * per colore, o non ci sarebbe nessun posto dove caricarle.
     */
    public static function colorPhotosInGroups(int $modelId): bool
    {
        $asse = static::variantAttributeId($modelId);

        return $asse > 0 && (static::axesOrder($modelId)[0] ?? 0) === $asse;
    }

    /**
     * Se la griglia nasce raggruppata.
     *
     * Con un attributo solo ogni gruppo conterrebbe una riga e la testata
     * ripeterebbe il nome della riga: i gruppi cominciano da due attributi in
     * su. Fa eccezione l'attributo con foto proprie: la testata è il posto
     * delle foto del colore, e c'è anche quando è l'unico.
     *
     * In creazione — e su un articolo che non ha ancora spuntato niente — non
     * c'è niente da guardare, quindi la testata si prepara se il negozio
     * potrebbe averne bisogno, e il browser spegne i gruppi finché le righe
     * non la chiedono.
     */
    protected static function groupsByAxis(int $modelId): bool
    {
        $asseColore = static::variantAttributeId($modelId);
        $assi = $modelId > 0 ? static::axesInUse($modelId) : [];

        if ($assi === []) {
            return count(static::optionAttributes()) >= 2 || $asseColore > 0;
        }

        return count($assi) >= 2 || ($asseColore > 0 && in_array($asseColore, $assi, true));
    }

    /**
     * Gli attributi che questo articolo sta davvero usando.
     *
     * @return list<int>
     */
    public static function axesInUse(int $modelId): array
    {
        $assi = [];
        $asseVariante = static::variantAttributeId($modelId);

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

        $asseVariante = static::variantAttributeId($modelId);

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
     * dirlo. Dalla seconda sede mostrata in poi si legge e basta, e i pezzi
     * si scrivono sede per sede (P102, P103).
     */
    public static function stockIsWritable(): bool
    {
        return count(Locations::shown()) <= 1;
    }

    /** Se il magazzino mostra due o più sedi (P102): la scheda le scrive una per una. */
    protected static function hasManyLocations(): bool
    {
        return count(Locations::shown()) >= 2;
    }

    /**
     * Le sedi da mostrare come voci di una tendina: `[id => nome]`.
     *
     * @return array<int, string>
     */
    protected static function locationChoices(): array
    {
        $sedi = [];

        foreach (Locations::shown() as $sede) {
            $sedi[(int) ($sede['id'] ?? 0)] = (string) ($sede['label'] ?? '');
        }

        return $sedi;
    }

    /**
     * La scorta minima della sede principale di ogni prodotto (P100), zero
     * dove non c'è: è quella che la scheda mostra e scrive finché il
     * magazzino ha una sede sola.
     *
     * @param list<int> $productIds
     * @return array<int, float> `[productId => soglia]`
     */
    protected static function mainThresholds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if ($productIds === []) {
            return [];
        }

        $principale = Locations::mainId();
        $soglie = [];

        foreach (Thresholds::forProducts($productIds) as $productId => $perSede) {
            $soglie[(int) $productId] = (float) ($perSede[$principale] ?? 0);
        }

        return $soglie;
    }

    /**
     * L'attributo con pagina propria che conta per questo articolo.
     *
     * Il negozio può averne più d'uno — «Colore» e una sua prova — e quello
     * che vale è quello che l'articolo sta davvero usando: è lui a decidere
     * se le foto stanno nella testata del gruppo. Il primo del negozio resta
     * il ripiego in creazione, e su un articolo che non ha ancora scelto.
     */
    public static function variantAttributeId(int $modelId = 0): int
    {
        $primo = 0;
        $conPaginaPropria = [];

        foreach (static::attributes() as $attribute) {
            if (($attribute['level'] ?? '') !== 'variant') {
                continue;
            }

            $id = (int) $attribute['id'];
            $conPaginaPropria[$id] = true;
            $primo = $primo > 0 ? $primo : $id;
        }

        if ($primo === 0 || $modelId <= 0) {
            return $primo;
        }

        try {
            $valori = static::attributeValues();

            foreach (static::variantValues($modelId) as $valueId) {
                $asse = (int) ($valori[$valueId]['attribute_id'] ?? 0);

                if (isset($conPaginaPropria[$asse])) {
                    return $asse;
                }
            }
        } catch (Throwable) {
            // Senza database (i test degli schemi) vale il ripiego.
        }

        return $primo;
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
     * @return array<int, array{group: string, group_value: string, rest: string, full: string, label: string, key: string}>
     */
    public static function optionLabels(int $modelId): array
    {
        $etichetteValori = static::valueLabels();
        $varianti = static::variantLabels($modelId);
        $valoriVariante = static::variantValues($modelId);
        $asseVariante = static::variantAttributeId($modelId);
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
            $primoAsse = 0;

            foreach ($ordine as $asse) {
                if (($perAsse[$asse] ?? '') !== '') {
                    $primoAsse = $primoAsse ?: (int) $asse;
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
                // Le foto della testata sono del colore: solo quando è lui a
                // raggruppare.
                'group_value' => $primoAsse > 0 && $primoAsse === $asseVariante && $valoreColore > 0
                    ? (string) $valoreColore
                    : '',
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
     * Prezzo, scontato, giacenza e codice a barre dell'articolo senza varianti.
     *
     * Con le varianti spariscono tutti: ogni opzione ha i suoi nella griglia.
     * Il prezzo nascosto viene postato lo stesso, e `savePrices()` lo passa
     * alle opzioni appena nate senza un prezzo loro: chi accende le varianti
     * su un articolo che costava 24,90 non le trova a zero. Con più versioni
     * la casella arriva vuota e non tocca niente.
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
        // Il prezzo si scrive come un prezzo: due decimali e il simbolo della
        // valuta, che mette il campo prezzo della lib.
        $fields = [
            FormField::key('product_price')
                ->price()
                ->decimal(2)
                ->label('Prezzo')
                ->hiddenWhen('has_variants', 'true'),
            FormField::key('product_sale_price')
                ->price()
                ->decimal(2)
                ->label('Prezzo scontato')
                ->hiddenWhen('has_variants', 'true'),
        ];

        // Si scrive quanti pezzi ci sono: il movimento della differenza lo
        // fa il pannello. Con più sedi il numero sarebbe ambiguo, e la
        // casella si legge e basta, anche in creazione: i pezzi si scrivono
        // nelle righe per sede.
        //
        // I decimali sono quelli dell'unità, e l'unità sta in coda: «12 pz»,
        // «2,500 kg». Cambiare l'unità in «Misure» li cambia al volo.
        $modelId = (int) ($modelId ?? 0);
        $sola = $modelId > 0 ? static::soleProduct($modelId) : null;
        $formato = static::stockFormat($modelId, $sola === null ? [] : [$sola]);

        $giacenza = FormField::key('product_stock')
            ->number()
            ->decimal($formato['decimals']);

        if ($formato['suffix'] !== '') {
            $giacenza->suffix($formato['suffix']);
        }

        $fields[] = $giacenza
            ->readonly(!static::stockIsWritable())
            ->label('Giacenza')
            ->hiddenWhen('has_variants', 'true');

        // La soglia dell'avviso: vale sul disponibile di tutte le sedi, e con
        // zero non arriva niente. Senza la funzionalità non esiste.
        if (Gestionale::feature('low_stock_alerts')) {
            $fields[] = static::minStockInput(
                FormField::key('product_min_stock'),
                $modelId,
                $sola === null ? [] : [$sola]
            )
                ->label('Scorta minima')
                ->hiddenWhen('has_variants', 'true');
        }

        $fields[] = FormField::key('product_ean')
            ->text()
            ->label('EAN')
            ->hiddenWhen('has_variants', 'true');

        return $fields;
    }

    /**
     * I campi dei fornitori di un'opzione sola (P109): dell'articolo senza
     * varianti, con il prefisso `product_`, o della scheda dell'opzione.
     *
     * Con un fornitore i due campi, codice e costo, con il suo nome nel
     * tooltip; da due in su il campo nascosto con il JSON delle righe e il
     * bottone che apre la finestra, con il riassunto accanto. Senza
     * fornitori, o senza `purchasing`, niente. Non sono colonne di nessuna
     * tabella: li legge `saveSuppliers()`, e `withoutExtras()` li scarta.
     *
     * @return list<Input>
     */
    protected static function supplierInputs(int $modelId, string $prefix = ''): array
    {
        $modo = static::supplierMode($modelId);

        if ($modo === 'flat') {
            $titolo = static::soleSupplierTitle($modelId);
            $campi = [
                FormField::key($prefix.'supplier_sku')
                    ->text()
                    ->maxLength(ProductSuppliers::SKU_MAX_LENGTH)
                    ->label('Codice fornitore')
                    ->attribute($titolo),
                FormField::key($prefix.'supplier_cost')
                    ->price()
                    ->decimal(2)
                    ->label('Costo d\'acquisto')
                    ->attribute($titolo),
            ];
        } elseif ($modo === 'modal') {
            $campi = [
                FormField::key($prefix.'suppliers')->hidden(),
                FormField::key($prefix.'suppliers_button')
                    ->button('Fornitori')
                    ->opensModal(static::SUPPLIERS_MODAL)
                    ->emptyCaption('Nessun fornitore'),
            ];
        } else {
            return [];
        }

        // Quelli dell'articolo valgono finché non ha varianti: il campo
        // nascosto resta, non si vede comunque.
        if ($prefix !== '') {
            foreach ($campi as $campo) {
                if ($campo->name !== $prefix.'suppliers') {
                    $campo->hiddenWhen('has_variants', 'true');
                }
            }
        }

        return $campi;
    }

    /**
     * Le colonne dei fornitori nella griglia (P108, P109), dietro «Compila
     * le informazioni avanzate»: i due campi del fornitore unico, oppure la
     * colonna nascosta con il JSON e il bottone della finestra.
     *
     * Il bottone sta in fila con «Giacenza» quando c'è anche quello, mezza
     * riga per uno; da solo la prende intera.
     *
     * @return array{fields: list<Input>, buttons: list<Input>, advanced: list<string>}
     */
    protected static function supplierColumns(int $modelId, bool $conGiacenza): array
    {
        $modo = static::supplierMode($modelId);

        if ($modo === 'flat') {
            $titolo = static::soleSupplierTitle($modelId);

            return [
                'fields' => [
                    RepeaterColumn::key('supplier_sku')
                        ->text()
                        ->maxLength(ProductSuppliers::SKU_MAX_LENGTH)
                        ->label('Codice fornitore')
                        ->attribute($titolo)
                        ->columnSpan(6),
                    RepeaterColumn::key('supplier_cost')
                        ->price()
                        ->decimal(2)
                        ->label('Costo d\'acquisto')
                        ->attribute($titolo)
                        ->columnSpan(6),
                ],
                'buttons' => [],
                'advanced' => ['supplier_sku', 'supplier_cost'],
            ];
        }

        if ($modo === 'modal') {
            return [
                'fields' => [],
                'buttons' => [
                    RepeaterColumn::key('suppliers')->hidden(),
                    RepeaterColumn::key('suppliers_button')
                        ->button('Fornitori')
                        ->opensModal(static::SUPPLIERS_MODAL)
                        ->emptyCaption('Nessun fornitore')
                        ->columnSpan($conGiacenza ? 6 : 12),
                ],
                'advanced' => ['suppliers_button'],
            ];
        }

        return ['fields' => [], 'buttons' => [], 'advanced' => []];
    }

    /**
     * La finestra «Fornitori» (P110): una riga per fornitore, con il
     * fornitore, il suo codice e il costo d'acquisto.
     *
     * Non è legata a nessuna opzione: il bottone che la apre le passa il
     * JSON della sua — il campo nascosto `suppliers` della riga della
     * griglia, `product_suppliers` dell'articolo senza varianti — e «Salva»
     * glielo riporta (`suppliersScript()`). Le righe stanno nascoste finché
     * non servono: «Aggiungi fornitore» ne mostra una, la «x» la svuota e la
     * nasconde. I suoi campi escono dal form con la finestra, e
     * `withoutExtras()` li scarta: quello che conta è il JSON.
     *
     * «Salva per tutte le opzioni» (P111) c'è solo dove c'è una griglia, e
     * lo script lo nasconde quando la finestra l'ha aperta il bottone del
     * riquadro «Prodotto».
     */
    protected static function suppliersModal(int $modelId, bool $perTutte = true): Modal
    {
        $scelte = static::supplierChoices($modelId);
        $legami = 0;

        foreach (static::supplierLinks($modelId) as $suoi) {
            $legami = max($legami, count($suoi));
        }

        // Lo stesso fornitore non si scrive due volte: più righe dei
        // fornitori non servono.
        $righe = min(count($scelte), max(static::SUPPLIER_ROWS, $legami));

        // Il posto dell'avviso e le intestazioni, una volta sola sopra le
        // righe.
        $components = [
            RichText::make(
                '<div class="alert alert-danger small" data-wi-supplier-error role="alert" hidden></div>'
                .'<div class="row g-3 small text-body-secondary">'
                .'<div class="col-5">Fornitore</div>'
                .'<div class="col-3">Codice fornitore</div>'
                .'<div class="col-3">Costo d\'acquisto</div>'
                .'<div class="col-1"></div>'
                .'</div>'
            )->tag('div')->columnSpan(12),
        ];

        for ($i = 0; $i < $righe; $i++) {
            $components[] = (new Container)->components([
                FormField::key('wi_product_supplier['.$i.'][supplier_id]')
                    ->select(['' => '—'] + $scelte)
                    ->label('')
                    ->columnSpan(5),
                FormField::key('wi_product_supplier['.$i.'][sku]')
                    ->text()
                    ->maxLength(ProductSuppliers::SKU_MAX_LENGTH)
                    ->label('')
                    ->columnSpan(3),
                FormField::key('wi_product_supplier['.$i.'][cost]')
                    ->price()
                    ->decimal(2)
                    ->label('')
                    ->columnSpan(3),
                FormField::key('wi_product_supplier_remove')
                    ->button('')
                    ->icon('bi bi-x-lg')
                    ->size('sm')
                    ->attribute('data-wi-supplier-remove="'.$i.'" title="Togli il fornitore" aria-label="Togli il fornitore"')
                    ->columnSpan(1),
            ])
                ->attr('data-wi-supplier-line', (string) $i)
                ->columns(12)
                ->columnSpan(12);
        }

        return Modal::make('Fornitori')
            ->id(static::SUPPLIERS_MODAL)
            ->size('lg')
            ->columns(12)
            ->components($components)
            ->footer([
                Button::make('Aggiungi fornitore')->variant('secondary')->outline()->attr('data-wi-supplier-add', 'true'),
                Button::make('Annulla')->variant('secondary')->attr('data-bs-dismiss', 'modal'),
                ...($perTutte ? [
                    Button::make('Salva per tutte le opzioni')->outline()->attr('data-wi-supplier-save-all', 'true'),
                ] : []),
                Button::make('Salva')->attr('data-wi-supplier-save', 'true'),
            ]);
    }

    /**
     * Il codice della finestra «Fornitori» (P110, P111).
     *
     * All'apertura legge il JSON dell'opzione che l'ha aperta e riempie una
     * riga per fornitore; senza righe ne mostra una vuota, con la tendina su
     * «—»: una riga con il solo fornitore è già un legame, e sceglierlo al
     * posto di chi compila ne scriverebbe uno che nessuno ha chiesto. I
     * fornitori non attivi stanno nella tendina solo per l'opzione che li ha
     * già (P92).
     *
     * «Salva» controlla le righe come farà il server — fornitore mancante,
     * doppione, codice lungo, costo fuori misura — e se qualcosa non va lo
     * dice nella finestra, senza chiuderla. Poi riscrive il JSON nello
     * stesso formato del server (`supplier_id`, `supplier_sku`, `cost`;
     * costo vuoto = `""`) e il riassunto accanto al bottone. «Salva per
     * tutte le opzioni» lo scrive su ogni riga della griglia. La scheda si
     * salva con il suo Salva: è `saveSuppliers()` che legge il JSON.
     */
    protected static function suppliersScript(int $modelId): RichText
    {
        $nomi = [];

        foreach (static::supplierChoices($modelId) as $id => $nome) {
            $nomi[] = ['id' => (int) $id, 'name' => $nome];
        }

        // Le frasi sono quelle del server: chi sbaglia legge la stessa cosa
        // nella finestra e dopo il salvataggio.
        $messaggi = [
            'missing' => UserError::make('product.supplier_missing')->getMessage(),
            'duplicate' => UserError::make('product.supplier_duplicate', ['supplier' => '{{supplier}}'])->getMessage(),
            'sku' => UserError::make(
                'product.supplier_sku_too_long',
                ['max' => (string) ProductSuppliers::SKU_MAX_LENGTH]
            )->getMessage(),
            'invalid' => UserError::make('product.supplier_cost_invalid')->getMessage(),
            'negative' => UserError::make('product.supplier_cost_negative')->getMessage(),
            'high' => UserError::make('product.supplier_cost_too_high')->getMessage(),
        ];

        $json = static fn (array $valore): string => static::escape(json_encode(
            $valore,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ));

        return RichText::make(
            '<div class="wi-product-suppliers"'
            .' data-wi-supplier-names="'.$json($nomi).'"'
            .' data-wi-supplier-inactive="'.$json(array_values(static::inactiveSupplierIds($modelId))).'"'
            .' data-wi-supplier-messages="'.$json($messaggi).'"'
            .' data-wi-supplier-max-cost="'.ProductSuppliers::MAX_COST.'"'
            .' data-wi-supplier-sku-length="'.ProductSuppliers::SKU_MAX_LENGTH.'"></div>'
            .<<<'HTML'
<script>
    window.wiProductSuppliers = window.wiProductSuppliers || (function () {
        var FINESTRA = 'wi-product-suppliers';
        // Il campo nascosto e il bottone dell'opzione che ha aperto la
        // finestra.
        var aperta = null;

        function radice() {
            return document.querySelector('.wi-product-suppliers');
        }

        function dato(nome, vuoto) {
            var elemento = radice();

            try {
                var valore = JSON.parse(elemento ? elemento.getAttribute(nome) || '' : '');

                return valore === null || valore === undefined ? vuoto : valore;
            } catch (errore) {
                return vuoto;
            }
        }

        function fornitori() {
            var valori = dato('data-wi-supplier-names', []);

            return Array.isArray(valori) ? valori : [];
        }

        function inattivi() {
            var valori = dato('data-wi-supplier-inactive', []);

            return Array.isArray(valori) ? valori.map(String) : [];
        }

        function messaggio(chiave) {
            var testi = dato('data-wi-supplier-messages', {});

            return testi && typeof testi === 'object' && testi[chiave] ? String(testi[chiave]) : 'Controlla le righe dei fornitori.';
        }

        function limite(nome) {
            var elemento = radice();
            var numero = elemento ? Number(elemento.getAttribute(nome) || 0) : 0;

            return isNaN(numero) ? 0 : numero;
        }

        function nomeFornitore(id) {
            var nome = '';

            fornitori().forEach(function (fornitore) {
                if (String(fornitore.id) === String(id)) {
                    nome = String(fornitore.name || '').trim();
                }
            });

            return nome !== '' ? nome : 'Fornitore n. ' + id;
        }

        function autoNumeric(campo) {
            return window.AutoNumeric && typeof window.AutoNumeric.getAutoNumericElement === 'function'
                ? window.AutoNumeric.getAutoNumericElement(campo)
                : null;
        }

        // Il numero nella casella, col punto: «» se vuota. AutoNumeric
        // quando c'è, se no letto come lo scrive una persona — «12,50 €»,
        // «1.234,5».
        function numeroDi(campo) {
            var an = autoNumeric(campo);
            var testo;

            if (an) {
                testo = String(an.getNumericString() || '');

                return testo === '' || isNaN(Number(testo)) ? '' : testo;
            }

            testo = String(campo.value || '').replace(/[^0-9.,-]/g, '');

            if (testo.indexOf(',') !== -1) {
                testo = testo.replace(/\./g, '').replace(',', '.');
            }

            return testo === '' || isNaN(Number(testo)) ? '' : String(Number(testo));
        }

        function scrivi(campo, valore) {
            var vuoto = valore === null || valore === undefined || valore === '' || isNaN(Number(valore));
            var an = autoNumeric(campo);

            if (an) {
                if (vuoto) {
                    an.clear();
                } else {
                    an.set(Number(valore));
                }

                return;
            }

            campo.value = vuoto ? '' : String(Number(valore));
        }

        // «12,00 €» o «1.234,50 €», come il riassunto che scrive il server.
        function euro(costo) {
            var parti = Number(costo).toFixed(2).split('.');

            parti[0] = parti[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');

            return parti.join(',') + ' €';
        }

        function linee(finestra) {
            return Array.prototype.slice.call(finestra.querySelectorAll('[data-wi-supplier-line]'));
        }

        function caselle(linea) {
            var i = linea.getAttribute('data-wi-supplier-line');

            return {
                fornitore: linea.querySelector('[name="wi_product_supplier[' + i + '][supplier_id]"]'),
                codice: linea.querySelector('[name="wi_product_supplier[' + i + '][sku]"]'),
                costo: linea.querySelector('[name="wi_product_supplier[' + i + '][cost]"]')
            };
        }

        // La cella che contiene la riga, quando il tema la incolonna.
        function cella(linea) {
            var padre = linea.parentElement;

            return padre && /(^|\s)col-/.test(padre.className || '') ? padre : linea;
        }

        function nascosta(linea) {
            return cella(linea).hidden === true;
        }

        function mostra(linea, si) {
            cella(linea).hidden = !si;
        }

        function svuota(linea) {
            var c = caselle(linea);

            if (c.fornitore) {
                c.fornitore.value = '';
            }

            if (c.codice) {
                c.codice.value = '';
            }

            if (c.costo) {
                scrivi(c.costo, null);
            }
        }

        function avviso(finestra, testo) {
            var posto = finestra.querySelector('[data-wi-supplier-error]');

            if (posto) {
                posto.textContent = testo || '';
                posto.hidden = !testo;
            }
        }

        // «Aggiungi fornitore» si spegne quando le righe sono tutte fuori.
        function aggiorna(finestra) {
            var bottone = finestra.querySelector('[data-wi-supplier-add]');

            if (bottone) {
                bottone.disabled = linee(finestra).filter(nascosta).length === 0;
            }
        }

        function rigaDi(bottone) {
            return bottone && bottone.closest ? bottone.closest('.wi-repeater-row') : null;
        }

        // Il campo nascosto dell'opzione del bottone: quello della riga
        // della griglia, o quello della scheda quando l'opzione è una sola.
        function campoDi(bottone) {
            var riga = rigaDi(bottone);

            if (riga) {
                return riga.querySelector('input[name$="[suppliers]"]');
            }

            var form = bottone.closest('form') || document;

            return form.querySelector('[name="product_suppliers"]') || form.querySelector('[name="suppliers"]');
        }

        // Il nome dell'opzione, per il titolo: «Blu / S», o il nome
        // dell'articolo senza varianti.
        function nomeDi(bottone) {
            var riga = rigaDi(bottone);

            if (!riga) {
                var nome = (bottone.closest('form') || document).querySelector('[name="name"]');

                return nome ? String(nome.value || '').trim() : '';
            }

            var gruppo = riga.querySelector('input[name$="[group]"]');
            var opzione = riga.querySelector('input[name$="[option]"]');
            var g = gruppo ? String(gruppo.value || '').trim() : '';
            var o = opzione ? String(opzione.value || '').trim() : '';

            return g !== '' && o !== '' && g !== o ? g + ' / ' + o : (o || g);
        }

        function righe(campo) {
            try {
                var elenco = JSON.parse(campo && campo.value ? campo.value : '[]');

                return Array.isArray(elenco) ? elenco.filter(function (riga) {
                    return riga && typeof riga === 'object';
                }) : [];
            } catch (errore) {
                return [];
            }
        }

        // I fornitori scritti nel campo di un'opzione.
        function fornitoriDi(campo) {
            return righe(campo).map(function (riga) {
                return String(riga.supplier_id === undefined || riga.supplier_id === null ? '' : riga.supplier_id);
            });
        }

        // Un fornitore non attivo resta nella tendina solo per l'opzione
        // che lo ha già (P92).
        function tendine(finestra, suoi) {
            var spenti = inattivi();

            if (spenti.length === 0) {
                return;
            }

            linee(finestra).forEach(function (linea) {
                var c = caselle(linea);

                if (!c.fornitore) {
                    return;
                }

                Array.prototype.slice.call(c.fornitore.options).forEach(function (voce) {
                    if (spenti.indexOf(String(voce.value)) !== -1) {
                        voce.hidden = voce.disabled = suoi.indexOf(String(voce.value)) === -1;
                    }
                });
            });
        }

        function riempi(finestra, campo) {
            var elenco = righe(campo);
            var tutte = linee(finestra);

            tendine(finestra, fornitoriDi(campo));

            tutte.forEach(function (linea, i) {
                var riga = elenco[i] || null;
                var c = caselle(linea);

                svuota(linea);

                if (riga === null) {
                    mostra(linea, false);

                    return;
                }

                if (c.fornitore) {
                    c.fornitore.value = String(riga.supplier_id === undefined || riga.supplier_id === null ? '' : riga.supplier_id);
                }

                if (c.codice) {
                    c.codice.value = String(riga.supplier_sku === undefined || riga.supplier_sku === null ? '' : riga.supplier_sku);
                }

                if (c.costo) {
                    scrivi(c.costo, riga.cost);
                }

                mostra(linea, true);
            });

            // Senza righe se ne mostra una vuota: qualcosa da compilare.
            if (elenco.length === 0 && tutte.length > 0) {
                mostra(tutte[0], true);
            }

            aggiorna(finestra);
        }

        // La prima riga nascosta esce, vuota.
        function aggiungi(finestra) {
            var libera = linee(finestra).filter(nascosta)[0] || null;

            if (libera === null) {
                return;
            }

            svuota(libera);
            mostra(libera, true);
            aggiorna(finestra);
        }

        // Le righe scritte, nell'ordine della finestra, oppure la frase di
        // quello che non va: gli stessi controlli del server.
        function leggi(finestra) {
            var elenco = [];
            var visti = {};
            var errore = '';
            var massimo = limite('data-wi-supplier-max-cost');
            var lunghezza = limite('data-wi-supplier-sku-length');

            linee(finestra).forEach(function (linea) {
                if (errore !== '' || nascosta(linea)) {
                    return;
                }

                var c = caselle(linea);
                var fornitore = c.fornitore ? String(c.fornitore.value || '') : '';
                var codice = c.codice ? String(c.codice.value || '').trim() : '';
                var costo = c.costo ? numeroDi(c.costo) : '';
                var scritto = c.costo && !autoNumeric(c.costo) ? String(c.costo.value || '').trim() : '';

                // Una riga lasciata vuota non dice niente.
                if (fornitore === '' && codice === '' && costo === '' && scritto === '') {
                    return;
                }

                if (fornitore === '') {
                    errore = messaggio('missing');
                } else if (lunghezza > 0 && codice.length > lunghezza) {
                    errore = messaggio('sku');
                } else if (costo === '' && scritto !== '') {
                    errore = messaggio('invalid');
                } else if (costo !== '' && Number(costo) < 0) {
                    errore = messaggio('negative');
                } else if (costo !== '' && massimo > 0 && Number(costo) > massimo) {
                    errore = messaggio('high');
                } else if (visti[fornitore]) {
                    errore = messaggio('duplicate').replace('{{supplier}}', function () {
                        return nomeFornitore(fornitore);
                    });
                }

                if (errore !== '') {
                    return;
                }

                visti[fornitore] = true;
                elenco.push({
                    supplier_id: Number(fornitore),
                    supplier_sku: codice,
                    cost: costo === '' ? '' : Number(costo)
                });
            });

            return { elenco: elenco, errore: errore };
        }

        function riassunto(elenco) {
            var parti = elenco.map(function (riga) {
                var nome = nomeFornitore(riga.supplier_id);

                return riga.cost === '' || riga.cost === null || riga.cost === undefined
                    ? nome
                    : nome + ' ' + euro(riga.cost);
            });

            return parti.length > 0 ? parti.join(' · ') : 'Nessun fornitore';
        }

        function scriviIn(campo, bottone, elenco) {
            if (!campo) {
                return;
            }

            campo.value = JSON.stringify(elenco);
            campo.dispatchEvent(new Event('change', { bubbles: true }));

            var posto = bottone && bottone.parentElement
                ? bottone.parentElement.querySelector('[data-wi-button-caption]')
                : null;

            if (posto) {
                posto.textContent = riassunto(elenco);
            }
        }

        // Su tutte le righe della griglia, anche quelle chiuse in un gruppo.
        // Un fornitore non attivo va solo alle opzioni che lo hanno già: il
        // server lo rifiuterebbe sulle altre.
        function scriviOvunque(elenco) {
            var form = (aperta && aperta.bottone ? aperta.bottone.closest('form') : null) || document;
            var spenti = inattivi();
            var campi = Array.prototype.slice.call(
                form.querySelectorAll('.wi-repeater-row input[name$="[suppliers]"]')
            );

            campi.forEach(function (campo) {
                var riga = campo.closest('.wi-repeater-row');
                var suoi = fornitoriDi(campo);
                var ammesse = elenco.filter(function (voce) {
                    var id = String(voce.supplier_id);

                    return spenti.indexOf(id) === -1 || suoi.indexOf(id) !== -1;
                });

                scriviIn(
                    campo,
                    riga ? riga.querySelector('[data-bs-target="#' + FINESTRA + '"]') : null,
                    ammesse
                );
            });
        }

        function salva(finestra, ovunque) {
            if (!aperta || !aperta.campo) {
                return false;
            }

            var letto = leggi(finestra);

            if (letto.errore !== '') {
                avviso(finestra, letto.errore);

                return false;
            }

            avviso(finestra, '');

            if (ovunque) {
                scriviOvunque(letto.elenco);
            }

            scriviIn(aperta.campo, aperta.bottone, letto.elenco);

            return true;
        }

        function avvia() {
            if (window.wiProductSuppliersReady) {
                return;
            }

            window.wiProductSuppliersReady = true;

            document.addEventListener('show.bs.modal', function (evento) {
                var finestra = evento.target;
                var bottone = evento.relatedTarget || null;

                if (!finestra || finestra.id !== FINESTRA) {
                    return;
                }

                aperta = bottone ? { bottone: bottone, campo: campoDi(bottone) } : null;

                var titolo = finestra.querySelector('[data-wi-modal-title]');
                var nome = bottone ? nomeDi(bottone) : '';
                var perTutte = finestra.querySelector('[data-wi-supplier-save-all]');

                if (titolo) {
                    titolo.textContent = nome !== '' ? 'Fornitori · ' + nome : 'Fornitori';
                }

                // «Salva per tutte le opzioni» serve dove c'è una griglia.
                if (perTutte) {
                    perTutte.hidden = !(bottone && rigaDi(bottone));
                }

                if (typeof setAutonumeric === 'function') {
                    setAutonumeric(finestra);
                }

                avviso(finestra, '');
                riempi(finestra, aperta ? aperta.campo : null);
            });

            document.addEventListener('click', function (evento) {
                var bersaglio = evento.target && evento.target.closest ? evento.target : null;

                if (!bersaglio) {
                    return;
                }

                var togli = bersaglio.closest('[data-wi-supplier-remove]');

                // La «x»: la riga si svuota e torna nascosta.
                if (togli) {
                    var linea = togli.closest('[data-wi-supplier-line]');
                    var dentro = togli.closest('#' + FINESTRA);

                    evento.preventDefault();

                    if (linea && dentro) {
                        svuota(linea);
                        mostra(linea, false);
                        aggiorna(dentro);
                    }

                    return;
                }

                var aggiungiFornitore = bersaglio.closest('[data-wi-supplier-add]');

                if (aggiungiFornitore) {
                    var finestraDaAllargare = aggiungiFornitore.closest('#' + FINESTRA);

                    evento.preventDefault();

                    if (finestraDaAllargare) {
                        aggiungi(finestraDaAllargare);
                    }

                    return;
                }

                var perTutte = bersaglio.closest('[data-wi-supplier-save-all]');
                var conferma = perTutte || bersaglio.closest('[data-wi-supplier-save]');

                if (!conferma) {
                    return;
                }

                var finestra = conferma.closest('.modal');

                evento.preventDefault();

                // Con un errore la finestra resta aperta, con la frase in
                // cima.
                if (finestra && salva(finestra, perTutte !== null) && window.bootstrap && window.bootstrap.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(finestra).hide();
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', avvia);
        } else {
            avvia();
        }

        return avvia;
    })();
</script>
HTML
        )->tag('div');
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
            $values['product_min_stock'],
            // Le righe per sede (P102) e i campi della finestra «Giacenza»
            // (P103): li legge `saveLocationStock()`.
            $values['locations'],
            $values['wi_location_stock'],
            // I fornitori (P109) e i campi della finestra: li legge
            // `saveSuppliers()`.
            $values['product_supplier_sku'],
            $values['product_supplier_cost'],
            $values['product_suppliers'],
            $values['product_suppliers_button'],
            $values['suppliers'],
            $values['suppliers_button'],
            $values['supplier_sku'],
            $values['supplier_cost'],
            $values['wi_product_supplier'],
            $values['wi_product_supplier_remove'],
            // Sono delle opzioni: le scrive `saveBackorders()`.
            $values['allow_backorder'],
            $values['backorder_lead_days'],
            // La composizione di un multiprodotto: la scrive `saveBundle()`.
            $values['bundle_components'],
            $values['bundle_groups'],
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
     * Prezzi, codice e codice a barre scritti in «Prodotto».
     *
     * Con una versione sola sono i suoi. Con più versioni la casella del
     * prezzo è nascosta, ma arriva: piena solo se alla lettura della scheda la
     * versione era una, cioè quando le varianti si sono appena accese, e
     * allora il prezzo che l'articolo aveva va alle opzioni nate senza il
     * loro. Vuota non tocca niente — è l'unico modo di avere prezzi diversi
     * senza che un salvataggio distratto li riallinei tutti.
     *
     * SKU ed EAN invece riguardano solo la versione unica: quando sono più di
     * una, ognuna ha i suoi nella griglia.
     *
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
                'sale_price' => Numbers::fromForm($riga['sale_price'] ?? null) ?? $scontato,
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
            $riga = $scritto($product);

            // Una versione appena nata con il suo prezzo non si tocca: è stata
            // scritta nella stessa schermata. Con il solo scontato suo prende
            // il prezzo dell'articolo e tiene lo sconto.
            if (Numbers::fromForm($riga['price'] ?? null) !== null) {
                continue;
            }

            $suoi = $values;

            if (Numbers::fromForm($riga['sale_price'] ?? null) !== null) {
                unset($suoi['sale_price']);
            }

            if ($suoi !== []) {
                Product::update($suoi, (int) $product['id']);
            }
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
     * Il tipo fiscale: parte dal predefinito e si vede sempre.
     *
     * Su un articolo salvato il tipo che usa resta nell'elenco anche se nel
     * frattempo è stato nascosto. Senza nessun tipo le imposte ricadono
     * sull'aliquota di ripiego, e il campo lo dice invece di pretendere una
     * scelta che non c'è.
     */
    protected static function taxCategoryField(int $modelId = 0): Input
    {
        $keep = 0;

        if ($modelId > 0) {
            $row = static::rowsOf(ProductModel::class, ['id' => $modelId])[0] ?? [];
            $keep = (int) ($row['tax_category_id'] ?? 0);
        }

        $options = TaxCategories::options($keep);

        if ($options === []) {
            return FormField::key('tax_category_id')
                ->select(['' => "Nessuno: vale l'aliquota di ripiego"])
                ->value('')
                ->label('Tipo fiscale')
                ->quickCreate(TaxCategoryResource::class);
        }

        return FormField::key('tax_category_id')
            ->select($options)
            ->value((string) (TaxCategories::defaultId() ?: ''))
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

    /**
     * Il numero grezzo per le caselle numeriche: col punto e senza zeri in
     * coda, «3», «2.5», «1234.125». Le cifre e l'unità le mette AutoNumeric;
     * una virgola qui la leggerebbe come separatore delle migliaia.
     */
    protected static function rawNumber(float $value): string
    {
        $value = round($value, 3);

        if ($value === round($value, 0)) {
            return number_format($value, 0, '.', '');
        }

        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    /** L'unità di misura dell'articolo; «pz» per uno nuovo, come la select. */
    protected static function modelUnit(int $modelId): string
    {
        if ($modelId <= 0) {
            return 'pz';
        }

        $riga = static::rowsOf(ProductModel::class, ['id' => $modelId])[0] ?? [];
        $unita = trim((string) ($riga['unit'] ?? ''));

        return $unita === '' ? 'pz' : $unita;
    }

    /**
     * Le giacenze di queste versioni, sommate fra le sedi.
     *
     * @param list<int> $productIds
     * @return array<int, float>
     */
    protected static function stockQuantities(array $productIds): array
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds)));

        if ($productIds === []) {
            return [];
        }

        return array_map(
            static fn (array $level): float => (float) $level['quantity'],
            Levels::forProducts($productIds)
        );
    }

    /**
     * Come si scrive la giacenza di queste versioni: i decimali dell'unità,
     * tre se una giacenza ne ha già, e l'unità in coda.
     *
     * @param list<array<string, mixed>> $products
     * @return array{decimals: int, suffix: string}
     */
    protected static function stockFormat(int $modelId, array $products): array
    {
        $unita = static::modelUnit($modelId);
        $giacenze = static::stockQuantities(array_map(
            static fn (array $product): int => (int) ($product['id'] ?? 0),
            $products
        ));

        return [
            'decimals' => SaleUnits::decimalsFor($unita, ...array_values($giacenze)),
            'suffix' => SaleUnits::suffix($unita),
        ];
    }

    /**
     * Come si scrivono le righe per sede di queste versioni: si guarda ogni
     * sede, perché una somma tonda nasconde i decimali di una sede sola.
     *
     * @param list<array<string, mixed>> $products
     * @return array{stock: int, min_stock: int, suffix: string}
     */
    protected static function locationFormat(int $modelId, array $products): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $product): int => (int) ($product['id'] ?? 0),
            $products
        )));

        return LocationRows::format(
            static::modelUnit($modelId),
            static::locationQuantities($ids),
            static::locationThresholds($ids)
        );
    }

    /**
     * @param list<int> $productIds
     * @return list<float> le giacenze di queste versioni, sede per sede
     */
    protected static function locationQuantities(array $productIds): array
    {
        $quantities = [];

        foreach ($productIds === [] ? [] : Levels::byLocationForProducts($productIds) as $perSede) {
            foreach ($perSede as $level) {
                $quantities[] = (float) $level['quantity'];
            }
        }

        return $quantities;
    }

    /**
     * @param list<int> $productIds
     * @return list<float> le soglie di queste versioni, sede per sede
     */
    protected static function locationThresholds(array $productIds): array
    {
        $thresholds = [];

        foreach ($productIds === [] ? [] : Thresholds::forProducts($productIds) as $perSede) {
            foreach ($perSede as $threshold) {
                $thresholds[] = (float) $threshold;
            }
        }

        return $thresholds;
    }

    /**
     * La casella della scorta minima, scritta come la giacenza: i decimali
     * dell'unità — tre se una soglia ne ha già — e l'unità in coda.
     *
     * @param list<array<string, mixed>> $products
     */
    protected static function minStockInput(FormField $campo, int $modelId, array $products): InputNumber
    {
        $unita = static::modelUnit($modelId);
        // Le soglie sono in `gst_stock_thresholds` (P100, P101): quelle
        // della sede principale, che è ciò che la casella mostra.
        $soglie = array_values(static::mainThresholds(array_map(
            static fn (array $product): int => (int) ($product['id'] ?? 0),
            $products
        )));

        $campo = $campo->number()->decimal(SaleUnits::decimalsFor($unita, ...$soglie));
        $suffisso = SaleUnits::suffix($unita);

        if ($suffisso !== '') {
            $campo->suffix($suffisso);
        }

        return $campo;
    }

    /**
     * La descrizione come la vuole l'editor.
     *
     * Quella scritta con l'editor ha già i suoi tag e passa com'è. Quella di
     * prima era testo semplice, salvato con gli apostrofi protetti e le
     * lettere accentate in entità: si decodifica come in lettura, e ogni riga
     * non vuota diventa un paragrafo. Un «<» scritto nel testo resta un «<»:
     * conta come HTML solo un tag che l'editor conosce.
     */
    public static function editorHtml(string $stored): string
    {
        if (trim($stored) === '') {
            return '';
        }

        if (preg_match('/<\/?(p|br|strong|b|em|i|u|s|strike|del|a|div|span|ul|ol|li|h[1-6]|blockquote)\b[^>]*>/i', $stored) === 1) {
            return $stored;
        }

        $testo = function_exists('sanitizeEcho')
            ? (string) sanitizeEcho($stored)
            : Entity::decode(stripslashes($stored));

        $paragrafi = [];

        foreach (preg_split('/\R/u', $testo) ?: [] as $riga) {
            $riga = trim($riga);

            if ($riga !== '') {
                $paragrafi[] = '<p>'.htmlspecialchars($riga, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';
            }
        }

        return implode('', $paragrafi);
    }

    /** Il testo su una riga sola: gli a capo diventano uno spazio. */
    public static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s*\R\s*/u', ' ', $text));
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
