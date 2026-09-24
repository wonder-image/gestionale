<?php
/** php tests/ProductModelResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\ResourceSchema\Inputs\InputCheckbox;
use Wonder\App\ResourceSchema\Inputs\InputNumber;
use Wonder\App\ResourceSchema\Inputs\InputText;
use Wonder\Elements\Components\Link;
use Wonder\Elements\Components\QuickCreateButton;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeValueResource;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

$campi = static function (): array {
    $campi = [];

    foreach (ProductModelResource::formSchema() as $field) {
        $campi[(string) $field->name] = $field;
    }

    return $campi;
};

check('la pagina dei prodotti sta nel catalogo', fn () =>
    ProductModelResource::$model === ProductModel::class
    && ProductModelResource::path() === 'app/gestionale/prodotti'
    && ProductModelResource::titleLabel() === 'Prodotti'
    && (ProductModelResource::navigationSchema()->toArray()['section_key'] ?? '') === 'catalogo'
);

check('il magazzino non si vede ancora', function () use ($campi) {
    // G2a.6: le colonne esistono sul prodotto, ma non si chiedono a nessuno
    // finché non arrivano giacenze (G2b) e vendita senza giacenza (G4).
    foreach (['min_stock_quantity', 'allow_backorder', 'backorder_lead_days'] as $chiave) {
        if (isset($campi()[$chiave])) {
            return false;
        }
    }

    return true;
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

check('i campi che non sono colonne non finiscono nella query', function () {
    $valori = ProductModelResource::mutateRequestValues([
        'name' => 'Maglietta',
        'categories' => ['1', '2'],
        'main_category' => '1',
        'tags' => ['3'],
        'attribute_7' => 'Cotone',
        'product_ean' => '',
        'product_price' => '19,90',
    ], 'store');

    foreach (['categories', 'main_category', 'tags', 'attribute_7', 'product_ean', 'product_price'] as $chiave) {
        if (isset($valori[$chiave])) {
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

check('il prezzo si legge come intervallo solo quando serve', function () {
    $scheda = new class extends ProductModelResource {
        public static array $finti = [];

        public static function products(int $modelId): array
        {
            return static::$finti;
        }
    };

    $scheda::$finti = [['price' => '19.90'], ['price' => '19.90']];
    $uguali = $scheda::priceRange(1);

    $scheda::$finti = [['price' => '24.50'], ['price' => '19.90']];
    $diversi = $scheda::priceRange(1);

    $scheda::$finti = [];
    $nessuno = $scheda::priceRange(1);

    return $uguali === '19,90' && $diversi === 'da 19,90' && $nessuno === '';
});

/**
 * I titoli dei riquadri di una scheda aperta, nell'ordine in cui stanno.
 *
 * Fuori da una richiesta `currentId()` è nullo e la scheda mostra la
 * creazione: qui si finge un articolo già salvato, con una versione sola.
 */
$schedaAperta = new class extends ProductModelResource {
    protected static function currentId(): ?int
    {
        return 1;
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

check('senza varianti SKU ed EAN stanno sotto prezzo e scontato', function () use ($riquadro) {
    $nomi = [];
    $larghezze = [];

    foreach ($riquadro(0, 'Prodotto') as $pezzo) {
        if (property_exists($pezzo, 'name') && (string) $pezzo->name !== '') {
            $nomi[] = (string) $pezzo->name;
            $larghezze[(string) $pezzo->name] = ((array) ($pezzo->columnSpan ?? []))['default'] ?? null;
        }
    }

    $prezzo = array_search('product_price', $nomi, true);
    $sku = array_search('sku', $nomi, true);
    $ean = array_search('product_ean', $nomi, true);

    if ($prezzo === false || $sku === false || $ean !== $sku + 1) {
        return false;
    }

    // Subito dopo la riga del prezzo (giacenza e scorta minima comprese), e
    // larghi come le due caselle sopra: il codice cade sotto il prezzo,
    // l'EAN sotto lo scontato.
    $riga = array_slice($nomi, $prezzo, $sku - $prezzo);
    $attesa = array_values(array_filter(
        ['product_price', 'product_sale_price', 'product_stock', 'product_min_stock'],
        static fn ($chiave) => in_array($chiave, $nomi, true)
    ));

    return $riga === $attesa
        && $larghezze['sku'] === $larghezze['product_price']
        && $larghezze['product_ean'] === $larghezze['product_sale_price'];
});

check('SKU ed EAN spariscono quando l\'articolo ha varianti', function () use ($riquadro) {
    $regole = [];

    // I campi che il layout mette davvero in «Prodotto», non quelli
    // dichiarati: la regola deve viaggiare con il clone.
    foreach ($riquadro(0, 'Prodotto') as $pezzo) {
        if (property_exists($pezzo, 'name') && in_array((string) $pezzo->name, ['sku', 'product_ean'], true)) {
            $regole[(string) $pezzo->name] = $pezzo->conditionalAttributes();
        }
    }

    $regola = [
        'data-hidden-when' => 'has_variants',
        'data-hidden-when-values' => 'true',
    ];

    return $regole === ['sku' => $regola, 'product_ean' => $regola];
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

/** I pezzi di un riquadro, trovati per nome del campo o per classe. */
$dentroScheda = static function (object $riquadro, string $cosa): array {
    return array_values(array_filter(
        (array) ($riquadro->components ?? []),
        static fn ($pezzo): bool => $pezzo instanceof $cosa || (isset($pezzo->name) && $pezzo->name === $cosa)
    ));
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

check('testo e numero si scrivono, a mezza riga e con l\'unità', function () use ($schedaTecnica, $dentroScheda) {
    $riquadro = $schedaTecnica::vediScheda();
    $testo = $dentroScheda($riquadro, 'attribute_32')[0] ?? null;
    $numero = $dentroScheda($riquadro, 'attribute_33')[0] ?? null;

    return $testo instanceof InputText
        && $numero instanceof InputNumber
        && $numero->get('label') === 'Spessore (mm)'
        && (($testo->columnSpan ?? [])['default'] ?? null) === 6
        && (($numero->columnSpan ?? [])['default'] ?? null) === 6;
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
        public static function variantAttributeId(): int
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

summary();
