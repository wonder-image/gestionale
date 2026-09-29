<?php
/** php tests/ProductResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\Inputs\InputButton;
use Wonder\App\ResourceSchema\Inputs\InputHidden;
use Wonder\App\ResourceSchema\Inputs\InputPrice;
use Wonder\App\ResourceSchema\Inputs\InputText;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\Modal;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;

$campi = static function (): array {
    $campi = [];

    foreach (ProductResource::formSchema() as $field) {
        $campi[(string) $field->name] = $field;
    }

    return $campi;
};

// Le funzionalità si forzano riscrivendo la mappa memoizzata; `null` la fa
// ricalcolare, e senza database è tutto bloccato.
$forza = static function (?array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
};

// I riquadri della scheda, nell'ordine in cui si leggono: prima la colonna
// larga del lavoro di tutti i giorni, poi quella dei codici.
$colonne = static function (string $scheda = ProductResource::class): array {
    $riquadri = [];

    foreach ($scheda::formLayoutSchema()->components ?? [] as $colonna) {
        foreach ($colonna->components ?? [] as $riquadro) {
            $riquadri[] = $riquadro;
        }
    }

    return $riquadri;
};

// I titoli dei riquadri della scheda, nell'ordine in cui stanno.
$riquadri = static function () use ($colonne): array {
    $titoli = [];

    foreach ($colonne() as $riquadro) {
        foreach ($riquadro->components ?? [] as $dentro) {
            if ($dentro instanceof SectionTitle) {
                $titoli[] = $dentro->getText();
                break;
            }
        }
    }

    return $titoli;
};

// Il riquadro con quel titolo, e quello che c'è dentro: il tooltip del
// titolo, le caselle con la loro larghezza, i testi.
$riquadro = static function (string $titolo, string $scheda = ProductResource::class) use ($colonne): ?array {
    foreach ($colonne($scheda) as $riquadro) {
        $trovato = false;
        $letto = ['tooltip' => '', 'campi' => [], 'testi' => []];

        foreach ($riquadro->components ?? [] as $dentro) {
            if ($dentro instanceof SectionTitle) {
                $trovato = $dentro->getText() === $titolo;
                $letto['tooltip'] = (string) ($dentro->getSchema()['tooltip'] ?? '');
            } elseif ($dentro instanceof Input) {
                $letto['campi'][(string) $dentro->name] = ((array) $dentro->columnSpan)['default'] ?? null;
            } elseif ($dentro instanceof RichText) {
                $letto['testi'][] = [(string) ($dentro->getSchema()['tag'] ?? 'p'), (string) $dentro->getText()];
            }
        }

        if ($trovato) {
            return $letto;
        }
    }

    return null;
};

// Le sedi mostrate si forzano come le funzionalità: `shown()` è memoizzata,
// e senza database non ce n'è nessuna. Con due o più sedi la scheda cambia.
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

check('la singola opzione non sta nel menu', fn () =>
    ProductResource::$model === Product::class
    // L'indirizzo resta quello di sempre: cambiarlo romperebbe i link già
    // salvati e la rotta dello store API del "+ Aggiungi".
    && ProductResource::path() === 'app/gestionale/versioni'
    && ProductResource::titleLabel() === 'Opzioni in vendita'
    // Ci si arriva dalla scheda del prodotto, non dal menu: un elenco piatto
    // di quello che si vende avrà senso con le giacenze.
    && (ProductResource::navigationSchema()->toArray()['enabled'] ?? true) === false
);

check('la pagina parla di opzioni, non di versioni', function () {
    $titoli = (array) (ProductResource::pageSchema()->toArray()['titles'] ?? []);

    return ($titoli['list'] ?? '') === 'Opzioni in vendita'
        && ($titoli['edit'] ?? '') === 'Modifica opzione'
        && (ProductResource::textSchema()['plural_label'] ?? '') === 'opzioni'
        && (ProductResource::labelSchema()['name'] ?? '') === 'Opzione';
});

check('l\'elenco si filtra sull\'articolo', function () {
    $_GET['prodotto'] = '7';
    $condizione = (array) (ProductResource::querySchema()['condition'] ?? []);
    unset($_GET['prodotto']);

    return ($condizione['product_model_id'] ?? null) === 7;
});

check('senza articolo nell\'indirizzo si vede tutto', function () {
    unset($_GET['prodotto']);

    return !isset(ProductResource::querySchema()['condition']['product_model_id']);
});

check('un prodotto non si crea da qui', function () {
    $pagine = (array) (ProductResource::pageSchema()->toArray()['pages'] ?? []);

    return ($pagine['create'] ?? true) === false
        && ($pagine['store'] ?? true) === false
        && ($pagine['delete'] ?? true) === false
        && ($pagine['edit'] ?? false) === true
        && ($pagine['list'] ?? false) === true;
});

check('a funzionalità spente la scheda non ha soglia, righe per sede, vendita senza giacenza né fornitori', function () use ($campi, $forza) {
    $forza(['low_stock_alerts' => false, 'backorders' => false, 'purchasing' => false]);

    try {
        $trovati = array_intersect(
            ['min_stock', 'locations', 'allow_backorder', 'backorder_lead_days', 'suppliers', 'suppliers_button', 'supplier_sku', 'supplier_cost'],
            array_keys($campi())
        );
    } finally {
        $forza(null);
    }

    return $trovati === [];
});

check('con gli acquisti la scheda ha il riquadro «Fornitori», dopo il magazzino', function () use ($campi, $forza, $riquadri) {
    $forza(['purchasing' => true]);

    try {
        $con = $riquadri();
        $nomi = array_keys($campi());
    } finally {
        $forza(['purchasing' => false]);
    }

    try {
        $senza = $riquadri();
    } finally {
        $forza(null);
    }

    // Senza database non ci sono attributi: niente riquadro «Attributi». E
    // nessun fornitore da proporre: il riquadro c'è, i campi no.
    return $con === ['Prodotto', 'Magazzino', 'Fornitori', 'Identificazione']
        && $senza === ['Prodotto', 'Magazzino', 'Identificazione']
        && array_intersect(['suppliers', 'suppliers_button', 'supplier_sku', 'supplier_cost'], $nomi) === [];
});

// Una scheda che propone quei fornitori, con quei legami già salvati: senza
// database non ce ne sarebbe nessuno.
$conFornitori = new class extends ProductResource {
    /** @var array<int, string> i fornitori che la scheda propone, id => nome */
    public static array $fornitori = [];

    /** @var list<array<string, mixed>> i fornitori già legati all'opzione */
    public static array $legami = [];

    protected static function supplierChoices(int $productId): array
    {
        return static::$fornitori;
    }

    protected static function supplierLinks(int $productId): array
    {
        return static::$legami === [] ? [] : [$productId => static::$legami];
    }

    protected static function inactiveSupplierIds(int $productId): array
    {
        return [];
    }
};

$dueFornitori = [5 => 'Filati Nord', 6 => 'Imballaggi Sud'];
$tooltipFornitori = 'Da chi si compra questa opzione: il codice che le dà il fornitore e il costo d\'acquisto. Un costo lasciato vuoto vuol dire «non lo so», non zero.';

/**
 * Il riquadro «Fornitori», i suoi campi, le finestre e lo script della
 * scheda con quei fornitori da proporre.
 *
 * @param array<int, string> $fornitori
 * @return array{riquadro: array<string, mixed>, campi: array<string, Input>, modali: list<Modal>, script: string}
 */
$schedaCon = static function (array $fornitori) use ($conFornitori, $forza, $riquadro): array {
    $conFornitori::$fornitori = $fornitori;
    $forza(['purchasing' => true]);

    try {
        $campi = [];
        $modali = [];
        $script = '';

        foreach ($conFornitori::formSchema() as $field) {
            $campi[(string) $field->name] = $field;
        }

        foreach ($conFornitori::formLayoutSchema()->components[0]->components ?? [] as $pezzo) {
            if ($pezzo instanceof Modal) {
                $modali[] = $pezzo;
            } elseif ($pezzo instanceof RichText && str_contains((string) $pezzo->getText(), 'wiProductSuppliers')) {
                $script .= (string) $pezzo->getText();
            }
        }

        return [
            'riquadro' => $riquadro('Fornitori', $conFornitori::class) ?? [],
            'campi' => $campi,
            'modali' => $modali,
            'script' => $script,
        ];
    } finally {
        $forza(null);
        $conFornitori::$fornitori = [];
    }
};

check('senza fornitori da proporre il riquadro dice dove aggiungerli', function () use ($schedaCon, $tooltipFornitori) {
    $vuoto = $schedaCon([]);
    $pieno = $schedaCon([5 => 'Filati Nord']);

    return ($vuoto['riquadro']['tooltip'] ?? '') === $tooltipFornitori
        && ($vuoto['riquadro']['campi'] ?? null) === []
        && array_column($vuoto['riquadro']['testi'] ?? [], 0) === ['p']
        && str_contains($vuoto['riquadro']['testi'][0][1] ?? '', 'Anagrafiche → Fornitori')
        && $vuoto['modali'] === []
        && $vuoto['script'] === ''
        && ($pieno['riquadro']['testi'] ?? null) === [];
});

check('con un fornitore solo il riquadro chiede codice e costo, con il suo nome nel tooltip', function () use ($schedaCon, $tooltipFornitori) {
    $letto = $schedaCon([5 => 'Filati Nord']);
    $codice = $letto['campi']['supplier_sku'] ?? null;
    $costo = $letto['campi']['supplier_cost'] ?? null;

    // Niente tendina del fornitore né finestra (P112): è quello, e lo dice
    // il tooltip dei due campi.
    return $codice instanceof InputText
        && $costo instanceof InputPrice
        && $codice->get('label') === 'Codice fornitore'
        && $codice->get('max_length') === 100
        && str_contains((string) $codice->get('attribute'), 'title="Filati Nord, l\'unico fornitore"')
        && $costo->get('label') === 'Costo d\'acquisto'
        && ((((array) $costo->get('context'))['number'] ?? [])['decimal'] ?? null) === 2
        && str_contains((string) $costo->get('attribute'), 'title="Filati Nord, l\'unico fornitore"')
        // Qui le varianti non si accendono: i campi si vedono sempre.
        && $codice->conditionalAttributes() === []
        && $costo->conditionalAttributes() === []
        && !isset($letto['campi']['suppliers'], $letto['campi']['suppliers_button'])
        && ($letto['riquadro']['tooltip'] ?? '') === $tooltipFornitori
        && ($letto['riquadro']['campi'] ?? null) === ['supplier_sku' => 4, 'supplier_cost' => 4]
        && $letto['modali'] === []
        && $letto['script'] === '';
});

check('con più fornitori il riquadro ha il bottone che apre la finestra, senza «Salva per tutte le opzioni»', function () use ($schedaCon, $dueFornitori, $tooltipFornitori) {
    $letto = $schedaCon($dueFornitori);
    $nascosto = $letto['campi']['suppliers'] ?? null;
    $bottone = $letto['campi']['suppliers_button'] ?? null;
    $finestra = $letto['modali'][0] ?? null;

    if (count($letto['modali']) !== 1 || !$finestra instanceof Modal) {
        return false;
    }

    $righe = count(array_filter($finestra->components, static fn ($pezzo) => $pezzo instanceof Container));
    $bottoni = array_map(static fn (Button $bottone) => $bottone->getLabel(), $finestra->footer);
    $attributi = array_map(static fn (Button $bottone) => (array) $bottone->getSchema('attributes'), $finestra->footer);

    return $nascosto instanceof InputHidden
        && $bottone instanceof InputButton
        && $bottone->get('label') === 'Fornitori'
        && str_contains((string) $bottone->get('attribute'), 'data-bs-target="#wi-product-suppliers"')
        && (((array) $bottone->get('context'))['empty_caption'] ?? null) === 'Nessun fornitore'
        && $bottone->conditionalAttributes() === []
        && !isset($letto['campi']['supplier_sku'], $letto['campi']['supplier_cost'])
        && ($letto['riquadro']['tooltip'] ?? '') === $tooltipFornitori
        // Il campo nascosto non occupa posto: la larghezza è quella che il core dà a tutti.
        && ($letto['riquadro']['campi'] ?? null) === ['suppliers' => 1, 'suppliers_button' => 12]
        && ($letto['riquadro']['testi'] ?? null) === []
        && $finestra->getSchema('id') === 'wi-product-suppliers'
        && $finestra->getTitle() === 'Fornitori'
        // Lo stesso fornitore non si scrive due volte: due fornitori, due righe.
        && $righe === 2
        // La scheda è di un'opzione sola: non c'è nessun'altra a cui copiarli.
        && $bottoni === ['Aggiungi fornitore', 'Annulla', 'Salva']
        && array_key_exists('data-wi-supplier-save', $attributi[2] ?? [])
        && str_contains($letto['script'], 'window.wiProductSuppliers')
        && str_contains($letto['script'], 'Imballaggi Sud');
});

check('i fornitori letti per un\'opzione non finiscono fra quelli dell\'articolo con lo stesso id', function () use ($forza) {
    $scheda = new class extends ProductResource {
        public static function leggi(int $productId): array
        {
            return [
                static::supplierLinks($productId),
                static::supplierChoices($productId),
                static::inactiveSupplierIds($productId),
            ];
        }
    };

    $forza(['purchasing' => true]);
    ProductResource::forgetCatalogCache();

    try {
        $letti = $scheda::leggi(7);
    } finally {
        $forza(null);
    }

    // Le cache del genitore hanno per chiave l'id dell'articolo: l'opzione 7
    // non è l'articolo 7.
    foreach (['supplierLinks', 'supplierChoices', 'inactiveSuppliers'] as $nome) {
        if (array_key_exists(7, (array) (new ReflectionProperty(ProductModelResource::class, $nome))->getValue())) {
            return false;
        }
    }

    // Senza database: nessun legame, nessuno da proporre.
    return $letti === [[], [], []];
});

// La chiave dell'errore con cui la scheda rifiuta i fornitori arrivati, ''
// se li accetta.
$rifiutoFornitori = static function (array $post, ?array $fornitori = null, bool $acquisti = true) use ($forza, $conFornitori, $dueFornitori): string {
    $conFornitori::$fornitori = $fornitori ?? $dueFornitori;
    $forza(['purchasing' => $acquisti]);
    $prima = $_POST;
    $_POST = $post + $_POST;

    try {
        $conFornitori::mutateRequestValues(['sku' => 'X-1'] + $post, 'update', 'backend', ['id' => 1]);
    } catch (UserError $errore) {
        return $errore->key().(str_contains($errore->getMessage(), '{{') ? ' con segnaposto' : '');
    } finally {
        $_POST = $prima;
        $conFornitori::$fornitori = [];
        $forza(null);
    }

    return '';
};

check('un fornitore che la scheda non propone viene rifiutato', fn () =>
    $rifiutoFornitori(['suppliers' => json_encode([['supplier_id' => 9, 'supplier_sku' => '', 'cost' => '1,00']])])
        === 'product.supplier_invalid'
);

check('un codice senza fornitore viene rifiutato', fn () =>
    $rifiutoFornitori(['suppliers' => json_encode([['supplier_id' => '', 'supplier_sku' => 'FN-12', 'cost' => '']])])
        === 'product.supplier_missing'
);

check('lo stesso fornitore due volte viene rifiutato, con il suo nome', function () use ($forza, $conFornitori, $dueFornitori) {
    $conFornitori::$fornitori = $dueFornitori;
    $forza(['purchasing' => true]);
    $_POST['suppliers'] = json_encode([
        ['supplier_id' => 5, 'supplier_sku' => '', 'cost' => '1,00'],
        ['supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => ''],
    ]);

    try {
        $conFornitori::mutateRequestValues(['sku' => 'X-1'], 'update', 'backend', ['id' => 1]);
    } catch (UserError $errore) {
        return $errore->key() === 'product.supplier_duplicate'
            && str_contains($errore->getMessage(), 'Filati Nord');
    } finally {
        unset($_POST['suppliers']);
        $conFornitori::$fornitori = [];
        $forza(null);
    }

    return false;
});

check('righe corrette e righe vuote passano', fn () =>
    $rifiutoFornitori(['suppliers' => json_encode([
        ['supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => '12,50'],
        ['supplier_id' => 6, 'supplier_sku' => '', 'cost' => ''],
        // Una riga aggiunta e lasciata lì.
        ['supplier_id' => '', 'supplier_sku' => '', 'cost' => ''],
    ])]) === ''
    // Una finestra svuotata stacca tutti: è una scelta, non un errore.
    && $rifiutoFornitori(['suppliers' => '[]']) === ''
);

check('con un fornitore solo si controllano i due campi', fn () =>
    $rifiutoFornitori(['supplier_sku' => 'FN-12', 'supplier_cost' => 'abc'], [5 => 'Filati Nord']) === 'product.supplier_cost_invalid'
    && $rifiutoFornitori(['supplier_sku' => str_repeat('x', 101), 'supplier_cost' => ''], [5 => 'Filati Nord']) === 'product.supplier_sku_too_long'
    && $rifiutoFornitori(['supplier_sku' => 'FN-12', 'supplier_cost' => '12,50'], [5 => 'Filati Nord']) === ''
    && $rifiutoFornitori(['supplier_sku' => '', 'supplier_cost' => ''], [5 => 'Filati Nord']) === ''
);

check('i due campi compilati quando il fornitore unico non c\'è più vengono rifiutati', fn () =>
    // La scheda è stata aperta con un fornitore, e nel frattempo ne è nato
    // un altro: di chi siano codice e costo non si sa più.
    $rifiutoFornitori(['supplier_sku' => 'FN-12', 'supplier_cost' => ''])
        === 'product.supplier_invalid'
    && $rifiutoFornitori(['supplier_sku' => 'FN-12', 'supplier_cost' => ''], []) === 'product.supplier_invalid'
    // Vuoti non dicono niente.
    && $rifiutoFornitori(['supplier_sku' => '', 'supplier_cost' => '']) === ''
);

check('ad acquisti spenti i fornitori non si guardano: il riquadro non c\'è', fn () =>
    $rifiutoFornitori(['suppliers' => json_encode([['supplier_id' => 9, 'cost' => '1,00']])], null, false) === ''
    && $rifiutoFornitori(['supplier_sku' => 'FN-12', 'supplier_cost' => 'abc'], null, false) === ''
);

check('i campi dei fornitori non arrivano alle colonne dell\'opzione', function () use ($forza, $conFornitori, $dueFornitori) {
    $arrivati = [
        'suppliers' => '[]',
        'suppliers_button' => '',
        'supplier_sku' => '',
        'supplier_cost' => '',
        'wi_product_supplier' => [['supplier_id' => '5', 'sku' => '', 'cost' => '']],
        'wi_product_supplier_remove' => '',
    ];
    $resto = [];

    // Anche ad acquisti spenti: un form aperto prima di bloccarli li manda lo stesso.
    foreach ([true, false] as $acquisti) {
        $conFornitori::$fornitori = $dueFornitori;
        $forza(['purchasing' => $acquisti]);

        try {
            $valori = $conFornitori::mutateRequestValues(['sku' => 'X-1'] + $arrivati, 'update', 'backend', ['id' => 1]);
        } finally {
            $conFornitori::$fornitori = [];
            $forza(null);
        }

        $resto[] = array_intersect(array_keys($arrivati), array_keys($valori));
    }

    return $resto === [[], []];
});

check('i fornitori arrivano al form: due campi con un fornitore, JSON e riassunto con più', function () use ($forza, $conFornitori, $dueFornitori) {
    $leggi = static function (array $valori, array $fornitori, array $legami, bool $acquisti = true) use ($forza, $conFornitori): array {
        $conFornitori::$fornitori = $fornitori;
        $conFornitori::$legami = $legami;
        $forza(['purchasing' => $acquisti]);

        try {
            // Senza id la scheda non legge gli attributi: qui non c'è database.
            return $conFornitori::mutateFormValues($valori, 'edit');
        } finally {
            $conFornitori::$fornitori = [];
            $conFornitori::$legami = [];
            $forza(null);
        }
    };

    $legami = [
        ['supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => 12.3456],
        ['supplier_id' => 6, 'supplier_sku' => '', 'cost' => null],
    ];

    $due = $leggi(['sku' => 'X-1'], [5 => 'Filati Nord'], [$legami[0]]);
    $vuoti = $leggi(['sku' => 'X-1'], [5 => 'Filati Nord'], []);
    // Dopo un salvataggio rifiutato quello scritto resta.
    $scritti = $leggi(['supplier_sku' => 'X', 'supplier_cost' => ''], [5 => 'Filati Nord'], [$legami[0]]);
    $finestra = $leggi(['sku' => 'X-1'], $dueFornitori, $legami);
    $nessuno = $leggi(['sku' => 'X-1'], $dueFornitori, []);
    $tenuto = $leggi(['suppliers' => '[{"supplier_id":6,"supplier_sku":"IS-1","cost":3}]'], $dueFornitori, $legami);
    $senza = $leggi(['sku' => 'X-1'], [], []);
    $spenti = $leggi(['sku' => 'X-1'], $dueFornitori, $legami, false);

    // Il numero grezzo, con il punto: le cifre le mette la casella del prezzo.
    return $due === ['sku' => 'X-1', 'supplier_sku' => 'FN-12', 'supplier_cost' => '12.35']
        && $vuoti === ['sku' => 'X-1', 'supplier_sku' => '', 'supplier_cost' => '']
        && $scritti === ['supplier_sku' => 'X', 'supplier_cost' => '']
        && json_decode((string) ($finestra['suppliers'] ?? ''), true) === [
            ['supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => '12.35'],
            ['supplier_id' => 6, 'supplier_sku' => '', 'cost' => ''],
        ]
        && str_contains((string) ($finestra['suppliers_button'] ?? ''), 'Filati Nord')
        && str_contains((string) ($finestra['suppliers_button'] ?? ''), 'Imballaggi Sud')
        && ($nessuno['suppliers'] ?? null) === '[]'
        && ($nessuno['suppliers_button'] ?? null) === 'Nessun fornitore'
        && ($tenuto['suppliers'] ?? null) === '[{"supplier_id":6,"supplier_sku":"IS-1","cost":3}]'
        && str_contains((string) ($tenuto['suppliers_button'] ?? ''), 'Imballaggi Sud')
        && !str_contains((string) ($tenuto['suppliers_button'] ?? ''), 'Filati Nord')
        && $senza === ['sku' => 'X-1']
        && $spenti === ['sku' => 'X-1'];
});

check('prodotto e opzione nell\'elenco si leggono, non sono numeri', function () {
    $colonne = [];

    foreach (ProductResource::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column->toArray()['formatter'] ?? null;
    }

    return $colonne['product_model_id'] instanceof Closure
        && $colonne['name'] instanceof Closure;
});

check('un EAN storto si ferma con una frase', function () {
    try {
        ProductResource::mutateRequestValues(['ean' => '12345'], 'update', 'backend', ['id' => 1]);
    } catch (UserError $errore) {
        return str_contains($errore->getMessage(), '8 o 13');
    }

    return false;
});

check('i prezzi arrivano al database con il punto', function () {
    $valori = ProductResource::mutateRequestValues(
        ['price' => '19,90', 'sale_price' => '1.234,50', 'weight' => ''],
        'update',
        'backend',
        ['id' => 1]
    );

    return $valori['price'] === '19.90'
        && $valori['sale_price'] === '1234.50'
        && $valori['weight'] === null;
});

check('i campi degli attributi non finiscono nella query', function () {
    $valori = ProductResource::mutateRequestValues(
        ['sku' => 'X-1', 'attribute_7' => '12'],
        'update',
        'backend',
        ['id' => 1]
    );

    return !isset($valori['attribute_7']) && ($valori['sku'] ?? '') === 'X-1';
});

check('con una sede sola il riquadro Magazzino dice dove si scrive la giacenza, e tiene la scorta minima', function () use ($forza, $riquadro) {
    $leggi = static function (bool $avvisi) use ($forza, $riquadro): array {
        $forza(['low_stock_alerts' => $avvisi]);

        try {
            return $riquadro('Magazzino') ?? [];
        } finally {
            $forza(null);
        }
    };

    $con = $leggi(true);
    $senza = $leggi(false);

    return str_starts_with($con['tooltip'] ?? '', 'Qui la giacenza si legge. Si scrive nella riga della griglia delle opzioni in vendita')
        && str_contains($con['tooltip'] ?? '', 'La scorta minima è la soglia sotto cui arriva l\'avviso, e con zero non arriva niente.')
        && ($con['campi'] ?? null) === ['min_stock' => 4]
        && str_starts_with($senza['tooltip'] ?? '', 'Qui la giacenza si legge.')
        && !str_contains($senza['tooltip'] ?? '', 'scorta minima')
        && ($senza['campi'] ?? null) === []
        // Sotto restano il riassunto (vuoto senza id) e gli ultimi movimenti.
        && count($con['testi'] ?? []) === 2
        && str_contains($con['testi'][1][1] ?? '', 'Nessun movimento');
});

check('con due sedi la scorta minima lascia il posto alle righe «Giacenza per sede»', function () use ($conSedi, $riquadro, $campi, $dueSedi) {
    $leggi = static function (bool $avvisi) use ($conSedi, $riquadro, $campi, $dueSedi): array {
        return $conSedi($dueSedi, ['low_stock_alerts' => $avvisi], static function () use ($riquadro, $campi): array {
            $tutti = $campi();
            $repeater = $tutti['locations'] ?? null;
            $contesto = (array) ($repeater?->get('context') ?? []);
            $colonne = [];

            foreach ((array) ($contesto['columns'] ?? []) as $colonna) {
                $numero = (array) (((array) $colonna->get('context'))['number'] ?? []);
                $colonne[(string) $colonna->name] = [
                    $colonna->get('label'),
                    ((array) $colonna->columnSpan)['default'] ?? null,
                    $numero['decimal'] ?? null,
                    $numero['symbol'] ?? null,
                ];
            }

            return [
                'riquadro' => $riquadro('Magazzino') ?? [],
                'soglia' => array_key_exists('min_stock', $tutti),
                'label' => $repeater?->get('label'),
                'helper' => $repeater?->get('helper'),
                'colonne' => $colonne,
                'sedi' => (array) ($contesto['columns'][0] ?? null)?->get('options'),
                'contesto' => $contesto,
            ];
        });
    };

    $con = $leggi(true);
    $senza = $leggi(false);

    return str_starts_with($con['riquadro']['tooltip'] ?? '', 'Una riga per sede: la giacenza scritta diventa una rettifica su quella sede, vuota non tocca niente.')
        && str_contains($con['riquadro']['tooltip'] ?? '', 'La scorta minima è la soglia sotto cui arriva l\'avviso per quella sede, e con zero non arriva niente.')
        && ($con['riquadro']['campi'] ?? null) === ['locations' => 12]
        && $con['soglia'] === false
        && $con['label'] === 'Giacenza per sede'
        && $con['helper'] === 'inputRepeater'
        // Undici dodicesimi: il dodicesimo è del cestino, come nella scheda
        // dell'articolo. Con dodici il cestino va a capo.
        && $con['colonne'] === [
            'location_id' => ['Sede', 5, null, null],
            // Senza un articolo da leggere l'unità è «pz», come la select.
            'stock' => ['Giacenza', 3, 0, ' pz'],
            'min_stock' => ['Scorta minima', 3, 0, ' pz'],
        ]
        // Il «—» per primo: una riga lasciata lì non scrive su nessuna sede.
        && $con['sedi'] === ['' => '—', 1 => 'Milano', 2 => 'Roma']
        && ($con['contesto']['add_label'] ?? null) === 'Aggiungi sede'
        && ($con['contesto']['delete_modal_title'] ?? null) === 'Togli sede'
        && str_starts_with((string) ($con['contesto']['delete_modal_text'] ?? ''), 'Questa sede perde la sua scorta minima al salvataggio. I pezzi restano dove sono')
        && ($con['contesto']['delete_modal_cancel_label'] ?? null) === 'Annulla'
        && ($con['contesto']['delete_modal_confirm_label'] ?? null) === 'Togli'
        // Senza avvisi la colonna della soglia non c'è e le altre due si allargano.
        && !str_contains($senza['riquadro']['tooltip'] ?? '', 'scorta minima')
        && $senza['colonne'] === [
            'location_id' => ['Sede', 7, null, null],
            'stock' => ['Giacenza', 4, 0, ' pz'],
        ];
});

check('le righe per sede si scrivono con l\'unità dell\'articolo, come nella sua scheda', function () use ($conSedi, $dueSedi) {
    $opzione = new class extends ProductResource {
        public static string $unita = 'pz';

        /** @var list<float> */
        public static array $pezzi = [];

        /** @var list<float> */
        public static array $soglie = [];

        protected static function currentId(): ?int
        {
            return 5;
        }

        protected static function modelUnit(int $modelId): string
        {
            return static::$unita;
        }

        protected static function locationQuantities(array $productIds): array
        {
            return $productIds === [5] ? static::$pezzi : [];
        }

        protected static function locationThresholds(array $productIds): array
        {
            return $productIds === [5] ? static::$soglie : [];
        }
    };

    $leggi = static function (string $unita, array $pezzi, array $soglie) use ($conSedi, $dueSedi, $opzione): array {
        $opzione::$unita = $unita;
        $opzione::$pezzi = $pezzi;
        $opzione::$soglie = $soglie;

        return $conSedi($dueSedi, ['low_stock_alerts' => true], static function () use ($opzione): array {
            $formati = [];

            foreach ($opzione::formSchema() as $campo) {
                if ((string) $campo->name !== 'locations') {
                    continue;
                }

                foreach ((array) (((array) $campo->get('context'))['columns'] ?? []) as $colonna) {
                    $numero = (array) (((array) $colonna->get('context'))['number'] ?? []);
                    $formati[(string) $colonna->name] = [$numero['decimal'] ?? null, $numero['symbol'] ?? null];
                }
            }

            return $formati;
        });
    };

    $pezzi = $leggi('pz', [20.0, 3.0], [5.0]);
    $chili = $leggi('kg', [20.0], []);
    // Una sede coi decimali li tiene, anche se la somma è tonda.
    $mezzi = $leggi('pz', [1.5, 1.5], [0.5]);

    return ($pezzi['stock'] ?? null) === [0, ' pz']
        && ($pezzi['min_stock'] ?? null) === [0, ' pz']
        && ($chili['stock'] ?? null) === [3, ' kg']
        && ($chili['min_stock'] ?? null) === [3, ' kg']
        && ($mezzi['stock'] ?? null) === [3, ' pz']
        && ($mezzi['min_stock'] ?? null) === [3, ' pz'];
});

check('con una sede sola la scorta minima si scrive con l\'unità dell\'articolo', function () use ($forza) {
    $opzione = new class extends ProductResource {
        public static string $unita = 'pz';

        /** @var list<float> */
        public static array $soglie = [];

        protected static function currentId(): ?int
        {
            return 5;
        }

        protected static function modelUnit(int $modelId): string
        {
            return static::$unita;
        }

        protected static function locationQuantities(array $productIds): array
        {
            return [];
        }

        protected static function locationThresholds(array $productIds): array
        {
            return $productIds === [5] ? static::$soglie : [];
        }
    };

    $leggi = static function (string $unita, array $soglie) use ($forza, $opzione): array {
        $opzione::$unita = $unita;
        $opzione::$soglie = $soglie;
        $forza(['low_stock_alerts' => true]);

        try {
            foreach ($opzione::formSchema() as $campo) {
                if ((string) $campo->name === 'min_stock') {
                    $numero = (array) (((array) $campo->get('context'))['number'] ?? []);

                    return [$numero['decimal'] ?? null, $numero['symbol'] ?? null];
                }
            }

            return [];
        } finally {
            $forza(null);
        }
    };

    return $leggi('pz', [5.0]) === [0, ' pz']
        && $leggi('kg', [5.0]) === [3, ' kg']
        // Una soglia già scritta coi decimali li tiene, anche in pezzi.
        && $leggi('pz', [2.5]) === [3, ' pz'];
});

check('con due sedi le righe si controllano prima di scrivere, e la soglia della sede sola non si guarda', function () use ($conSedi, $dueSedi) {
    $esito = static function (array $righe, array $valori = []) use ($conSedi, $dueSedi): string {
        return $conSedi($dueSedi, ['low_stock_alerts' => true], static function () use ($righe, $valori): string {
            $_POST['locations'] = $righe;

            try {
                $puliti = ProductResource::mutateRequestValues($valori + ['sku' => 'X-1'], 'update', 'backend', ['id' => 1]);
            } catch (UserError $errore) {
                return $errore->key();
            } finally {
                unset($_POST['locations']);
            }

            return implode(',', array_keys($puliti));
        });
    };

    // La soglia della sede sola e le righe non sono colonne: se ne vanno, e
    // quel «-2» non ferma niente perché quella casella lì non c'è più.
    return $esito([['location_id' => '1', 'stock' => '3', 'min_stock' => '']], ['min_stock' => '-2', 'locations' => 'x']) === 'sku'
        && $esito([['location_id' => '', 'stock' => '', 'min_stock' => '']]) === 'sku'
        && $esito([['location_id' => '1', 'stock' => '3'], ['location_id' => '1', 'stock' => '']]) === 'stock.location_duplicate'
        && $esito([['location_id' => '9', 'stock' => '3']]) === 'stock.location_unknown';
});

check('con due sedi una giacenza per sede sotto zero si ferma prima di scrivere', function () use ($conSedi, $dueSedi) {
    // Senza opzione salvata non c'è una giacenza di prima da confrontare:
    // qualunque numero sotto zero è scritto a mano.
    $esito = static function (array $righe) use ($conSedi, $dueSedi): string {
        return $conSedi($dueSedi, ['low_stock_alerts' => true], static function () use ($righe): string {
            $_POST['locations'] = $righe;

            try {
                ProductResource::mutateRequestValues(['sku' => 'X-1'], 'update', 'backend', []);
            } catch (UserError $errore) {
                return $errore->key();
            } finally {
                unset($_POST['locations']);
            }

            return '';
        });
    };

    return $esito([['location_id' => '1', 'stock' => '3'], ['location_id' => '2', 'stock' => '-1']]) === 'product.stock_negative'
        && $esito([['location_id' => '1', 'stock' => '0'], ['location_id' => '2', 'stock' => '4']]) === '';
});

check('la scheda dice di che articolo si tratta', function () {
    $resource = new class extends ProductResource {
        public static function currentTitle(): string { return parent::currentTitle(); }
    };

    // Senza id nell'indirizzo resta il titolo generico.
    return $resource::currentTitle() === 'Prodotto';
});

check('la scheda dell\'opzione sta su due colonne, con i codici a destra', function () use ($riquadro) {
    $colonne = ProductResource::formLayoutSchema()->components ?? [];
    $larghezze = array_map(
        static fn (object $colonna): mixed => ((array) ($colonna->columnSpan ?? []))['default'] ?? null,
        $colonne
    );
    $codici = $riquadro('Identificazione') ?? [];

    return $larghezze === [8, 4]
        // Le misure sono salite accanto ai codici: il riquadro «Misure» non
        // c'è più, e quello che c'era dentro si ritrova qui.
        && array_keys($codici['campi'] ?? []) === ['sku', 'ean', 'mpn', 'weight', 'length', 'width', 'height']
        && str_contains($codici['tooltip'] ?? '', 'peso e misure dell\'articolo');
});

check('gli attributi dell\'opzione si dividono: quelli che la fanno nascere e quelli con l\'unità', function () {
    $diviso = ProductResource::splitAttributes([
        ['id' => 1, 'type' => 'select', 'name' => 'Formato'],
        ['id' => 2, 'type' => 'number', 'name' => 'Volume'],
        ['id' => 3, 'type' => 'color', 'name' => 'Colore'],
        ['id' => 4, 'type' => 'text', 'name' => 'Profumo'],
    ]);

    return array_column($diviso['sales'], 'id') === [1, 3]
        && array_column($diviso['technical'], 'id') === [2, 4];
});

// Una scheda con quegli attributi e quei valori: senza database non ce n'è
// nessuno.
$conAttributi = new class extends ProductResource {
    /** @var list<array<string, mixed>> */
    public static array $tutti = [];

    /** @var array<int, array<string, string>> le voci di ogni attributo */
    public static array $voci = [];

    public static function attributes(): array
    {
        return static::$tutti;
    }

    public static function valueChoices(array $attribute): array
    {
        return static::$voci[(int) ($attribute['id'] ?? 0)] ?? [];
    }

    public static function leggiVendita(array $links): array
    {
        return static::salesOptions(static::splitAttributes(static::$tutti)['sales'], $links);
    }
};

check('le opzioni di vendita si leggono col nome del valore, e quelle vuote non si vedono', function () use ($conAttributi) {
    $conAttributi::$tutti = [
        ['id' => 1, 'type' => 'select', 'name' => 'Formato', 'level' => 'product'],
        ['id' => 3, 'type' => 'color', 'name' => 'Colore', 'level' => 'product'],
        ['id' => 5, 'type' => 'select', 'name' => 'Finitura', 'level' => 'product'],
    ];
    $conAttributi::$voci = [
        1 => ['10' => '50 ml'],
        // Un colore porta con sé il pallino: il nome sta dentro.
        3 => ['20' => ['name' => 'Rosso', 'color' => '#f00']],
    ];

    $voci = $conAttributi::leggiVendita([
        1 => ['attribute_value_id' => '10'],
        3 => ['attribute_value_id' => '20'],
        5 => ['attribute_value_id' => '0'],
    ]);

    $conAttributi::$tutti = [];
    $conAttributi::$voci = [];

    return $voci === [
        ['name' => 'Formato', 'value' => '50 ml'],
        ['name' => 'Colore', 'value' => 'Rosso'],
    ];
});

check('il riquadro delle opzioni di vendita si legge e non si scrive', function () {
    $html = ProductResource::salesOptionsHtml([
        ['name' => 'Formato', 'value' => '50 ml'],
        ['name' => '<b>Colore</b>', 'value' => '"Rosso"'],
    ]);

    return str_contains($html, 'Formato')
        && str_contains($html, '50 ml')
        // Nome e valore arrivano da chi vende: si scrivono come testo.
        && str_contains($html, '&lt;b&gt;Colore&lt;/b&gt;')
        && str_contains($html, '&quot;Rosso&quot;')
        && !str_contains($html, '<input')
        && !str_contains($html, '<select');
});

check('la rettifica si apre in una finestra che posta dove postava la pagina', function () {
    $html = ProductResource::stockAdjustModal(7, '/backend/app/gestionale/versioni/7/edit/');

    return str_contains($html, '<template')
        // Un form dentro un form il browser lo butta via: la finestra nasce
        // in un template e lo script la porta in fondo alla pagina.
        && str_contains($html, 'method="post"')
        && str_contains($html, 'name="mode"')
        && str_contains($html, 'name="quantity"')
        && str_contains($html, 'name="reason"')
        && str_contains($html, 'name="note"')
        && str_contains($html, 'name="product_id" value="7"')
        && str_contains($html, 'name="back" value="/backend/app/gestionale/versioni/7/edit/"')
        && str_contains($html, 'Aggiungi')
        && str_contains($html, 'Sottrai')
        && str_contains($html, 'Imposta');
});

check('senza opzione aperta la finestra della rettifica non c\'è', fn () =>
    ProductResource::stockAdjustModal(0, '') === ''
);

check('i campi obbligatori della finestra armano la spunta del backend', function () {
    $html = ProductResource::stockAdjustModal(7, '/torna');

    // Il backend tiene spento «Salva» finché un campo obbligatorio è vuoto, e
    // riaccende ascoltando i campi con «data-wi-check». Senza, il bottone
    // della finestra resterebbe spento per sempre.
    return str_contains($html, 'name="mode" data-wi-check="true"')
        && str_contains($html, 'name="quantity" data-wi-check="true"')
        && str_contains($html, 'name="reason" data-wi-check="true"')
        && str_contains($html, 'typeof check');
});

check('i movimenti della scheda sono le colonne dell\'elenco Movimenti, senza l\'opzione', function () {
    $dichiarate = [];

    foreach (StockMovementResource::tableSchema() as $colonna) {
        $dichiarate[] = (string) $colonna->name;
    }

    $scelte = ProductResource::stockHistoryColumns();

    // Le colonne nascono dallo schema della Resource che le possiede: se una
    // sparisce di là, la scheda se ne accorge qui invece che in pagina.
    return $scelte === ['creation', 'type', 'reason', 'quantity', 'quantity_after']
        && array_diff($scelte, $dichiarate) === []
        && !in_array('product_id', $scelte, true);
});

check('senza opzione aperta i movimenti sono la frase, non la tabella', function () {
    $metodo = new ReflectionMethod(ProductResource::class, 'stockHistoryTable');
    $html = (string) $metodo->invoke(null);

    return str_contains($html, 'Nessun movimento') && !str_contains($html, '<table');
});

check('il codice del produttore si chiama MPN', fn () =>
    (ProductResource::labelSchema()['mpn'] ?? '') === 'MPN'
);

check('lo stato dell\'opzione si cambia con un click, dov\'è scritto (P124)', function () {
    foreach (ProductResource::tableSchema() as $colonna) {
        if ((string) $colonna->name !== 'active') {
            continue;
        }

        $badge = (array) ($colonna->schema['badge'] ?? []);

        return ($badge['clickable'] ?? false) === true
            && (($badge['on'] ?? [])['text'] ?? '') === 'Pubblicato'
            && (($badge['off'] ?? [])['text'] ?? '') === 'Bozza';
    }

    return false;
});

// L'opzione sta nel negozio o non c'è: sono le stesse due parole
// dell'articolo, e chi le legge in fila non deve tradurre «attiva» in
// «pubblicata». Anche il bottone della pillola dice cosa succede al click.
check('l\'opzione è pubblicata o in bozza, come l\'articolo', function () {
    foreach (ProductResource::tableSchema() as $colonna) {
        if ((string) $colonna->name !== 'active') {
            continue;
        }

        $badge = (array) ($colonna->schema['badge'] ?? []);

        return (($badge['on'] ?? [])['button'] ?? '') === 'Metti in bozza'
            && (($badge['off'] ?? [])['button'] ?? '') === 'Pubblica';
    }

    return false;
});

check('anche la modifica dell\'opzione dice «Pubblicato» e «Bozza»', function () use ($campi) {
    $scelte = (array) (($campi()['active'] ?? null)?->get('options') ?? []);

    return ($scelte['true'] ?? '') === 'Pubblicato' && ($scelte['false'] ?? '') === 'Bozza';
});

summary();
