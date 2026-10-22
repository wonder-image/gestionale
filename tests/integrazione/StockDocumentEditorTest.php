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
use Wonder\Plugin\Gestionale\Resources\Stock\StockStocktakeResource;
use Wonder\Plugin\Gestionale\Support\SearchClient;
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
    file_put_contents($file, "var window = {addEventListener: function () {}}; var document = {readyState: 'complete', querySelectorAll: function () { return []; }};\n"
        .SearchClient::source()."\n"
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

check('script: il lettore veloce non perde letture, anche con le risposte in disordine (node)', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($node === '') {
        echo "  (salto: node non c'è in PATH)\n";

        return true;
    }

    // Un DOM finto quanto basta all'editor; fetch resta in sospeso finché la prova non risponde.
    $harness = <<<'JS'
function El(tag) { this.tagName = tag; this.children = []; this.attrs = {}; this.listeners = {}; this.value = ''; this.textContent = ''; this.className = ''; this.style = {}; this.parentNode = null; this.firstChild = null; this.classList = {add: function () {}, remove: function () {}}; }
El.prototype.appendChild = function (c) { c.parentNode = this; this.children.push(c); this.firstChild = this.children[0]; return c; };
El.prototype.removeChild = function (c) { this.children.splice(this.children.indexOf(c), 1); this.firstChild = this.children[0] || null; return c; };
El.prototype.insertBefore = function (c) { return this.appendChild(c); };
El.prototype.setAttribute = function (k, v) { this.attrs[k] = String(v); };
El.prototype.getAttribute = function (k) { return k in this.attrs ? this.attrs[k] : null; };
El.prototype.addEventListener = function (t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); };
El.prototype.fire = function (t, e) { (this.listeners[t] || []).forEach(function (f) { f(e); }); };
El.prototype.focus = function () {};
El.prototype.select = function () {};
El.prototype.contains = function () { return true; };
El.prototype.closest = function () { return null; };
var parts = {search: new El('input'), results: new El('div'), message: new El('div'), rows: new El('tbody'), empty: new El('div'), totals: new El('div'), lines: new El('script')};
parts.lines.textContent = '[]';
var root = new El('div');
root.setAttribute('data-search-url', '/cerca/');
root.querySelector = function (sel) { return parts[sel.replace('[data-wi-stock-', '').replace(']', '')]; };
var window = {addEventListener: function () {}};
var document = {readyState: 'complete', createElement: function (t) { return new El(t); }, querySelectorAll: function () { return [root]; }, addEventListener: function () {}};
var answers = {'111': {result: 'one', versions: [{id: 1, label: 'A', stock: 0}]}, '222': {result: 'one', versions: [{id: 2, label: 'B', stock: 0}]}, 'zzz': {result: 'none', versions: []}};
var waiting = [];
window.fetch = function (url) {
    var code = decodeURIComponent((/code=([^&]*)/.exec(url) || [])[1] || '');
    return new Promise(function (resolve) { waiting.push(function () { resolve({ok: true, json: function () { return answers[code] || {result: 'none', versions: []}; }}); }); });
};
JS;
    $run = <<<'JS'
// Il lettore scrive il codice e Invio; la lettura dopo arriva prima delle risposte.
['111', '222', '111', 'zzz'].forEach(function (code) {
    parts.search.value += code;
    parts.search.fire('input', {});
    parts.search.fire('keydown', {key: 'Enter', preventDefault: function () {}});
});
waiting.slice().reverse().forEach(function (answer) { answer(); });
setTimeout(function () {
    console.log(JSON.stringify({
        rows: parts.rows.children.map(function (tr) { var input = tr.children[3].children[0]; return [input.getAttribute('aria-label'), input.value]; }),
        box: parts.search.value,
        message: parts.message.textContent
    }));
}, 50);
JS;
    $base = (string) tempnam(sys_get_temp_dir(), 'wi-stock');
    $file = $base.'.js';
    file_put_contents($file, $harness."\n".SearchClient::source()."\n".StockDocumentEditor::script()."\n".$run);
    $out = (string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1');
    $js = json_decode($out, true);
    @unlink($file);
    @unlink($base);

    if (!is_array($js)) {
        echo '  node: '.$out."\n";

        return false;
    }

    $ok = $js['rows'] === [['Pezzi di A', '2'], ['Pezzi di B', '1']]
        && $js['box'] === ''
        && str_contains($js['message'], 'zzz');

    if (!$ok) {
        echo '  '.json_encode($js, JSON_UNESCAPED_UNICODE)."\n";
    }

    return $ok;
});

check('stocktakeTotals: righe, contate e con differenza, singolare e plurale', fn () =>
    StockDocumentEditor::stocktakeTotals(40, 12, 3) === '40 righe · 12 contate · 3 con differenza'
    && StockDocumentEditor::stocktakeTotals(1, 1, 0) === '1 riga · 1 contata · 0 con differenza'
);

check('render inventario: Attesi · Contati · Differenza, Aggiungi versioni con le scelte escapate, totale dalle righe', function () {
    $righe = [
        ['id' => 1, 'label' => 'A', 'sku' => '', 'ean' => '', 'photo' => '', 'stock' => 5.0, 'inactive' => false, 'quantity' => '6', 'expected' => 4.0],
        ['id' => 2, 'label' => 'B', 'sku' => '', 'ean' => '', 'photo' => '', 'stock' => 3.0, 'inactive' => false, 'quantity' => '3', 'expected' => 3.0],
        ['id' => 3, 'label' => 'C', 'sku' => '', 'ean' => '', 'photo' => '', 'stock' => 2.0, 'inactive' => false, 'quantity' => '', 'expected' => null],
    ];
    $html = StockDocumentEditor::render($righe, '/x/', 'stocktake', [
        'categories' => ['4' => 'Maglie', '5' => '— <b>Rosse</b>'],
        'brands' => ['9' => 'Marca & Co'],
    ]);
    $carico = StockDocumentEditor::render([], '/x/');

    return str_contains($html, 'data-mode="stocktake"')
        && str_contains($html, '>Attesi</th>') && str_contains($html, '>Contati</th>') && str_contains($html, '>Differenza</th>')
        && !str_contains($html, '>Pezzi</th>')
        && str_contains($html, '<option value="all">Tutte le versioni</option>')
        && str_contains($html, '<optgroup label="Categorie"><option value="category:4">Maglie</option><option value="category:5">— &lt;b&gt;Rosse&lt;/b&gt;</option></optgroup>')
        && str_contains($html, '<option value="brand:9">Marca &amp; Co</option>')
        && str_contains($html, 'data-wi-stock-scope-add>Aggiungi versioni</button>')
        && str_contains($html, '3 righe · 2 contate · 1 con differenza')
        && str_contains($carico, 'data-mode="pieces"')
        && !str_contains($carico, 'Tutte le versioni')
        && str_contains($carico, '>Pezzi</th>');
});

check('confirmQuestion inventario: contate, differenza e correzione da riempire', fn () =>
    StockStocktakeResource::confirmQuestion() === '{contate}, {differenza}: {correzione}. Dopo non si modifica più: si può solo annullare.'
);

check('script inventario: attesi come StockDocumentRules::expected(), differenza, cambio sede, Aggiungi versioni (node)', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($node === '') {
        echo "  (salto: node non c'è in PATH)\n";

        return true;
    }

    $harness = <<<'JS'
function El(tag) { this.tagName = tag; this.children = []; this.attrs = {}; this.listeners = {}; this.value = ''; this.textContent = ''; this.className = ''; this.style = {}; this.parentNode = null; this.firstChild = null; this.disabled = false; this.classList = {add: function () {}, remove: function () {}}; }
El.prototype.appendChild = function (c) { c.parentNode = this; this.children.push(c); this.firstChild = this.children[0]; return c; };
El.prototype.removeChild = function (c) { this.children.splice(this.children.indexOf(c), 1); this.firstChild = this.children[0] || null; return c; };
El.prototype.insertBefore = function (c) { return this.appendChild(c); };
El.prototype.setAttribute = function (k, v) { this.attrs[k] = String(v); };
El.prototype.getAttribute = function (k) { return k in this.attrs ? this.attrs[k] : null; };
El.prototype.addEventListener = function (t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); };
El.prototype.fire = function (t, e) { (this.listeners[t] || []).forEach(function (f) { f(e || {}); }); };
El.prototype.focus = function () {};
El.prototype.select = function () {};
El.prototype.contains = function () { return true; };
var parts = {search: new El('input'), results: new El('div'), message: new El('div'), rows: new El('tbody'), empty: new El('div'), totals: new El('div'), lines: new El('script'), scope: new El('select'), 'scope-add': new El('button')};
parts.lines.textContent = JSON.stringify([
    {id: 1, label: 'A', stock: 5, expected: 4, quantity: '6'},
    {id: 2, label: 'B', stock: 3, expected: null, quantity: ''}
]);
var place = new El('select');
place.value = '1';
var root = new El('div');
root.setAttribute('data-search-url', '/cerca/');
root.setAttribute('data-mode', 'stocktake');
root.setAttribute('data-max-lines', '4');
root.querySelector = function (sel) { return parts[sel.replace('[data-wi-stock-', '').replace(']', '')]; };
root.closest = function () { return {querySelector: function () { return place; }}; };
var window = {addEventListener: function () {}};
var document = {readyState: 'complete', createElement: function (t) { return new El(t); }, querySelectorAll: function () { return [root]; }, addEventListener: function () {}};
var urls = [];
window.fetch = function (url) {
    var data = {versions: []};
    urls.push(url);
    if (url.indexOf('ids=') !== -1) { data = {versions: [{id: 1, label: 'A', stock: 10}, {id: 2, label: 'B', stock: 1}]}; }
    if (url.indexOf('scope_id=3') !== -1) { data = {count: 3, ids: [1, 2, 3], versions: [{id: 1, label: 'A', stock: 10}, {id: 2, label: 'B', stock: 1}, {id: 3, label: 'C', stock: 2}]}; }
    if (url.indexOf('scope=all') !== -1) { data = {count: 600, ids: [1, 2, 3, 4, 5], versions: []}; }
    return Promise.resolve({ok: true, json: function () { return data; }});
};
JS;
    $run = <<<'JS'
var ed = root.wiStockEditor;
function row(i) { var tr = parts.rows.children[i]; return [tr.children[2].textContent, tr.children[3].children[0].value, tr.children[4].textContent]; }
function input(i) { return parts.rows.children[i].children[3].children[0]; }
function wait() { return new Promise(function (r) { setTimeout(r, 10); }); }
var out = {};
out.start = [row(0), row(1), parts.totals.textContent, ed.words()];
input(0).value = '5'; input(0).fire('input');
out.newCount = [row(0), parts.totals.textContent, ed.words()];
input(0).value = '6'; input(0).fire('input');
out.back = row(0);
input(1).value = '0'; input(1).fire('input');
out.zero = [row(1), ed.words()];
place.value = '2'; place.fire('change');
wait().then(function () {
    out.place = [row(0), row(1)];
    place.value = '1'; place.fire('change');
    return wait();
}).then(function () {
    out.placeBack = row(0);
    parts.scope.value = 'brand:3'; parts['scope-add'].fire('click');
    return wait();
}).then(function () {
    out.scope = [parts.rows.children.length, row(2), parts.message.textContent];
    parts['scope-add'].fire('click');
    return wait();
}).then(function () {
    out.again = parts.message.textContent;
    parts.scope.value = 'all'; parts['scope-add'].fire('click');
    return wait();
}).then(function () {
    out.crowded = [parts.rows.children.length, parts.message.textContent];
    out.scopeUrl = urls.filter(function (u) { return u.indexOf('scope=brand') !== -1; })[0];
    input(0).value = ''; input(0).fire('input'); input(1).value = ''; input(1).fire('input');
    out.none = ed.words();
    console.log(JSON.stringify(out));
});
JS;
    $base = (string) tempnam(sys_get_temp_dir(), 'wi-stock');
    $file = $base.'.js';
    file_put_contents($file, $harness."\n".SearchClient::source()."\n".StockDocumentEditor::script()."\n".$run);
    $raw = (string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1');
    $js = json_decode($raw, true);
    @unlink($file);
    @unlink($base);

    $atteso = [
        'start' => [['4', '6', '+2'], ['3', '', '—'], '2 righe · 1 contata · 1 con differenza',
            ['contate' => '1 riga contata', 'differenza' => '1 con differenza', 'correzione' => 'la giacenza di quella versione si corregge']],
        'newCount' => [['5', '5', '0'], '2 righe · 1 contata · 0 con differenza',
            ['contate' => '1 riga contata', 'differenza' => 'nessuna differenza', 'correzione' => 'la giacenza non cambia']],
        'back' => ['4', '6', '+2'],
        'zero' => [['3', '0', '−3'], ['contate' => '2 righe contate', 'differenza' => '2 con differenza', 'correzione' => 'la giacenza di quelle 2 versioni si corregge']],
        'place' => [['10', '6', '−4'], ['1', '0', '−1']],
        'placeBack' => ['4', '6', '+2'],
        'scope' => [3, ['2', '', '—'], 'Aggiunta 1 versione.'],
        'again' => 'Ci sono già tutte.',
        'crowded' => [3, 'Sarebbero 5 righe: un documento ne tiene al massimo 4. Conta per categorie o per marchi.'],
        'scopeUrl' => '/cerca/?scope=brand&scope_id=3&location=1',
        'none' => null,
    ];

    if ($js !== $atteso) {
        echo '  '.$raw."\n";

        return false;
    }

    return true;
});

check('script: l\'editor si lega dopo createCard() del core, al nodo che resta nella pagina (node)', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($node === '') {
        echo "  (salto: node non c'è in PATH)\n";

        return true;
    }

    // Il nodo visto durante il parse può essere sostituito prima di «loaded»:
    // l'editor si lega a quello che resta nella pagina.
    $harness = <<<'JS'
function El(tag) { this.tagName = tag; this.children = []; this.attrs = {}; this.listeners = {}; this.value = ''; this.textContent = ''; this.className = ''; this.style = {}; this.parentNode = null; this.firstChild = null; this.classList = {add: function () {}, remove: function () {}}; }
El.prototype.appendChild = function (c) { c.parentNode = this; this.children.push(c); this.firstChild = this.children[0]; return c; };
El.prototype.removeChild = function (c) { this.children.splice(this.children.indexOf(c), 1); this.firstChild = this.children[0] || null; return c; };
El.prototype.insertBefore = function (c) { return this.appendChild(c); };
El.prototype.setAttribute = function (k, v) { this.attrs[k] = String(v); };
El.prototype.getAttribute = function (k) { return k in this.attrs ? this.attrs[k] : null; };
El.prototype.addEventListener = function (t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); };
El.prototype.contains = function () { return true; };
El.prototype.closest = function () { return null; };
function makeRoot() {
    var parts = {search: new El('input'), results: new El('div'), message: new El('div'), rows: new El('tbody'), empty: new El('div'), totals: new El('div'), lines: new El('script')};
    var root = new El('div');
    parts.lines.textContent = '[]';
    root.setAttribute('data-search-url', '/cerca/');
    root.querySelector = function (sel) { return parts[sel.replace('[data-wi-stock-', '').replace(']', '')] || null; };
    return root;
}
var handlers = {};
var window = {addEventListener: function (t, f) { (handlers[t] = handlers[t] || []).push(f); }};
var parsed = makeRoot();
var copy = makeRoot();
var late = makeRoot();
var live = [parsed];
var document = {readyState: 'loading', createElement: function (t) { return new El(t); }, querySelectorAll: function () { return live; }, addEventListener: function () {}};
var out = {};
JS;
    $loaded = <<<'JS'
out.atParse = !!parsed.wiStockEditor;
live = [copy];
(handlers.loaded || []).forEach(function (f) { f(); });
out.copy = !!copy.wiStockEditor;
out.parsed = !!parsed.wiStockEditor;
// Uno script arrivato a pagina già pronta non aspetta un «loaded» che non torna.
document.readyState = 'complete';
live = [copy, late];
JS;
    $late = <<<'JS'
out.late = !!late.wiStockEditor;
console.log(JSON.stringify(out));
JS;
    $base = (string) tempnam(sys_get_temp_dir(), 'wi-stock');
    $file = $base.'.js';
    file_put_contents($file, $harness."\n".SearchClient::source()."\n".StockDocumentEditor::script()."\n".$loaded."\n".StockDocumentEditor::script()."\n".$late);
    $raw = (string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1');
    $js = json_decode($raw, true);
    @unlink($file);
    @unlink($base);

    if ($js !== ['atParse' => false, 'copy' => true, 'parsed' => false, 'late' => true]) {
        echo '  '.$raw."\n";

        return false;
    }

    return true;
});

check('pagina: Conferma e lo spegnimento dei bottoni valgono anche per i bottoni sostituiti dopo il caricamento (node)', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($node === '') {
        echo "  (salto: node non c'è in PATH)\n";

        return true;
    }

    // Lo script della vista, con una stringa al posto di ogni valore scritto dal PHP.
    $view = (string) file_get_contents(Gestionale::viewPath('pages/stock-document-form.php'));

    if (!preg_match('~<script>(.*)</script>~s', $view, $match)) {
        return false;
    }

    $page = (string) preg_replace('~<\?=.*?\?>~', '"x"', $match[1]);
    $harness = <<<'JS'
function Btn(confirm) { this.disabled = false; this.confirm = confirm; this.listeners = {}; }
Btn.prototype.addEventListener = function (t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); };
Btn.prototype.closest = function (sel) { return sel === '[data-wi-doc-confirm]' && this.confirm ? this : null; };
var listeners = {};
var parsed = {save: new Btn(false), confirm: new Btn(true)};
var copy = {save: new Btn(false), confirm: new Btn(true)};
var live = parsed;
var intent = {value: 'save'};
var lines = {value: ''};
var root = {wiStockEditor: {validate: function () { return true; }, words: function () { return null; }, serialize: function () { return '[]'; }}};
var requested = false;
var form = {
    addEventListener: function (t, f) { (listeners[t] = listeners[t] || []).push(f); },
    querySelector: function (sel) {
        return {'input[name="intent"]': intent, 'input[name="lines"]': lines, '[data-wi-doc-confirm]': live.confirm, '[data-wi-stock-editor]': root}[sel] || null;
    },
    querySelectorAll: function () { return [live.save, live.confirm]; },
    requestSubmit: function () {
        var event = {preventDefault: function () {}};
        requested = true;
        (listeners.submit || []).forEach(function (f) { f(event); });
    }
};
var document = {getElementById: function () { return form; }};
var window = {addEventListener: function () {}};
function click(target) { (listeners.click || []).forEach(function (f) { f({target: target}); }); }
JS;
    $run = <<<'JS'
live = copy;
var out = {};
click(copy.save);
out.other = requested;
click(copy.confirm);
out.requested = requested;
out.intent = intent.value;
out.lines = lines.value;
out.off = copy.save.disabled && copy.confirm.disabled;
console.log(JSON.stringify(out));
JS;
    $base = (string) tempnam(sys_get_temp_dir(), 'wi-stock');
    $file = $base.'.js';
    file_put_contents($file, $harness."\n".$page."\n".$run);
    $raw = (string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1');
    $js = json_decode($raw, true);
    @unlink($file);
    @unlink($base);

    if ($js !== ['other' => false, 'requested' => true, 'intent' => 'confirm', 'lines' => '[]', 'off' => true]) {
        echo '  '.$raw."\n";

        return false;
    }

    return true;
});

summary();
