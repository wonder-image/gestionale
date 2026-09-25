<?php
/** php tests/ProductResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

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

check('a funzionalità spente la scheda non ha soglia, vendita senza giacenza né fornitori', function () use ($campi, $forza) {
    $forza(['low_stock_alerts' => false, 'backorders' => false, 'purchasing' => false]);

    try {
        $trovati = array_intersect(
            ['min_stock_quantity', 'allow_backorder', 'backorder_lead_days', 'suppliers'],
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

check('ogni riga dice fornitore, codice, costo e preferito', function () use ($campi, $forza) {
    $forza(['purchasing' => true]);

    try {
        $contesto = (array) $campi()['suppliers']->get('context');
    } finally {
        $forza(null);
    }

    $colonne = [];

    foreach ((array) ($contesto['columns'] ?? []) as $colonna) {
        $colonne[(string) $colonna->name] = $colonna;
    }

    $relazione = $contesto['relation'] ?? null;
    $preferito = (array) $colonne['is_preferred']->get('options');

    return array_keys($colonne) === ['id', 'supplier_id', 'supplier_sku', 'cost', 'is_preferred']
        && $colonne['id']->get('helper') === 'hidden'
        && $colonne['supplier_id']->get('label') === 'Fornitore'
        // Senza database nessuno da proporre: resta la voce vuota.
        && array_keys((array) $colonne['supplier_id']->get('options')) === ['']
        && $colonne['supplier_sku']->get('label') === 'Codice fornitore'
        // La colonna tiene cento caratteri: il browser non lascia scrivere oltre.
        && $colonne['supplier_sku']->get('max_length') === 100
        && $colonne['cost']->get('label') === 'Costo d\'acquisto'
        && $colonne['cost']->get('helper') === 'price'
        && $colonne['is_preferred']->get('label') === 'Preferito'
        // Il «No» per primo: una riga nuova non ruba il preferito.
        && array_keys($preferito) === ['false', 'true']
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
    $rifiutoFornitori([['id' => '', 'supplier_id' => '9', 'supplier_sku' => '', 'cost' => '1,00', 'is_preferred' => 'false']])
        === 'product.supplier_invalid'
);

check('un codice senza fornitore viene rifiutato', fn () =>
    $rifiutoFornitori([['id' => '', 'supplier_id' => '', 'supplier_sku' => 'FN-12', 'cost' => '', 'is_preferred' => 'false']])
        === 'product.supplier_missing'
);

check('lo stesso fornitore due volte viene rifiutato, con il suo nome', function () use ($forza, $conFornitori) {
    $forza(['purchasing' => true]);
    $_POST['suppliers'] = [
        ['id' => '', 'supplier_id' => '5', 'supplier_sku' => '', 'cost' => '1,00', 'is_preferred' => 'false'],
        ['id' => '', 'supplier_id' => '5', 'supplier_sku' => 'FN-12', 'cost' => '', 'is_preferred' => 'false'],
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
        ['id' => '', 'supplier_id' => '5', 'supplier_sku' => 'FN-12', 'cost' => '12,50', 'is_preferred' => 'true'],
        ['id' => '', 'supplier_id' => '6', 'supplier_sku' => '', 'cost' => '', 'is_preferred' => 'false'],
        // Una riga aggiunta e lasciata lì: il «No» del preferito non è un dato.
        ['id' => '', 'supplier_id' => '', 'supplier_sku' => '', 'cost' => '', 'is_preferred' => 'false'],
    ]) === ''
);

check('ad acquisti spenti le righe non si guardano: il riquadro non c\'è', fn () =>
    $rifiutoFornitori([['id' => '', 'supplier_id' => '9', 'cost' => '1,00']], false) === ''
);

check('le righe arrivano al salvataggio pulite, con un preferito solo', fn () =>
    ProductResource::prepareRepeaterRows('suppliers', [
        ['id' => '', 'supplier_id' => '', 'supplier_sku' => ' ', 'cost' => '', 'is_preferred' => 'false'],
        ['id' => '12', 'supplier_id' => '5', 'supplier_sku' => ' FN-12 ', 'cost' => '12,50', 'is_preferred' => 'false'],
        ['id' => '', 'supplier_id' => '6', 'supplier_sku' => '', 'cost' => '', 'is_preferred' => 'false'],
    ], 'update') === [
        ['id' => '12', 'supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => '12.50', 'is_preferred' => 'true'],
        // Vuoto resta vuoto, e il database lo scrive NULL: «non lo so».
        ['id' => '', 'supplier_id' => 6, 'supplier_sku' => '', 'cost' => '', 'is_preferred' => 'false'],
    ]
);

check('con due «Sì» vince quello appena scelto, non quello di prima', function () {
    $scheda = new class extends ProductResource {
        /** La riga 12 era già la preferita. */
        protected static function supplierCardStoredPreferred(array $rowIds): array
        {
            return array_values(array_intersect($rowIds, ['12']));
        }
    };

    $righe = $scheda::prepareRepeaterRows('suppliers', [
        ['id' => '12', 'supplier_id' => '5', 'cost' => '', 'is_preferred' => 'true'],
        ['id' => '13', 'supplier_id' => '6', 'cost' => '', 'is_preferred' => 'true'],
    ], 'update');

    // Senza niente di salvato vince il primo, come nella finestra «Costo».
    $senzaStoria = ProductResource::prepareRepeaterRows('suppliers', [
        ['id' => '', 'supplier_id' => '5', 'cost' => '', 'is_preferred' => 'true'],
        ['id' => '', 'supplier_id' => '6', 'cost' => '', 'is_preferred' => 'true'],
    ], 'store');

    return array_column($righe, 'is_preferred') === ['false', 'true']
        && array_column($senzaStoria, 'is_preferred') === ['true', 'false'];
});

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

check('la scheda dice di che articolo si tratta', function () {
    $resource = new class extends ProductResource {
        public static function currentTitle(): string { return parent::currentTitle(); }
    };

    // Senza id nell'indirizzo resta il titolo generico.
    return $resource::currentTitle() === 'Prodotto';
});

summary();
