<?php
/** php tests/integrazione/StockDocumentEditorTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Stock\StockIssueResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockReceiptResource;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentEditor;
use Wonder\Plugin\Gestionale\Support\Stock\Stocktake;

$riga = ['id' => 7, 'label' => 'Maglia </script><b>rossa</b>', 'sku' => 'M-1', 'ean' => '', 'photo' => '', 'stock' => 3.0, 'inactive' => false, 'quantity' => '2,5'];
$html = StockDocumentEditor::render([$riga], '/cerca/?a=1&b=2');

check('totals: righe e pezzi, singolare e plurale, virgola e migliaia', fn () =>
    StockDocumentEditor::totals(12, 34) === '12 righe · 34 pezzi'
    && StockDocumentEditor::totals(1, 1) === '1 riga · 1 pezzo'
    && StockDocumentEditor::totals(2, 2.5) === '2 righe · 2,5 pezzi'
    && StockDocumentEditor::totals(0, 0) === '0 righe · 0 pezzi'
    && StockDocumentEditor::totals(3, 1234.125) === '3 righe · 1.234,125 pezzi'
);

check('render: le righe stanno nel JSON, mai come HTML', fn () =>
    !str_contains($html, '</script><b>')
    && !str_contains($html, '<b>rossa')
    && str_contains($html, '\u003C\/script\u003E')
);

check('render: dal JSON le righe tornano uguali', function () use ($html, $riga) {
    preg_match('#<script type="application/json" data-wi-stock-lines>(.*?)</script>#s', $html, $m);

    return isset($m[1]) && json_decode($m[1], true) === [$riga];
});

check('render: indirizzo escapato, limite di 500 righe, casella, vuoto e totale', fn () =>
    str_contains($html, 'data-search-url="/cerca/?a=1&amp;b=2"')
    && str_contains($html, 'data-max-lines="500"')
    && str_contains($html, 'data-max-message="Un documento tiene al massimo 500 righe"')
    && str_contains($html, 'placeholder="Cerca o scansiona"')
    && str_contains($html, 'Nessuna riga: cerca una versione o scansiona un codice.')
    && str_contains($html, '1 riga · 2,5 pezzi')
);

check('render: senza righe il messaggio del vuoto si vede', fn () =>
    str_contains(StockDocumentEditor::render([], '/x/'), 'class="text-body-secondary my-3" data-wi-stock-empty')
);

check('searchUrl: la rotta delle versioni', fn () =>
    str_contains(StockDocumentEditor::searchUrl(), '/gestionale/backend/magazzino/versioni/')
);

check('script: i messaggi del lettore e della ricerca', function () {
    $js = StockDocumentEditor::script();

    return str_contains($js, 'Più versioni con questo codice: scegline una')
        && str_contains($js, 'Nessuna versione con questo codice')
        && str_contains($js, 'Nessuna versione trovata')
        && str_contains($js, 'La ricerca non risponde: riprova.')
        && !str_contains($js, '</script');
});

check('script: il JS legge i pezzi come il server e scrive il totale come il PHP (node)', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($node === '') {
        echo "  (salto: node non c'è in PATH)\n";

        return true;
    }

    $casi = ['2,5', '1.234,5', '3 pz', '€5', '5€', 'abc', '', '  ', '-1', '1e3', '0', '7.5', '1 000', '--3'];
    $base = (string) tempnam(sys_get_temp_dir(), 'wi-stock');
    $file = $base.'.js';
    file_put_contents($file, "var window = {}; var document = {querySelectorAll: function () { return []; }};\n"
        .StockDocumentEditor::script()."\n"
        .'console.log(JSON.stringify({parse: '.json_encode($casi).'.map(function (c) {'
        .' var v = window.wiStockEditor.parse(c); return v === null ? null : (isNaN(v) ? "x" : v); }),'
        .' totals: window.wiStockEditor.totals(3, 1234.125)}));');
    $js = json_decode((string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file)), true);
    @unlink($file);
    @unlink($base);

    $php = array_map(static function (string $caso): float|string|null {
        if (trim($caso) === '') {
            return null;
        }

        return Stocktake::quantity($caso) ?? 'x';
    }, $casi);

    return is_array($js)
        && array_map(static fn (mixed $v): mixed => is_int($v) || is_float($v) ? (float) $v : $v, $js['parse']) === $php
        && $js['totals'] === StockDocumentEditor::totals(3, 1234.125);
});

check('confirmQuestion: la domanda della spec, con {pezzi} e {righe} da riempire', fn () =>
    StockReceiptResource::confirmQuestion() === '{pezzi} pezzi in {righe} righe entrano in magazzino. Dopo non si modifica più: si può solo annullare.'
    && StockIssueResource::confirmQuestion() === '{pezzi} pezzi in {righe} righe escono dal magazzino. Dopo non si modifica più: si può solo annullare.'
);

check('formNotices: niente senza righe perse, una frase per una e per più', fn () =>
    StockReceiptResource::formNotices(['editor' => ['lines' => [], 'dropped' => 0]], 'edit') === []
    && StockReceiptResource::formNotices([], 'create') === []
    && StockReceiptResource::formNotices(['editor' => ['lines' => [], 'dropped' => 1]], 'edit') === [[
        'level' => 'warning',
        'message' => "Una riga non c'è più: la sua versione è stata eliminata. Salvando la bozza esce anche dal documento.",
    ]]
    && StockIssueResource::formNotices(['editor' => ['lines' => [], 'dropped' => 2]], 'edit') === [[
        'level' => 'warning',
        'message' => '2 righe non ci sono più: le loro versioni sono state eliminate. Salvando la bozza escono anche dal documento.',
    ]]
);

check('pageSchema: nuovo e bozza con il template della bozza', function () {
    $schema = StockReceiptResource::pageSchema();
    $pages = (array) $schema->get('pages');
    $template = Gestionale::viewPath('pages/stock-document-form.php');

    foreach (['list', 'create', 'store', 'edit', 'update', 'delete'] as $page) {
        if (empty($pages[$page])) {
            return false;
        }
    }

    return ((array) $schema->get('views'))['form'] === $template
        && is_file($template)
        && ((array) $schema->get('titles'))['create'] === 'Nuovo carico'
        && ((array) $schema->get('titles'))['edit'] === 'Bozza di carico'
        && ((array) StockIssueResource::pageSchema()->get('titles'))['edit'] === 'Bozza di scarico';
});

summary();
