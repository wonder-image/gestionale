<?php
/** php tests/StockMovementResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Backend\Support\ResourceTableRenderer;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Stock\StockIssueResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockReceiptResource;
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

check('i movimenti si leggono e basta: elenco e scheda, niente aggiungi, modifica o elimina', function () use ($pagine) {
    $attive = array_keys(array_filter((array) ($pagine['pages'] ?? [])));
    sort($attive);

    return $attive === ['list', 'view'];
});

check('la scheda si apre con gli stessi permessi dell\'elenco', function () {
    $backend = (array) (StockMovementResource::permissionSchema()->toArray()['backend'] ?? []);
    $azioni = array_keys(array_filter($backend, static fn ($chi): bool => $chi !== []));
    sort($azioni);

    return $azioni === ['list', 'view']
        && ($backend['view'] ?? []) === ['admin', 'administrator'];
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

check('il menu ⋯: Visualizza, documento, ordine; il documento solo sulle righe che ne hanno uno', function () {
    foreach (StockMovementResource::tableSchema() as $colonna) {
        if ((string) $colonna->name !== 'actions') {
            continue;
        }

        $azioni = (array) $colonna->getSchema('actions');
        $documento = (array) ($azioni['document_type'] ?? []);
        $ordine = (array) ($azioni['ordine'] ?? []);

        return array_keys($azioni) === ['view', 'document_type', 'ordine']
            && ($azioni['view'] ?? null) === true
            && ($documento['label'] ?? null) === ['receipt' => 'Visualizza carico', 'issue' => 'Visualizza scarico', 'stocktake' => 'Visualizza inventario', 'transfer' => 'Visualizza trasferimento']
            && ($documento['href'] ?? '') === '{document_url}'
            && !isset($documento['filter'])
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

check('il filtro Tipo offre i documenti al posto del carico, con «Tutti»', function () use ($filtro) {
    $f = $filtro('tipo');

    return $f !== null
        && array_keys((array) $f['array']) === [
            '', 'sale', 'sale_cancel', 'return', 'doc_receipt', 'doc_issue', 'doc_stocktake',
            'adjustment', 'transfer_in', 'transfer_out',
        ]
        && $f['array']['doc_receipt'] === 'Carico'
        && $f['array']['doc_issue'] === 'Scarico'
        && $f['array']['doc_stocktake'] === 'Inventario'
        && is_callable($f['where']);
});

check('il filtro Tipo: un tipo semplice è il suo tipo', function () use ($filtro) {
    return $filtro('tipo')['where'](['sale']) === "`type` = 'sale'";
});

check('il filtro Tipo: Scarico guarda il documento, storni compresi', function () use ($filtro) {
    $sql = $filtro('tipo')['where'](['doc_issue']);

    return str_contains($sql, "`reference_type` IN ('stock_document', 'stock_document_cancel')")
        && str_contains($sql, "FROM `gst_stock_documents` WHERE `type` = 'issue'");
});

check('il filtro Tipo: Carico prende anche i carichi senza documento', function () use ($filtro) {
    $sql = $filtro('tipo')['where'](['doc_receipt']);

    return str_contains($sql, "`type` = 'receipt'") && str_contains($sql, "`type` = 'purchase' AND");
});

check('il filtro Tipo: Rettifica è una rettifica senza documento', function () use ($filtro) {
    $sql = $filtro('tipo')['where'](['adjustment']);

    return str_starts_with($sql, "(`type` = 'adjustment' AND (`reference_type` IS NULL OR `reference_type` NOT IN");
});

check('il filtro Tipo: più voci vanno in OR fra parentesi', function () use ($filtro) {
    $sql = $filtro('tipo')['where'](['sale', 'doc_issue']);

    return str_starts_with($sql, "(`type` = 'sale' OR (") && str_ends_with($sql, ')');
});

check('il filtro Tipo non fa passare niente che non sia una voce', function () use ($filtro) {
    $dove = $filtro('tipo')['where'];

    return $dove(["sale') OR 1=1 --"]) === ''
        && $dove(["doc_issue') OR 1=1 --"]) === ''
        && $dove(['doc_transfer']) === ''
        && $dove(['purchase']) === ''
        && $dove([]) === ''
        && $dove(['']) === '';
});

check('Tipo con un documento: l\'etichetta del documento, non quella del tipo registrato', function () {
    $html = StockMovementResource::typeCell(['type' => 'adjustment'], '2026/100003', '', 'Scarico');

    return $html === 'Scarico · 2026/100003';
});

check('Tipo con uno storno: «Annullamento del carico» col numero', function () {
    $html = StockMovementResource::typeCell(['type' => 'purchase'], '2026/100001', '', 'Annullamento del carico');

    return $html === 'Annullamento del carico · 2026/100001';
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

$movimento = [
    'id' => 5, 'code' => 'MOV-00005', 'creation' => '2026-10-12 09:15:00', 'product_id' => 0, 'location_id' => 2,
    'type' => 'adjustment', 'reason' => 'damaged', 'quantity_before' => '10.000', 'quantity' => '-3.000',
    'quantity_after' => '7.000', 'unit_cost' => '12.5000', 'batch_id' => 0, 'supplier_id' => 0,
    'source' => 'user', 'user_id' => 0, 'note' => "Caduto <b>dallo</b> scaffale\nseconda riga",
];
$scheda = static fn (array $riga, array $sedi): string => $conSedi($sedi, static fn (): string =>
    Wonder\Backend\Support\ResourceFormLayoutRenderer::renderLayout(StockMovementResource::showLayoutSchema($riga))
);
$voce = static fn (string $html, string $etichetta, string $valore): bool =>
    str_contains($html, '<div class="small text-muted">'.$etichetta.'</div><div>'.$valore.'</div>');

check('la scheda del movimento ha tutti i dettagli', function () use ($scheda, $movimento, $unaSede, $voce) {
    $html = $scheda($movimento, $unaSede);

    return $voce($html, 'Codice', 'MOV-00005')
        && $voce($html, 'Quando', '12/10/2026 09:15')
        && $voce($html, 'Tipo', 'Rettifica')
        && $voce($html, 'Causale', 'Danneggiato')
        && $voce($html, 'Prima', '10')
        && $voce($html, 'Pezzi', '-3')
        && $voce($html, 'Dopo', '7')
        && $voce($html, 'Costo unitario', '12,50 €')
        && $voce($html, 'Chi', 'Sistema')
        && str_contains($html, 'Versione');
});

check('la nota della scheda va a capo e non esce come HTML', function () use ($scheda, $movimento, $unaSede) {
    $html = $scheda($movimento, $unaSede);

    return !str_contains($html, '<b>dallo</b>')
        && str_contains($html, 'Caduto &lt;b&gt;dallo&lt;/b&gt; scaffale<br />');
});

check('la scheda: la sede solo con più sedi; lotto e fornitore solo se ci sono; senza costo un trattino', function () use ($scheda, $movimento, $unaSede, $dueSedi, $voce) {
    $una = $scheda($movimento, $unaSede);
    $due = $scheda($movimento, $dueSedi);
    $senzaCosto = $scheda(['unit_cost' => null] + $movimento, $unaSede);
    $conLotto = $scheda(['batch_id' => 4] + $movimento, $unaSede);

    return !str_contains($una, '>Sede<') && $voce($due, 'Sede', 'Magazzino')
        && !str_contains($una, '>Lotto<') && !str_contains($una, '>Fornitore<')
        && $voce($conLotto, 'Lotto', '#4')
        && $voce($senzaCosto, 'Costo unitario', '—');
});

check('la versione della scheda porta alla sua pagina', function () use ($scheda, $movimento, $unaSede) {
    $html = $scheda(['product_id' => 76918] + $movimento, $unaSede);

    return str_contains($html, '/76918/edit/');
});

check('l\'indirizzo della scheda di un movimento', fn () =>
    str_ends_with(StockMovementResource::detailUrl(5), '/movimenti/5/')
);

check('il link dalla scheda porta all\'elenco filtrato', fn () =>
    str_ends_with(StockMovementResource::listUrlFor(7), '?versione=7')
);

check('l\'elenco legge tipo, numero e indirizzo del documento con select()', function () use ($layout) {
    $select = StockMovementResource::select();

    return ($layout()['select'] ?? '') === $select
        && ResourceTableRenderer::selectAliases($select) === ['document_type', 'document_number', 'document_url']
        && str_contains($select, "IN ('stock_document', 'stock_document_cancel')");
});

check('l\'indirizzo calcolato copre carichi, scarichi e inventari', function () {
    $select = StockMovementResource::select();

    return str_contains($select, "WHEN 'receipt' THEN")
        && str_contains($select, "WHEN 'issue' THEN")
        && str_contains($select, "WHEN 'stocktake' THEN");
});

check('il modello dell\'indirizzo della scheda tiene il segnaposto al posto dell\'id', fn () =>
    StockReceiptResource::viewUrlPattern('{x}') === str_replace('/8/', '/{x}/', StockReceiptResource::viewUrl(8))
    && StockIssueResource::viewUrlPattern('__ID__') === str_replace('/8/', '/__ID__/', StockIssueResource::viewUrl(8))
);

check('Tipo legge il documento dalla riga, senza database', fn () =>
    StockMovementResource::typeCellOf([
        'type' => 'adjustment', 'reference_type' => 'stock_document', 'reference_id' => 5,
        'document_type' => 'issue', 'document_number' => '2026/100003',
    ]) === 'Scarico · 2026/100003'
    && StockMovementResource::typeCellOf([
        'type' => 'purchase', 'reference_type' => 'stock_document_cancel', 'reference_id' => 4,
        'document_type' => 'receipt', 'document_number' => '2026/100001',
    ]) === 'Annullamento del carico · 2026/100001'
);

check('Tipo con le colonne del documento vuote resta l\'etichetta del tipo', fn () =>
    StockMovementResource::typeCellOf([
        'type' => 'adjustment', 'reference_type' => '', 'reference_id' => 0,
        'document_type' => null, 'document_number' => null,
    ]) === 'Rettifica'
);

check('Movimenti sta dopo Carichi e Scarichi nel menu Magazzino', fn () =>
    (StockMovementResource::navigationSchema()->toArray()['order'] ?? null) === 50
);

summary();
