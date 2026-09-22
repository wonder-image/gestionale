<?php
/** php tests/ProductModelResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Elements\Components\Link;
use Wonder\Elements\Components\SectionTitle;
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

    // Due colonne più il riquadro delle opzioni, che prende la pagina intera:
    // la griglia ha sette caselle per riga e in due terzi di schermo vanno a
    // capo.
    return count($colonne) === 3
        && $titoli($colonne[0]) === ['Prodotto', 'Foto e video', 'Descrizione']
        && $titoli($colonne[1]) === ['Pubblicazione', 'Codici', 'Dove si trova', 'Spedizione']
        && ($colonne[2]->components[0] ?? null) instanceof SectionTitle
        && $colonne[2]->components[0]->getText() === 'Opzioni in vendita';
});

check('in creazione non si chiede quello che non esiste ancora', function () {
    $chiavi = array_map(
        static fn ($campo) => (string) $campo->name,
        ProductModelResource::formSchema()
    );

    // Niente giacenza da rettificare e niente foto dei singoli colori: sono
    // cose che nascono dal primo salvataggio. La griglia invece c'è, vuota:
    // le righe le aggiungono le spunte, e aggiungere e modificare devono
    // essere la stessa schermata.
    return !in_array('product_stock', $chiavi, true)
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
    // Le opzioni in vendita non stanno più qui: hanno il loro riquadro a
    // piena larghezza, in fondo.
    return $riquadri(0) === ['Prodotto', 'Foto e video', 'Descrizione'];
});

check('il riquadro delle opzioni sta in fondo, a piena larghezza', function () use ($schedaAperta) {
    $form = $schedaAperta::formLayoutSchema();
    $riquadro = ($form->components ?? [])[2] ?? null;

    return $riquadro !== null
        && (((array) ($riquadro->columnSpan ?? []))['default'] ?? null) === 12;
});

check('la colonna stretta tiene quello che si decide', function () use ($riquadri) {
    return $riquadri(1) === ['Pubblicazione', 'Codici', 'Dove si trova', 'Spedizione'];
});

check('le parole interne non compaiono più nei titoli', function () use ($riquadri) {
    $vecchie = ['Articolo', 'Varianti', 'Genera varianti e prodotti', 'Categorie e tag', 'Attributi', 'Immagini'];

    return array_intersect($riquadri(), $vecchie) === [];
});

check('la scheda chiede l\'imballaggio e dice quanto parte', function () use ($campi) {
    $chiavi = array_keys($campi());

    return in_array('package_id', $chiavi, true)
        && in_array('shipping_weight', $chiavi, true);
});

check('il peso spedito è una frase da leggere, non una colonna', function () {
    $scheda = new class extends ProductModelResource {
        public static function senzaExtra(array $values): array
        {
            return static::withoutExtras($values);
        }
    };

    $ripulito = $scheda::senzaExtra(['name' => 'Maglietta', 'shipping_weight' => '1,4 kg', 'weight' => '1.2']);

    return !isset($ripulito['shipping_weight']) && ($ripulito['weight'] ?? '') === '1.2';
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

check('ogni area di foto guarda solo la sua fetta', function () use ($schedaAperta) {
    foreach ($schedaAperta::formSchema() as $campo) {
        if ((string) $campo->name !== 'images_0') {
            continue;
        }

        $relazione = ($campo->get('context')['relation'] ?? null);

        // `product_id` nullo tiene fuori le foto delle singole opzioni: senza,
        // salvando quest'area il core le cancellerebbe, non trovandole fra le
        // righe postate.
        return $relazione !== null
            && $relazione->condition === ['product_variant_id' => null, 'product_id' => null];
    }

    return false;
});

check('le versioni si raggruppano, e il gruppo ha il suo prezzo', function () {
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

        // Il raggruppamento non si sceglie: è il colore, e basta.
        return in_array('variant', $colonne, true)
            && ($contesto['group_fixed'] ?? '') === 'variant'
            && ($contesto['group_by'] ?? []) === ['variant']
            && ($contesto['group_command']['column'] ?? '') === 'price'
            // Le righe nascono dalle spunte: niente bottone, e niente riga
            // vuota di cortesia che al salvataggio diventerebbe un record.
            && ($contesto['add_button'] ?? null) === false
            && ($contesto['start_empty'] ?? null) === true;
    }

    return false;
});

check('il selettore chiede prima quale attributo, i valori vengono dopo', function () use ($schedaAperta) {
    $html = $schedaAperta::vediSelettore();

    return str_contains($html, 'wi-option-picker')
        && str_contains($html, 'Aggiungi un attributo')
        && str_contains($html, '>Colore</option>')
        // Tre sono il massimo: il quarto non si aggiunge.
        && str_contains($html, 'var MASSIMO = 3;');
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

check('la griglia si spegne quando non c\'è niente da vedere, e si raggruppa quando c\'è un colore', function () use ($schedaAperta) {
    $html = $schedaAperta::vediGriglia();

    return str_contains($html, 'quante <= 1 && spuntate === 0')
        && str_contains($html, "wiRepeaterGroupApply(righe.id, box.id + '-group-template', conColore ? 'variant' : '')");
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

summary();
