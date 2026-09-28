<?php
/** php tests/ProductResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
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

// I titoli dei riquadri della scheda, nell'ordine in cui stanno.
$riquadri = static function (): array {
    $titoli = [];

    foreach (ProductResource::formLayoutSchema()->components[0]->components ?? [] as $riquadro) {
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
$riquadro = static function (string $titolo, string $scheda = ProductResource::class): ?array {
    foreach ($scheda::formLayoutSchema()->components[0]->components ?? [] as $riquadro) {
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
            ['min_stock', 'locations', 'allow_backorder', 'backorder_lead_days', 'suppliers'],
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
        $campo = $campi()['suppliers'] ?? null;
    } finally {
        $forza(['purchasing' => false]);
    }

    try {
        $senza = $riquadri();
    } finally {
        $forza(null);
    }

    // Senza database non ci sono attributi: niente riquadro «Attributi».
    return $con === ['Prodotto', 'Misure', 'Magazzino', 'Fornitori']
        && $senza === ['Prodotto', 'Misure', 'Magazzino']
        && $campo !== null
        && $campo->get('helper') === 'inputRepeater';
});

check('ogni riga dice fornitore, codice e costo, un terzo ciascuno', function () use ($campi, $forza) {
    $forza(['purchasing' => true]);

    try {
        $contesto = (array) $campi()['suppliers']->get('context');
    } finally {
        $forza(null);
    }

    $colonne = [];
    $larghezze = [];

    foreach ((array) ($contesto['columns'] ?? []) as $colonna) {
        $colonne[(string) $colonna->name] = $colonna;
        $larghezze[(string) $colonna->name] = ((array) $colonna->columnSpan)['default'] ?? null;
    }

    $relazione = $contesto['relation'] ?? null;

    // Niente «Preferito» (P96): il fornitore da cui si compra lo si decide
    // sull'ordine, non qui.
    return array_keys($colonne) === ['id', 'supplier_id', 'supplier_sku', 'cost']
        && $colonne['id']->get('helper') === 'hidden'
        && $colonne['supplier_id']->get('label') === 'Fornitore'
        // Senza database nessuno da proporre: resta la voce vuota.
        && array_keys((array) $colonne['supplier_id']->get('options')) === ['']
        && $colonne['supplier_sku']->get('label') === 'Codice fornitore'
        // La colonna tiene cento caratteri: il browser non lascia scrivere oltre.
        && $colonne['supplier_sku']->get('max_length') === 100
        && $colonne['cost']->get('label') === 'Costo d\'acquisto'
        && $colonne['cost']->get('helper') === 'price'
        && ((((array) $colonne['cost']->get('context'))['number'] ?? [])['decimal'] ?? null) === 2
        && array_slice($larghezze, 1) === ['supplier_id' => 4, 'supplier_sku' => 4, 'cost' => 4]
        && ($contesto['add_label'] ?? null) === 'Aggiungi fornitore'
        && ($contesto['delete_modal_title'] ?? null) === 'Togli fornitore'
        && $relazione instanceof RepeaterRelation
        && $relazione->table === ProductSupplier::$table
        && $relazione->parentKey === 'product_id'
        && $relazione->positionKey === 'position'
        // Il legame è il costo di oggi: una riga tolta se ne va davvero.
        && $relazione->softDelete === false
        && ($contesto['sortable'] ?? false) === true;
});

// Una scheda con due fornitori da proporre: senza database la tendina
// sarebbe vuota.
$conFornitori = new class extends ProductResource {
    protected static function supplierCardChoices(array $keepIds): array
    {
        return [5 => 'Filati Nord', 6 => 'Imballaggi Sud'];
    }
};

check('senza fornitori da proporre il riquadro dice dove aggiungerli', function () use ($forza, $conFornitori) {
    // Il testo del riquadro «Fornitori», prima del repeater.
    $avviso = static function (string $scheda): string {
        $riquadri = $scheda::formLayoutSchema()->components[0]->components ?? [];
        $testo = '';

        foreach (end($riquadri)->components ?? [] as $dentro) {
            if ($dentro instanceof RichText) {
                $testo .= (string) $dentro->getText();
            }
        }

        return $testo;
    };

    $forza(['purchasing' => true]);

    try {
        $vuoto = $avviso(ProductResource::class);
        $pieno = $avviso($conFornitori::class);
    } finally {
        $forza(null);
    }

    return str_contains($vuoto, 'Anagrafiche → Fornitori') && $pieno === '';
});

// La chiave dell'errore con cui la scheda rifiuta le righe, '' se le accetta.
$rifiutoFornitori = static function (array $righe, bool $acquisti = true) use ($forza, $conFornitori): string {
    $forza(['purchasing' => $acquisti]);
    $_POST['suppliers'] = $righe;

    try {
        $conFornitori::mutateRequestValues(['sku' => 'X-1'], 'update', 'backend', ['id' => 1]);
    } catch (UserError $errore) {
        return $errore->key().(str_contains($errore->getMessage(), '{{') ? ' con segnaposto' : '');
    } finally {
        unset($_POST['suppliers']);
        $forza(null);
    }

    return '';
};

check('un fornitore che la scheda non propone viene rifiutato', fn () =>
    $rifiutoFornitori([['id' => '', 'supplier_id' => '9', 'supplier_sku' => '', 'cost' => '1,00']])
        === 'product.supplier_invalid'
);

check('un codice senza fornitore viene rifiutato', fn () =>
    $rifiutoFornitori([['id' => '', 'supplier_id' => '', 'supplier_sku' => 'FN-12', 'cost' => '']])
        === 'product.supplier_missing'
);

check('lo stesso fornitore due volte viene rifiutato, con il suo nome', function () use ($forza, $conFornitori) {
    $forza(['purchasing' => true]);
    $_POST['suppliers'] = [
        ['id' => '', 'supplier_id' => '5', 'supplier_sku' => '', 'cost' => '1,00'],
        ['id' => '', 'supplier_id' => '5', 'supplier_sku' => 'FN-12', 'cost' => ''],
    ];

    try {
        $conFornitori::mutateRequestValues(['sku' => 'X-1'], 'update', 'backend', ['id' => 1]);
    } catch (UserError $errore) {
        return $errore->key() === 'product.supplier_duplicate'
            && str_contains($errore->getMessage(), 'Filati Nord');
    } finally {
        unset($_POST['suppliers']);
        $forza(null);
    }

    return false;
});

check('righe corrette e righe vuote passano', fn () =>
    $rifiutoFornitori([
        ['id' => '', 'supplier_id' => '5', 'supplier_sku' => 'FN-12', 'cost' => '12,50'],
        ['id' => '', 'supplier_id' => '6', 'supplier_sku' => '', 'cost' => ''],
        // Una riga aggiunta e lasciata lì.
        ['id' => '', 'supplier_id' => '', 'supplier_sku' => '', 'cost' => ''],
    ]) === ''
);

check('ad acquisti spenti le righe non si guardano: il riquadro non c\'è', fn () =>
    $rifiutoFornitori([['id' => '', 'supplier_id' => '9', 'cost' => '1,00']], false) === ''
);

check('le righe arrivano al salvataggio pulite, senza doppioni', fn () =>
    ProductResource::prepareRepeaterRows('suppliers', [
        ['id' => '', 'supplier_id' => '', 'supplier_sku' => ' ', 'cost' => ''],
        ['id' => '12', 'supplier_id' => '5', 'supplier_sku' => ' FN-12 ', 'cost' => '12,50'],
        ['id' => '', 'supplier_id' => '6', 'supplier_sku' => '', 'cost' => ''],
        // Lo stesso fornitore un'altra volta: la scheda l'ha già rifiutato,
        // qui non passa comunque.
        ['id' => '', 'supplier_id' => '5', 'supplier_sku' => 'FN-13', 'cost' => '9'],
    ], 'update') === [
        ['id' => '12', 'supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => '12.50'],
        // Vuoto resta vuoto, e il database lo scrive NULL: «non lo so».
        ['id' => '', 'supplier_id' => 6, 'supplier_sku' => '', 'cost' => ''],
    ]
);

check('gli altri repeater passano come sono', fn () =>
    ProductResource::prepareRepeaterRows('altro', [['x' => '1'], ['x' => '']]) === [['x' => '1'], ['x' => '']]
);

check('il costo salvato con quattro decimali non si arrotonda se la casella non cambia', function () {
    $riga = ['supplier_id' => 5, 'cost' => '12.35', 'product_id' => 7, 'position' => 0];

    $uguale = ProductResource::prepareRepeaterRelationRow('suppliers', $riga, $riga, ['id' => '12', 'cost' => '12.3456'], 'update');
    $cambiato = ProductResource::prepareRepeaterRelationRow('suppliers', ['cost' => '12.40'] + $riga, $riga, ['id' => '12', 'cost' => '12.3456'], 'update');
    $vuoto = ProductResource::prepareRepeaterRelationRow('suppliers', ['cost' => ''] + $riga, $riga, ['id' => '12', 'cost' => '12.3456'], 'update');
    $nuovo = ProductResource::prepareRepeaterRelationRow('suppliers', $riga, $riga, null, 'update');

    return $uguale['cost'] === '12.3456'
        && $cambiato['cost'] === '12.40'
        && $vuoto['cost'] === ''
        && $nuovo['cost'] === '12.35';
});

check('la scheda mostra il costo con due decimali, e vuoto quando non si sa', function () {
    // Senza id la scheda non legge gli attributi: qui non c'è database.
    $valori = ProductResource::mutateFormValues([
        'suppliers' => [
            ['id' => '1', 'supplier_id' => '5', 'cost' => '12.3456'],
            ['id' => '2', 'supplier_id' => '6', 'cost' => null],
            ['id' => '3', 'supplier_id' => '8', 'cost' => '3.0000'],
        ],
    ], 'edit');

    // Il numero grezzo, con il punto: le cifre le mette la casella del prezzo.
    return array_column($valori['suppliers'], 'cost') === ['12.35', '', '3.00'];
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

check('il riquadro Fornitori spiega che vince sull\'articolo, e la riga di contesto c\'è solo con un articolo', function () use ($forza, $riquadro) {
    // Senza database l'opzione non c'è: l'articolo «non ha fornitori». Con
    // una scheda che ne trova, la riga li elenca prima del repeater.
    $scheda = new class extends ProductResource {
        public static string $contesto = 'Dall\'articolo: Filati Nord · 12,00 € · Lana Sud · costo sconosciuto';

        protected static function supplierCardContext(int $productId): string
        {
            return static::$contesto;
        }

        public static function contestoDi(int $productId): string
        {
            return parent::supplierCardContext($productId);
        }
    };

    $forza(['purchasing' => true]);

    try {
        $senzaId = $riquadro('Fornitori') ?? [];
        $conArticolo = $riquadro('Fornitori', $scheda::class) ?? [];
        $vuotoDaDb = $scheda::contestoDi(0);
        $senzaLegami = $scheda::contestoDi(1);
    } finally {
        $forza(null);
    }

    return ($senzaId['tooltip'] ?? '') === 'Vale solo per questa opzione e vince sui fornitori dell\'articolo. Un costo lasciato vuoto vuol dire «non lo so», non zero.'
        && ($senzaId['campi'] ?? null) === ['suppliers' => 12]
        // Senza id nessuna riga di contesto: resta solo l'avviso che non c'è nessuno da proporre.
        && array_column($senzaId['testi'] ?? [], 0) === ['p']
        && str_contains($senzaId['testi'][0][1] ?? '', 'Anagrafiche → Fornitori')
        // La riga è testo, non HTML: l'apostrofo arriva già protetto.
        && ($conArticolo['testi'][0] ?? null) === ['div', '<p class="small text-body-secondary mb-0">Dall&#039;articolo: Filati Nord · 12,00 € · Lana Sud · costo sconosciuto</p>']
        && ($conArticolo['campi'] ?? null) === ['suppliers' => 12]
        && $vuotoDaDb === ''
        && $senzaLegami === 'L\'articolo non ha fornitori';
});

check('la scheda dice di che articolo si tratta', function () {
    $resource = new class extends ProductResource {
        public static function currentTitle(): string { return parent::currentTitle(); }
    };

    // Senza id nell'indirizzo resta il titolo generico.
    return $resource::currentTitle() === 'Prodotto';
});

summary();
