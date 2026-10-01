<?php
/** php tests/StockMovementResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\MovementPeriod;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;

/**
 * Esegue `$fai` con le sedi date, poi rimette tutto com'era: le sedi entrano
 * nella cache di `Locations::shown()`, come in `StockPagesTest`.
 */
$conSedi = static function (array $sedi, callable $fai): mixed {
    $cacheSedi = new ReflectionProperty(Locations::class, 'shown');
    $cacheFeatures = new ReflectionProperty(Gestionale::class, 'features');
    $prima = [$cacheSedi->getValue(), $cacheFeatures->getValue()];
    $cacheSedi->setValue(null, $sedi);
    $cacheFeatures->setValue(null, ['multi_location' => true, 'low_stock_alerts' => false, 'orders' => true]);

    try {
        return $fai();
    } finally {
        $cacheSedi->setValue(null, $prima[0]);
        $cacheFeatures->setValue(null, $prima[1]);
    }
};

$unaSede = [['id' => 1, 'label' => '']];
$dueSedi = [['id' => 1, 'label' => 'Negozio'], ['id' => 2, 'label' => 'Magazzino']];

$nomiColonne = static function (): array {
    $nomi = [];

    foreach (StockMovementResource::tableSchema() as $colonna) {
        $nomi[] = (string) $colonna->name;
    }

    return $nomi;
};

$layout = static fn (): array => StockMovementResource::tableLayoutSchema()->toArray();

$filtro = static function (string $key) use ($layout): ?array {
    foreach ((array) ($layout()['custom_filters'] ?? []) as $f) {
        if (($f['column'] ?? '') === $key) {
            return $f;
        }
    }

    return null;
};

$pagine = StockMovementResource::pageSchema()->toArray();

check('l\'elenco dei movimenti ha il suo indirizzo e il suo model', fn () =>
    StockMovementResource::path() === 'app/gestionale/movimenti'
    && StockMovementResource::$model === StockMovement::class
);

check('i movimenti si leggono e basta: niente aggiungi, modifica o elimina', function () use ($pagine) {
    $attive = array_keys(array_filter((array) ($pagine['pages'] ?? [])));

    return $attive === ['list'];
});

check('con una sede sola le colonne sono quelle della spec, senza Sede', function () use ($conSedi, $unaSede, $nomiColonne) {
    return $conSedi($unaSede, $nomiColonne) === [
        'creation', 'product_id', 'type', 'reason', 'quantity_before', 'quantity', 'quantity_after', 'user_id', 'note', 'actions',
    ];
});

check('con più sedi compare la colonna Sede, dopo la versione', function () use ($conSedi, $dueSedi, $nomiColonne) {
    return $conSedi($dueSedi, $nomiColonne) === [
        'creation', 'product_id', 'location_id', 'type', 'reason', 'quantity_before', 'quantity', 'quantity_after', 'user_id', 'note', 'actions',
    ];
});

check('le colonne hanno le loro etichette: Prima, Dopo, Chi, Sede', function () {
    $etichette = StockMovementResource::labelSchema();

    return ($etichette['quantity_before'] ?? '') === 'Prima'
        && ($etichette['quantity_after'] ?? '') === 'Dopo'
        && ($etichette['user_id'] ?? '') === 'Chi'
        && ($etichette['location_id'] ?? '') === 'Sede'
        && ($etichette['product_id'] ?? '') === 'Versione';
});

check('la data si legge all\'italiana e si ordina', function () {
    foreach (StockMovementResource::tableSchema() as $colonna) {
        if ((string) $colonna->name !== 'creation') {
            continue;
        }

        $f = $colonna->getSchema('formatter');

        return is_callable($f) && $f(['creation' => '2025-10-15 10:30:00']) === '15/10/2025 10:30'
            && $colonna->getSchema('sortable') === true;
    }

    return false;
});

check('le celle si disegnano davvero, con il segno e le etichette', function () use ($conSedi, $unaSede) {
    // Le colonne con `formatter()` non girano finché qualcuno non le chiama:
    // un metodo che non esiste si scoprirebbe solo aprendo la pagina.
    $riga = [
        'product_id' => 0, 'type' => 'adjustment', 'reason' => 'damaged',
        'quantity_before' => '10.000', 'quantity' => '-3.000', 'quantity_after' => '7.000', 'source' => 'user', 'user_id' => 0,
    ];
    $celle = [];

    $conSedi($unaSede, function () use ($riga, &$celle) {
        foreach (StockMovementResource::tableSchema() as $colonna) {
            $formatter = $colonna->getSchema('formatter');
            $celle[(string) $colonna->name] = is_callable($formatter) ? $formatter($riga) : null;
        }
    });

    return $celle['type'] === 'Rettifica'
        && $celle['reason'] === 'Danneggiato'
        && $celle['quantity'] === '-3'
        && $celle['quantity_before'] === '10'
        && $celle['quantity_after'] === '7'
        && $celle['product_id'] === '—';
});

check('Chi: il nome dell\'utente, altrimenti l\'origine', fn () =>
    StockMovementResource::who(['user_id' => 3, 'source' => 'user'], 'Anna Verdi') === 'Anna Verdi'
    && StockMovementResource::who(['user_id' => 0, 'source' => 'import'], null) === 'Importazione'
    && StockMovementResource::who(['user_id' => 0, 'source' => 'system'], null) === 'Sistema'
    && StockMovementResource::who(['user_id' => 0, 'source' => ''], null) === 'Sistema'
    && StockMovementResource::who(['user_id' => 9, 'source' => 'user'], null) === 'Sistema'
    && StockMovementResource::who(['user_id' => 0, 'source' => 'cron'], '') === 'Sistema'
);

check('Chi: un nome con un tag non esce come HTML', fn () =>
    !str_contains(StockMovementResource::whoCell(['user_id' => 3], '<b>Anna</b>'), '<b>')
);

check('Tipo col documento: il numero dell\'ordine, con il link', function () {
    $html = StockMovementResource::typeCell(['type' => 'sale', 'reference_type' => 'order', 'reference_id' => 9], '2026/0009', '/backend/ordini/9/');

    return str_contains($html, 'Vendita')
        && str_contains($html, '2026/0009')
        && str_contains($html, 'href="/backend/ordini/9/"');
});

check('Tipo senza documento è solo l\'etichetta, senza link', function () {
    $html = StockMovementResource::typeCell(['type' => 'adjustment', 'reference_type' => '', 'reference_id' => 0], '', '');

    return $html === 'Rettifica' && !str_contains($html, '<a');
});

check('Tipo con un ordine sparito mostra l\'etichetta e basta', function () {
    $html = StockMovementResource::typeCell(['type' => 'sale', 'reference_type' => 'order', 'reference_id' => 77], '', '');

    return $html === 'Vendita';
});

check('il menu ⋯ ha «Apri la versione» e «Visualizza ordine» solo per le righe di un ordine', function () {
    foreach (StockMovementResource::tableSchema() as $colonna) {
        if ((string) $colonna->name !== 'actions') {
            continue;
        }

        $azioni = (array) $colonna->getSchema('actions');
        $versione = (array) ($azioni['versione'] ?? []);
        $ordine = (array) ($azioni['ordine'] ?? []);

        return ($versione['label'] ?? '') === 'Apri la versione'
            && str_contains((string) ($versione['href'] ?? ''), '{product_id}')
            && !isset($versione['filter'])
            && ($ordine['label'] ?? '') === 'Visualizza ordine'
            && str_contains((string) ($ordine['href'] ?? ''), '{reference_id}')
            && ($ordine['filter']['row']['reference_type'] ?? '') === 'order';
    }

    return false;
});

check('ricerca su codice, nota, SKU, EAN, nome dell\'opzione e dell\'articolo', function () use ($layout) {
    $campi = (array) $layout()['search_fields'];
    $relazione = null;

    foreach ($campi as $campo) {
        if (is_array($campo)) {
            $relazione = $campo;
        }
    }

    $figlia = (array) (($relazione['relations'] ?? [])[0] ?? []);

    return in_array('code', $campi, true) && in_array('note', $campi, true)
        && $relazione !== null
        && ($relazione['local_key'] ?? '') === 'product_id'
        && ($relazione['foreign_key'] ?? '') === 'id'
        && array_diff(['sku', 'ean', 'name'], (array) $relazione['columns']) === []
        && ($figlia['local_key'] ?? '') === 'product_model_id'
        && in_array('name', (array) ($figlia['columns'] ?? []), true);
});

check('il filtro Tipo offre i tipi veri, con «Tutti»', function () use ($filtro) {
    $f = $filtro('tipo');

    return $f !== null && array_keys((array) $f['array']) === ['', ...StockMovement::TYPES] && is_callable($f['where']);
});

check('il filtro Tipo non fa passare niente che non sia un tipo', function () use ($filtro) {
    $dove = $filtro('tipo')['where'];

    return $dove(['sale']) === "`type` IN ('sale')"
        && $dove(["sale') OR 1=1 --"]) === ''
        && $dove([]) === ''
        && $dove(['']) === '';
});

check('il filtro Causale offre le causali vere', function () use ($filtro) {
    $f = $filtro('reason');

    return $f !== null && array_keys((array) $f['array']) === array_keys(Reasons::all());
});

check('il filtro Sede c\'è solo con più sedi, e filtra per id', function () use ($conSedi, $unaSede, $dueSedi, $filtro) {
    $senza = $conSedi($unaSede, fn () => $filtro('sede'));
    $con = $conSedi($dueSedi, fn () => $filtro('sede'));

    return $senza === null
        && $con !== null
        && array_map('strval', array_keys((array) $con['array'])) === ['', '1', '2']
        && ($con['where'])(['2']) === '`location_id` IN (2)'
        && ($con['where'])(['2) OR (1=1']) === ''
        && ($con['where'])(['']) === '';
});

check('il filtro Periodo offre i cinque periodi, e usa la data del movimento', function () use ($filtro) {
    $f = $filtro('periodo');

    return $f !== null
        && array_keys((array) $f['array']) === ['', ...array_keys(MovementPeriod::OPTIONS)]
        && str_contains(($f['where'])(['oggi']), '`creation` >= ')
        && ($f['where'])(['']) === ''
        && ($f['where'])(["oggi'; DROP TABLE x; --"]) === '';
});

check('i vecchi parametri dal e al non filtrano più', function () {
    $_GET['dal'] = '2026-09-01';
    $_GET['al'] = '2026-09-30';
    $schema = StockMovementResource::querySchema();
    unset($_GET['dal'], $_GET['al']);

    return !str_contains(json_encode($schema['condition'] ?? ''), '2026-09');
});

check('l\'ultimo movimento sta in cima', fn () =>
    StockMovementResource::$orderColumn === 'id' && StockMovementResource::$orderDirection === 'DESC'
);

check('l\'elenco si filtra su una versione dall\'indirizzo, senza far riemergere le righe cancellate', function () {
    $_GET['versione'] = '42';
    $schema = StockMovementResource::querySchema();
    unset($_GET['versione']);
    $condizione = (string) ($schema['condition'] ?? '');

    return str_contains($condizione, 'product_id = 42') && str_contains($condizione, "deleted = 'false'");
});

check('una versione scritta male non arriva al database', function () {
    $_GET['versione'] = "42; DROP TABLE gst_stock_movements";
    $schema = StockMovementResource::querySchema();
    unset($_GET['versione']);

    return !str_contains(json_encode($schema['condition'] ?? ''), 'DROP');
});

check('con ?versione= il titolo cambia e compare «Mostra tutti»', function () use ($layout) {
    $senza = $layout();
    $_GET['versione'] = '42';
    $con = $layout();
    unset($_GET['versione']);

    $bottoni = implode('', array_map('strval', (array) ($con['buttons_custom'] ?? [])));

    return ($senza['title']['text'] ?? '') === 'Movimenti'
        && (array) ($senza['buttons_custom'] ?? []) === []
        && str_starts_with((string) ($con['title']['text'] ?? ''), 'Movimenti di ')
        && str_contains($bottoni, 'Mostra tutti')
        && str_contains($bottoni, 'href="'.htmlspecialchars(StockMovementResource::listUrl(), ENT_QUOTES).'"');
});

check('il link dalla scheda porta all\'elenco filtrato', fn () =>
    str_ends_with(StockMovementResource::listUrlFor(7), '?versione=7')
);

summary();
