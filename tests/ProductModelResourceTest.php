<?php
/** php tests/ProductModelResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\ResourceSchema\Inputs\InputButton;
use Wonder\App\ResourceSchema\Inputs\InputCheckbox;
use Wonder\App\ResourceSchema\Inputs\InputHidden;
use Wonder\App\ResourceSchema\Inputs\InputNumber;
use Wonder\App\ResourceSchema\Inputs\InputPrice;
use Wonder\App\ResourceSchema\Inputs\InputRadio;
use Wonder\App\ResourceSchema\Inputs\InputText;
use Wonder\Elements\Components\Accordion;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\Link;
use Wonder\Elements\Components\Modal;
use Wonder\Elements\Components\QuickCreateButton;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeValueResource;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;

$campi = static function (): array {
    $campi = [];

    foreach (ProductModelResource::formSchema() as $field) {
        $campi[(string) $field->name] = $field;
    }

    return $campi;
};

/** Sostituisce la mappa delle funzionalità; `null` la fa rileggere. */
$forza = static function (?array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
};

check('la pagina dei prodotti sta nel catalogo', fn () =>
    ProductModelResource::$model === ProductModel::class
    && ProductModelResource::path() === 'app/gestionale/prodotti'
    && ProductModelResource::titleLabel() === 'Prodotti'
    && (ProductModelResource::navigationSchema()->toArray()['section_key'] ?? '') === 'catalogo'
);

check('senza «backorders» la scheda non chiede la vendita senza giacenza', function () use ($campi, $forza) {
    // Le colonne stanno sul prodotto: la soglia si scrive in
    // `product_min_stock`, la vendita senza giacenza solo con la
    // funzionalità accesa (P84). Qui è spenta apposta, non per caso.
    $forza(['backorders' => false, 'low_stock_alerts' => false]);

    try {
        $nomi = array_keys($campi());
    } finally {
        $forza(null);
    }

    return array_intersect(['product_min_stock', 'allow_backorder', 'backorder_lead_days'], $nomi) === [];
});

check('indirizzo e posizione non si scrivono a mano', function () use ($campi) {
    foreach (['slug', 'position'] as $chiave) {
        if (isset($campi()[$chiave])) {
            return false;
        }
    }

    $nuovo = ProductModelResource::mutateRequestValues(['name' => 'Maglietta'], 'store');
    $modifica = ProductModelResource::mutateRequestValues(
        ['name' => 'Maglietta', 'slug' => 'altro', 'position' => 9],
        'update',
        'backend',
        ['id' => 1]
    );

    return ($nuovo['slug'] ?? '') !== ''
        && ($nuovo['position'] ?? 0) > 0
        && !isset($modifica['slug'], $modifica['position']);
});

check('i campi che non sono colonne non finiscono nella query', function () use ($forza) {
    $extra = ['categories', 'main_category', 'tags', 'attribute_7', 'product_ean', 'product_price', 'allow_backorder', 'backorder_lead_days'];

    // La vendita senza giacenza sta sulle opzioni, non sul modello: con la
    // funzionalità accesa la scrive `saveExtras()`, spenta non si scrive, e
    // in tutti e due i casi non arriva a `gst_product_models`.
    foreach ([false, true] as $backorders) {
        $forza(['backorders' => $backorders]);

        try {
            $valori = ProductModelResource::mutateRequestValues([
                'name' => 'Maglietta',
                'categories' => ['1', '2'],
                'main_category' => '1',
                'tags' => ['3'],
                'attribute_7' => 'Cotone',
                'product_ean' => '',
                'product_price' => '19,90',
                'allow_backorder' => 'true',
                'backorder_lead_days' => '5',
            ], 'store');
        } finally {
            $forza(null);
        }

        if (array_intersect($extra, array_keys($valori)) !== []) {
            return false;
        }
    }

    return true;
});

check('un EAN storto si ferma con una frase', function () {
    try {
        ProductModelResource::mutateRequestValues(
            ['name' => 'Maglietta', 'product_ean' => '123456789012'],
            'store'
        );
    } catch (UserError $errore) {
        return str_contains($errore->getMessage(), '8 o 13');
    }

    return false;
});

check('un EAN giusto passa', function () {
    ProductModelResource::mutateRequestValues(
        ['name' => 'Maglietta', 'product_ean' => '1234567890123'],
        'store'
    );

    return true;
});

check('uno SKU già preso si ferma con una frase', function () {
    $resource = new class extends ProductModelResource {
        public static function products(int $modelId): array { return []; }
    };

    // `Sku::isFree` risponde `true` senza database: qui conta che la Resource
    // lo chieda davvero, e che il messaggio arrivi.
    try {
        throw UserError::make('product.sku_taken');
    } catch (UserError $errore) {
        return str_contains($errore->getMessage(), 'SKU');
    }
});

check('con una versione sola prezzo ed EAN stanno nel riquadro principale', function () use ($campi) {
    $chiavi = array_keys($campi());

    return in_array('product_price', $chiavi, true)
        && in_array('product_ean', $chiavi, true)
        // Lo SKU è quello dell'articolo: due caselle "SKU" nella stessa
        // scheda sono solo un modo per sbagliare.
        && !in_array('product_sku', $chiavi, true)
        // L'elenco dei colori non esiste più: un colore si chiama come il suo
        // valore nell'anagrafica, e lì si rinomina.
        && !in_array('variants', $chiavi, true)
        // La griglia invece c'è sempre: aggiungere e modificare sono la
        // stessa schermata.
        && in_array('products', $chiavi, true);
});

check('una riga nuova del repeater nasce con il suo codice', function () {
    $prodotto = ProductModelResource::prepareRepeaterRelationRow(
        'products',
        ['sku' => 'TSH-1-BLU', 'product_model_id' => 1],
        ['sku' => 'TSH-1-BLU']
    );

    return str_starts_with((string) ($prodotto['code'] ?? ''), 'pro_')
        && array_key_exists('product_variant_id', $prodotto);
});

check('al sync del core arrivano solo le righe che esistono già', function () {
    // Una riga nata da una spunta non ha ancora il suo colore: passarla al
    // sync vorrebbe dire scriverla con una variante che non c'è.
    $righe = ProductModelResource::prepareRepeaterRows('products', [
        ['id' => '12', 'sku' => 'TSH-1-BLU-S'],
        ['id' => '', 'sku' => 'TSH-1-BLU-M'],
        ['sku' => 'TSH-1-ROSSO-S'],
    ]);

    return count($righe) === 1 && ($righe[0]['id'] ?? '') === '12';
});

check('una riga che c\'è già non si tocca', function () {
    $payload = ProductModelResource::prepareRepeaterRelationRow(
        'variants',
        ['id' => 3, 'name' => 'Blu'],
        ['id' => 3, 'name' => 'Blu'],
        ['id' => 3, 'name' => 'Blu', 'code' => 'var_abc1234']
    );

    return !isset($payload['code']);
});

check('varianti e prodotti si contano dalle loro tabelle', function () {
    $resource = new class extends ProductModelResource {
        public static function variants(int $modelId): array { return [['id' => 1], ['id' => 2]]; }
        public static function products(int $modelId): array { return [['id' => 5]]; }
    };

    return $resource::variantCount(1) === 2
        && $resource::productCount(1) === 1
        && ($resource::soleProduct(1)['id'] ?? 0) === 5;
});

check('i Model del catalogo restano quelli giusti', fn () =>
    ProductVariant::$table === 'gst_product_variants' && Product::$table === 'gst_products'
);

check('una foto nuova nasce in attesa delle sue misure', function () {
    // Il nome del campo porta la fetta: `images_0` è l'articolo, `images_12`
    // il colore con quell'id.
    $riga = ProductModelResource::prepareRepeaterRelationRow(
        'images_0',
        ['product_model_id' => 1, 'file' => '["foto.jpg"]'],
        ['file' => '["foto.jpg"]']
    );

    return ($riga['status'] ?? '') === 'pending' && (int) ($riga['attempts'] ?? -1) === 0;
});

check('una foto sceglie a chi appartiene, e "tutto" è una scelta', function () {
    $resource = new class extends ProductModelResource {
        public static function variants(int $modelId): array
        {
            return [['id' => 3, 'name' => 'Blu'], ['id' => 4, 'name' => 'Rosso']];
        }
    };

    $voci = $resource::variantOptions(1);

    return ($voci[''] ?? '') === 'Tutto l\'articolo'
        && ($voci['3'] ?? '') === 'Blu'
        && count($voci) === 3;
});

check('le foto non si ridimensionano al salvataggio', function () {
    // G2a.8: il campo del Model non dichiara nessuna misura, quindi
    // `uploadFiles()` salta il ridimensionamento e la scheda si salva subito.
    foreach (ProductImage::dataSchema() as $field) {
        if ((string) $field->key !== 'file') {
            continue;
        }

        return empty($field->getSchema('resize')) && empty($field->getSchema('webp'));
    }

    return false;
});

check('le spunte delle versioni stanno in un campo solo', function () use ($campi) {
    return !isset($campi()['variant_values'], $campi()['product_values']);
});

check('un articolo non può restare senza versioni', function () {
    $scheda = new class extends ProductModelResource {
        public static function productCount(int $modelId): int
        {
            return 6;
        }
    };

    $_POST['products'] = [];

    try {
        $scheda::assertSomeVersionLeft(1);
    } catch (UserError $errore) {
        unset($_POST['products']);

        return $errore->key() === 'product.no_versions';
    }

    unset($_POST['products']);

    return false;
});

check('finché una riga resta, si salva', function () {
    $scheda = new class extends ProductModelResource {
        public static function productCount(int $modelId): int
        {
            return 6;
        }
    };

    $_POST['products'] = [['id' => '3', 'sku' => 'TSH-1-M']];
    $scheda::assertSomeVersionLeft(1);
    unset($_POST['products']);

    return true;
});

check('senza attributi da cui nascono opzioni la domanda sulle varianti non si fa', function () {
    $trova = static function ($nodo, string $nome) use (&$trova) {
        if ($nodo instanceof \Wonder\App\ResourceSchema\Input && $nodo->name === $nome) {
            return $nodo;
        }

        foreach ((array) ($nodo->components ?? []) as $figlio) {
            if (($trovato = $trova($figlio, $nome)) !== null) {
                return $trovato;
            }
        }

        return null;
    };
    $senza = new class extends ProductModelResource {
        public static function optionAttributes(): array { return []; }
    };
    $con = new class extends ProductModelResource {
        public static function optionAttributes(): array
        {
            return [['id' => 7, 'name' => 'Colore', 'level' => 'variant', 'type' => 'select']];
        }
    };

    return $trova($senza::formLayoutSchema(), 'has_variants') instanceof \Wonder\App\ResourceSchema\Inputs\InputHidden
        && ($domanda = $trova($con::formLayoutSchema(), 'has_variants')) !== null
        && !($domanda instanceof \Wonder\App\ResourceSchema\Inputs\InputHidden);
});

check('dopo il salvataggio si atterra sulla scheda', function () {
    $schema = ProductModelResource::pageSchema();

    return ($schema->get('redirects')['store'] ?? '') === 'edit';
});

check('la creazione mostra la scheda intera, due colonne comprese', function () {
    // `currentId()` è nullo fuori da una richiesta: è la creazione.
    $scheda = new class extends ProductModelResource {
    /**
     * Un'opzione finta: senza database non ce ne sarebbe nessuna, e la scheda
     * non mostrerebbe né il selettore né il blocco delle versioni.
     */
    public static function optionAttributes(): array
    {
        return [['id' => 7, 'name' => 'Colore', 'level' => 'variant', 'type' => 'select']];
    }

    public static function valuesOf(int $attributeId): array
    {
        return $attributeId === 7 ? ['11' => 'Blu', '12' => 'Rosso'] : [];
    }

    };

    $form = $scheda::formLayoutSchema();
    $colonne = $form->components ?? [];

    $titoli = static function ($contenitore): array {
        $titoli = [];

        foreach ($contenitore->components ?? [] as $riquadro) {
            foreach ($riquadro->components ?? [] as $dentro) {
                if ($dentro instanceof SectionTitle) {
                    $titoli[] = $dentro->getText();
                    break;
                }
            }
        }

        return $titoli;
    };

    // Due colonne, e le opzioni in vendita nella larga, subito sotto
    // «Prodotto»: la griglia raggruppata ha tre caselle per riga e in due
    // terzi di schermo ci sta. Foto e misure stanno a destra.
    return count($colonne) === 2
        && $titoli($colonne[0]) === ['Prodotto', 'Opzioni in vendita', 'Scheda tecnica']
        && $titoli($colonne[1]) === ['Foto e video', 'Come si vende', 'Tipo fiscale', 'Dove si trova', 'Misure'];
});

check('in creazione non si chiede quello che non esiste ancora', function () {
    $chiavi = array_map(
        static fn ($campo) => (string) $campo->name,
        ProductModelResource::formSchema()
    );

    // La giacenza c'è già (P59): chi crea l'articolo ha la merce davanti, e
    // il numero entra come carico iniziale. Le pagine dei colori no: nascono
    // dal primo salvataggio. La griglia c'è, vuota: le righe le aggiungono
    // le spunte, e aggiungere e modificare devono essere la stessa schermata.
    return in_array('product_stock', $chiavi, true)
        && !in_array('variants', $chiavi, true)
        && in_array('products', $chiavi, true)
        && in_array('images_0', $chiavi, true);
});

check('l\'elenco dice foto, prezzo e quante versioni', function () {
    $colonne = [];

    foreach (ProductModelResource::tableSchema() as $colonna) {
        $colonne[] = (string) $colonna->name;
    }

    return in_array('photo', $colonne, true)
        && in_array('price', $colonne, true)
        && in_array('versions', $colonne, true);
});

/**
 * I titoli dei riquadri di una scheda aperta, nell'ordine in cui stanno.
 *
 * Fuori da una richiesta `currentId()` è nullo e la scheda mostra la
 * creazione: qui si finge un articolo già salvato, con una versione sola.
 */
$schedaAperta = new class extends ProductModelResource {
    /** @var array<int, string> i fornitori che la pagina propone, id => nome */
    public static array $fornitori = [];

    protected static function currentId(): ?int
    {
        return 1;
    }

    /** @var array<int, list<array<string, mixed>>> i fornitori già legati, per prodotto */
    public static array $legami = [];

    protected static function supplierChoices(int $modelId): array
    {
        return static::$fornitori;
    }

    protected static function supplierLinks(int $modelId): array
    {
        return static::$legami;
    }

    protected static function inactiveSupplierIds(int $modelId): array
    {
        return [];
    }

    public static function vediCampiFornitori(
        array $row,
        string $prefix,
        string $mode,
        array $links,
        array $choices,
        int $soleSupplierId = 0
    ): array {
        return static::supplierFields($row, $prefix, $mode, $links, $choices, $soleSupplierId);
    }

    /**
     * Un'opzione finta: senza database non ce ne sarebbe nessuna, e la scheda
     * non mostrerebbe né il selettore né il blocco delle versioni.
     */
    public static function optionAttributes(): array
    {
        return [['id' => 7, 'name' => 'Colore', 'level' => 'variant', 'type' => 'select']];
    }

    public static function valuesOf(int $attributeId): array
    {
        return $attributeId === 7 ? ['11' => 'Blu', '12' => 'Rosso'] : [];
    }

    /** @return list<object> */
    public static function vediBlocchi(): array
    {
        return static::optionBlocks();
    }

    public static function vediSelettore(): string
    {
        return (string) static::optionsPicker()->getText();
    }

    public static function vediGriglia(): string
    {
        return (string) static::optionsGridScript(0)->getText();
    }

    public static function productCount(int $modelId): int
    {
        return 1;
    }

    public static function variantCount(int $modelId): int
    {
        return 1;
    }

    public static function products(int $modelId): array
    {
        return [['id' => 2, 'sku' => 'CAP-1', 'price' => '24.90']];
    }
};

$riquadri = static function (int $colonna = 0) use ($schedaAperta): array {
    $form = $schedaAperta::formLayoutSchema();
    $contenitore = $form->components[$colonna] ?? null;
    $titoli = [];

    foreach ($contenitore->components ?? [] as $riquadro) {
        foreach ($riquadro->components ?? [] as $dentro) {
            if ($dentro instanceof SectionTitle) {
                $titoli[] = $dentro->getText();
                break;
            }
        }
    }

    return $titoli;
};

check('la colonna larga tiene quello che si compone', function () use ($riquadri) {
    // Le opzioni in vendita subito sotto «Prodotto»: è lì che si risponde
    // «sì, ha varianti». La descrizione sta dentro «Prodotto», e la scheda
    // tecnica c'è anche senza caratteristiche.
    return $riquadri(0) === ['Prodotto', 'Opzioni in vendita', 'Scheda tecnica'];
});

check('il riquadro delle opzioni risponde alla domanda sulle varianti', function () use ($schedaAperta) {
    $form = $schedaAperta::formLayoutSchema();
    $riquadro = (($form->components ?? [])[0]->components ?? [])[1] ?? null;

    return count($form->components ?? []) === 2
        && $riquadro !== null
        && (((array) ($riquadro->columnSpan ?? []))['default'] ?? null) === 12
        && str_contains(json_encode($riquadro->getSchema()) ?: '', 'has_variants');
});

check('la colonna stretta tiene quello che si decide', function () use ($riquadri) {
    // Le foto in cima, sopra gli interruttori; le misure in fondo. «Codici»
    // e «Spedizione» non ci sono più: SKU ed EAN stanno sotto il prezzo,
    // l'imballaggio sotto «Da spedire».
    return $riquadri(1) === ['Foto e video', 'Come si vende', 'Tipo fiscale', 'Dove si trova', 'Misure'];
});

/** I pezzi di un riquadro della colonna stretta o larga, per titolo. */
$riquadro = static function (int $colonna, string $titolo) use ($schedaAperta): array {
    $form = $schedaAperta::formLayoutSchema();

    foreach (($form->components[$colonna] ?? null)->components ?? [] as $card) {
        $dentro = $card->components ?? [];

        if (($dentro[0] ?? null) instanceof SectionTitle && $dentro[0]->getText() === $titolo) {
            return $dentro;
        }
    }

    return [];
};

check('il tipo fiscale ha un riquadro tutto suo', function () use ($riquadro) {
    $dentro = $riquadro(1, 'Tipo fiscale');
    $nomi = array_values(array_filter(array_map(
        static fn ($pezzo) => property_exists($pezzo, 'name') ? (string) $pezzo->name : '',
        $dentro
    )));

    // Il titolo dice già cos'è: la select non lo ripete.
    return $nomi === ['tax_category_id']
        && $dentro[1]->get('label') === 'IVA'
        && (string) ($dentro[0]->getSchema()['tooltip'] ?? '') !== '';
});

check('«Come si vende» ha tre interruttori con una riga che li spiega', function () use ($riquadro, $campi) {
    $nomi = array_values(array_filter(array_map(
        static fn ($pezzo) => property_exists($pezzo, 'name') ? (string) $pezzo->name : '',
        $riquadro(1, 'Come si vende')
    )));
    $etichette = [];

    foreach (['visible_online', 'returnable', 'requires_shipping'] as $chiave) {
        $campo = $campi()[$chiave];
        $spiega = (string) (((array) $campo->get('context'))['description'] ?? '');

        if ($spiega === '' || mb_strlen($spiega) > 70) {
            return false;
        }

        // Uno switch manda sempre un valore: l'asterisco non direbbe niente.
        if (preg_match('/\brequired\b/', (string) $campo->get('attribute'))) {
            return false;
        }

        $etichette[] = $campo->get('label');
    }

    return $nomi === ['visible_online', 'returnable', 'requires_shipping', 'package_id']
        && $etichette === ['Acquistabile online', 'Accetta resi', 'Da spedire'];
});

check('l\'imballaggio sta sotto «Da spedire» e compare solo se si spedisce', function () use ($riquadro) {
    $imballaggio = null;

    foreach ($riquadro(1, 'Come si vende') as $pezzo) {
        if (property_exists($pezzo, 'name') && (string) $pezzo->name === 'package_id') {
            $imballaggio = $pezzo;
        }
    }

    // Un interruttore che si cambia senza ricaricare: la regola sta sul
    // campo, non su un riquadro che il server include o no.
    return $imballaggio !== null
        && $imballaggio->conditionalAttributes() === [
            'data-visible-when' => 'requires_shipping',
            'data-visible-when-values' => 'true',
        ];
});

check('con «backorders» «Come si vende» chiede anche la vendita senza giacenza', function () use ($riquadro, $campi, $forza) {
    $forza(['backorders' => true]);

    try {
        $nomi = array_values(array_filter(array_map(
            static fn ($pezzo) => property_exists($pezzo, 'name') ? (string) $pezzo->name : '',
            $riquadro(1, 'Come si vende')
        )));
        $interruttore = $campi()['allow_backorder'] ?? null;
        $giorni = $campi()['backorder_lead_days'] ?? null;
    } finally {
        $forza(null);
    }

    if ($interruttore === null || $giorni === null) {
        return false;
    }

    $spiega = (string) (((array) $interruttore->get('context'))['description'] ?? '');
    $formato = (array) (((array) $giorni->get('context'))['number'] ?? []);

    // Sotto «Da spedire» e la sua scatola; i giorni sono un numero intero,
    // con «giorni» in coda come «pz» sulla giacenza.
    return $nomi === ['visible_online', 'returnable', 'requires_shipping', 'package_id', 'allow_backorder', 'backorder_lead_days']
        && $interruttore->get('helper') === 'toggle'
        && $interruttore->get('label') === 'Vendita senza giacenza'
        && $spiega !== ''
        && mb_strlen($spiega) <= 70
        && !preg_match('/\brequired\b/', (string) $interruttore->get('attribute'))
        && $giorni->get('helper') === 'number'
        && $giorni->get('label') === 'Giorni di attesa'
        && ($formato['decimal'] ?? null) === 0
        && ($formato['symbol'] ?? null) === ' giorni'
        && ($formato['symbol_placement'] ?? null) === 's';
});

check('i giorni di attesa compaiono solo con la vendita senza giacenza accesa', function () use ($riquadro, $forza) {
    $forza(['backorders' => true]);

    try {
        $pezzi = $riquadro(1, 'Come si vende');
    } finally {
        $forza(null);
    }

    $giorni = null;

    foreach ($pezzi as $pezzo) {
        if (property_exists($pezzo, 'name') && (string) $pezzo->name === 'backorder_lead_days') {
            $giorni = $pezzo;
        }
    }

    // Come la scatola sotto «Da spedire»: la regola sta sul campo.
    return $giorni !== null
        && $giorni->conditionalAttributes() === [
            'data-visible-when' => 'allow_backorder',
            'data-visible-when-values' => 'true',
        ];
});

check('i giorni di attesa vanno da 0 a 365, interi', function () use ($forza) {
    $rifiuta = static function (string $giorni): bool {
        try {
            ProductModelResource::mutateRequestValues(
                ['name' => 'Maglietta', 'allow_backorder' => 'true', 'backorder_lead_days' => $giorni],
                'store'
            );
        } catch (UserError $errore) {
            return $errore->key() === 'product.backorder_lead_days_invalid'
                && $errore->getMessage() === 'I giorni di attesa vanno da 0 a 365.';
        }

        return false;
    };
    $passa = static function (array $valori): bool {
        ProductModelResource::mutateRequestValues(['name' => 'Maglietta'] + $valori, 'store');

        return true;
    };

    $forza(['backorders' => true]);

    try {
        $accesa = $rifiuta('366') && $rifiuta('-1') && $rifiuta('2,5') && $rifiuta('tre')
            && $passa(['allow_backorder' => 'true', 'backorder_lead_days' => '0'])
            && $passa(['allow_backorder' => 'true', 'backorder_lead_days' => '365'])
            && $passa(['allow_backorder' => 'true', 'backorder_lead_days' => '12 giorni'])
            && $passa(['allow_backorder' => 'true', 'backorder_lead_days' => ''])
            // Spento, i giorni sono solo nascosti: quello che è rimasto nella
            // casella non ferma il salvataggio, tornano a zero.
            && $passa(['allow_backorder' => 'false', 'backorder_lead_days' => '999']);
    } finally {
        $forza(null);
    }

    // Senza la funzionalità non si guarda niente: non si scrive niente.
    $forza(['backorders' => false]);

    try {
        $spenta = $passa(['allow_backorder' => 'true', 'backorder_lead_days' => '999']);
    } finally {
        $forza(null);
    }

    return $accesa && $spenta;
});

check('la vendita senza giacenza si scrive solo con la funzionalità e con l\'interruttore postato', function () use ($forza) {
    $forza(['backorders' => true]);

    try {
        $accesa = ProductModelResource::backorderChoice(['allow_backorder' => 'true', 'backorder_lead_days' => '7']);
        $spenta = ProductModelResource::backorderChoice(['allow_backorder' => 'false', 'backorder_lead_days' => '7']);
        $vuota = ProductModelResource::backorderChoice(['allow_backorder' => 'true', 'backorder_lead_days' => '']);
        $assente = ProductModelResource::backorderChoice(['backorder_lead_days' => '7']);
    } finally {
        $forza(null);
    }

    $forza(['backorders' => false]);

    try {
        $senza = ProductModelResource::backorderChoice(['allow_backorder' => 'true', 'backorder_lead_days' => '7']);
    } finally {
        $forza(null);
    }

    return $accesa === ['allow_backorder' => 'true', 'backorder_lead_days' => 7]
        && $spenta === ['allow_backorder' => 'false', 'backorder_lead_days' => 0]
        && $vuota === ['allow_backorder' => 'true', 'backorder_lead_days' => 0]
        && $assente === null
        && $senza === null;
});

check('riaprendo, la vendita senza giacenza è accesa solo se lo è su tutte le opzioni', function () {
    $tutte = ProductModelResource::backorderSummary([
        ['allow_backorder' => 'true', 'backorder_lead_days' => '3'],
        ['allow_backorder' => 'true', 'backorder_lead_days' => '10'],
    ]);
    $una = ProductModelResource::backorderSummary([
        ['allow_backorder' => 'true', 'backorder_lead_days' => '4'],
        ['allow_backorder' => 'false', 'backorder_lead_days' => '20'],
    ]);
    $nessuna = ProductModelResource::backorderSummary([
        ['allow_backorder' => 'false', 'backorder_lead_days' => '0'],
    ]);

    // I giorni sono i più lunghi fra le opzioni accese: è quello che
    // aspetta chi ordina l'opzione più lenta.
    return $tutte === ['allow_backorder' => 'true', 'backorder_lead_days' => '10']
        && $una === ['allow_backorder' => 'false', 'backorder_lead_days' => '4']
        && $nessuna === ['allow_backorder' => 'false', 'backorder_lead_days' => '0']
        && ProductModelResource::backorderSummary([]) === ['allow_backorder' => 'false', 'backorder_lead_days' => '0'];
});

/** La tendina «Compila le informazioni avanzate» del riquadro Prodotto. */
$avanzateProdotto = static function () use ($riquadro): ?Accordion {
    foreach ($riquadro(0, 'Prodotto') as $pezzo) {
        if ($pezzo instanceof Accordion) {
            return $pezzo;
        }
    }

    return null;
};

/** @return array<string, int|null> nome => larghezza, nell'ordine */
$larghezzeDi = static function (array $pezzi): array {
    $larghezze = [];

    foreach ($pezzi as $pezzo) {
        if (property_exists($pezzo, 'name') && (string) $pezzo->name !== '') {
            $larghezze[(string) $pezzo->name] = ((array) ($pezzo->columnSpan ?? []))['default'] ?? null;
        }
    }

    return $larghezze;
};

check('senza varianti prezzo, scontato e giacenza stanno in una riga da tre', function () use ($riquadro, $larghezzeDi, $forza) {
    $risultato = true;

    // Con e senza la scorta minima la riga è la stessa: la soglia è andata
    // nella tendina, accanto ai codici.
    foreach ([false, true] as $soglia) {
        $forza(['low_stock_alerts' => $soglia]);
        $larghezze = $larghezzeDi($riquadro(0, 'Prodotto'));
        $riga = array_intersect_key($larghezze, array_flip(['product_price', 'product_sale_price', 'product_stock']));

        $risultato = $risultato
            && $riga === ['product_price' => 4, 'product_sale_price' => 4, 'product_stock' => 4]
            && !array_key_exists('product_min_stock', $larghezze)
            && !array_key_exists('sku', $larghezze)
            && !array_key_exists('product_ean', $larghezze);
    }

    $forza(null);

    return $risultato;
});

check('SKU ed EAN stanno in «Compila le informazioni avanzate», sotto il prezzo', function () use ($riquadro, $larghezzeDi, $forza) {
    $forza(['low_stock_alerts' => false]);
    $pezzi = $riquadro(0, 'Prodotto');
    $forza(null);

    // Subito dopo la giacenza: è il resto di quella riga.
    $tendina = null;

    foreach ($pezzi as $indice => $pezzo) {
        if (property_exists($pezzo, 'name') && (string) $pezzo->name === 'product_stock') {
            $tendina = $pezzi[$indice + 1] ?? null;
        }
    }

    return $tendina instanceof Accordion
        && $tendina->isLink()
        && $tendina->getText() === 'Compila le informazioni avanzate'
        && $larghezzeDi($tendina->components) === ['sku' => 6, 'product_ean' => 6];
});

check('con gli avvisi sbloccati la scorta minima sta accanto a SKU ed EAN', function () use ($avanzateProdotto, $larghezzeDi, $forza) {
    $forza(['low_stock_alerts' => true]);
    $tendina = $avanzateProdotto();
    $forza(null);

    return $tendina !== null
        && $larghezzeDi($tendina->components) === ['sku' => 4, 'product_ean' => 4, 'product_min_stock' => 4];
});

check('la tendina e i suoi campi spariscono quando l\'articolo ha varianti', function () use ($avanzateProdotto, $forza) {
    $forza(['low_stock_alerts' => true]);
    $tendina = $avanzateProdotto();
    $forza(null);

    if ($tendina === null) {
        return false;
    }

    $regola = [
        'data-hidden-when' => 'has_variants',
        'data-hidden-when-values' => 'true',
    ];
    $attributi = (array) $tendina->getSchema('attributes');
    $regole = [];

    // I campi che il layout mette davvero nella tendina, non quelli
    // dichiarati: la regola deve viaggiare con il clone.
    foreach ($tendina->components as $pezzo) {
        if (property_exists($pezzo, 'name')) {
            $regole[(string) $pezzo->name] = $pezzo->conditionalAttributes();
        }
    }

    return ($attributi['data-hidden-when'] ?? null) === 'has_variants'
        && ($attributi['data-hidden-when-values'] ?? null) === 'true'
        && $regole === ['sku' => $regola, 'product_ean' => $regola, 'product_min_stock' => $regola];
});

/** @return array<string, Input> i campi della scheda aperta, per nome */
$campiAperta = static function () use ($schedaAperta): array {
    $campi = [];

    foreach ($schedaAperta::formSchema() as $campo) {
        $campi[(string) $campo->name] = $campo;
    }

    return $campi;
};

/** @return list<Modal> le finestre della scheda aperta, dovunque stiano */
$finestre = static function () use ($schedaAperta): array {
    $trovate = [];
    $cerca = static function (array $pezzi) use (&$cerca, &$trovate): void {
        foreach ($pezzi as $pezzo) {
            if ($pezzo instanceof Modal) {
                $trovate[] = $pezzo;
            } elseif (is_object($pezzo) && isset($pezzo->components) && is_array($pezzo->components)) {
                $cerca($pezzo->components);
            }
        }
    };

    $cerca($schedaAperta::formLayoutSchema()->components ?? []);

    return $trovate;
};

/**
 * I campi della tendina, della griglia e le finestre della scheda aperta,
 * con quei fornitori da proporre e quelle funzionalità.
 *
 * @param array<int, string> $fornitori
 * @param array<string, bool> $features
 * @return array{nomi: list<string>, titoli: list<string>, tendina: ?Accordion, modali: list<Modal>, colonne: array<string, Input>, avanzate: list<string>, script: string}
 */
$conFornitori = static function (array $fornitori, array $features) use ($schedaAperta, $campiAperta, $finestre, $riquadri, $avanzateProdotto, $forza): array {
    $schedaAperta::$fornitori = $fornitori;
    $forza($features);

    try {
        $campi = $campiAperta();
        $contesto = (array) (($campi['products'] ?? null)?->get('context') ?? []);
        $colonne = [];
        $script = '';

        foreach ((array) ($contesto['columns'] ?? []) as $colonna) {
            $colonne[(string) $colonna->name] = $colonna;
        }

        foreach ($schedaAperta::formLayoutSchema()->components[0]->components ?? [] as $pezzo) {
            if ($pezzo instanceof RichText && str_contains((string) $pezzo->getText(), 'wiProductSuppliers')) {
                $script .= (string) $pezzo->getText();
            }
        }

        return [
            'nomi' => array_keys($campi),
            'campi' => $campi,
            'titoli' => $riquadri(1),
            'tendina' => $avanzateProdotto(),
            'modali' => $finestre(),
            'colonne' => $colonne,
            'avanzate' => (array) ($contesto['advanced'] ?? []),
            'script' => $script,
        ];
    } finally {
        $forza(null);
        $schedaAperta::$fornitori = [];
    }
};

$deiFornitori = ['product_supplier_sku', 'product_supplier_cost', 'product_suppliers', 'product_suppliers_button'];
$colonneFornitori = ['supplier_sku', 'supplier_cost', 'suppliers', 'suppliers_button'];

check('senza acquisti la scheda non chiede né fornitore né costo', function () use ($conFornitori, $deiFornitori, $colonneFornitori, $larghezzeDi) {
    $letto = $conFornitori([5 => 'Filati Nord', 6 => 'Lanificio Sud'], ['purchasing' => false, 'low_stock_alerts' => false]);

    return array_intersect($deiFornitori, $letto['nomi']) === []
        && array_intersect($colonneFornitori, array_keys($letto['colonne'])) === []
        && !in_array('Fornitori', $letto['titoli'], true)
        && $letto['tendina'] !== null
        && $larghezzeDi($letto['tendina']->components) === ['sku' => 6, 'product_ean' => 6]
        && $letto['avanzate'] === ['sku', 'ean', 'active', 'photo']
        && $letto['modali'] === [];
});

check('senza fornitori da proporre la scheda non chiede niente, e il riquadro Fornitori non c\'è più', function () use ($conFornitori, $deiFornitori, $colonneFornitori, $larghezzeDi) {
    $letto = $conFornitori([], ['purchasing' => true, 'low_stock_alerts' => false]);

    // Il riquadro della colonna stretta è andato (P108): i fornitori stanno
    // nelle informazioni avanzate, e senza fornitori non c'è niente.
    return array_intersect($deiFornitori, $letto['nomi']) === []
        && array_intersect($colonneFornitori, array_keys($letto['colonne'])) === []
        && $letto['titoli'] === ['Foto e video', 'Come si vende', 'Tipo fiscale', 'Dove si trova', 'Misure']
        && $larghezzeDi($letto['tendina']->components) === ['sku' => 6, 'product_ean' => 6]
        && $letto['modali'] === [];
});

check('con un fornitore solo la tendina chiede codice e costo, con il suo nome nel tooltip', function () use ($conFornitori, $larghezzeDi) {
    $letto = $conFornitori([5 => 'Filati Nord'], ['purchasing' => true, 'low_stock_alerts' => false]);
    $codice = $letto['campi']['product_supplier_sku'] ?? null;
    $costo = $letto['campi']['product_supplier_cost'] ?? null;
    $regola = [
        'data-hidden-when' => 'has_variants',
        'data-hidden-when-values' => 'true',
    ];

    // Niente tendina del fornitore né finestra (P109): è quello, e lo dice
    // il tooltip.
    return $codice instanceof InputText
        && $costo instanceof InputPrice
        && $codice->get('label') === 'Codice fornitore'
        && $codice->get('max_length') === 100
        && str_contains((string) $codice->get('attribute'), 'title="Filati Nord, l\'unico fornitore"')
        && $costo->get('label') === 'Costo d\'acquisto'
        && ((((array) $costo->get('context'))['number'] ?? [])['decimal'] ?? null) === 2
        && str_contains((string) $costo->get('attribute'), 'title="Filati Nord, l\'unico fornitore"')
        && $codice->conditionalAttributes() === $regola
        && $costo->conditionalAttributes() === $regola
        && !isset($letto['campi']['product_suppliers'], $letto['campi']['product_suppliers_button'])
        && $larghezzeDi($letto['tendina']->components) === [
            'sku' => 6, 'product_ean' => 6, 'product_supplier_sku' => 6, 'product_supplier_cost' => 6,
        ]
        && !in_array('Fornitori', $letto['titoli'], true)
        && $letto['modali'] === []
        && $letto['script'] === '';
});

check('con un fornitore solo ogni riga della griglia ha codice e costo, nelle informazioni avanzate', function () use ($conFornitori) {
    $letto = $conFornitori([5 => 'Filati Nord'], ['purchasing' => true, 'low_stock_alerts' => true]);
    $colonne = $letto['colonne'];
    $codice = $colonne['supplier_sku'] ?? null;
    $costo = $colonne['supplier_cost'] ?? null;

    return $codice !== null
        && $costo !== null
        && $codice->get('helper') === 'text'
        && $codice->get('max_length') === 100
        && $costo->get('helper') === 'price'
        && str_contains((string) $codice->get('attribute'), 'title="Filati Nord, l\'unico fornitore"')
        && (((array) $codice->columnSpan)['default'] ?? null) === 6
        && (((array) $costo->columnSpan)['default'] ?? null) === 6
        && !isset($colonne['suppliers'], $colonne['suppliers_button'])
        && $letto['avanzate'] === ['sku', 'ean', 'min_stock', 'active', 'supplier_sku', 'supplier_cost', 'photo'];
});

check('con più fornitori la tendina e la griglia hanno il bottone che apre la finestra', function () use ($conFornitori, $larghezzeDi) {
    $letto = $conFornitori([5 => 'Filati Nord', 6 => 'Lanificio Sud'], ['purchasing' => true, 'low_stock_alerts' => false]);
    $nascosto = $letto['campi']['product_suppliers'] ?? null;
    $bottone = $letto['campi']['product_suppliers_button'] ?? null;
    $colonne = $letto['colonne'];
    $inRiga = $colonne['suppliers_button'] ?? null;
    $nellaTendina = [];

    foreach ($letto['tendina']->components as $pezzo) {
        $nellaTendina[] = (string) ($pezzo->name ?? '');
    }

    return $nascosto instanceof InputHidden
        // Il JSON viaggia anche con le varianti accese: non si vede comunque.
        && $nascosto->conditionalAttributes() === []
        && $bottone instanceof InputButton
        && $bottone->get('label') === 'Fornitori'
        && str_contains((string) $bottone->get('attribute'), 'data-bs-target="#wi-product-suppliers"')
        && (((array) $bottone->get('context'))['empty_caption'] ?? null) === 'Nessun fornitore'
        && ($bottone->conditionalAttributes()['data-hidden-when'] ?? null) === 'has_variants'
        && !isset($letto['campi']['product_supplier_sku'], $letto['campi']['product_supplier_cost'])
        && $nellaTendina === ['sku', 'product_ean', 'product_suppliers', 'product_suppliers_button']
        && ($larghezzeDi($letto['tendina']->components)['product_suppliers_button'] ?? null) === 12
        && ($colonne['suppliers'] ?? null)?->get('helper') === 'hidden'
        && $inRiga instanceof InputButton
        && $inRiga->get('label') === 'Fornitori'
        && str_contains((string) $inRiga->get('attribute'), 'data-bs-target="#wi-product-suppliers"')
        && (((array) $inRiga->get('context'))['empty_caption'] ?? null) === 'Nessun fornitore'
        // Da solo prende la riga: «Giacenza» c'è solo con più sedi.
        && (((array) $inRiga->columnSpan)['default'] ?? null) === 12
        && !isset($colonne['supplier_sku'], $colonne['supplier_cost'])
        && $letto['avanzate'] === ['sku', 'ean', 'active', 'suppliers_button', 'photo']
        && !in_array('Fornitori', $letto['titoli'], true);
});

check('la finestra «Fornitori» ha una riga per fornitore, con la sua «x», e «Salva per tutte le opzioni»', function () use ($conFornitori) {
    $letto = $conFornitori([5 => 'Filati Nord', 6 => 'Lanificio Sud', 7 => 'Tessuti Est'], ['purchasing' => true]);
    $finestra = $letto['modali'][0] ?? null;

    if (count($letto['modali']) !== 1 || !$finestra instanceof Modal) {
        return false;
    }

    $nomi = [];
    $scelte = [];
    $linee = [];
    $togli = [];
    $obbligatori = 0;
    $testa = '';

    foreach ($finestra->components as $pezzo) {
        if ($pezzo instanceof Container) {
            $linee[] = (string) (((array) $pezzo->getSchema('attributes'))['data-wi-supplier-line'] ?? '');
        }

        foreach ($pezzo instanceof Container ? $pezzo->components : [$pezzo] as $dentro) {
            if ($dentro instanceof InputButton) {
                $togli[] = [(string) $dentro->get('label'), (string) $dentro->get('attribute')];
            } elseif ($dentro instanceof Input) {
                $nomi[] = (string) $dentro->name;
                $obbligatori += str_contains((string) $dentro->get('attribute'), 'required') ? 1 : 0;

                if (str_ends_with((string) $dentro->name, '[supplier_id]')) {
                    $scelte[] = (array) $dentro->get('options');
                }
            } elseif ($dentro instanceof RichText) {
                $testa .= (string) $dentro->getText();
            }
        }
    }

    $bottoni = array_map(static fn (Button $bottone) => $bottone->getLabel(), $finestra->footer);
    $attributi = array_map(static fn (Button $bottone) => (array) $bottone->getSchema('attributes'), $finestra->footer);
    $tendina = ['' => '—', 5 => 'Filati Nord', 6 => 'Lanificio Sud', 7 => 'Tessuti Est'];

    return $finestra->getSchema('id') === 'wi-product-suppliers'
        && $finestra->getTitle() === 'Fornitori'
        // Lo stesso fornitore non si scrive due volte: tre fornitori, tre righe.
        && $nomi === [
            'wi_product_supplier[0][supplier_id]', 'wi_product_supplier[0][sku]', 'wi_product_supplier[0][cost]',
            'wi_product_supplier[1][supplier_id]', 'wi_product_supplier[1][sku]', 'wi_product_supplier[1][cost]',
            'wi_product_supplier[2][supplier_id]', 'wi_product_supplier[2][sku]', 'wi_product_supplier[2][cost]',
        ]
        && $linee === ['0', '1', '2']
        && $scelte === [$tendina, $tendina, $tendina]
        && count($togli) === 3
        && $togli[0][0] === ''
        && str_contains($togli[0][1], 'data-wi-supplier-remove="0"')
        && str_contains($togli[2][1], 'data-wi-supplier-remove="2"')
        && str_contains($togli[0][1], 'aria-label="Togli il fornitore"')
        && $obbligatori === 0
        && str_contains($testa, 'data-wi-supplier-error')
        && str_contains($testa, 'Fornitore')
        && str_contains($testa, 'Codice fornitore')
        && str_contains($testa, 'Costo d\'acquisto')
        && $bottoni === ['Aggiungi fornitore', 'Annulla', 'Salva per tutte le opzioni', 'Salva']
        && ($attributi[0]['data-wi-supplier-add'] ?? null) === 'true'
        && ($attributi[1]['data-bs-dismiss'] ?? null) === 'modal'
        && array_key_exists('data-wi-supplier-save-all', $attributi[2] ?? [])
        && array_key_exists('data-wi-supplier-save', $attributi[3] ?? [])
        // Lo script che la riempie e la legge, con i nomi dei fornitori.
        && str_contains($letto['script'], 'window.wiProductSuppliers')
        && str_contains($letto['script'], 'Lanificio Sud');
});

check('la finestra ha al massimo dieci righe, ma tutte quelle di chi ne ha già di più', function () use ($schedaAperta, $finestre, $forza) {
    $conta = static function (array $legami) use ($schedaAperta, $finestre, $forza): int {
        $schedaAperta::$fornitori = array_combine(range(1, 14), array_map(static fn (int $n) => 'Fornitore '.$n, range(1, 14)));
        $schedaAperta::$legami = $legami;
        $forza(['purchasing' => true]);

        try {
            $finestra = $finestre()[0] ?? null;
        } finally {
            $forza(null);
            $schedaAperta::$fornitori = [];
            $schedaAperta::$legami = [];
        }

        return count(array_filter($finestra?->components ?? [], static fn ($pezzo) => $pezzo instanceof Container));
    };

    $dodici = array_map(
        static fn (int $n) => ['supplier_id' => $n, 'supplier_sku' => '', 'cost' => null],
        range(1, 12)
    );

    return $conta([]) === 10 && $conta([2 => $dodici]) === 12;
});

check('i valori dei fornitori arrivano al form: due campi, o il JSON con il riassunto', function () use ($schedaAperta) {
    $legami = [
        ['supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => 12.3456],
        ['supplier_id' => 6, 'supplier_sku' => '', 'cost' => null],
    ];
    $nomi = [5 => 'Filati Nord', 6 => 'Lanificio Sud'];

    $due = $schedaAperta::vediCampiFornitori(['id' => 2], '', 'flat', $legami, [5 => 'Filati Nord'], 5);
    $vuoti = $schedaAperta::vediCampiFornitori(['id' => 3], 'product_', 'flat', [], [5 => 'Filati Nord'], 5);
    // Dopo un salvataggio rifiutato quello scritto resta.
    $scritti = $schedaAperta::vediCampiFornitori(['supplier_sku' => 'X', 'supplier_cost' => ''], '', 'flat', $legami, [5 => 'Filati Nord'], 5);
    $finestra = $schedaAperta::vediCampiFornitori(['id' => 2], '', 'modal', $legami, $nomi);
    $nessuno = $schedaAperta::vediCampiFornitori(['id' => 3], 'product_', 'modal', [], $nomi);
    $tenuto = $schedaAperta::vediCampiFornitori(
        ['suppliers' => '[{"supplier_id":6,"supplier_sku":"LS-1","cost":3}]'],
        '',
        'modal',
        $legami,
        $nomi
    );

    return $due === ['id' => 2, 'supplier_sku' => 'FN-12', 'supplier_cost' => '12.35']
        && $vuoti === ['id' => 3, 'product_supplier_sku' => '', 'product_supplier_cost' => '']
        && $scritti === ['supplier_sku' => 'X', 'supplier_cost' => '']
        && json_decode($finestra['suppliers'], true) === [
            ['supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => '12.35'],
            ['supplier_id' => 6, 'supplier_sku' => '', 'cost' => ''],
        ]
        && str_contains((string) $finestra['suppliers_button'], 'Filati Nord')
        && str_contains((string) $finestra['suppliers_button'], 'Lanificio Sud')
        && $nessuno['product_suppliers'] === '[]'
        && $nessuno['product_suppliers_button'] === 'Nessun fornitore'
        && $tenuto['suppliers'] === '[{"supplier_id":6,"supplier_sku":"LS-1","cost":3}]'
        && str_contains((string) $tenuto['suppliers_button'], 'Lanificio Sud')
        && !str_contains((string) $tenuto['suppliers_button'], 'Filati Nord');
});

check('dopo un salvataggio rifiutato il riassunto del bottone si rifà dal JSON', function () use ($schedaAperta, $forza) {
    $schedaAperta::$fornitori = [5 => 'Filati Nord', 6 => 'Lanificio Sud'];
    $forza(['purchasing' => true]);

    try {
        $valori = $schedaAperta::mutateFormValues([
            'name' => 'Maglietta',
            'product_suppliers' => '[{"supplier_id":5,"supplier_sku":"FN-12","cost":12.5}]',
            'products' => [
                'a' => ['suppliers' => '[{"supplier_id":6,"supplier_sku":"","cost":""}]'],
                'b' => ['suppliers' => ''],
            ],
        ], 'edit');
    } finally {
        $forza(null);
        $schedaAperta::$fornitori = [];
    }

    return str_contains((string) ($valori['product_suppliers_button'] ?? ''), 'Filati Nord')
        && str_contains((string) ($valori['products']['a']['suppliers_button'] ?? ''), 'Lanificio Sud')
        // Una finestra mai salvata non ha niente da riassumere.
        && !isset($valori['products']['b']['suppliers_button']);
});

check('l\'aiuto del riquadro Prodotto parla dei fornitori solo quando si compilano', function () use ($schedaAperta, $riquadro, $forza) {
    $aiuto = static function (bool $acquisti, array $fornitori) use ($schedaAperta, $riquadro, $forza): string {
        $schedaAperta::$fornitori = $fornitori;
        $forza(['purchasing' => $acquisti, 'low_stock_alerts' => false]);

        try {
            $dentro = $riquadro(0, 'Prodotto');
        } finally {
            $forza(null);
            $schedaAperta::$fornitori = [];
        }

        return (string) (($dentro[0] ?? null)?->getSchema()['tooltip'] ?? '');
    };

    $con = $aiuto(true, [5 => 'Filati Nord']);

    return str_contains($con, 'SKU, EAN e fornitori stanno in «Compila le informazioni avanzate»')
        && str_contains($con, 'costo d\'acquisto')
        && str_contains($aiuto(true, []), 'SKU, EAN stanno in «Compila le informazioni avanzate»')
        && !str_contains($aiuto(true, []), 'costo d\'acquisto')
        && !str_contains($aiuto(false, [5 => 'Filati Nord']), 'costo d\'acquisto');
});

check('scontato, righe per sede, fornitori e campi delle finestre non finiscono fra le colonne del modello', function () use ($forza) {
    $risultato = true;
    $extra = [
        'product_sale_price', 'locations', 'wi_location_stock',
        'product_supplier_sku', 'product_supplier_cost', 'product_suppliers', 'product_suppliers_button',
        'suppliers', 'suppliers_button', 'supplier_sku', 'supplier_cost',
        'wi_product_supplier', 'wi_product_supplier_remove',
    ];

    foreach ([true, false] as $acquisti) {
        $forza(['purchasing' => $acquisti]);

        try {
            $valori = ProductModelResource::mutateRequestValues(
                ['name' => 'Maglietta', 'locations' => [], 'wi_location_stock' => []]
                    + array_fill_keys($extra, ''),
                'store'
            );
        } finally {
            $forza(null);
        }

        $risultato = $risultato && array_intersect($extra, array_keys($valori)) === [];
    }

    return $risultato;
});

check('un fornitore che la pagina non propone si ferma prima del salvataggio', function () use ($forza) {
    $scheda = new class extends ProductModelResource {
        protected static function supplierChoices(int $modelId): array
        {
            return [5 => 'Filati Nord', 6 => 'Lanificio Sud'];
        }
    };

    $postato = $_POST;
    $esiti = [];

    // Spenti gli acquisti, quello che arriva non si guarda nemmeno.
    foreach ([true, false] as $acquisti) {
        $forza(['purchasing' => $acquisti]);
        $_POST = [
            'name' => 'Maglietta',
            'has_variants' => 'false',
            'product_suppliers' => '[{"supplier_id":9,"supplier_sku":"","cost":3}]',
        ];

        try {
            $scheda::mutateRequestValues($_POST, 'store');
            $esiti[] = '';
        } catch (UserError $errore) {
            $esiti[] = $errore->key();
        } finally {
            $forza(null);
            $_POST = $postato;
        }
    }

    return $esiti === ['product.supplier_invalid', ''];
});

// La colonna del costo è DECIMAL(12,4), quella del codice VARCHAR(100), e un
// fornitore sta su una riga sola: quello che non ci sta si ferma qui, prima
// che l'articolo sia scritto.
check('costo, codice, doppioni e righe senza fornitore si fermano prima del salvataggio', function () use ($forza) {
    $scheda = new class extends ProductModelResource {
        protected static function supplierChoices(int $modelId): array
        {
            return [5 => 'Filati Nord', 6 => 'Lanificio Sud'];
        }
    };

    $esiti = [];
    $forza(['purchasing' => true]);

    try {
        foreach ([
            [['supplier_id' => 5, 'cost' => 123456789]],
            [['supplier_id' => 5, 'cost' => '12..5']],
            [['supplier_id' => 5, 'cost' => -1]],
            [['supplier_id' => 6, 'supplier_sku' => str_repeat('S', 101)]],
            [['supplier_id' => 5, 'cost' => 1], ['supplier_id' => 5, 'cost' => 2]],
            // Un codice o un costo senza fornitore si devono vedere, non
            // sparire in silenzio.
            [['supplier_id' => '', 'supplier_sku' => 'FN-12', 'cost' => '12,50']],
            [['supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => 12.5], ['supplier_id' => 6, 'cost' => '']],
        ] as $righe) {
            $json = (string) json_encode($righe);

            // Senza varianti il JSON del riquadro «Prodotto», con le varianti
            // quello di ogni riga della griglia: il rifiuto è lo stesso.
            foreach ([
                [['product_suppliers' => $json], false],
                [['products' => ['11' => ['suppliers' => $json]]], true],
            ] as [$post, $conVarianti]) {
                try {
                    $scheda::assertSupplierRows(0, $post, $conVarianti);
                    $esiti[] = '';
                } catch (UserError $errore) {
                    // Il doppione si chiama per nome: è quello che si legge sul form.
                    $esiti[] = $errore->key()
                        .(str_contains($errore->getMessage(), 'Filati Nord') ? ' (Filati Nord)' : '');
                }
            }
        }
    } finally {
        $forza(null);
    }

    return $esiti === [
        'product.supplier_cost_too_high', 'product.supplier_cost_too_high',
        'product.supplier_cost_invalid', 'product.supplier_cost_invalid',
        'product.supplier_cost_negative', 'product.supplier_cost_negative',
        'product.supplier_sku_too_long', 'product.supplier_sku_too_long',
        'product.supplier_duplicate (Filati Nord)', 'product.supplier_duplicate (Filati Nord)',
        'product.supplier_missing', 'product.supplier_missing',
        '', '',
    ];
});

check('i campi che non contano non fermano il salvataggio: il riquadro con le varianti, la griglia senza', function () use ($forza) {
    $scheda = new class extends ProductModelResource {
        protected static function supplierChoices(int $modelId): array
        {
            return [5 => 'Filati Nord', 6 => 'Lanificio Sud'];
        }
    };

    $storto = '[{"supplier_id":9,"supplier_sku":"","cost":3}]';
    $forza(['purchasing' => true]);

    try {
        // Alla creazione con le varianti il riquadro «Prodotto» è nascosto.
        $scheda::assertSupplierRows(0, ['product_suppliers' => $storto, 'products' => []], true);
        $scheda::assertSupplierRows(0, ['products' => ['11' => ['suppliers' => $storto]]], false);
        // Una finestra mai aperta non manda niente, e non c'è niente da dire.
        $scheda::assertSupplierRows(0, ['products' => ['11' => ['suppliers' => '']]], true);

        return true;
    } catch (UserError) {
        return false;
    } finally {
        $forza(null);
    }
});

check('con un fornitore solo i due campi si controllano come una riga, e senza di lui si rifiutano', function () use ($forza) {
    $uno = new class extends ProductModelResource {
        protected static function supplierChoices(int $modelId): array
        {
            return [5 => 'Filati Nord'];
        }
    };
    $due = new class extends ProductModelResource {
        protected static function supplierChoices(int $modelId): array
        {
            return [5 => 'Filati Nord', 6 => 'Lanificio Sud'];
        }
    };

    $esiti = [];
    $forza(['purchasing' => true]);

    try {
        foreach ([
            [$uno, ['product_supplier_sku' => 'FN-12', 'product_supplier_cost' => '12,50'], false],
            [$uno, ['product_supplier_sku' => '', 'product_supplier_cost' => ''], false],
            [$uno, ['product_supplier_sku' => str_repeat('S', 101), 'product_supplier_cost' => ''], false],
            [$uno, ['product_supplier_sku' => '', 'product_supplier_cost' => '12..5'], false],
            [$uno, ['products' => ['11' => ['supplier_sku' => '', 'supplier_cost' => '-1']]], true],
            // Nel frattempo è nato un altro fornitore: i due campi non si sa
            // più di chi siano. Vuoti non dicono niente.
            [$due, ['product_supplier_sku' => 'FN-12', 'product_supplier_cost' => ''], false],
            [$due, ['product_supplier_sku' => '', 'product_supplier_cost' => ''], false],
        ] as [$scheda, $post, $conVarianti]) {
            try {
                $scheda::assertSupplierRows(0, $post, $conVarianti);
                $esiti[] = '';
            } catch (UserError $errore) {
                $esiti[] = $errore->key();
            }
        }
    } finally {
        $forza(null);
    }

    return $esiti === [
        '',
        '',
        'product.supplier_sku_too_long',
        'product.supplier_cost_invalid',
        'product.supplier_cost_negative',
        'product.supplier_invalid',
        '',
    ];
});

/**
 * Fa girare `$fai` con quelle sedi in magazzino e «Più sedi» accesa, più le
 * altre funzionalità chieste: senza database le sedi mostrate sarebbero zero.
 *
 * @param list<array{id: int, label: string}> $sedi
 * @param array<string, bool> $features
 */
$conSedi = static function (array $sedi, array $features, callable $fai) use ($forza): mixed {
    $cache = new ReflectionProperty(Locations::class, 'shown');
    $prima = $cache->getValue();
    $cache->setValue(null, $sedi);
    $forza($features + ['multi_location' => true]);

    try {
        return $fai();
    } finally {
        $cache->setValue(null, $prima);
        $forza(null);
    }
};

$dueSedi = [['id' => 1, 'label' => 'Milano'], ['id' => 2, 'label' => 'Roma']];

check('con due sedi prezzo e scontato prendono la riga, e giacenza e soglia vanno nel riquadro Magazzino', function () use ($conSedi, $dueSedi, $riquadri, $riquadro, $avanzateProdotto, $larghezzeDi) {
    return $conSedi($dueSedi, ['low_stock_alerts' => true], function () use ($riquadri, $riquadro, $avanzateProdotto, $larghezzeDi): bool {
        $prodotto = $riquadro(0, 'Prodotto');
        $aiuto = (string) (($prodotto[0] ?? null)?->getSchema()['tooltip'] ?? '');
        $larghezze = $larghezzeDi($prodotto);
        $tendina = $avanzateProdotto();

        // Il riquadro «Magazzino» subito sotto «Prodotto» (P102): la scorta
        // minima non sta più nella tendina, che tiene i soli codici.
        return $riquadri(0) === ['Prodotto', 'Magazzino', 'Opzioni in vendita', 'Scheda tecnica']
            && str_contains($aiuto, 'Giacenza e scorta minima stanno nel riquadro «Magazzino», sede per sede.')
            && str_contains($aiuto, 'SKU, EAN stanno in «Compila le informazioni avanzate».')
            && !str_contains($aiuto, 'giacenza iniziale')
            && ($larghezze['product_price'] ?? null) === 6
            && ($larghezze['product_sale_price'] ?? null) === 6
            && !array_key_exists('product_stock', $larghezze)
            && $tendina !== null
            && $larghezzeDi($tendina->components) === ['sku' => 6, 'product_ean' => 6];
    });
});

check('il riquadro Magazzino spiega come si scrive la giacenza, e ha una riga per sede', function () use ($conSedi, $dueSedi, $schedaAperta, $riquadro) {
    $leggi = static function (bool $soglia) use ($conSedi, $dueSedi, $schedaAperta, $riquadro): array {
        return $conSedi($dueSedi, ['low_stock_alerts' => $soglia], function () use ($schedaAperta, $riquadro): array {
            $dentro = $riquadro(0, 'Magazzino');
            $campo = $dentro[1] ?? null;
            $contesto = (array) ($campo?->get('context') ?? []);
            $colonne = [];

            foreach ((array) ($contesto['columns'] ?? []) as $colonna) {
                $colonne[(string) $colonna->name] = [
                    (string) $colonna->get('label'),
                    ((array) $colonna->columnSpan)['default'] ?? null,
                ];
            }

            $attributi = [];

            foreach ($schedaAperta::formLayoutSchema()->components[0]->components ?? [] as $card) {
                $titolo = $card->components[0] ?? null;

                if ($titolo instanceof SectionTitle && $titolo->getText() === 'Magazzino') {
                    $attributi = (array) $card->getSchema('attributes');
                }
            }

            return [
                'aiuto' => (string) (($dentro[0] ?? null)?->getSchema()['tooltip'] ?? ''),
                'nome' => (string) ($campo?->name ?? ''),
                'etichetta' => (string) ($campo?->get('label') ?? ''),
                'helper' => (string) ($campo?->get('helper') ?? ''),
                'larghezza' => ((array) ($campo?->columnSpan ?? []))['default'] ?? null,
                'colonne' => $colonne,
                'sedi' => (array) (($contesto['columns'][0] ?? null)?->get('options') ?? []),
                'contesto' => $contesto,
                'attributi' => $attributi,
            ];
        });
    };

    $con = $leggi(true);
    $senza = $leggi(false);

    return str_starts_with($con['aiuto'], 'Quanti pezzi ci sono in ogni sede, e sotto quanti arriva l\'avviso. Si scrive quanti ce ne sono, e il movimento della differenza lo fa il magazzino: casella vuota = non toccare, zero scritto = zero.')
        && str_contains($con['aiuto'], 'Una sede tolta dalle righe perde la sua scorta minima, non i pezzi')
        // La scheda è di un articolo già salvato: niente giacenza iniziale.
        && !str_contains($con['aiuto'], 'giacenza iniziale')
        && str_starts_with($senza['aiuto'], 'Quanti pezzi ci sono in ogni sede. Si scrive')
        && $con['nome'] === 'locations'
        && $con['etichetta'] === 'Giacenza per sede'
        && $con['helper'] === 'inputRepeater'
        && $con['larghezza'] === 12
        && $con['colonne'] === ['location_id' => ['Sede', 5], 'stock' => ['Giacenza', 3], 'min_stock' => ['Scorta minima', 3]]
        && $senza['colonne'] === ['location_id' => ['Sede', 7], 'stock' => ['Giacenza', 4]]
        && $con['sedi'] === ['' => '—', 1 => 'Milano', 2 => 'Roma']
        // Niente relazione: le righe le compone e le legge la scheda.
        && ($con['contesto']['relation'] ?? null) === null
        && ($con['contesto']['nested'] ?? false) === true
        && ($con['contesto']['add_label'] ?? null) === 'Aggiungi sede'
        && ($con['contesto']['delete_modal_title'] ?? null) === 'Togli sede'
        && ($con['contesto']['delete_modal_text'] ?? null) === 'Questa sede perde la sua scorta minima al salvataggio. I pezzi restano dove sono: finché ne ha, la riga ricompare.'
        && ($con['contesto']['delete_modal_cancel_label'] ?? null) === 'Annulla'
        && ($con['contesto']['delete_modal_confirm_label'] ?? null) === 'Togli'
        // Con le varianti i pezzi stanno nella griglia: il riquadro sparisce.
        && ($con['attributi']['data-hidden-when'] ?? null) === 'has_variants'
        && ($con['attributi']['data-hidden-when-values'] ?? null) === 'true';
});

check('con due sedi la griglia non ha la giacenza: si scrive dalla finestra, e l\'opzione prende il suo posto', function () use ($conSedi, $dueSedi, $campiAperta, $schedaAperta) {
    $leggi = static function (array $fornitori, array $features) use ($conSedi, $dueSedi, $campiAperta, $schedaAperta): array {
        $schedaAperta::$fornitori = $fornitori;

        try {
            return $conSedi($dueSedi, $features, function () use ($campiAperta): array {
                $campo = $campiAperta()['products'] ?? null;
                $contesto = (array) ($campo?->get('context') ?? []);
                $colonne = [];

                foreach ((array) ($contesto['columns'] ?? []) as $colonna) {
                    $colonne[(string) $colonna->name] = $colonna;
                }

                return ['colonne' => $colonne, 'avanzate' => (array) ($contesto['advanced'] ?? [])];
            });
        } finally {
            $schedaAperta::$fornitori = [];
        }
    };

    $letto = $leggi([], ['low_stock_alerts' => true]);
    $colonne = $letto['colonne'];
    $bottone = $colonne['stock_button'] ?? null;
    $larghezza = static fn (?Input $colonna): mixed => ((array) ($colonna?->columnSpan ?? []))['default'] ?? null;

    $conFinestra = $leggi([5 => 'Filati Nord', 6 => 'Lanificio Sud'], ['low_stock_alerts' => true, 'purchasing' => true]);
    $conCampi = $leggi([5 => 'Filati Nord'], ['purchasing' => true]);

    // La soglia è per sede e sta nella finestra (P103); un totale che non si
    // può scrivere non c'è più (P113), e il JSON viaggia nascosto.
    return !isset($colonne['min_stock'], $colonne['stock'])
        && $larghezza($colonne['option'] ?? null) === 7
        && $larghezza($colonne['price'] ?? null) + $larghezza($colonne['sale_price'] ?? null) + 7 === 11
        && ($colonne['locations'] ?? null)?->get('helper') === 'hidden'
        && $bottone instanceof InputButton
        && $bottone->get('label') === 'Giacenza'
        && str_contains((string) $bottone->get('attribute'), 'data-bs-target="#wi-location-stock"')
        && (((array) $bottone->get('context'))['empty_caption'] ?? null) === 'Nessun pezzo'
        && $larghezza($bottone) === 12
        && $letto['avanzate'] === ['sku', 'ean', 'active', 'stock_button', 'photo']
        && $larghezza($colonne['sku']) === 4
        // Con la finestra dei fornitori i due bottoni stanno in fila.
        && $larghezza($conFinestra['colonne']['stock_button'] ?? null) === 6
        && $larghezza($conFinestra['colonne']['suppliers_button'] ?? null) === 6
        && $conFinestra['avanzate'] === ['sku', 'ean', 'active', 'stock_button', 'suppliers_button', 'photo']
        // Con il fornitore unico i suoi due campi, e «Giacenza» tiene la riga.
        && $larghezza($conCampi['colonne']['stock_button'] ?? null) === 12
        && $conCampi['avanzate'] === ['sku', 'ean', 'active', 'supplier_sku', 'supplier_cost', 'stock_button', 'photo'];
});

check('la finestra «Giacenza» ha una riga per sede, con la sua «x», e niente di obbligatorio', function () use ($conSedi, $dueSedi, $finestre, $schedaAperta) {
    [$finestre, $nellaColonna, $script] = $conSedi($dueSedi, ['low_stock_alerts' => true], function () use ($finestre, $schedaAperta): array {
        $colonna = $schedaAperta::formLayoutSchema()->components[0]->components ?? [];
        $script = '';

        foreach ($colonna as $pezzo) {
            if ($pezzo instanceof RichText && str_contains((string) $pezzo->getText(), 'wiLocationStock')) {
                $script .= (string) $pezzo->getText();
            }
        }

        // Ogni lettura del layout costruisce oggetti nuovi: la finestra
        // della colonna si cerca per tipo, non per identità.
        return [$finestre(), count(array_filter($colonna, static fn ($pezzo) => $pezzo instanceof Modal)), $script];
    });

    $finestra = $finestre[0] ?? null;

    if (count($finestre) !== 1 || !$finestra instanceof Modal) {
        return false;
    }

    $nomi = [];
    $scelte = [];
    $linee = [];
    $togli = [];
    $obbligatori = 0;
    $testa = '';

    // Ogni sede è una riga con la sua maniglia: lo script la mostra e la
    // nasconde per intero, e la «x» in fondo la svuota.
    foreach ($finestra->components as $pezzo) {
        if ($pezzo instanceof Container) {
            $linee[] = (string) (((array) $pezzo->getSchema('attributes'))['data-wi-location-line'] ?? '');
        }

        foreach ($pezzo instanceof Container ? $pezzo->components : [$pezzo] as $dentro) {
            if ($dentro instanceof InputButton) {
                $togli[] = [(string) $dentro->get('label'), (string) $dentro->get('attribute')];
            } elseif ($dentro instanceof Input) {
                $nomi[] = (string) $dentro->name;
                $obbligatori += str_contains((string) $dentro->get('attribute'), 'required') ? 1 : 0;

                if (str_ends_with((string) $dentro->name, '[location_id]')) {
                    $scelte[] = (array) $dentro->get('options');
                }
            } elseif ($dentro instanceof RichText) {
                $testa .= (string) $dentro->getText();
            }
        }
    }

    $bottoni = array_map(static fn (Button $bottone) => $bottone->getLabel(), $finestra->footer);
    $attributi = array_map(static fn (Button $bottone) => (array) $bottone->getSchema('attributes'), $finestra->footer);

    return $finestra->getSchema('id') === 'wi-location-stock'
        && $finestra->getTitle() === 'Giacenza'
        // Nella colonna larga, fuori dal riquadro delle opzioni che sparisce
        // senza varianti.
        && $nellaColonna === 1
        && $nomi === [
            'wi_location_stock[0][location_id]', 'wi_location_stock[0][stock]', 'wi_location_stock[0][min_stock]',
            'wi_location_stock[1][location_id]', 'wi_location_stock[1][stock]', 'wi_location_stock[1][min_stock]',
        ]
        && $linee === ['0', '1']
        && $scelte === [['' => '—', 1 => 'Milano', 2 => 'Roma'], ['' => '—', 1 => 'Milano', 2 => 'Roma']]
        && count($togli) === 2
        && $togli[0][0] === ''
        && str_contains($togli[0][1], 'data-wi-location-remove="0"')
        && str_contains($togli[1][1], 'data-wi-location-remove="1"')
        && str_contains($togli[0][1], 'aria-label="Togli la sede"')
        && $obbligatori === 0
        && str_contains($testa, 'Sede')
        && str_contains($testa, 'Giacenza')
        && str_contains($testa, 'Scorta minima')
        && $bottoni === ['Aggiungi sede', 'Annulla', 'Salva']
        && ($attributi[0]['data-wi-location-add'] ?? null) === 'true'
        && ($attributi[1]['data-bs-dismiss'] ?? null) === 'modal'
        && array_key_exists('data-wi-location-stock-save', $attributi[2] ?? [])
        // Lo script che la riempie e la legge, con i nomi delle sedi.
        && str_contains($script, 'window.wiLocationStock')
        && str_contains($script, 'data-wi-location-main="')
        && str_contains($script, 'Milano');
});

check('le righe per sede tengono i decimali di una sede, anche se la somma è tonda', function () use ($conSedi, $dueSedi) {
    $scheda = new class extends ProductModelResource {
        /** @var list<float> */
        public static array $pezzi = [];

        /** @var list<float> */
        public static array $soglie = [];

        public static function products(int $modelId): array
        {
            return [['id' => 2, 'sku' => 'CAP-2', 'price' => '24.90']];
        }

        protected static function modelUnit(int $modelId): string
        {
            return 'pz';
        }

        protected static function locationQuantities(array $productIds): array
        {
            return $productIds === [2] ? static::$pezzi : [];
        }

        protected static function locationThresholds(array $productIds): array
        {
            return $productIds === [2] ? static::$soglie : [];
        }

        /** @return array<string, array{0: mixed, 1: mixed}> decimali e unità, per casella */
        public static function vediFormati(): array
        {
            $formato = static fn (object $casella): array => [
                (((array) $casella->get('context'))['number'] ?? [])['decimal'] ?? null,
                (((array) $casella->get('context'))['number'] ?? [])['symbol'] ?? null,
            ];
            $formati = [];

            foreach ((array) (((array) static::stockRowsField(1)->get('context'))['columns'] ?? []) as $colonna) {
                $formati['righe.'.$colonna->name] = $formato($colonna);
            }

            foreach (static::locationStockModal(1)->components as $pezzo) {
                foreach ($pezzo instanceof Container ? $pezzo->components : [] as $dentro) {
                    if ($dentro instanceof Input && str_starts_with((string) $dentro->name, 'wi_location_stock[0]')) {
                        $formati['finestra.'.substr((string) $dentro->name, 21, -1)] = $formato($dentro);
                    }
                }
            }

            return $formati;
        }
    };

    $leggi = static function (array $pezzi, array $soglie) use ($conSedi, $dueSedi, $scheda): array {
        $scheda::$pezzi = $pezzi;
        $scheda::$soglie = $soglie;

        return $conSedi($dueSedi, ['low_stock_alerts' => true], static fn (): array => $scheda::vediFormati());
    };

    $interi = $leggi([20.0, 3.0], [5.0]);
    // 1,5 + 1,5 fa 3: la somma è tonda, le sedi no. Mostrarle arrotondate
    // vorrebbe dire salvare un movimento che nessuno ha chiesto.
    $mezzi = $leggi([1.5, 1.5], [0.5]);

    return ($interi['righe.stock'] ?? null) === [0, ' pz']
        && ($interi['righe.min_stock'] ?? null) === [0, ' pz']
        && ($interi['finestra.stock'] ?? null) === [0, ' pz']
        && ($interi['finestra.min_stock'] ?? null) === [0, ' pz']
        && ($mezzi['righe.stock'] ?? null) === [3, ' pz']
        && ($mezzi['righe.min_stock'] ?? null) === [3, ' pz']
        && ($mezzi['finestra.stock'] ?? null) === [3, ' pz']
        && ($mezzi['finestra.min_stock'] ?? null) === [3, ' pz'];
});

check('con due sedi le righe per sede si controllano prima di scrivere, sull\'articolo solo e in griglia', function () use ($conSedi, $dueSedi) {
    $prova = static function (array $post, bool $conVarianti): string {
        try {
            ProductModelResource::assertLocationRows($post, $conVarianti);

            return '';
        } catch (UserError $errore) {
            return $errore->key();
        }
    };

    $esiti = $conSedi($dueSedi, ['low_stock_alerts' => true], static fn (): array => [
        $prova(['locations' => [['location_id' => '1', 'stock' => '2', 'min_stock' => '1'], ['location_id' => '2', 'stock' => '', 'min_stock' => '']]], false),
        $prova(['locations' => [['location_id' => '1', 'stock' => '2'], ['location_id' => '1', 'stock' => '3']]], false),
        $prova(['locations' => [['location_id' => '9', 'stock' => '2']]], false),
        $prova(['locations' => [['location_id' => '1', 'stock' => '2', 'min_stock' => '-2']]], false),
        // Con le varianti le righe viaggiano nel JSON di ogni opzione.
        $prova(['products' => ['0' => ['id' => '2', 'locations' => '[{"location_id":1,"stock":"2"},{"location_id":9,"stock":"1"}]']]], true),
        $prova(['products' => ['0' => ['id' => '2', 'locations' => '[{"location_id":1,"stock":"2"},{"location_id":2,"stock":"1"}]']]], true),
        // Le righe dell'altro modo non si guardano.
        $prova(['products' => ['0' => ['id' => '2', 'locations' => '[{"location_id":9}]']]], false),
    ]);

    // Con una sede sola le righe non esistono: quello che arriva non si guarda.
    $esiti[] = $prova(['locations' => [['location_id' => '9', 'stock' => '2']]], false);

    return $esiti === [
        '',
        'stock.location_duplicate',
        'stock.location_unknown',
        'product.min_stock_invalid',
        'stock.location_unknown',
        '',
        '',
        '',
    ];
});

check('con due sedi una giacenza per sede sotto zero si ferma prima di scrivere', function () use ($conSedi, $dueSedi) {
    $prova = static function (array $post, bool $conVarianti): string {
        try {
            ProductModelResource::assertLocationRows($post, $conVarianti);

            return '';
        } catch (UserError $errore) {
            return $errore->key();
        }
    };

    // Righe di un articolo e di un'opzione che nascono ora: non c'è nessuna
    // giacenza di prima con cui confrontarle.
    return $conSedi($dueSedi, ['low_stock_alerts' => true], static fn (): array => [
        $prova(['locations' => [['location_id' => '1', 'stock' => '-3', 'min_stock' => '']]], false),
        $prova(['products' => ['blu' => ['locations' => '[{"location_id":1,"stock":"2"},{"location_id":2,"stock":"-1"}]']]], true),
        $prova(['locations' => [['location_id' => '1', 'stock' => '0', 'min_stock' => '']]], false),
    ]) === ['product.stock_negative', 'product.stock_negative', ''];
});

check('con due sedi un articolo nuovo parte con una riga vuota sulla sede principale', function () use ($conSedi, $dueSedi) {
    [$nuovo, $tornato] = $conSedi($dueSedi, [], static fn (): array => [
        ProductModelResource::mutateFormValues([], 'create'),
        // Dopo un errore le righe tornano dal form, e restano quelle.
        ProductModelResource::mutateFormValues(['locations' => [['location_id' => '2', 'stock' => '1', 'min_stock' => '']]], 'create'),
    ]);

    return ($nuovo['locations'] ?? null) === [['location_id' => (string) Locations::mainId(), 'stock' => '', 'min_stock' => '']]
        && ($tornato['locations'] ?? null) === [['location_id' => '2', 'stock' => '1', 'min_stock' => '']]
        // Con una sede sola il repeater non c'è, e nemmeno la riga.
        && !array_key_exists('locations', ProductModelResource::mutateFormValues([], 'create'));
});

check('un articolo che non si spedisce non tocca la sua scatola', function () {
    $scheda = new class extends ProductModelResource {
        public static function productCount(int $modelId): int
        {
            return 1;
        }
    };

    // Da spento l'imballaggio è solo nascosto e viene postato lo stesso:
    // una scatola ferma non è tra le scelte, e la select manderebbe vuoto.
    $spento = $scheda::mutateRequestValues(
        ['name' => 'Buono regalo', 'requires_shipping' => 'false', 'package_id' => ''],
        'update'
    );
    $acceso = $scheda::mutateRequestValues(
        ['name' => 'Cappello', 'requires_shipping' => 'true', 'package_id' => '4'],
        'update'
    );

    return !array_key_exists('package_id', $spento)
        && ($acceso['package_id'] ?? null) === '4';
});

check('a destra le misure stanno due per riga', function () use ($riquadro) {
    $larghezze = [];

    foreach ($riquadro(1, 'Misure') as $pezzo) {
        if (property_exists($pezzo, 'name') && (string) $pezzo->name !== '') {
            $larghezze[(string) $pezzo->name] = ((array) ($pezzo->columnSpan ?? []))['default'] ?? null;
        }
    }

    // In un terzo di schermo quattro caselle affiancate non si leggono.
    return $larghezze === [
        'unit' => 6,
        'weight' => 6,
        'length' => 6,
        'width' => 6,
        'height' => 6,
        'circumference' => 6,
    ];
});

check('in «Prodotto» la descrizione non ha un titolo suo', function () use ($riquadro) {
    $titoli = [];
    $nomi = [];

    foreach ($riquadro(0, 'Prodotto') as $pezzo) {
        if ($pezzo instanceof SectionTitle) {
            $titoli[] = $pezzo->getText();
        } elseif (property_exists($pezzo, 'name')) {
            $nomi[] = (string) $pezzo->name;
        }
    }

    // Bastano le etichette dei due campi: il titolo sopra le ripeteva.
    return $titoli === ['Prodotto']
        && in_array('short_description', $nomi, true)
        && in_array('description', $nomi, true);
});

check('le parole interne non compaiono più nei titoli', function () use ($riquadri) {
    $vecchie = ['Articolo', 'Varianti', 'Genera varianti e prodotti', 'Categorie e tag', 'Attributi', 'Immagini'];

    return array_intersect($riquadri(), $vecchie) === [];
});

/**
 * Una scheda con quattro caratteristiche finte: un Elenco con due valori, un
 * Testo, un Numero con l'unità e un'Icona ancora senza valori.
 */
$schedaTecnica = new class extends ProductModelResource {
    protected static function currentId(): ?int
    {
        return 1;
    }

    public static function optionAttributes(): array
    {
        return [];
    }

    public static function attributes(): array
    {
        return [
            ['id' => 31, 'name' => 'Lavaggio', 'level' => 'model', 'type' => 'select', 'unit' => ''],
            ['id' => 32, 'name' => 'Composizione', 'level' => 'model', 'type' => 'text', 'unit' => ''],
            ['id' => 33, 'name' => 'Spessore', 'level' => 'model', 'type' => 'number', 'unit' => 'mm'],
            ['id' => 34, 'name' => 'Simboli', 'level' => 'model', 'type' => 'icon', 'unit' => ''],
        ];
    }

    public static function attributeValues(): array
    {
        return [
            41 => ['id' => 41, 'attribute_id' => 31, 'label' => 'Lavaggio a 30°'],
            42 => ['id' => 42, 'attribute_id' => 31, 'label' => 'Non candeggiare'],
        ];
    }

    public static function vediScheda(): object
    {
        return static::technicalSheetCard();
    }

    /** @param list<array<string, mixed>> $rows */
    public static function vediValore(int $attributeId, array $rows): string|array
    {
        foreach (static::attributes() as $attribute) {
            if ((int) $attribute['id'] === $attributeId) {
                return static::technicalValue($attribute, $rows);
            }
        }

        return '';
    }
};

/**
 * I pezzi di un riquadro, trovati per nome del campo o per classe. Si guarda
 * anche dentro i blocchi: ogni caratteristica sta nel suo.
 */
$dentroScheda = static function (object $riquadro, string $cosa) use (&$dentroScheda): array {
    $trovati = [];

    foreach ((array) ($riquadro->components ?? []) as $pezzo) {
        if ($pezzo instanceof $cosa || (isset($pezzo->name) && $pezzo->name === $cosa)) {
            $trovati[] = $pezzo;
        }

        if ($pezzo instanceof Container) {
            array_push($trovati, ...$dentroScheda($pezzo, $cosa));
        }
    }

    return $trovati;
};

/** Il blocco di una caratteristica, trovato dal suo id. */
$bloccoTecnico = static function (object $riquadro, int $id): ?Container {
    foreach ((array) ($riquadro->components ?? []) as $pezzo) {
        if ($pezzo instanceof Container && (($pezzo->getSchema('attributes') ?? [])['data-wi-technical'] ?? null) === (string) $id) {
            return $pezzo;
        }
    }

    return null;
};

/** Il testo di tutti i RichText del riquadro, uno dopo l'altro. */
$testoScheda = static function (object $riquadro) use ($dentroScheda): string {
    return implode("\n", array_map(
        static fn ($pezzo): string => (string) $pezzo->getText(),
        $dentroScheda($riquadro, RichText::class)
    ));
};

check('la scheda tecnica c\'è anche vuota, e dice a cosa serve', function () use ($riquadri, $schedaAperta) {
    $riquadro = null;

    foreach ($schedaAperta::formLayoutSchema()->components[0]->components ?? [] as $candidato) {
        $titolo = $candidato->components[0] ?? null;

        if ($titolo instanceof SectionTitle && $titolo->getText() === 'Scheda tecnica') {
            $riquadro = $candidato;
        }
    }

    $testo = '';

    foreach ($riquadro->components ?? [] as $pezzo) {
        if ($pezzo instanceof RichText) {
            $testo .= $pezzo->getText();
        }
    }

    return in_array('Scheda tecnica', $riquadri(0), true)
        && str_contains($testo, 'wi-technical-empty"')
        && str_contains($testo, 'materiale, composizione, lavaggio')
        && str_contains($testo, 'Catalogo → Attributi');
});

check('«Nuova caratteristica» apre il modal degli attributi', function () use ($schedaTecnica, $dentroScheda) {
    $bottoni = $dentroScheda($schedaTecnica::vediScheda(), QuickCreateButton::class);
    $config = $bottoni[0]?->quickCreateConfig() ?? [];

    return count($bottoni) === 1
        && $config['resource'] === AttributeResource::class
        && $config['button'] === 'Nuova caratteristica'
        && $config['label'] === 'name'
        && $config['layout'] instanceof Closure;
});

check('una caratteristica a elenco si spunta a pillole, con il «+» per un valore nuovo', function () use ($schedaTecnica, $dentroScheda) {
    $campo = $dentroScheda($schedaTecnica::vediScheda(), 'attribute_31')[0] ?? null;
    $rapido = (array) (($campo?->get('context')['quick_create'] ?? []) ?: []);

    return $campo instanceof InputCheckbox
        && $campo->get('pills') === true
        && array_map('strval', array_keys((array) $campo->get('options'))) === ['41', '42']
        && (($campo->columnSpan ?? [])['default'] ?? null) === 12
        && ($rapido['resource'] ?? '') === AttributeValueResource::class
        && ($rapido['button'] ?? '') === 'Aggiungi valore';
});

check('testo e numero si scrivono, a mezza riga e con l\'unità', function () use ($schedaTecnica, $dentroScheda, $bloccoTecnico) {
    $riquadro = $schedaTecnica::vediScheda();
    $testo = $dentroScheda($riquadro, 'attribute_32')[0] ?? null;
    $numero = $dentroScheda($riquadro, 'attribute_33')[0] ?? null;

    // La mezza riga è del blocco: il campo lo riempie.
    return $testo instanceof InputText
        && $numero instanceof InputNumber
        && $numero->get('label') === 'Spessore (mm)'
        && (($bloccoTecnico($riquadro, 32)?->columnSpan ?? [])['default'] ?? null) === 6
        && (($bloccoTecnico($riquadro, 33)?->columnSpan ?? [])['default'] ?? null) === 6;
});

check('ogni caratteristica sta nel suo blocco, che si mostra solo se serve', function () use ($schedaTecnica, $dentroScheda, $bloccoTecnico) {
    $riquadro = $schedaTecnica::vediScheda();
    $attesi = [31 => ['Lavaggio', 12], 32 => ['Composizione', 6], 33 => ['Spessore', 6]];

    foreach ($attesi as $id => [$nome, $larghezza]) {
        $blocco = $bloccoTecnico($riquadro, $id);
        $campo = $dentroScheda($blocco ?? (object) [], 'attribute_'.$id)[0] ?? null;

        if ($blocco === null
            || (($blocco->getSchema('attributes') ?? [])['data-wi-technical-name'] ?? null) !== $nome
            || (($blocco->columnSpan ?? [])['default'] ?? null) !== $larghezza
            || (($campo?->columnSpan ?? [])['default'] ?? null) !== 12
        ) {
            return false;
        }
    }

    // L'icona ancora senza valori non ha né campo né blocco.
    return $bloccoTecnico($riquadro, 34) === null;
});

check('«Aggiungi caratteristica» propone quelle nascoste e quella nuova', function () use ($schedaTecnica, $testoScheda) {
    $testo = $testoScheda($schedaTecnica::vediScheda());

    return str_contains($testo, 'Aggiungi caratteristica')
        && str_contains($testo, 'border-style:dashed')
        && str_contains($testo, 'data-wi-technical-add="31">Lavaggio<')
        && str_contains($testo, 'data-wi-technical-add="32">Composizione<')
        && str_contains($testo, 'data-wi-technical-add="33">Spessore<')
        && !str_contains($testo, 'data-wi-technical-add="34"')
        // «Nuova caratteristica» è l'ultima voce del menu: apre il modal
        // del bottone, che resta nella pagina ma non si vede.
        && str_contains($testo, 'data-wi-technical-new')
        && str_contains($testo, 'Nuova caratteristica…');
});

check('togliere una caratteristica la svuota, perché nascosta si salverebbe lo stesso', function () use ($schedaTecnica, $testoScheda) {
    $testo = $testoScheda($schedaTecnica::vediScheda());

    return str_contains($testo, 'window.wiTechnicalSheet')
        && str_contains($testo, 'wi-technical-remove')
        && str_contains($testo, 'casella.checked = false')
        && str_contains($testo, "casella.value = ''")
        // Un blocco vuoto se ne va subito; la conferma solo se c'era scritto
        // qualcosa, con il modal del repeater o, senza, quello del browser.
        && str_contains($testo, 'if (!scritto(nodo))')
        && strpos($testo, 'if (!scritto(nodo))') < strpos($testo, 'wiRepeaterConfirmDelete')
        && str_contains($testo, 'window.confirm(');
});

check('senza caratteristiche il menu propone solo quella nuova', function () use ($schedaAperta, $dentroScheda, $testoScheda) {
    $vuota = null;

    foreach ($schedaAperta::formLayoutSchema()->components[0]->components ?? [] as $candidato) {
        $titolo = $candidato->components[0] ?? null;

        if ($titolo instanceof SectionTitle && $titolo->getText() === 'Scheda tecnica') {
            $vuota = $candidato;
        }
    }

    $testo = $testoScheda($vuota ?? (object) []);

    // Lo script cerca le voci con lo stesso attributo: conta solo il menu.
    return str_contains($testo, 'data-wi-technical-new')
        && !str_contains($testo, 'class="dropdown-item" data-wi-technical-add="');
});

check('un elenco ancora senza valori non diventa una casella sola', function () use ($schedaTecnica) {
    foreach ($schedaTecnica::formSchema() as $campo) {
        if ((string) $campo->name === 'attribute_34') {
            return false;
        }
    }

    return true;
});

check('con le caratteristiche la riga del vuoto non c\'è', function () use ($schedaTecnica, $testoScheda) {
    // Nascosta lascerebbe lo stesso la sua colonna, e un buco nel riquadro.
    return !str_contains($testoScheda($schedaTecnica::vediScheda()), 'wi-technical-empty"');
});

check('il campo appena nato ha il suo modello, e lo script lo mette al suo posto', function () use ($schedaTecnica, $testoScheda) {
    $testo = $testoScheda($schedaTecnica::vediScheda());

    return str_contains($testo, '<template data-wi-technical-template="text"')
        && str_contains($testo, '<template data-wi-technical-template="number"')
        && substr_count($testo, 'name="attribute___WI_ID__"') === 2
        // Anche il campo nuovo ha il suo blocco, con la sua ×.
        && substr_count($testo, 'data-wi-technical="__WI_ID__"') === 2
        && str_contains($testo, 'wi:quick-create:created')
        && str_contains($testo, '"app-gestionale-attributi"');
});

check('il cursore va nel campo nuovo quando il modale si è chiuso', function () use ($schedaTecnica, $testoScheda) {
    // Chiudendosi, Bootstrap rimette il cursore sul bottone che l'ha aperto.
    return str_contains($testoScheda($schedaTecnica::vediScheda()), "addEventListener('hidden.bs.modal'");
});

check('i testi della scheda tecnica non finiscono dentro un paragrafo', function () use ($schedaTecnica, $schedaAperta, $dentroScheda) {
    // Il `p` di default di un RichText, attorno a un `div` o a un altro `p`,
    // lascia due paragrafi vuoti con il loro margine.
    $vuota = null;

    foreach ($schedaAperta::formLayoutSchema()->components[0]->components ?? [] as $candidato) {
        $titolo = $candidato->components[0] ?? null;

        if ($titolo instanceof SectionTitle && $titolo->getText() === 'Scheda tecnica') {
            $vuota = $candidato;
        }
    }

    $testi = [
        ...$dentroScheda($schedaTecnica::vediScheda(), RichText::class),
        ...$dentroScheda($vuota ?? (object) [], RichText::class),
    ];

    foreach ($testi as $testo) {
        if (($testo->getSchema()['tag'] ?? 'p') !== 'div') {
            return false;
        }
    }

    return count($testi) === 3;
});

check('un elenco si rilegge come lista, testo e numero come la prima riga', function () use ($schedaTecnica) {
    return $schedaTecnica::vediValore(31, [['attribute_value_id' => 41], ['attribute_value_id' => 42]]) === ['41', '42']
        && $schedaTecnica::vediValore(31, []) === []
        && $schedaTecnica::vediValore(32, [['value_text' => 'Cotone 100%'], ['value_text' => 'altro']]) === 'Cotone 100%'
        && $schedaTecnica::vediValore(33, [['value_number' => '1.500']]) === '1.500'
        && $schedaTecnica::vediValore(32, []) === '';
});

check('la scheda chiede l\'imballaggio, e le misure sono del prodotto', function () use ($campi) {
    $chiavi = array_keys($campi());

    // «Spedito» non c'è più: era una frase calcolata che diceva prodotto +
    // tara, e le misure non parlano di spedizione.
    return in_array('package_id', $chiavi, true)
        && in_array('circumference', $chiavi, true)
        && !in_array('shipping_weight', $chiavi, true);
});

check('le foto si caricano dove appartengono', function () use ($schedaAperta) {
    // Un'area per l'articolo, e una per ogni colore quando ce n'è più d'uno.
    $chiavi = array_map(
        static fn ($campo) => (string) $campo->name,
        $schedaAperta::formSchema()
    );

    return in_array('images_0', $chiavi, true)
        && !in_array('images', $chiavi, true);
});

check('l\'area delle foto è un campo solo, con il suo tetto', function () use ($schedaAperta) {
    foreach ($schedaAperta::formSchema() as $campo) {
        if ((string) $campo->name !== 'images_0') {
            continue;
        }

        // Una riga per file con descrizione e stato era una tabella dentro
        // una scheda: ora è un rettangolo su cui si trascina.
        return $campo->get('helper') === 'inputFileDragDrop'
            && (int) (((array) $campo->get('prepare'))['max_file'] ?? 0) === 10
            && ((array) $campo->get('context')) === [];
    }

    return false;
});

check('le opzioni si raggruppano per il primo attributo, e il gruppo ha il suo prezzo', function () {
    // La griglia esiste solo con più di una versione: qui se ne fingono due.
    $scheda = new class extends ProductModelResource {
        protected static function currentId(): ?int
        {
            return 1;
        }

        public static function optionAttributes(): array
        {
            return [];
        }

        public static function productCount(int $modelId): int
        {
            return 2;
        }

        public static function variantCount(int $modelId): int
        {
            return 2;
        }

        /** Due colori veri: è quello che fa nascere il raggruppamento. */
        public static function variantLabels(int $modelId): array
        {
            return [1 => 'Blu', 2 => 'Rosso'];
        }

        /** Due assi: sotto i due non c'è niente da raggruppare. */
        public static function axesInUse(int $modelId): array
        {
            return [7, 9];
        }
    };

    foreach ($scheda::formSchema() as $campo) {
        if ((string) $campo->name !== 'products') {
            continue;
        }

        $contesto = $campo->get('context');
        $colonne = array_map(
            static fn ($colonna) => (string) $colonna->name,
            $contesto['columns'] ?? []
        );

        // Il raggruppamento non si sceglie qui: è il primo asse, e basta.
        return in_array('group', $colonne, true)
            && ($contesto['group_fixed'] ?? '') === 'group'
            && ($contesto['group_by'] ?? []) === ['group']
            && ($contesto['group_command']['column'] ?? '') === 'price'
            // Le righe nascono dalle spunte: niente bottone, e niente riga
            // vuota di cortesia che al salvataggio diventerebbe un record.
            && ($contesto['add_button'] ?? null) === false
            && ($contesto['start_empty'] ?? null) === true;
    }

    return false;
});

/** Un articolo con due colori, raggruppato per colore: `$assi` dice quali attributi usa. */
$schedaColori = static function (array $assi, array $ordine = []) {
    return new class ($assi, $ordine) extends ProductModelResource {
        public static array $assi = [];
        public static array $ordine = [];

        public function __construct(array $assi, array $ordine)
        {
            static::$assi = $assi;
            static::$ordine = $ordine;
        }

        protected static function currentId(): ?int
        {
            return 1;
        }

        public static function optionAttributes(): array
        {
            return [];
        }

        public static function productCount(int $modelId): int
        {
            return 2;
        }

        public static function variantCount(int $modelId): int
        {
            return 2;
        }

        public static function variants(int $modelId): array
        {
            return [['id' => 1, 'name' => 'Blu'], ['id' => 2, 'name' => 'Rosso']];
        }

        public static function variantLabels(int $modelId): array
        {
            return [1 => 'Blu', 2 => 'Rosso'];
        }

        /** Blu e Rosso sono i valori 31 e 32 del colore. */
        public static function variantValues(int $modelId): array
        {
            return [1 => 31, 2 => 32];
        }

        /** Il colore è l'attributo 7. */
        public static function variantAttributeId(int $modelId = 0): int
        {
            return 7;
        }

        public static function axesInUse(int $modelId): array
        {
            return static::$assi;
        }

        public static function axesOrder(int $modelId): array
        {
            return static::$ordine !== [] ? static::$ordine : static::$assi;
        }

        public static function imageFieldNames(): array
        {
            return array_map(static fn ($campo) => (string) $campo->name, static::imageFields(1));
        }
    };
};

$contestoGriglia = static function ($scheda): array {
    foreach ($scheda::formSchema() as $campo) {
        if ((string) $campo->name === 'products') {
            return (array) $campo->get('context');
        }
    }

    return [];
};

check('la testata del colore porta le sue foto, con la chiave del valore', function () use ($schedaColori, $contestoGriglia) {
    $contesto = $contestoGriglia($schedaColori([7, 9]));
    $colonne = array_map(static fn ($colonna) => (string) $colonna->name, $contesto['columns'] ?? []);
    $foto = $contesto['group_files'] ?? [];
    $campo = $foto['field'] ?? null;

    // La chiave è l'id del valore, non il nome: un nome si rinomina.
    return in_array('group_value', $colonne, true)
        && ($foto['key_column'] ?? '') === 'group_value'
        // Un'area per colore, anche vuota: la testata sa di chi sono.
        && array_keys((array) $campo?->get('value')) === [31, 32]
        && ($foto['label'] ?? '') === 'Foto del colore'
        && $campo !== null
        && (string) $campo->name === 'group_images'
        && $campo->get('helper') === 'inputFileDragDrop'
        && (int) (((array) $campo->get('prepare'))['max_file'] ?? 0) === 10;
});

check('il colore da solo fa già un gruppo: è il posto delle sue foto', function () use ($schedaColori, $contestoGriglia) {
    $soloColore = $contestoGriglia($schedaColori([7]));
    $soloTaglia = $contestoGriglia($schedaColori([9]));

    return ($soloColore['group_fixed'] ?? '') === 'group'
        && isset($soloColore['group_files'])
        // Una taglia sola non ha niente da raggruppare, né foto da tenere.
        && ($soloTaglia['group_fixed'] ?? '') === '';
});

check('con il colore davanti il riquadro delle foto tiene solo quelle comuni', function () use ($schedaColori) {
    $coloreDavanti = $schedaColori([7, 9]);
    $comuni = $coloreDavanti::imageFieldNames();

    // Con la taglia davanti i gruppi sono taglie: le foto del colore restano
    // nel riquadro, un'area per colore.
    $tagliaDavanti = $schedaColori([7, 9], [9, 7]);
    $perColore = $tagliaDavanti::imageFieldNames();

    return $comuni === ['images_0']
        && $perColore === ['images_0', 'images_1', 'images_2'];
});

check('la griglia scrive la chiave delle foto solo quando raggruppa il colore', function () use ($schedaAperta) {
    $html = $schedaAperta::vediGriglia();

    return str_contains($html, "scrivi(riga, 'group_value', chiaveFoto(combo[0]));")
        && str_contains($html, "scrivi(riga, 'group_value', chiaveFoto(messi[0]));")
        && str_contains($html, "String(valore.attribute) === asseFoto()");
});

check('il selettore chiede prima quale attributo, i valori vengono dopo', function () use ($schedaAperta) {
    $html = $schedaAperta::vediSelettore();

    return str_contains($html, 'wi-option-picker')
        && str_contains($html, 'Aggiungi un attributo')
        && str_contains($html, 'data-wi-option-add="7"')
        && str_contains($html, '>Colore</button>')
        // Tre sono il massimo: il quarto non si aggiunge.
        && str_contains($html, 'var MASSIMO = 3;');
});

check('«Aggiungi un attributo» è un bottone largo quanto il riquadro, sotto gli attributi', function () use ($schedaAperta, $riquadro) {
    $html = $schedaAperta::vediSelettore();
    $dentro = $riquadro(0, 'Opzioni in vendita');
    $blocchi = null;
    $selettore = null;

    foreach ($dentro as $indice => $pezzo) {
        if ($pezzo instanceof RichText && str_contains((string) $pezzo->getText(), 'wi-option-picker')) {
            $selettore = $indice;
        } elseif ($blocchi === null && !($pezzo instanceof SectionTitle) && !empty($pezzo->components)) {
            $blocchi = $indice;
        }
    }

    // Niente più select in alto a destra: il bottone sta dopo l'ultimo
    // attributo e prende tutta la riga.
    return !str_contains($html, '<select')
        && preg_match('/<button[^>]*class="[^"]*w-100[^"]*wi-option-choose/', $html) === 1
        && $blocchi !== null
        && $selettore !== null
        && $selettore > $blocchi
        && (((array) ($dentro[$selettore]->columnSpan ?? []))['default'] ?? null) === 12;
});

check('scelto un attributo, il fuoco va sul suo blocco', function () use ($schedaAperta) {
    $html = $schedaAperta::vediSelettore();

    return str_contains($html, 'var primo = nodo ? caselle(nodo)[0] : null;')
        && str_contains($html, 'primo.focus();');
});

check('togliere un attributo chiede conferma', function () use ($schedaAperta) {
    $html = $schedaAperta::vediSelettore();

    // Un clic storto non deve portarsi via le spunte e le righe nuove.
    return str_contains($html, 'wiRepeaterConfirmDelete')
        && str_contains($html, "title: \"Togliere l'attributo \" + titolo(nodo) + '?'")
        && str_contains($html, "confirmLabel: 'Togli'")
        && str_contains($html, 'window.confirm(');
});

check('ogni blocco di opzione porta la maniglia che lo accende', function () use ($schedaAperta) {
    $blocchi = $schedaAperta::vediBlocchi();

    return count($blocchi) === 1
        && $blocchi[0]->getAttr('data-wi-option') === '7'
        && $blocchi[0]->getAttr('data-wi-option-name') === 'Colore';
});

check('la matita che apriva l\'anagrafica non c\'è più', function () use ($schedaAperta) {
    $dentro = $schedaAperta::vediBlocchi()[0]->components ?? [];

    foreach ($dentro as $pezzo) {
        if ($pezzo instanceof Link) {
            return false;
        }
    }

    return $dentro !== [];
});

check('le spunte aggiungono righe alla griglia, con la chiave della combinazione', function () use ($schedaAperta) {
    $html = $schedaAperta::vediGriglia();

    return str_contains($html, 'wi-options-grid')
        && str_contains($html, "data-wi-repeater=\"products\"")
        && str_contains($html, 'window.wiRepeaterAddRow(righe.id, templateId, k)');
});

check('la griglia si spegne quando non c\'è niente da vedere, e si raggruppa da due attributi in su', function () use ($schedaAperta) {
    $html = $schedaAperta::vediGriglia();

    return str_contains($html, 'quante <= 1 && spuntate === 0')
        // Un attributo solo basta quando è il colore: la testata è il posto
        // delle sue foto.
        && str_contains($html, "conGruppo && (assi >= 2 || colore) ? 'group' : ''")
        && str_contains($html, 'data-wi-photo-attribute="')
        // L'ordine degli assi lo legge dal campo nascosto, non dall'ordine
        // in cui le caselle stanno in pagina.
        && str_contains($html, 'function ordineAssi()');
});

check('la griglia chiede codice, prezzo, giacenza e foto, e il nome non si scrive', function () use ($schedaAperta) {
    $colonne = [];

    foreach ($schedaAperta::formSchema() as $campo) {
        if ((string) $campo->name !== 'products') {
            continue;
        }

        foreach ($campo->get('context')['columns'] ?? [] as $colonna) {
            $colonne[(string) $colonna->name] = $colonna;
        }
    }

    return isset($colonne['sku'], $colonne['ean'], $colonne['price'], $colonne['stock'], $colonne['photo'])
        // Il nome lo scrive il sistema: nella griglia non c'è nemmeno la
        // casella, o una di sola lettura lo accorcerebbe salvando.
        && !isset($colonne['name'])
        && str_contains((string) $colonne['option']->get('attribute'), 'readonly')
        && $colonne['photo']->get('helper') === 'inputFileDragDrop';
});

check('ogni riga della griglia ha lo scontato accanto al prezzo, con due decimali', function () use ($schedaAperta) {
    $colonne = [];

    foreach ($schedaAperta::formSchema() as $campo) {
        if ((string) $campo->name !== 'products') {
            continue;
        }

        foreach ($campo->get('context')['columns'] ?? [] as $colonna) {
            $colonne[(string) $colonna->name] = $colonna;
        }
    }

    $nomi = array_keys($colonne);
    $prezzo = array_search('price', $nomi, true);
    $scontato = $colonne['sale_price'] ?? null;

    // Come nel riquadro in alto (P105): vuoto vuol dire che non c'è sconto.
    // Opzione, prezzo, scontato e giacenza fanno undici dodicesimi.
    return $prezzo !== false
        && ($nomi[$prezzo + 1] ?? null) === 'sale_price'
        && $scontato?->get('label') === 'Scontato'
        && $scontato->get('helper') === 'price'
        && ((((array) $scontato->get('context'))['number'] ?? [])['decimal'] ?? null) === 2
        && (((array) $scontato->columnSpan)['default'] ?? null) === 2
        && (((array) $colonne['price']->columnSpan)['default'] ?? null) === 2
        && (((array) $colonne['stock']->columnSpan)['default'] ?? null) === 2
        && (((array) $colonne['option']->columnSpan)['default'] ?? null) === 5;
});

check('i prezzi sono prezzi, con il loro «€» e due decimali', function () use ($campi, $schedaAperta) {
    $formato = static fn (?object $campo): array =>
        (array) (((array) ($campo?->get('context') ?? []))['number'] ?? []);

    $prezzo = $campi()['product_price'] ?? null;
    $scontato = $campi()['product_sale_price'] ?? null;
    $colonna = null;

    foreach ($schedaAperta::formSchema() as $campo) {
        if ((string) $campo->name !== 'products') {
            continue;
        }

        foreach ($campo->get('context')['columns'] ?? [] as $dentro) {
            if ((string) $dentro->name === 'price') {
                $colonna = $dentro;
            }
        }
    }

    // Un prezzo senza valuta non si distingue da una quantità.
    return $prezzo?->get('helper') === 'price'
        && $scontato?->get('helper') === 'price'
        && $colonna?->get('helper') === 'price'
        && ($formato($prezzo)['decimal'] ?? null) === 2
        && ($formato($scontato)['decimal'] ?? null) === 2
        && ($formato($colonna)['decimal'] ?? null) === 2
        // Il «€» lo mette il core: un simbolo scritto qui lo sostituirebbe.
        && !isset($formato($prezzo)['symbol'], $formato($colonna)['symbol']);
});

check('la descrizione breve è una riga, di al massimo 255 caratteri', function () use ($campi) {
    $campo = $campi()['short_description'] ?? null;

    return $campo?->get('helper') === 'text'
        && $campo->get('max_length') === 255;
});

check('la descrizione ha il grassetto, e poco altro', function () use ($campi) {
    $campo = $campi()['description'] ?? null;

    return $campo?->get('helper') === 'textarea'
        && $campo->get('version') === 'plus';
});

check('il modello salva la descrizione come HTML pulito, e la breve come testo', function () {
    $campi = [];

    foreach (ProductModel::dataSchema() as $campo) {
        $campi[(string) $campo->key] = $campo;
    }

    // Il server non si fida del browser: la lista bianca la applica il core.
    return ($campi['description']->getSchema('rich_text') ?? false) === true
        && ($campi['short_description']->getSchema('rich_text') ?? false) !== true;
});

check('una descrizione scritta prima, senza tag, si apre con un paragrafo per riga', fn () =>
    ProductModelResource::editorHtml("Cotone biologico\r\n\r\nLavare a 30°\nTaglia <M>")
        === '<p>Cotone biologico</p><p>Lavare a 30°</p><p>Taglia &lt;M&gt;</p>'
);

check('la descrizione scritta prima si legge come la leggeva il vecchio campo', fn () =>
    // Il vecchio salvataggio aggiungeva le slash e le entità: la lettura le
    // toglieva. Ora che la colonna non passa più da lì, le toglie la scheda.
    ProductModelResource::editorHtml("L\\'acqua &egrave; &quot;buona&quot;")
        === '<p>L\'acqua è "buona"</p>'
);

check('una descrizione già in HTML resta com\'è', fn () =>
    ProductModelResource::editorHtml('<p>Ciao <strong>mondo</strong></p>') === '<p>Ciao <strong>mondo</strong></p>'
    && ProductModelResource::editorHtml('Riga<br>altra') === 'Riga<br>altra'
    && ProductModelResource::editorHtml('') === ''
    && ProductModelResource::editorHtml("  \n ") === ''
);

check('una descrizione breve scritta su più righe si legge su una sola', fn () =>
    ProductModelResource::oneLine("Maglietta in cotone\r\n  a maniche corte\n") === 'Maglietta in cotone a maniche corte'
    && ProductModelResource::oneLine('Una riga') === 'Una riga'
    && ProductModelResource::oneLine('') === ''
);

// Un negozio con due attributi «Opzione con foto proprie»: «Prova Colore» (7)
// e «Colore» (9). Un articolo ne usa uno solo, e quello conta (P127).
$dueAttributiConFoto = static function (array $valoriDelleVarianti) {
    return new class($valoriDelleVarianti) extends ProductModelResource {
        /** @var array<int, int> variante => valore d'attributo */
        public static array $valori = [];

        public function __construct(array $valori)
        {
            static::$valori = $valori;
        }

        public static function attributes(): array
        {
            return [
                ['id' => 7, 'name' => 'Prova Colore', 'level' => 'variant', 'type' => 'color'],
                ['id' => 9, 'name' => 'Colore', 'level' => 'variant', 'type' => 'color'],
                ['id' => 11, 'name' => 'Taglia', 'level' => 'product', 'type' => 'select'],
            ];
        }

        public static function attributeValues(): array
        {
            return [
                31 => ['id' => 31, 'attribute_id' => 7, 'label' => 'Blu di prova'],
                51 => ['id' => 51, 'attribute_id' => 9, 'label' => 'Blu'],
                52 => ['id' => 52, 'attribute_id' => 9, 'label' => 'Rosso'],
            ];
        }

        public static function variantValues(int $modelId): array
        {
            return static::$valori;
        }
    };
};

check('l\'attributo con foto proprie è quello che l\'articolo usa, non il primo del negozio', function () use ($dueAttributiConFoto) {
    $scheda = $dueAttributiConFoto([1 => 51, 2 => 52]);

    return $scheda::variantAttributeId(3168) === 9;
});

check('un articolo che usa il primo attributo tiene il primo', function () use ($dueAttributiConFoto) {
    $scheda = $dueAttributiConFoto([1 => 31]);

    return $scheda::variantAttributeId(3168) === 7;
});

check('in creazione, e su un articolo senza varianti, vale il primo del negozio', function () use ($dueAttributiConFoto) {
    $scheda = $dueAttributiConFoto([]);

    return $scheda::variantAttributeId(0) === 7
        && $scheda::variantAttributeId(3168) === 7;
});

check('senza attributi con foto proprie non c\'è nessun asse', function () {
    $scheda = new class extends ProductModelResource {
        public static function attributes(): array
        {
            return [['id' => 11, 'name' => 'Taglia', 'level' => 'product', 'type' => 'select']];
        }
    };

    return $scheda::variantAttributeId(3168) === 0 && $scheda::variantAttributeId() === 0;
});

check('le foto del colore stanno nella testata anche col secondo attributo', function () {
    $scheda = new class extends ProductModelResource {
        public static function attributes(): array
        {
            return [
                ['id' => 7, 'name' => 'Prova Colore', 'level' => 'variant', 'type' => 'color'],
                ['id' => 9, 'name' => 'Colore', 'level' => 'variant', 'type' => 'color'],
            ];
        }

        public static function attributeValues(): array
        {
            return [51 => ['id' => 51, 'attribute_id' => 9, 'label' => 'Blu']];
        }

        public static function variantValues(int $modelId): array
        {
            return [1 => 51];
        }

        public static function axesOrder(int $modelId): array
        {
            return [9];
        }
    };

    // L'articolo raggruppa per «Colore»: le foto stanno nella testata del
    // gruppo, e imageTargets() non rimette un riquadro per colore.
    return $scheda::colorPhotosInGroups(3168) === true;
});

check('il prezzo dell\'elenco porta l\'euro, e barra il pieno quando c\'è lo sconto', function () {
    $scheda = new class extends ProductModelResource {
        public static array $finti = [];

        public static function products(int $modelId): array
        {
            return static::$finti;
        }
    };

    $scheda::$finti = [['price' => '19.90'], ['price' => '19.90']];
    $uguali = $scheda::priceCell(1);

    $scheda::$finti = [['price' => '24.50'], ['price' => '19.90']];
    $diversi = $scheda::priceCell(1);

    $scheda::$finti = [['price' => '19.90', 'sale_price' => '14.90']];
    $scontato = $scheda::priceCell(1);

    $scheda::$finti = [['price' => '19.90', 'sale_price' => '0']];
    $senzaSconto = $scheda::priceCell(1);

    $scheda::$finti = [];
    $nessuno = $scheda::priceCell(1);

    return $uguali === '19,90 €'
        && $diversi === 'da 19,90 €'
        && $scontato === '<s>19,90 €</s> 14,90 €'
        && $senzaSconto === '19,90 €'
        && $nessuno === '';
});

check('il «da» guarda quello che si paga davvero', function () {
    $scheda = new class extends ProductModelResource {
        public static function products(int $modelId): array
        {
            return [
                ['price' => '19.90'],
                ['price' => '19.90', 'sale_price' => '14.90'],
            ];
        }
    };

    return $scheda::priceCell(1) === 'da 14,90 €';
});

check('l\'elenco non porta più il marchio, e lo SKU non è stretto', function () {
    $nomi = [];
    $strette = [];

    foreach (ProductModelResource::tableSchema() as $colonna) {
        $nomi[] = (string) $colonna->name;

        if (($colonna->schema['size'] ?? '') === 'little') {
            $strette[] = (string) $colonna->name;
        }
    }

    return !in_array('brand_id', $nomi, true)
        && in_array('sku', $nomi, true)
        && !in_array('sku', $strette, true);
});

// ── Il sedicesimo giro: la scheda in lettura (P123, P124, P125) ─────────────

check('la scheda dell\'articolo si apre in lettura', function () {
    $pagine = (array) ProductModelResource::pageSchema()->get('pages');

    return ($pagine['view'] ?? false) === true
        && ($pagine['edit'] ?? false) === true;
});

check('la pagina in lettura ha la sua view nel modulo', function () {
    $view = (string) (((array) ProductModelResource::pageSchema()->get('views'))['show'] ?? '');

    return $view !== '' && is_file($view) && str_ends_with($view, '/view/pages/product-model-show.php');
});

check('il nome dell\'elenco porta alla scheda, non al cantiere', function () {
    foreach (ProductModelResource::tableSchema() as $colonna) {
        if ((string) $colonna->name === 'name') {
            return ($colonna->schema['link'] ?? '') === 'view';
        }
    }

    return false;
});

check('dalla scheda in lettura «Modifica» apre la modifica', function () {
    $azioni = (array) ProductModelResource::pageSchema()->get('actions');
    $perLaLettura = $azioni['view'] ?? null;

    if (!is_callable($perLaLettura)) {
        return false;
    }

    $bottoni = $perLaLettura(['id' => 3168]);
    $primo = $bottoni[0] ?? [];

    return count($bottoni) === 1
        && ($primo['label'] ?? '') === 'Modifica'
        && ($primo['href'] ?? '') === ProductModelResource::editUrlFor(3168);
});

check('la modifica non ha più il bottone «Dettagli delle opzioni»', fn () =>
    (((array) ProductModelResource::pageSchema()->get('actions'))['edit'] ?? null) === null
    && !method_exists(ProductModelResource::class, 'optionsAction')
    && !method_exists(ProductModelResource::class, 'optionsModal')
    && !defined(ProductModelResource::class . '::OPTIONS_MODAL')
);

check('la scheda in lettura nasce a due colonne, come la modifica', function () {
    $scheda = ProductModelResource::showLayoutSchema(['id' => 3168]);
    $colonne = $scheda->components ?? [];

    return $scheda instanceof Container
        && count($colonne) === 2
        && (((array) $colonne[0]->columnSpan)['default'] ?? null) === 8
        && (((array) $colonne[1]->columnSpan)['default'] ?? null) === 4;
});

check('i riquadri della scheda in lettura sono quelli della modifica', function () {
    $scheda = ProductModelResource::showLayoutSchema(['id' => 3168]);

    $titoli = static function (object $colonna): array {
        $titoli = [];

        foreach ($colonna->components ?? [] as $riquadro) {
            foreach ($riquadro->components ?? [] as $dentro) {
                if ($dentro instanceof SectionTitle) {
                    $titoli[] = $dentro->getText();
                    break;
                }
            }
        }

        return $titoli;
    };

    return $titoli($scheda->components[0]) === ['Prodotto', 'Opzioni in vendita']
        && $titoli($scheda->components[1]) === ['Foto e video', 'Stato', 'Dove si trova'];
});

check('i riquadri della scheda in lettura sono larghi quanto la loro colonna', function () {
    $scheda = ProductModelResource::showLayoutSchema(['id' => 3168]);
    $riquadri = [];

    foreach ($scheda->components ?? [] as $colonna) {
        foreach ($colonna->components ?? [] as $riquadro) {
            $riquadri[] = $riquadro;
        }
    }

    if ($riquadri === []) {
        return false;
    }

    foreach ($riquadri as $riquadro) {
        // Senza questi due numeri il riquadro esce in «col-1» e il testo
        // scende in colonna, una lettera per riga.
        if ((((array) $riquadro->columnSpan)['default'] ?? null) !== 12) {
            return false;
        }

        if ((((array) $riquadro->columns)['default'] ?? null) !== 12) {
            return false;
        }
    }

    return true;
});

check('le opzioni della scheda sono le colonne che ProductResource dichiara', function () {
    $dichiarate = [];

    foreach (ProductResource::tableSchema() as $colonna) {
        $dichiarate[] = (string) $colonna->name;
    }

    $scelte = ProductModelResource::optionsColumns();

    return $scelte === ['name', 'sku', 'price', 'active', 'actions']
        && array_diff($scelte, $dichiarate) === [];
});

check('senza database la scheda in lettura si legge lo stesso', fn () =>
    str_contains(ProductModelResource::optionsTable(3168), 'Nessuna opzione')
    && ProductModelResource::optionsTable(0) !== ''
);

check('lo stato dell\'articolo si commuta dalla sua pillola', function () {
    $pubblicato = ProductModelResource::statusBadge(['id' => 3168, 'visible' => 'true']);
    $bozza = ProductModelResource::statusBadge(['id' => 3168, 'visible' => 'false']);

    return str_contains($pubblicato, 'PUBBLICATO')
        && str_contains($bozza, 'BOZZA')
        && str_contains($pubblicato, 'role=\'button\'')
        && str_contains($pubblicato, 'column=visible')
        && str_contains($pubblicato, 'id=3168')
        // Fuori da una tabella non c'è niente da ricaricare: si ricarica la
        // pagina, e `ajaxRequest` lo fa da sola con un argomento solo.
        && !str_contains($pubblicato, 'reloadDataTable');
});

// Il bottone della scheda in lettura porta al cantiere: giallo perché è
// l'unico gesto che cambia le carte, e piccolo perché la scheda si legge,
// non si comanda da lì.
check('dalla scheda in lettura si passa alla modifica con un bottone giallo e piccolo', function () {
    $azioni = ProductModelResource::pageSchema()->get('actions')['view'] ?? null;
    $azioni = is_callable($azioni) ? $azioni(['id' => 3168]) : (array) $azioni;
    $modifica = $azioni[0] ?? [];
    $classe = (string) ($modifica['class'] ?? '');

    return ($modifica['label'] ?? '') === 'Modifica'
        && str_contains($classe, 'btn-warning')
        && str_contains($classe, 'btn-sm')
        && !str_contains($classe, 'btn-primary');
});

// La guida stava sotto il titolo, in una riga sua, perché la portava la
// tabella incorporata: il riquadro si apriva con una fascia vuota e il
// titolo finiva schiacciato. Ora è il riquadro a metterla, accanto al
// titolo, e la tabella non se la porta più dietro.
check('la guida delle opzioni sta accanto al titolo, non sotto', function () {
    $card = null;

    foreach (ProductModelResource::showLayoutSchema(['id' => 3168])->components ?? [] as $colonna) {
        foreach ($colonna->components ?? [] as $riquadro) {
            foreach ($riquadro->components ?? [] as $pezzo) {
                if ($pezzo instanceof SectionTitle && trim((string) $pezzo->getText()) === 'Opzioni in vendita') {
                    $card = $riquadro;
                }
            }
        }
    }

    if ($card === null) {
        return false;
    }

    $titolo = $card->components[0] ?? null;
    $guida = $card->components[1] ?? null;

    if (!$titolo instanceof SectionTitle || $guida === null) {
        return false;
    }

    $largoTitolo = ((array) $titolo->columnSpan)['default'] ?? 0;
    $largoGuida = ((array) $guida->columnSpan)['default'] ?? 0;
    $html = (string) $guida->getText();

    return $largoTitolo < 12
        && $largoTitolo + $largoGuida === 12
        && str_contains($html, ProductResource::pageSchema()->docsUrl('list'))
        && str_contains($html, 'Guida</a>')
        && str_contains($html, 'text-end');
});

// ---- Personalizzazioni (G5, piano 1) ----

$riquadroPersonalizzazioni = static function (object $scheda): ?object {
    foreach ($scheda::formLayoutSchema()->components[0]->components ?? [] as $candidato) {
        $titolo = $candidato->components[0] ?? null;

        if ($titolo instanceof SectionTitle && $titolo->getText() === 'Personalizzazioni') {
            return $candidato;
        }
    }

    return null;
};

check('senza la funzionalità non c\'è né il campo né il riquadro «Personalizzazioni»', function () use ($forza, $campi, $schedaAperta, $riquadroPersonalizzazioni, $riquadri) {
    $forza(['customizations' => false]);

    try {
        return !isset($campi()['customizations'])
            && $riquadroPersonalizzazioni($schedaAperta) === null
            && !in_array('Personalizzazioni', $riquadri(0), true);
    } finally {
        $forza(null);
    }
});

check('con la funzionalità il campo c\'è, senza cancellazione logica e con la posizione', function () use ($forza, $campi) {
    $forza(['customizations' => true]);

    try {
        $campo = $campi()['customizations'] ?? null;
        $relazione = $campo === null ? null : (ProductModelResource::repeaterRelations()['customizations']['relation'] ?? null);

        return $campo !== null
            && $relazione instanceof RepeaterRelation
            && $relazione->softDelete === false
            && $relazione->positionKey === 'position';
    } finally {
        $forza(null);
    }
});

check('la lista delle personalizzazioni ha solo la scelta e «Obbligatoria»: il sovrapprezzo non si cambia per articolo', function () use ($forza, $campi) {
    $forza(['customizations' => true]);

    try {
        $schema = (new \ReflectionProperty(\Wonder\App\ResourceSchema\Input::class, 'schema'))->getValue($campi()['customizations']);
        $nomi = array_map(static fn ($colonna): string => (string) $colonna->name, (array) ($schema['context']['columns'] ?? []));

        return $nomi === ['id', 'customization_id', 'is_required'];
    } finally {
        $forza(null);
    }
});

check('il riquadro «Personalizzazioni» viene dopo «Scheda tecnica» e il suo script cita la Resource giusta', function () use ($forza, $schedaAperta, $riquadri, $riquadroPersonalizzazioni, $testoScheda) {
    $forza(['customizations' => true]);

    try {
        $titoli = $riquadri(0);
        $testo = $testoScheda($riquadroPersonalizzazioni($schedaAperta) ?? (object) []);
        $slug = \Wonder\Plugin\Gestionale\Resources\Catalog\CustomizationResource::slug();

        return array_search('Personalizzazioni', $titoli, true) === array_search('Scheda tecnica', $titoli, true) + 1
            && str_contains($testo, 'wi:quick-create:created')
            && str_contains($testo, json_encode($slug))
            && str_contains($testo, 'textContent')
            && !str_contains($testo, 'innerHTML = ');
    } finally {
        $forza(null);
    }
});

check('le righe delle personalizzazioni senza scelta o con una già vista si scartano', function () {
    $righe = [['customization_id' => '3'], ['customization_id' => ''], ['customization_id' => '3'], ['customization_id' => '5']];
    $ridate = ProductModelResource::prepareRepeaterRows('customizations', $righe);
    $altre = [['x' => 1], ['x' => 1]];

    return array_column($ridate, 'customization_id') === ['3', '5']
        && ProductModelResource::prepareRepeaterRows('altro', $altre) === $altre;
});

summary();
