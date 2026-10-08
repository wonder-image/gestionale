<?php
/** php tests/StockPagesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Backend\Support\ResourceTableRenderer;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockLevelResource;
use Wonder\Plugin\Gestionale\Support\Stock\Adjustment;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;

// Indirizzo e host del sito di prova: i test non lo contattano, ma devono coincidere tra loro.
define('TEST_URL', getenv('WI_TEST_URL') ?: 'https://ecommerce.test');
define('TEST_HOST', (string) parse_url(TEST_URL, PHP_URL_HOST));

$campi = static function (string $resource): array {
    $keys = [];

    foreach ($resource::formSchema() as $field) {
        $keys[] = (string) $field->name;
    }

    return $keys;
};

/**
 * Esegue `$fai` con le sedi e le funzionalità date, poi rimette tutto com'era.
 * Le sedi entrano nella cache di `Locations::shown()`.
 */
$conSedi = static function (array $sedi, array $features, callable $fai): mixed {
    $cacheSedi = new ReflectionProperty(Locations::class, 'shown');
    $cacheFeatures = new ReflectionProperty(Gestionale::class, 'features');
    $prima = [$cacheSedi->getValue(), $cacheFeatures->getValue()];
    $cacheSedi->setValue(null, $sedi);
    $cacheFeatures->setValue(null, $features + ['multi_location' => true, 'low_stock_alerts' => false, 'orders' => false]);

    try {
        return $fai();
    } finally {
        $cacheSedi->setValue(null, $prima[0]);
        $cacheFeatures->setValue(null, $prima[1]);
    }
};

$colonne = static function (): array {
    $nomi = [];

    foreach (StockLevelResource::tableSchema() as $column) {
        $nomi[] = (string) $column->name;
    }

    return $nomi;
};

check('la rettifica ha il suo indirizzo ed è una pagina-form', fn () =>
    StockAdjustmentResource::path() === 'app/gestionale/rettifica'
    && StockAdjustmentResource::isFormPage() === true
);

check('la rettifica non sta nel menu: ci si arriva da una riga', fn () =>
    StockAdjustmentResource::navigationSchema()->toArray()['enabled'] === false
);

check('chiede quantità, causale e nota, e come leggerla', function () use ($campi) {
    $keys = $campi(StockAdjustmentResource::class);

    return in_array('mode', $keys, true)
        && in_array('quantity', $keys, true)
        && in_array('reason', $keys, true)
        && in_array('note', $keys, true);
});

check('l\'azione ha le tre voci della rettifica e parte da «Aggiungi»', function () {
    foreach (StockAdjustmentResource::formSchema() as $field) {
        if ((string) $field->name === 'mode') {
            return (array) $field->get('options') === Adjustment::ACTIONS
                && $field->get('value') === 'add'
                && (string) $field->get('label') === 'Azione';
        }
    }

    return false;
});

check('le causali sono quelle vere, con l\'inventario già scelto', function () {
    foreach (StockAdjustmentResource::formSchema() as $field) {
        if ((string) $field->name === 'reason') {
            // Un select tiene le voci sotto `options`.
            return array_keys((array) $field->get('options')) === array_keys(Reasons::all())
                && $field->get('value') === Reasons::DEFAULT;
        }
    }

    return false;
});

check('il link porta l\'opzione e la strada del ritorno', function () {
    $url = StockAdjustmentResource::urlFor(7, '/backend/app/gestionale/giacenze/?p=2');

    return str_contains($url, 'versione=7')
        && str_contains($url, 'torna=');
});

check('la strada del ritorno accetta solo indirizzi di questo sito', function () {
    // Un `torna=https://altrove.example` sarebbe un redirect aperto. Le rotte
    // del core però tornano indirizzi assoluti di **questo** sito, e quelli
    // devono passare.
    $_SERVER['HTTP_HOST'] = TEST_HOST;

    return StockAdjustmentResource::backUrlFrom('https://altrove.example/x') === ''
        && StockAdjustmentResource::backUrlFrom('//altrove.example') === ''
        && StockAdjustmentResource::backUrlFrom('/backend/app/gestionale/giacenze/?p=2')
            === '/backend/app/gestionale/giacenze/?p=2'
        && StockAdjustmentResource::backUrlFrom(TEST_URL.'/backend/app/gestionale/giacenze/?cerca=TSH')
            === '/backend/app/gestionale/giacenze/?cerca=TSH';
});

check('la strada del ritorno non si fa aggirare da barre storte o spazi', function () {
    // I browser leggono "\" come "/" e saltano tab e a capo: `/\altrove`
    // e `/<tab>/altrove` sono `//altrove`, cioè un altro sito.
    $_SERVER['HTTP_HOST'] = TEST_HOST;

    return StockAdjustmentResource::backUrlFrom('/\\altrove.example') === ''
        && StockAdjustmentResource::backUrlFrom(TEST_URL.'//altrove.example') === ''
        && StockAdjustmentResource::backUrlFrom(TEST_URL.'/\\altrove.example') === ''
        && StockAdjustmentResource::backUrlFrom("/\t/altrove.example") === ''
        && StockAdjustmentResource::backUrlFrom("/\n/altrove.example") === ''
        && StockAdjustmentResource::backUrlFrom('/backend/app/gestionale/giacenze/?p=2')
            === '/backend/app/gestionale/giacenze/?p=2'
        && StockAdjustmentResource::backUrlFrom(TEST_URL.'/backend/app/gestionale/giacenze/?cerca=TSH&sotto=1')
            === '/backend/app/gestionale/giacenze/?cerca=TSH&sotto=1';
});

check('le giacenze sono un elenco del core in sola consultazione', function () {
    $pagine = (array) (StockLevelResource::pageSchema()->toArray()['pages'] ?? []);

    return StockLevelResource::path() === 'app/gestionale/giacenze'
        && StockLevelResource::isFormPage() === false
        && StockLevelResource::$model === Product::class
        && array_keys(array_filter($pagine)) === ['list'];
});

check('le giacenze stanno nel menu Magazzino, prima dei movimenti', function () {
    $nav = StockLevelResource::navigationSchema()->toArray();

    return ($nav['enabled'] ?? true) !== false
        && ($nav['section_key'] ?? '') === 'magazzino'
        && (int) ($nav['order'] ?? 0) < 20;
});

check('niente carichi né scarichi da qui: senza "Aggiungi" e senza API', function () {
    $layout = StockLevelResource::tableLayoutSchema()->toArray();

    return ($layout['button_add']['enabled'] ?? true) === false
        && (StockLevelResource::apiSchema()->toArray()['enabled'] ?? true) === false;
});

check('la rettifica porta con sé l\'opzione e il ritorno', function () {
    $_GET['versione'] = '9';
    $_GET['torna'] = '/backend/app/gestionale/giacenze/?p=2';
    $_SERVER['HTTP_HOST'] = TEST_HOST;

    $campi = [];

    foreach (StockAdjustmentResource::formSchema() as $field) {
        $campi[(string) $field->name] = $field;
    }

    unset($_GET['versione'], $_GET['torna']);

    // La rotta del salvataggio non ha query string: senza questi due campi
    // nascosti, al salvataggio non si saprebbe più di quale opzione si
    // stava parlando. `versione=` resta come chiave di query.
    return ($campi['product_id'] ?? null)?->get('value') === '9'
        && ($campi['back'] ?? null)?->get('value') === '/backend/app/gestionale/giacenze/?p=2';
});

check('un rifiuto della rettifica non diventa una pagina 500', function () {
    // Il controller delle pagine-form non intercetta niente: il messaggio
    // deve tornare come stringa, non come eccezione.
    $messaggio = StockAdjustmentResource::submitFormPage([
        'product_id' => '0',
        'quantity' => '3',
    ]);

    return str_contains($messaggio, 'non esiste più');
});

check('il riquadro della home porta alle sole righe sotto scorta', fn () =>
    str_ends_with(StockLevelResource::lowStockUrl(), 'giacenze?gst_products__scorta=sotto')
);

check('una colonna per sede solo quando le sedi sono almeno due', function () use ($conSedi, $colonne) {
    $una = $conSedi([['id' => 1, 'label' => 'Negozio']], [], $colonne);
    $due = $conSedi([['id' => 2, 'label' => 'Magazzino'], ['id' => 1, 'label' => 'Negozio']], [], $colonne);

    return $una === ['photo', 'model_name', 'sku', 'stock_quantity', 'actions']
        && $due === ['photo', 'model_name', 'sku', 'stock_loc_2', 'stock_loc_1', 'stock_quantity', 'actions'];
});

check('le colonne di sede hanno il nome della sede, e il totale si chiama Totale', function () use ($conSedi) {
    $etichette = $conSedi([['id' => 2, 'label' => 'Magazzino'], ['id' => 1, 'label' => 'Negozio']], [], function () {
        $etichette = [];

        foreach (StockLevelResource::tableSchema() as $column) {
            $etichette[(string) $column->name] = $column->toArray()['label'] ?? null;
        }

        return $etichette;
    });

    return $etichette['stock_loc_2'] === 'Magazzino'
        && $etichette['stock_loc_1'] === 'Negozio'
        && $etichette['stock_quantity'] === 'Totale';
});

check('scorta minima, impegnati e disponibili seguono le loro funzionalità', function () use ($conSedi, $colonne) {
    $tutte = $conSedi([], ['low_stock_alerts' => true, 'orders' => true], $colonne);

    return $tutte === ['photo', 'model_name', 'sku', 'stock_quantity', 'min_stock_quantity', 'stock_reserved', 'stock_available', 'actions'];
});

check('la select dà un alias a ogni numero, e nessuno in più', function () use ($conSedi) {
    $alias = $conSedi([['id' => 2, 'label' => 'A'], ['id' => 1, 'label' => 'B']], ['low_stock_alerts' => true, 'orders' => true], fn () =>
        ResourceTableRenderer::selectAliases(StockLevelResource::select())
    );

    sort($alias);

    return $alias === ['model_name', 'stock_alert', 'stock_available', 'stock_loc_1', 'stock_loc_2', 'stock_quantity', 'stock_reserved'];
});

check('il filtro Scorta c\'è solo con gli avvisi di scorta minima', function () use ($conSedi) {
    $filtri = fn () => array_column((array) (StockLevelResource::tableLayoutSchema()->toArray()['custom_filters'] ?? []), 'column');

    $senza = $conSedi([], [], $filtri);
    $con = $conSedi([], ['low_stock_alerts' => true], $filtri);

    return !in_array('scorta', $senza, true)
        && in_array('scorta', $con, true)
        && in_array('marchio', $con, true)
        && in_array('categoria', $con, true)
        && in_array('active', $con, true);
});

check('il menu della riga porta ai movimenti e alla versione', function () use ($conSedi) {
    $azioni = $conSedi([], [], function () {
        foreach (StockLevelResource::tableSchema() as $column) {
            if ((string) $column->name === 'actions') {
                return (array) ($column->toArray()['actions'] ?? []);
            }
        }

        return [];
    });

    return array_keys($azioni) === ['movimenti', 'versione']
        && str_contains((string) $azioni['movimenti']['href'], 'versione={id}')
        && str_contains((string) $azioni['versione']['href'], '{id}');
});

check('marchio: solo id validi, e niente condizione se non resta nulla', fn () =>
    StockLevelResource::brandCondition(['abc', '0', '-3', '']) === ''
    && str_contains(StockLevelResource::brandCondition(['4', '4', 'x', '7']), 'brand_id IN (4,7)')
);

check('categoria: comprende le sottocategorie', function () {
    $albero = [
        ['id' => 1, 'parent_id' => 0, 'name' => 'Abbigliamento'],
        ['id' => 2, 'parent_id' => 1, 'name' => 'Magliette'],
        ['id' => 3, 'parent_id' => 2, 'name' => 'Polo'],
        ['id' => 4, 'parent_id' => 0, 'name' => 'Scarpe'],
    ];

    return StockLevelResource::categoryCondition(['x'], $albero) === ''
        && str_contains(StockLevelResource::categoryCondition(['2'], $albero), 'category_id IN (2,3)')
        && str_contains(StockLevelResource::categoryCondition(['4'], $albero), 'category_id IN (4)');
});

// Una sede del gestionale (`gst_locations`) punta alla sua sede del core.
$sede = static fn (int $id, int $core, string $merce = 'true', string $cancellata = 'false'): array =>
    ['id' => $id, 'society_location_id' => $core, 'has_stock' => $merce, 'deleted' => $cancellata];
$delCore = static fn (int $id, string $nome, int $posizione, string $cancellata = 'false', string $attivita = ''): array =>
    ['id' => $id, 'label' => $nome, 'name' => $attivita, 'position' => $posizione, 'deleted' => $cancellata];

check('sedi: ordine per posizione, poi per id', function () use ($sede, $delCore) {
    $sedi = Locations::pick(
        [$sede(5, 50), $sede(3, 30), $sede(8, 80)],
        [$delCore(50, 'Centro', 1), $delCore(30, 'Porto', 2), $delCore(80, 'Lago', 1)],
        []
    );

    return array_column($sedi, 'id') === [5, 8, 3]
        && array_column($sedi, 'label') === ['Centro', 'Lago', 'Porto'];
});

check('sedi: senza merce gestita non c\'è colonna, a meno che abbia pezzi', function () use ($sede, $delCore) {
    $sedi = Locations::pick(
        [$sede(1, 10), $sede(2, 20, 'false'), $sede(3, 30, 'false')],
        [$delCore(10, 'Negozio', 1), $delCore(20, 'Ufficio', 2), $delCore(30, 'Deposito', 3)],
        [3]
    );

    return array_column($sedi, 'id') === [1, 3];
});

check('sedi: una sede cancellata con pezzi resta, col suo nome', function () use ($sede, $delCore) {
    $sedi = Locations::pick(
        [$sede(1, 10), $sede(2, 20, 'true', 'true')],
        [$delCore(10, 'Negozio', 1), $delCore(20, 'Vecchio negozio', 2, 'true')],
        [2]
    );
    $vuota = Locations::pick([$sede(2, 20, 'true', 'true')], [$delCore(20, 'Vecchio negozio', 2, 'true')], []);

    return array_column($sedi, 'label') === ['Negozio', 'Vecchio negozio'] && $vuota === [];
});

check('sedi: senza nome si usa il nome dell\'attività, poi "Sede #id"', function () use ($sede, $delCore) {
    $sedi = Locations::pick([$sede(1, 10)], [$delCore(10, '', 1, 'false', 'Bottega Rossi')], [9]);

    return array_column($sedi, 'label') === ['Bottega Rossi', 'Sede #9'];
});

summary();
