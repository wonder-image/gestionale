<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use Throwable;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\Support\Repeater;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\Modal;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;
use Wonder\Plugin\Gestionale\Support\Catalog\Ean;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Sku;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Purchasing\ProductSuppliers;
use Wonder\Plugin\Gestionale\Support\Stock\Adjustment;
use Wonder\Plugin\Gestionale\Support\Stock\Alerts;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\LocationRows;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\LocationStock;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\StockBadge;
use Wonder\Plugin\Gestionale\Support\Stock\StockHistory;
use Wonder\Plugin\Gestionale\Support\Stock\Thresholds;

/**
 * "Opzioni in vendita": l'elenco piatto di quello che si vende davvero.
 *
 * Serve a **trovare** — uno SKU, un EAN, un prezzo da correggere — non a
 * creare: un'opzione nasce nella griglia della scheda del prodotto, dove si
 * vede insieme alle sorelle. Per questo la pagina non ha il pulsante
 * "Aggiungi".
 *
 * La scheda della singola opzione tiene quello che nella griglia non
 * entrerebbe: MPN, misure proprie, gli attributi di livello `product`, la
 * scorta minima — per sede, quando le sedi sono più di una — e, con gli
 * acquisti, i suoi fornitori: gli stessi della sua riga nella griglia.
 *
 * Estende la Resource dei prodotti per riusarne le letture del catalogo —
 * attributi, valori, campi degli attributi — e ne riscrive tutto il resto:
 * qui non nasce nessuno scheletro e non si elimina nessun prodotto.
 *
 * Non è `final`: i test la estendono con una classe anonima.
 */
class ProductResource extends ProductModelResource
{
    /** L'id della finestra della rettifica: lo cerca il bottone del riquadro «Magazzino». */
    public const ADJUST_MODAL = 'wi-stock-adjust';

    public static string $model = Product::class;

    /**
     * I fornitori letti, per opzione e per richiesta: quelli del genitore
     * hanno per chiave l'id dell'articolo.
     *
     * @var array<int, array<int, string>>
     */
    private static array $optionSupplierChoices = [];

    /** @var array<int, array<int, list<array<string, mixed>>>> */
    private static array $optionSupplierLinks = [];

    /** @var array<int, list<int>> */
    private static array $optionInactiveSuppliers = [];
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
            'mpn' => 'MPN',
            'price' => 'Prezzo',
            'available' => 'D.tà',
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
            FormField::key('mpn')->text()->label('MPN'),
            FormField::key('price')->price()->decimal(2)->label('Prezzo'),
            FormField::key('sale_price')->price()->decimal(2)->label('Prezzo scontato'),
            FormField::key('active')
                ->select(['true' => 'Pubblicato', 'false' => 'Bozza'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('weight')->number()->decimal(3)->label('Peso (kg)'),
            FormField::key('length')->number()->decimal(2)->label('Lunghezza (cm)'),
            FormField::key('width')->number()->decimal(2)->label('Larghezza (cm)'),
            FormField::key('height')->number()->decimal(2)->label('Altezza (cm)'),
        ];

        // La soglia non è una colonna dell'opzione: sta in `gst_stock_thresholds`,
        // per sede. Con più sedi la scrivono le righe per sede, insieme alla
        // giacenza; con una sola resta la casella, che parla della sede
        // principale.
        if (static::hasManyLocations()) {
            $fields[] = static::locationRowsField();
        } elseif (Gestionale::feature('low_stock_alerts')) {
            $formato = static::optionFormat(static::currentId() ?? 0);
            $minima = FormField::key('min_stock')->number()->decimal($formato['min_stock'])->label('Scorta minima');

            if ($formato['suffix'] !== '') {
                $minima->suffix($formato['suffix']);
            }

            $fields[] = $minima;
        }

        // Come nella riga della griglia (P112): due campi con un fornitore
        // solo, il bottone della finestra con più, niente senza.
        array_push($fields, ...static::supplierInputs(static::currentId() ?? 0));

        $attributi = static::splitAttributes(Attributes::byLevel(static::attributes(), 'product'));

        // Le opzioni di vendita si leggono e non si scrivono (P118), ma il
        // legame va rimandato indietro lo stesso: `afterUpdate()` riscrive
        // quello che arriva, e un campo assente cancellerebbe il valore.
        foreach ($attributi['sales'] as $attribute) {
            $fields[] = FormField::key('attribute_' . (int) $attribute['id'])->hidden();
        }

        foreach ($attributi['technical'] as $attribute) {
            $fields[] = static::attributeField($attribute);
        }

        return $fields;
    }

    /** Due o più sedi da mostrare: giacenza e scorta minima si scrivono per sede. */
    protected static function hasManyLocations(): bool
    {
        return count(Locations::shown()) >= 2;
    }

    /**
     * Come si scrivono giacenza e scorta minima dell'opzione: con l'unità del
     * suo articolo, come nella scheda dell'articolo.
     *
     * @return array{stock: int, min_stock: int, suffix: string}
     */
    protected static function optionFormat(int $productId): array
    {
        $product = $productId > 0 ? (static::rowsOf(Product::class, ['id' => $productId])[0] ?? []) : [];

        return static::locationFormat(
            (int) ($product['product_model_id'] ?? 0),
            $productId > 0 ? [['id' => $productId]] : []
        );
    }

    /**
     * La giacenza e la scorta minima dell'opzione, una riga per sede.
     *
     * Il repeater non ha relazione: le righe le compone `LocationRows` da
     * giacenze e soglie in `mutateFormValues()`, le controlla
     * `mutateRequestValues()` e le scrive `afterUpdate()` con
     * `LocationStock::apply()`. Una riga tolta porta via la soglia della sede
     * e non i suoi pezzi: ricompare finché ha giacenza.
     */
    protected static function locationRowsField(): Input
    {
        $soglia = Gestionale::feature('low_stock_alerts');
        $formato = static::optionFormat(static::currentId() ?? 0);
        $sedi = [];

        foreach (Locations::shown() as $location) {
            $sedi[(int) ($location['id'] ?? 0)] = (string) ($location['label'] ?? '');
        }

        // La quantità che si vuole: vuota non tocca niente, zero scritto
        // vale zero e diventa una rettifica sulla sede.
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
        $columns = [
            // Il «—» per primo: una riga aggiunta e lasciata lì non scrive
            // niente su nessuna sede.
            RepeaterColumn::key('location_id')
                ->select(['' => '—'] + $sedi)
                ->label('Sede')
                ->columnSpan($soglia ? 5 : 7),
            $giacenza,
        ];

        if ($soglia) {
            $columns[] = $minima;
        }

        return FormField::key('locations')
            ->repeater($columns)
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
     * I fornitori che la scheda propone, id => nome: gli attivi, più quelli
     * già legati all'opzione anche se non lo sono più (P92).
     *
     * Dal loro numero dipende come si compilano (P112). Il numero è quello
     * dell'opzione: una sorella può avere un fornitore non più attivo che
     * qui non si propone, e la sua scheda può chiedere la finestra dove
     * questa chiede i due campi.
     *
     * Quelli del genitore leggono per articolo, e tengono per chiave l'id
     * dell'articolo: qui l'id è dell'opzione, e le letture stanno a parte.
     *
     * @return array<int, string>
     */
    protected static function supplierChoices(int $productId): array
    {
        if (!array_key_exists($productId, self::$optionSupplierChoices)) {
            self::$optionSupplierChoices[$productId] = Contacts::supplierOptions(array_map(
                'intval',
                array_column(static::supplierLinks($productId)[$productId] ?? [], 'supplier_id')
            ));
        }

        return self::$optionSupplierChoices[$productId];
    }

    /**
     * I fornitori dell'opzione, nella forma di quelli dell'articolo: per
     * prodotto, e qui il prodotto è uno. Vuoto se non ne ha.
     *
     * @return array<int, list<array{supplier_id: int, supplier_sku: string, cost: ?float}>>
     */
    protected static function supplierLinks(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        return self::$optionSupplierLinks[$productId] ??= ProductSuppliers::linksFor([$productId]);
    }

    /**
     * I fornitori proposti solo perché già legati all'opzione: sono su
     * «Non attivo». Il database si guarda solo se l'opzione ha dei legami.
     *
     * @return list<int>
     */
    protected static function inactiveSupplierIds(int $productId): array
    {
        if (!array_key_exists($productId, self::$optionInactiveSuppliers)) {
            $inattivi = [];

            if (static::supplierLinks($productId) !== []) {
                $attivi = Contacts::supplierOptions();

                foreach (array_keys(static::supplierChoices($productId)) as $id) {
                    if (!isset($attivi[$id])) {
                        $inattivi[] = (int) $id;
                    }
                }
            }

            self::$optionInactiveSuppliers[$productId] = $inattivi;
        }

        return self::$optionInactiveSuppliers[$productId];
    }

    public static function forgetCatalogCache(): void
    {
        parent::forgetCatalogCache();

        self::$optionSupplierChoices = [];
        self::$optionSupplierLinks = [];
        self::$optionInactiveSuppliers = [];
    }

    public static function formLayoutSchema(): ?Form
    {
        $productId = static::currentId() ?? 0;
        $attributi = static::splitAttributes(Attributes::byLevel(static::attributes(), 'product'));

        $cards = [
            (new Card)->components([
                SectionTitle::make(static::currentTitle())
                    ->tooltip('Questa opzione nasce nella scheda del prodotto: nome, descrizioni e categorie si cambiano di là, e il nome della riga segue gli attributi che la compongono. Qui c\'è quello che vale solo per lei.')
                    ->columnSpan(12),
                static::getInput('price')->columnSpan(4),
                static::getInput('sale_price')->columnSpan(4),
                static::getInput('active')->columnSpan(4),
            ])->columns(12)->columnSpan(12),
        ];

        if ($attributi['sales'] !== []) {
            // I campi nascosti ci sono tutti, anche quelli senza valore: sono
            // loro a rimandare indietro il legame che la scheda non scrive.
            $nascosti = [];

            foreach ($attributi['sales'] as $attribute) {
                $nascosti[] = static::getInput('attribute_' . (int) $attribute['id'])->columnSpan(12);
            }

            $voci = static::salesOptions(
                $attributi['sales'],
                $productId > 0 ? ProductAttributes::read('product', $productId) : []
            );
            $letto = static::salesOptionsHtml($voci);

            $cards[] = (new Card)->components([
                SectionTitle::make('Opzioni di vendita')
                    ->tooltip('Gli attributi che fanno nascere questa opzione — taglia, formato, colore — e che dicono quale delle sorelle è. Qui si leggono: cambiarli vuol dire cambiare opzione, e si fa nella griglia della scheda dell\'articolo, dove si vedono tutte insieme.')
                    ->columnSpan(12),
                RichText::make($letto !== ''
                    ? $letto
                    : '<p class="text-body-secondary mb-0">Questa opzione non ha ancora nessun valore scelto: si scelgono nella griglia della scheda dell\'articolo.</p>')
                    ->tag('div')
                    ->columnSpan(12),
                ...$nascosti,
            ])->columns(12)->columnSpan(12);
        }

        // La scheda tecnica è quella dell'articolo, con le caratteristiche di
        // livello «opzione»: senza nemmeno una, il riquadro non serve.
        if (static::technicalAttributes() !== []) {
            $cards[] = static::technicalSheetCard();
        }

        $soglia = Gestionale::feature('low_stock_alerts');
        $sedi = static::hasManyLocations();

        if ($sedi) {
            $spiegazione = 'Una riga per sede: la giacenza scritta diventa una rettifica su quella sede, vuota non tocca niente. Per lasciare una causale e una nota c\'è la rettifica.'
                . ($soglia ? ' La scorta minima è la soglia sotto cui arriva l\'avviso per quella sede, e con zero non arriva niente.' : '');
        } else {
            $spiegazione = 'Qui la giacenza si legge. Si scrive nella riga della griglia delle opzioni in vendita, oppure con una rettifica quando serve lasciare una causale e una nota.'
                . ($soglia ? ' La scorta minima è la soglia sotto cui arriva l\'avviso, e con zero non arriva niente.' : '');
        }

        $finestra = static::stockAdjustModal($productId, static::editUrlFor($productId));

        $cards[] = (new Card)->components([
            SectionTitle::make(static::stockCardTitle())
                ->tooltip($spiegazione)
                ->columnSpan(12),
            ...($sedi
                ? [static::getInput('locations')->columnSpan(12)]
                : ($soglia ? [static::getInput('min_stock')->columnSpan(4)] : [])),
            RichText::make(static::stockSummary())->columnSpan(12),
            RichText::make(static::stockHistoryTable())->tag('div')->columnSpan(12),
            // Senza un'opzione aperta non c'è niente da rettificare, e un
            // nodo vuoto lascerebbe comunque la sua colonna.
            ...($finestra !== '' ? [RichText::make($finestra)->tag('div')->columnSpan(12)] : []),
        ])->columns(12)->columnSpan(12);

        $fornitori = static::supplierMode($productId);

        if ($fornitori !== null) {
            $cards[] = (new Card)->components([
                SectionTitle::make('Fornitori')
                    ->tooltip('Da chi si compra questa opzione: il codice che le dà il fornitore e il costo d\'acquisto. Un costo lasciato vuoto vuol dire «non lo so», non zero.')
                    ->columnSpan(12),
                ...match ($fornitori) {
                    'flat' => [
                        static::getInput('supplier_sku')->columnSpan(4),
                        static::getInput('supplier_cost')->columnSpan(4),
                    ],
                    'modal' => [
                        static::getInput('suppliers'),
                        static::getInput('suppliers_button')->columnSpan(12),
                    ],
                    default => [
                        RichText::make('<p class="text-muted mb-0">Nessun fornitore da proporre: aggiungilo da Anagrafiche → Fornitori.</p>')->columnSpan(12),
                    ],
                },
            ])->columns(12)->columnSpan(12);
        }

        // La finestra è quella della griglia, senza «Salva per tutte le
        // opzioni»: qui l'opzione è una.
        if ($fornitori === 'modal') {
            $cards[] = static::suppliersModal($productId, false);
            $cards[] = static::suppliersScript($productId);
        }

        // Due colonne (P117): a sinistra il lavoro di tutti i giorni, a destra
        // i codici e le misure, che si guardano di rado. `columns(12)` sta
        // anche sul Form, non solo sui contenitori: il renderer calcola la
        // larghezza di un figlio sulle colonne del padre.
        return (new Form)->components([
            (new Container)->components($cards)->columns(12)->columnSpan(8),
            (new Container)->components([static::identificationCard()])->columns(12)->columnSpan(4),
        ])->columns(12);
    }

    /**
     * I codici dell'opzione e le sue misure, nella colonna stretta (P117,
     * P118). Un riquadro solo: i codici e le misure descrivono tutti e due il
     * pezzo fisico, e si guardano di rado.
     */
    protected static function identificationCard(): Card
    {
        return (new Card)->components([
            SectionTitle::make('Identificazione')
                ->tooltip('I codici di questa opzione, e quanto pesa e misura il suo pacco. Lasciando vuote le misure valgono peso e misure dell\'articolo.')
                ->columnSpan(12),
            static::getInput('sku')->columnSpan(12),
            static::getInput('ean')->columnSpan(12),
            static::getInput('mpn')->columnSpan(12),
            static::getInput('weight')->columnSpan(6),
            static::getInput('length')->columnSpan(6),
            static::getInput('width')->columnSpan(6),
            static::getInput('height')->columnSpan(6),
        ])->columns(12)->columnSpan(12);
    }

    /**
     * Gli attributi dell'opzione, divisi in due (P118): quelli che la fanno
     * nascere — taglia, colore, formato — e quelli che la descrivono.
     *
     * @param list<array<string, mixed>> $attributes
     * @return array{sales: list<array<string, mixed>>, technical: list<array<string, mixed>>}
     */
    public static function splitAttributes(array $attributes): array
    {
        $diviso = ['sales' => [], 'technical' => []];

        foreach ($attributes as $attribute) {
            $dove = Attributes::usesValues((string) ($attribute['type'] ?? '')) ? 'sales' : 'technical';
            $diviso[$dove][] = $attribute;
        }

        return $diviso;
    }

    /**
     * Le opzioni di vendita di questa opzione, come si leggono: il nome
     * dell'attributo e il valore scelto.
     *
     * Quelle senza valore restano fuori (P118): una riga vuota non dice
     * niente, e qui non c'è niente da compilare.
     *
     * @param list<array<string, mixed>> $attributes
     * @param array<int, array<string, mixed>> $links i legami di `ProductAttributes::read()`
     * @return list<array{name: string, value: string}>
     */
    public static function salesOptions(array $attributes, array $links): array
    {
        $voci = [];

        foreach ($attributes as $attribute) {
            $id = (int) ($attribute['id'] ?? 0);
            $valueId = (string) ($links[$id]['attribute_value_id'] ?? '');

            if ($valueId === '' || $valueId === '0') {
                continue;
            }

            $scelto = static::valueChoices($attribute)[$valueId] ?? '';
            // Un valore che porta con sé il pallino, la fantasia o l'icona
            // arriva come array: il nome sta dentro.
            $valore = trim(is_array($scelto) ? (string) ($scelto['name'] ?? '') : (string) $scelto);

            if ($valore === '') {
                continue;
            }

            $voci[] = ['name' => (string) ($attribute['name'] ?? ''), 'value' => $valore];
        }

        return $voci;
    }

    /**
     * Il riquadro delle opzioni di vendita: testo, non caselle (P118).
     *
     * @param list<array{name: string, value: string}> $voci
     */
    public static function salesOptionsHtml(array $voci): string
    {
        if ($voci === []) {
            return '';
        }

        $html = '<dl class="row mb-0">';

        foreach ($voci as $voce) {
            $html .= '<dt class="col-4 fw-normal text-body-secondary">' . static::escape($voce['name']) . '</dt>'
                . '<dd class="col-8 mb-1">' . static::escape($voce['value']) . '</dd>';
        }

        return $html . '</dl>';
    }

    /** Le caratteristiche della scheda tecnica dell'opzione: le sue, non quelle dell'articolo. */
    protected static function technicalAttributes(): array
    {
        return static::splitAttributes(Attributes::byLevel(static::attributes(), 'product'))['technical'];
    }

    /** Qui una caratteristica non nasce: nascerebbe sull'articolo (P118). */
    protected static function technicalNew(): bool
    {
        return false;
    }

    protected static function technicalEmptyText(): string
    {
        return 'Qui vanno le caratteristiche che cambiano da un\'opzione all\'altra: volume, gradazione, durata.';
    }

    protected static function technicalTooltip(): string
    {
        return 'Quello che descrive questa opzione e non la fa nascere: volume, gradazione, durata. Si vede solo quello che è compilato, e il resto si aggiunge da «Aggiungi caratteristica». Una caratteristica nuova si prepara in Catalogo → Attributi.';
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
        $parts = ['<b>Giacenza:</b> ' . static::escape(static::plainNumber($levels['quantity'])) . ' pezzi'];

        if (Gestionale::feature('orders')) {
            $parts[] = 'impegnati ' . static::escape(static::plainNumber($levels['reserved']));
            $parts[] = 'disponibili ' . static::escape(static::plainNumber($levels['available']));
        }

        // La rettifica non porta via dalla scheda: apre la finestra (P119).
        $parts[] = '<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#'
            . static::ADJUST_MODAL . '"><i class="bi bi-pencil-square me-1"></i>Rettifica</button>';

        return implode(' · ', $parts);
    }

    /**
     * Le colonne dei movimenti dentro la scheda dell'opzione (P128).
     *
     * Sono nomi dello schema di `StockMovementResource`, che le sceglie lei
     * (`historyColumns()`): la versione non c'è perché la scheda è già la sua,
     * la nota resta all'elenco, la sede compare solo con più sedi. Data, *Tipo*
     * col documento e *Sede* escono dalle stesse celle dell'elenco.
     *
     * @return list<string>
     */
    public static function stockHistoryColumns(): array
    {
        return StockMovementResource::historyColumns();
    }

    /**
     * I movimenti di questa opzione, cinque per volta (P119, P128).
     *
     * Nasce dalle colonne che `StockMovementResource` dichiara per il suo
     * elenco, ristretta a quest'opzione: le etichette e i formattatori si
     * scrivono una volta sola, di là. Titolo e filtri restano spenti — il
     * riquadro ha già il suo titolo, e le caselle dei filtri starebbero
     * dentro la form della scheda, dove un invio salverebbe invece di
     * filtrare.
     *
     * Senza database la tabella non nasce: resta la frase, e la scheda si
     * legge lo stesso.
     */
    protected static function stockHistoryTable(): string
    {
        $productId = static::currentId() ?? 0;
        $vuoto = '<p class="text-muted mb-0">Nessun movimento: la giacenza di questa opzione non è mai cambiata.</p>';

        if ($productId <= 0) {
            return $vuoto;
        }

        try {
            $tabella = StockMovementResource::backendTable(static::stockHistoryColumns());
            $tabella->title(false);
            $tabella->titleNResult(false);
            $tabella->filterSearch(false);
            $tabella->filterDate(false);
            $tabella->filterLimit(false);
            $tabella->filterCustom(false);
            $tabella->length(5);
            $tabella->query('`product_id` = ' . $productId . " AND `deleted` = 'false'");
            $tabella->queryOrder('id', 'DESC');

            $html = (string) $tabella->generate(false);
        } catch (Throwable) {
            $html = $vuoto;
        }

        return $html;
    }

    /**
     * La finestra della rettifica (P119).
     *
     * Posta dove postava la pagina — la stessa rotta, in POST — e con `back`
     * torna qui da sola. Un `<form>` dentro un altro `<form>` il browser lo
     * butta via: la finestra nasce dentro un `<template>`, e lo script la
     * porta in fondo alla pagina, fuori dalla scheda.
     */
    public static function stockAdjustModal(int $productId, string $back): string
    {
        if ($productId <= 0) {
            return '';
        }

        // I campi sono quelli della pagina della rettifica: una definizione
        // sola, due posti in cui si compila.
        $finestra = Modal::make('Rettifica la giacenza')
            ->id(static::ADJUST_MODAL)
            ->form(StockAdjustmentResource::submitUrl(), hidden: ['product_id' => $productId, 'back' => $back])
            ->columns(12)
            ->components([
                StockAdjustmentResource::getInput('mode')->columnSpan(4),
                StockAdjustmentResource::getInput('quantity')->columnSpan(4),
                StockAdjustmentResource::getInput('reason')->columnSpan(4),
                StockAdjustmentResource::getInput('note')->attribute('rows="2"')->columnSpan(12),
            ])
            ->cancel('Annulla')
            ->submit('Salva la rettifica')
            ->render('bootstrap');

        return '<template data-wi-stock-adjust="' . static::ADJUST_MODAL . '">' . $finestra . '</template>'
            . static::stockAdjustScript();
    }

    /**
     * Porta la finestra in fondo alla pagina: un form dentro un form non
     * esiste.
     *
     * Il backend tiene spento «Salva» finché un campo obbligatorio è vuoto, e
     * riaccende ascoltando i campi con «data-wi-check»: quegli ascolti li
     * attacca una volta sola al caricamento, quando la finestra non è ancora
     * arrivata. Qui li rimette la finestra stessa, e cerca la spunta del
     * backend quando l'evento arriva, non quando si attacca: al momento del
     * trasloco quella funzione può non esserci ancora.
     */
    protected static function stockAdjustScript(): string
    {
        $id = json_encode(static::ADJUST_MODAL);

        return <<<HTML
<script>
    (function () {
        var ID = {$id};

        function porta() {
            var modello = document.querySelector('template[data-wi-stock-adjust="' + ID + '"]');

            if (!modello || document.getElementById(ID)) {
                return;
            }

            document.body.appendChild(modello.content.cloneNode(true));
            spunta(document.getElementById(ID));
        }

        function spunta(finestra) {
            if (!finestra) {
                return;
            }

            // Il backend ascolta i tasti; «input» prende anche quello che
            // arriva senza tastiera — incollato, dettato, riempito dal
            // browser.
            ['input', 'keyup', 'change', 'focusin', 'focusout'].forEach(function (evento) {
                finestra.addEventListener(evento, function (fatto) {
                    if (typeof check === 'function' && fatto.target.dataset.wiCheck === 'true') {
                        check();
                    }
                }, true);
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', porta);
        } else {
            porta();
        }
    })();
</script>
HTML;
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')
                ->text()
                ->link('edit')
                // Le righe nate prima che il nome esistesse mostrano lo SKU:
                // meglio un codice di una casella vuota.
                ->formatter(static fn(array $row): string => static::escape(
                    trim((string) ($row['name'] ?? '')) !== ''
                        ? (string) $row['name']
                        : (string) ($row['sku'] ?? '')
                )),
            TableColumn::key('product_model_id')
                ->text()
                ->formatter(static fn(array $row): string => static::escape(
                    static::modelNames()[(int) ($row['product_model_id'] ?? 0)] ?? '—'
                )),
            TableColumn::key('sku')->text(),
            TableColumn::key('price')->price()->size('medium'),
            TableColumn::key('available')
                ->text()
                ->size('little')
                ->formatter(static fn(array $row): string => static::optionAvailableCell($row)),
            // Le stesse due parole dell'articolo: un'opzione o sta nel
            // negozio o è ancora in lavorazione, e chi legge la scheda non
            // deve tradurre «attiva» in «pubblicata». La pillola si clicca
            // (P124): togliere una taglia dal sito è un gesto solo,
            // dall'elenco come dalla scheda dell'articolo.
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Pubblicato', 'bi bi-eye', 'success', 'Metti in bozza')
                ->badgeOff('Bozza', 'bi bi-eye-slash', 'secondary', 'Pubblica')
                ->badgeClickable()
                ->size('little'),
            TableColumn::key('actions')->button()->actions(['edit']),
        ];
    }

    /**
     * Il badge della disponibilità dell'opzione, come nell'elenco degli
     * articoli. L'opzione di un multiprodotto non ha giacenza sua: conta le
     * confezioni che i pezzi permettono.
     */
    public static function optionAvailableCell(array $row): string
    {
        $productId = (int) ($row['id'] ?? 0);

        try {
            $stock = Bundles::isBundle($productId)
                ? StockBadge::forBundle((int) ($row['product_model_id'] ?? 0))
                : (StockBadge::forProducts([$productId])[$productId] ?? null);
        } catch (Throwable) {
            $stock = null;
        }

        $stock ??= ['available' => 0.0, 'low' => false];

        return StockBadge::html($stock['available'], $stock['low'], $stock['unlimited'] ?? false);
    }

    /**
     * L'elenco delle opzioni è quello di serie: i bottoni «Aggiungi» e i
     * filtri dell'elenco degli articoli non sono suoi — un'opzione nasce
     * nella scheda del suo prodotto, e le sue colonne non sono quelle
     * dell'articolo.
     */
    public static function tableLayoutSchema(): TableLayoutSchema
    {
        return static::tableLayout();
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
        $base = '/backend/' . static::path();

        if (function_exists('__r')) {
            try {
                $named = (string) __r('backend.resource.' . static::slug() . '.list');
                $base = $named !== '' ? $named : $base;
            } catch (\Throwable) {
                // Rotta non registrata: resta il percorso.
            }
        }

        return $base . '?prodotto=' . $modelId;
    }

    /**
     * L'indirizzo della scheda di un'opzione.
     *
     * La strada è la stessa di `listUrlFor()`: la rotta con il nome quando il
     * framework è avviato, il percorso quando non lo è.
     */
    public static function editUrlFor(int $productId): string
    {
        $fallback = '/backend/' . static::path() . '/' . $productId . '/edit/';

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.' . static::slug() . '.edit', ['id' => $productId]);
        } catch (\Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
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

        // Fermare un'opzione che è dentro un multiprodotto lo lascerebbe senza
        // un pezzo: lo si dice prima di scrivere qualunque cosa. Vale per la
        // scheda come per la pillola dell'elenco.
        if ($id > 0 && ($values['active'] ?? null) === 'false' && ($oldValues['active'] ?? 'true') !== 'false') {
            Bundles::assertNotUsed($oldValues);
        }

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

        // La scorta minima e le righe per sede non sono colonne dell'opzione:
        // le scrive `afterUpdate()`, e qui si controllano soltanto, prima che
        // si scriva qualcosa. Senza la funzionalità la casella non si guarda:
        // un form aperto prima di bloccarla non deve azzerare la soglia.
        $sedi = static::hasManyLocations();

        if (array_key_exists('min_stock', $values)) {
            if (!$sedi && Gestionale::feature('low_stock_alerts')) {
                static::minStockValue($values['min_stock']);
            }

            unset($values['min_stock']);
        }

        unset($values['locations']);

        if ($sedi) {
            // Anche la giacenza sotto zero si ferma qui (P59): `afterUpdate()`
            // gira fuori da qualunque try, e lì un rifiuto sarebbe una pagina
            // di guasto su un'opzione scritta a metà.
            LocationStock::assertNotNegative(
                $id,
                LocationRows::normalize(Repeater::rowsFromRequest('locations', $_POST), Locations::shown())
            );
        }

        // I fornitori non sono colonne dell'opzione: li scrive
        // `afterUpdate()`, e qui si controllano su quello che è arrivato,
        // prima che si scriva qualcosa. I campi della finestra arrivano
        // anche loro, e non servono: conta il JSON che la finestra ha scritto.
        $postate = static::postedSuppliers((array) $_POST, '', $id);

        if ($postate !== null) {
            $choices = static::supplierChoices($id);
            ProductSuppliers::assertValid($postate[1], array_keys($choices), $choices);
        }

        unset(
            $values['suppliers'],
            $values['suppliers_button'],
            $values['supplier_sku'],
            $values['supplier_cost'],
            $values['wi_product_supplier'],
            $values['wi_product_supplier_remove']
        );

        return $values;
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        $attributes = Attributes::byLevel(static::attributes(), 'product');
        $input = [];

        foreach ($attributes as $attribute) {
            $attributeId = (int) $attribute['id'];
            $input[$attributeId] = $_POST['attribute_' . $attributeId] ?? null;
        }

        ProductAttributes::save('product', (int) $id, $attributes, $input);

        // Le soglie, e con più sedi la giacenza, si scrivono adesso che
        // l'opzione è salvata: le righe le ha già controllate
        // `mutateRequestValues()`. Con una sede sola la casella parla della
        // sede principale; se non è arrivata, la soglia resta com'è.
        if (static::hasManyLocations()) {
            $rows = LocationRows::normalize(Repeater::rowsFromRequest('locations', $_POST), Locations::shown());
            LocationStock::apply((int) $id, static::keepThresholds((int) $id, $rows), false);
        } elseif (Gestionale::feature('low_stock_alerts') && array_key_exists('min_stock', $_POST)) {
            Thresholds::save((int) $id, [Locations::mainId() => static::minStockValue($_POST['min_stock'])]);
        }

        // La soglia può essere appena cambiata senza nessun movimento:
        // l'avviso si apre o si chiude adesso, non alla prossima vendita.
        if (Gestionale::feature('low_stock_alerts')) {
            Alerts::refresh((int) $id);
        }

        // I fornitori arrivati li ha già controllati `mutateRequestValues()`.
        // Se non è arrivato niente i legami restano quelli che sono.
        $postate = static::postedSuppliers((array) $_POST, '', (int) $id);

        if ($postate !== null) {
            static::writeSuppliers(
                (int) $id,
                $postate,
                static::supplierLinks((int) $id)[(int) $id] ?? [],
                static::soleSupplierId((int) $id)
            );

            unset(
                self::$optionSupplierChoices[(int) $id],
                self::$optionSupplierLinks[(int) $id],
                self::$optionInactiveSuppliers[(int) $id]
            );
        }
    }

    /**
     * Ad avvisi spenti la colonna della scorta minima non c'è: le soglie
     * salvate restano come sono, anche quelle delle sedi che nelle righe non
     * ci sono più, e le righe muovono solo la giacenza.
     *
     * @param list<array{location_id: int, stock: ?float, min_stock: float}> $rows righe di `LocationRows::normalize()`
     * @return list<array{location_id: int, stock: ?float, min_stock: float}>
     */
    protected static function keepThresholds(int $productId, array $rows): array
    {
        if (Gestionale::feature('low_stock_alerts')) {
            return $rows;
        }

        $stored = Thresholds::forProduct($productId);

        foreach ($rows as $index => $row) {
            $locationId = (int) $row['location_id'];
            $rows[$index]['min_stock'] = (float) ($stored[$locationId] ?? 0);
            unset($stored[$locationId]);
        }

        foreach ($stored as $locationId => $quantity) {
            $rows[] = ['location_id' => (int) $locationId, 'stock' => null, 'min_stock' => (float) $quantity];
        }

        return $rows;
    }

    /**
     * Riempie il form con gli attributi dell'opzione, i suoi fornitori, la
     * scorta minima e — con più sedi — le righe della giacenza.
     */
    public static function mutateFormValues(
        array $values,
        string $mode,
        string $context = 'backend'
    ): array {
        $productId = (int) ($values['id'] ?? 0);

        // I fornitori non sono colonne dell'opzione: si leggono adesso, e
        // quello scritto prima di un salvataggio rifiutato resta. L'id è
        // quello che ha scelto i campi della scheda.
        $aperta = $productId > 0 ? $productId : (static::currentId() ?? 0);
        $fornitori = static::supplierMode($aperta);

        if ($fornitori === 'flat' || $fornitori === 'modal') {
            $values = static::supplierFields(
                $values,
                '',
                $fornitori,
                static::supplierLinks($aperta)[$aperta] ?? [],
                static::supplierChoices($aperta),
                static::soleSupplierId($aperta)
            );
        }

        if ($mode !== 'edit' || $productId === 0) {
            return $values;
        }

        $links = ProductAttributes::read('product', $productId);

        foreach (Attributes::byLevel(static::attributes(), 'product') as $attribute) {
            $attributeId = (int) $attribute['id'];
            $values['attribute_' . $attributeId] = static::attributeValue($attribute, $links[$attributeId] ?? null);
        }

        // Soglie e righe per sede non sono colonne dell'opzione. Dopo un
        // salvataggio rifiutato le righe le ha già rilette il core dalla
        // richiesta, e la casella arriva com'era scritta; altrimenti si
        // leggono adesso, e i numeri vanno alle caselle grezzi, con il punto.
        if (static::hasManyLocations()) {
            if (!is_array($values['locations'] ?? null)) {
                $values['locations'] = LocationRows::compose(
                    Locations::shown(),
                    Levels::byLocation($productId),
                    Thresholds::forProduct($productId)
                );
            }

            foreach ($values['locations'] as $index => $row) {
                foreach (['stock', 'min_stock'] as $key) {
                    if (is_array($row) && is_numeric($row[$key] ?? null)) {
                        $values['locations'][$index][$key] = static::rawNumber((float) $row[$key]);
                    }
                }
            }
        } elseif (Gestionale::feature('low_stock_alerts') && !array_key_exists('min_stock', $values)) {
            $values['min_stock'] = static::rawNumber((float) (Thresholds::forProduct($productId)[Locations::mainId()] ?? 0));
        }

        return $values;
    }

    /**
     * Una versione con movimenti non si elimina, come il suo articolo.
     *
     * Quella del genitore legge l'id come id del modello: qui è già il
     * prodotto. Nessuna pagina elimina una versione, ma `api/backend/delete`
     * del core passa di qui.
     */
    public static function assertDeletable(int|string $id): void
    {
        $product = Product::findById((int) $id);

        if (is_array($product) && isset($product['id'])) {
            Bundles::assertNotUsed($product);
        }

        if (StockHistory::hasMovements((int) $id)) {
            throw UserError::refusal('product.has_movements');
        }
    }

    /** Eliminare un'opzione porta via i suoi attributi, le sue soglie e i suoi fornitori. */
    public static function deleteRecord(int|string $id): object
    {
        static::assertDeletable($id);

        foreach (ProductAttributes::read('product', (int) $id) as $link) {
            ProductAttributes::modelClass('product')::delete((int) $link['id']);
        }

        StockHistory::dropAlerts([(int) $id]);
        // Anche ad avvisi spenti: le soglie puntano all'opzione con una chiave esterna.
        Thresholds::dropFor([(int) $id]);
        // Anche ad acquisti spenti: la chiave esterna fermerebbe l'eliminazione.
        ProductSuppliers::dropFor([(int) $id]);

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

        return trim($model . ($variant === '' || $variant === $model ? '' : ' · ' . $variant)) ?: 'Prodotto';
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
    public static function afterStore(object $result, array $values = []): void {}

    protected static function attributeField(array $attribute): Input
    {
        return parent::attributeField($attribute);
    }
}
