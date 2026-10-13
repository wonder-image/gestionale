<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;

/**
 * La tabella delle righe della bozza con la casella «Cerca o scansiona»
 * (G2b-bis 4.2). Il PHP disegna lo scheletro; le righe le costruisce il JS da
 * un JSON con `textContent`: il nome di una versione non diventa mai HTML.
 *
 * Il lettore di codici a barre è una tastiera che scrive e preme Invio: Invio
 * cerca il codice esatto e aggiunge solo con una versione sola. Il server
 * resta l'arbitro dei pezzi; il JS li legge allo stesso modo per segnare in
 * rosso subito una casella sbagliata.
 */
final class StockDocumentEditor
{
    /**
     * Con `stocktake` le colonne sono Articolo · Attesi · Contati · Differenza
     * (su telefono Articolo e Contati) e sotto la casella c'è *Aggiungi
     * versioni* con le scelte di `StockVersionSearch::scopeOptions()`.
     *
     * @param list<array<string, mixed>> $lines Version + `quantity` in testo (+ `expected` nell'inventario: `StockDocumentForm::editorLines()`)
     * @param array{categories?: array<string, string>, brands?: array<string, string>} $scopes
     */
    public static function render(array $lines, string $searchUrl, string $type = 'receipt', array $scopes = []): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $lines = array_values($lines);
        $counting = $type === 'stocktake';
        $pieces = 0.0;
        $counted = 0;
        $differing = 0;

        foreach ($lines as $line) {
            $quantity = Stocktake::quantity($line['quantity'] ?? null);
            $pieces += $quantity !== null && $quantity > 0 ? $quantity : 0.0;

            if ($quantity !== null && $quantity >= 0) {
                $counted++;
                $expected = (float) ($line['expected'] ?? $line['stock'] ?? 0);
                $differing += round($quantity - $expected, 3) != 0 ? 1 : 0;
            }
        }

        $json = (string) json_encode($lines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        $head = $counting
            ? '<th>Articolo</th>'
                .'<th class="d-none d-md-table-cell text-end">Attesi</th>'
                .'<th class="text-end" style="width: 8rem">Contati</th>'
                .'<th class="d-none d-md-table-cell text-end">Differenza</th>'
            : '<th>Articolo</th>'
                .'<th class="d-none d-md-table-cell text-end">Giacenza</th>'
                .'<th class="text-end" style="width: 8rem">Pezzi</th>';

        return '<div class="col-12" data-wi-stock-editor'
            .' data-mode="'.($counting ? 'stocktake' : 'pieces').'"'
            .' data-search-url="'.$e($searchUrl).'"'
            .' data-max-lines="'.StockDocumentRules::MAX_LINES.'"'
            .' data-max-quantity="'.StockDocumentRules::MAX_QUANTITY.'"'
            .' data-max-message="'.$e('Un documento tiene al massimo '.StockDocumentRules::MAX_LINES.' righe').'">'
            .'<div class="position-relative">'
            .'<div class="input-group">'
            .'<span class="input-group-text"><i class="bi bi-upc-scan" aria-hidden="true"></i></span>'
            .'<input type="search" class="form-control" data-wi-stock-search placeholder="Cerca o scansiona"'
            .' aria-label="Cerca una versione o scansiona un codice" autocomplete="off" enterkeyhint="search">'
            .'</div>'
            .'<div class="list-group position-absolute w-100 shadow d-none" style="z-index: 1050; max-height: 20rem; overflow-y: auto" data-wi-stock-results></div>'
            .'</div>'
            .($counting ? self::scopePicker($scopes) : '')
            .'<div class="small mt-1" role="status" aria-live="polite" data-wi-stock-message></div>'
            .'<div class="table-responsive mt-3">'
            .'<table class="table table-sm align-middle mb-0">'
            .'<thead><tr>'
            .$head
            .'<th style="width: 3rem"><span class="visually-hidden">Togli</span></th>'
            .'</tr></thead>'
            .'<tbody data-wi-stock-rows></tbody>'
            .'</table>'
            .'</div>'
            .'<p class="text-body-secondary my-3'.($lines === [] ? '' : ' d-none').'" data-wi-stock-empty>Nessuna riga: cerca una versione o scansiona un codice.</p>'
            .'<div class="text-end fw-semibold mt-2" data-wi-stock-totals>'.$e($counting ? self::stocktakeTotals(count($lines), $counted, $differing) : self::totals(count($lines), $pieces)).'</div>'
            .'<script type="application/json" data-wi-stock-lines>'.$json.'</script>'
            .'<script>'.self::script().'</script>'
            .'</div>';
    }

    /** «40 righe · 12 contate · 3 con differenza», «1 riga · 1 contata · 0 con differenza». */
    public static function stocktakeTotals(int $rows, int $counted, int $differing): string
    {
        return $rows.' '.($rows === 1 ? 'riga' : 'righe')
            .' · '.$counted.' '.($counted === 1 ? 'contata' : 'contate')
            .' · '.$differing.' con differenza';
    }

    /**
     * La riga di *Aggiungi versioni*: tutte, una categoria (con le sue
     * sottocategorie) o un marchio.
     *
     * @param array{categories?: array<string, string>, brands?: array<string, string>} $scopes
     */
    private static function scopePicker(array $scopes): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $groups = ['category' => ['Categorie', $scopes['categories'] ?? []], 'brand' => ['Marchi', $scopes['brands'] ?? []]];
        $options = '<option value="all">Tutte le versioni</option>';

        foreach ($groups as $scope => [$label, $choices]) {
            if ($choices === []) {
                continue;
            }

            $options .= '<optgroup label="'.$e($label).'">';

            foreach ($choices as $id => $name) {
                $options .= '<option value="'.$e($scope.':'.$id).'">'.$e((string) $name).'</option>';
            }

            $options .= '</optgroup>';
        }

        return '<div class="d-flex flex-wrap gap-2 mt-2">'
            .'<select class="form-select form-select-sm w-auto mw-100" data-wi-stock-scope aria-label="Quali versioni aggiungere">'.$options.'</select>'
            .'<button type="button" class="btn btn-sm btn-outline-dark" data-wi-stock-scope-add>Aggiungi versioni</button>'
            .'</div>';
    }

    /** «12 righe · 34 pezzi», «1 riga · 1 pezzo», «2 righe · 2,5 pezzi». */
    public static function totals(int $rows, float $pieces): string
    {
        $pieces = round($pieces, 3) + 0.0;
        $text = $pieces === round($pieces)
            ? number_format($pieces, 0, ',', '.')
            : rtrim(rtrim(number_format($pieces, 3, ',', '.'), '0'), ',');

        return $rows.' '.($rows === 1 ? 'riga' : 'righe').' · '.$text.' '.($pieces === 1.0 ? 'pezzo' : 'pezzi');
    }

    /** La rotta JSON delle versioni (Task 2). */
    public static function searchUrl(): string
    {
        $fallback = '/api/gestionale/backend/magazzino/versioni/';

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $url = (string) __r('api.gestionale.backend.stock.versions');
        } catch (Throwable) {
            return $fallback;
        }

        return $url !== '' ? $url : $fallback;
    }

    /**
     * Il JS dell'editor, ES5 in una IIFE. `window.wiStockEditor` si definisce
     * una volta sola; `init()` lega ogni `[data-wi-stock-editor]` non ancora
     * legato.
     */
    public static function script(): string
    {
        return <<<'JS'
window.wiStockEditor = window.wiStockEditor || (function () {
    'use strict';

    var MANY = 'Più versioni con questo codice: scegline una';
    var NONE = 'Nessuna versione con questo codice';
    var EMPTY = 'Nessuna versione trovata';
    var DOWN = 'La ricerca non risponde: riprova.';
    var BAD = 'Controlla i pezzi della riga in rosso.';
    var CROWDED = ' righe: un documento ne tiene al massimo ';
    // Come Numbers::fromForm(): lettere e valute solo in coda («3 pz», «5€»).
    // ES5 non ha \p{L}: bastano latino, greco, cirillico e i simboli di valuta.
    var TRAILING = /[A-Za-zªµºÀ-ɏͰ-ϿЀ-ӿ$¢-¥₠-⃏]+$/;
    var NUMERIC = /^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/;

    function trim(text) {
        return String(text == null ? '' : text).replace(/^\s+|\s+$/g, '');
    }

    // null = casella vuota, NaN = non si legge; altrimenti i pezzi a tre decimali.
    function parse(raw) {
        var text = trim(raw);

        if (text === '') {
            return null;
        }

        text = text.replace(/[   ]/g, '').replace(TRAILING, '');

        if (text.indexOf(',') !== -1) {
            text = text.replace(/\./g, '');
        }

        text = text.replace(/,/g, '.');

        return NUMERIC.test(text) ? Math.round(parseFloat(text) * 1000) / 1000 : NaN;
    }

    // «2,5», «1.234»; grouped === false per le caselle, che rileggono il punto come decimale.
    function format(number, grouped) {
        var parts = (Math.round(number * 1000) / 1000).toFixed(3).split('.');
        var decimals = parts[1].replace(/0+$/, '');
        var whole = grouped === false ? parts[0] : parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');

        return decimals === '' ? whole : whole + ',' + decimals;
    }

    function totals(rows, pieces) {
        return rows + ' ' + (rows === 1 ? 'riga' : 'righe') + ' · ' + format(pieces) + ' ' + (pieces === 1 ? 'pezzo' : 'pezzi');
    }

    // Come StockDocumentEditor::stocktakeTotals().
    function counts(rows, counted, differing) {
        return rows + ' ' + (rows === 1 ? 'riga' : 'righe')
            + ' · ' + counted + ' ' + (counted === 1 ? 'contata' : 'contate')
            + ' · ' + differing + ' con differenza';
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text != null) {
            node.textContent = String(text);
        }

        return node;
    }

    function ask(options) {
        if (window.wi && typeof window.wi.confirm === 'function') {
            return window.wi.confirm(options);
        }

        return Promise.resolve(window.confirm(options.text));
    }

    function list(data) {
        var versions = data && data.versions ? data.versions : [];

        return Object.prototype.toString.call(versions) === '[object Array]' ? versions : [];
    }

    function codes(version) {
        var parts = [];

        if (version.sku) {
            parts.push(version.sku);
        }

        if (version.ean && version.ean !== version.sku) {
            parts.push(version.ean);
        }

        return parts.join(' · ');
    }

    function editor(root) {
        var search = root.querySelector('[data-wi-stock-search]');
        var results = root.querySelector('[data-wi-stock-results]');
        var message = root.querySelector('[data-wi-stock-message]');
        var body = root.querySelector('[data-wi-stock-rows]');
        var empty = root.querySelector('[data-wi-stock-empty]');
        var summary = root.querySelector('[data-wi-stock-totals]');
        var url = root.getAttribute('data-search-url');
        var maxLines = parseInt(root.getAttribute('data-max-lines'), 10) || 500;
        var maxQuantity = parseFloat(root.getAttribute('data-max-quantity')) || 9999999.999;
        var form = root.closest('form');
        var place = form ? form.querySelector('[data-wi-stock-location]') : null;
        var counting = root.getAttribute('data-mode') === 'stocktake';
        var scope = root.querySelector('[data-wi-stock-scope]');
        var scopeAdd = root.querySelector('[data-wi-stock-scope-add]');
        // La sede salvata: tornandoci, l'atteso salvato vale di nuovo (come in StockDocumentRules::expected()).
        var origin = place ? place.value : '';
        var rows = [];
        var timer = null;
        var seq = 0;
        var scans = null;
        var initial = [];
        var k;

        function say(text, tone) {
            message.textContent = text || '';
            message.className = 'small mt-1 ' + (tone === 'error' ? 'text-danger' : 'text-body-secondary');
        }

        function request(params, done, fail) {
            var where = place ? '&location=' + encodeURIComponent(place.value) : '';

            window.fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + params + where, {
                credentials: 'same-origin',
                headers: {Accept: 'application/json'}
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.json();
            }).then(done, fail);
        }

        // La ricerca per nome: conta solo l'ultima domanda.
        function lookup(params, done) {
            var mine = ++seq;

            request(params, function (data) {
                if (mine === seq) {
                    done(data);
                }
            }, function () {
                if (mine === seq) {
                    close();
                    say(DOWN, 'error');
                }
            });
        }

        function close() {
            results.classList.add('d-none');

            while (results.firstChild) {
                results.removeChild(results.firstChild);
            }
        }

        function option(version) {
            var item = el('button', 'list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2');
            var text = el('div', 'min-w-0');
            var side = el('div', 'small text-nowrap text-body-secondary', format(Number(version.stock) || 0));

            item.type = 'button';
            text.appendChild(el('div', 'text-truncate', version.label));

            if (codes(version)) {
                text.appendChild(el('div', 'small text-body-secondary', codes(version)));
            }

            if (version.inactive) {
                side.insertBefore(el('span', 'badge text-bg-warning me-1', 'ferma'), side.firstChild);
            }

            item.appendChild(text);
            item.appendChild(side);
            item.addEventListener('click', function () {
                picked(version);
            });

            return item;
        }

        function show(versions, text, tone) {
            var i;

            close();
            say(text, tone);

            for (i = 0; i < versions.length; i++) {
                results.appendChild(option(versions[i]));
            }

            if (versions.length) {
                results.classList.remove('d-none');
            }
        }

        function byId(id) {
            var i;

            for (i = 0; i < rows.length; i++) {
                if (rows[i].id === Number(id)) {
                    return rows[i];
                }
            }

            return null;
        }

        function check(row) {
            var value = parse(row.input.value);
            var bad = value !== null && !(value >= 0 && value <= maxQuantity);

            if (bad) {
                row.input.classList.add('is-invalid');
            } else {
                row.input.classList.remove('is-invalid');
            }

            return !bad;
        }

        // L'atteso della riga, con le regole di StockDocumentRules::expected():
        // quello salvato finché la casella ha il contato salvato e la sede è
        // quella; altrimenti la giacenza di adesso.
        function expected(row) {
            var value = parse(row.input.value);

            if (row.saved !== null && value === row.savedCount && (!place || place.value === origin)) {
                return row.saved;
            }

            return row.current;
        }

        // null = non contata (o casella da correggere).
        function difference(row) {
            var value = parse(row.input.value);

            if (value === null || !(value >= 0 && value <= maxQuantity)) {
                return null;
            }

            return Math.round((value - expected(row)) * 1000) / 1000;
        }

        function paint(row) {
            var diff;

            if (!counting) {
                return;
            }

            diff = difference(row);
            row.stock.textContent = format(expected(row));
            row.diff.textContent = diff === null ? '—' : (diff > 0 ? '+' : diff < 0 ? '−' : '') + format(Math.abs(diff));
            row.diff.className = 'd-none d-md-table-cell text-end text-nowrap '
                + (diff === null || diff === 0 ? 'text-body-secondary' : diff > 0 ? 'text-success' : 'text-danger');
        }

        function count() {
            var pieces = 0;
            var counted = 0;
            var differing = 0;
            var i;
            var value;
            var diff;

            for (i = 0; i < rows.length; i++) {
                value = parse(rows[i].input.value);

                if (value > 0) {
                    pieces += value;
                }

                if (counting) {
                    diff = difference(rows[i]);
                    counted += diff === null ? 0 : 1;
                    differing += diff === null || diff === 0 ? 0 : 1;
                }
            }

            return {rows: rows.length, pieces: Math.round(pieces * 1000) / 1000, counted: counted, differing: differing};
        }

        // I segnaposto della domanda di conferma (confirmQuestion() della
        // Resource); null quando non c'è niente da chiedere e risponde il servizio.
        function words() {
            var current = count();

            if (!counting) {
                return current.rows === 0 ? null : {pezzi: format(current.pieces), righe: String(current.rows)};
            }

            if (current.counted === 0) {
                return null;
            }

            return {
                contate: current.counted === 1 ? '1 riga contata' : current.counted + ' righe contate',
                differenza: current.differing === 0 ? 'nessuna differenza' : current.differing + ' con differenza',
                correzione: current.differing === 0
                    ? 'la giacenza non cambia'
                    : 'la giacenza di ' + (current.differing === 1 ? 'quella versione' : 'quelle ' + current.differing + ' versioni') + ' si corregge'
            };
        }

        function refresh() {
            var current = count();

            summary.textContent = counting ? counts(current.rows, current.counted, current.differing) : totals(current.rows, current.pieces);

            if (rows.length) {
                empty.classList.add('d-none');
            } else {
                empty.classList.remove('d-none');
            }
        }

        function drop(row) {
            row.tr.parentNode.removeChild(row.tr);
            rows.splice(rows.indexOf(row), 1);
            refresh();
            search.focus();
        }

        function append(version, quantity) {
            var row;
            var tr;
            var article;
            var wrap;
            var text;
            var photo;
            var cell;
            var input;
            var tools;
            var remove;

            if (rows.length >= maxLines) {
                say(root.getAttribute('data-max-message'), 'error');

                return null;
            }

            row = {id: Number(version.id), label: String(version.label || '')};
            tr = el('tr');
            article = el('td');
            wrap = el('div', 'd-flex align-items-center gap-2');
            text = el('div', 'min-w-0');
            cell = el('td', 'text-end');
            input = el('input', 'form-control form-control-sm text-end');
            tools = el('td', 'text-end');
            remove = el('button', 'btn btn-sm btn-link text-danger p-1');

            if (version.photo) {
                photo = el('img', 'rounded flex-shrink-0');
                photo.src = version.photo;
                photo.alt = '';
                photo.width = 32;
                photo.height = 32;
                photo.style.objectFit = 'cover';
                wrap.appendChild(photo);
            }

            text.appendChild(el('div', 'text-truncate', row.label));

            if (codes(version)) {
                text.appendChild(el('div', 'small text-body-secondary', codes(version)));
            }

            if (version.inactive) {
                text.appendChild(el('span', 'badge text-bg-warning', 'ferma'));
            }

            wrap.appendChild(text);
            article.appendChild(wrap);

            input.type = 'text';
            input.inputMode = 'decimal';
            input.value = quantity == null ? '' : String(quantity);
            input.setAttribute('aria-label', (counting ? 'Contati di ' : 'Pezzi di ') + row.label);
            input.addEventListener('input', function () {
                check(row);
                paint(row);
                refresh();
            });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    search.focus();
                }
            });
            cell.appendChild(input);

            remove.type = 'button';
            remove.setAttribute('aria-label', 'Togli la riga');
            remove.appendChild(el('i', 'bi bi-trash'));
            remove.addEventListener('click', function () {
                ask({text: 'Togliere la riga «' + row.label + '»?', ok: 'Togli', cancel: 'Tieni', variant: 'danger'}).then(function (ok) {
                    if (ok) {
                        drop(row);
                    }
                });
            });
            tools.appendChild(remove);

            row.tr = tr;
            row.input = input;
            row.current = Number(version.stock) || 0;
            row.saved = version.expected == null ? null : Number(version.expected);
            row.savedCount = parse(quantity);
            row.stock = el('td', 'd-none d-md-table-cell text-end text-nowrap', format(row.current));
            tr.appendChild(article);
            tr.appendChild(row.stock);
            tr.appendChild(cell);

            if (counting) {
                row.diff = el('td');
                tr.appendChild(row.diff);
            }

            tr.appendChild(tools);
            body.appendChild(tr);
            rows.push(row);
            check(row);
            paint(row);
            refresh();

            return row;
        }

        // Il lettore: +1 sulla riga che c'è, o una riga nuova; la casella non si tocca (può avere già il codice dopo).
        function scanned(version) {
            var row = byId(version.id);
            var current;

            if (row) {
                current = parse(row.input.value);

                if (!(current === null || current >= 0)) {
                    check(row);
                    say(BAD, 'error');

                    return;
                }

                row.input.value = format((current || 0) + 1, false);
                check(row);
                paint(row);
                refresh();
            } else if (!append(version, '1')) {
                return;
            }

            say('');
            search.focus();
        }

        // Il lettore: ogni Invio ha la sua risposta, letta nell'ordine delle letture.
        function scan(code) {
            var answer = new Promise(function (resolve) {
                request('code=' + encodeURIComponent(code), function (data) {
                    resolve({data: data});
                }, function () {
                    resolve(null);
                });
            });

            scans = (scans || Promise.resolve()).then(function () {
                return answer;
            }).then(function (reply) {
                var versions;

                if (reply === null) {
                    close();
                    say(DOWN, 'error');

                    return;
                }

                versions = list(reply.data);

                if (reply.data && reply.data.result === 'one' && versions.length === 1) {
                    close();
                    scanned(versions[0]);
                } else if (reply.data && reply.data.result === 'many') {
                    show(versions, MANY);
                } else {
                    close();
                    say(NONE + ': ' + code, 'error');
                }
            });
        }

        // Un clic sulla lista: la riga (nuova con 1 pezzo, o da contare nell'inventario; o quella che c'è) e il fuoco sui pezzi.
        function picked(version) {
            var row = byId(version.id) || append(version, counting ? null : '1');

            close();

            if (row) {
                search.value = '';
                say('');
                row.input.focus();
                row.input.select();
            }
        }

        function validate() {
            var first = null;
            var i;

            for (i = 0; i < rows.length; i++) {
                if (!check(rows[i]) && first === null) {
                    first = rows[i];
                }
            }

            if (first !== null) {
                say(BAD, 'error');
                first.input.focus();

                return false;
            }

            return true;
        }

        function serialize() {
            var out = [];
            var i;

            for (i = 0; i < rows.length; i++) {
                out.push({product_id: rows[i].id, quantity: rows[i].input.value});
            }

            return JSON.stringify(out);
        }

        search.addEventListener('input', function () {
            var q = trim(search.value);

            clearTimeout(timer);

            if (q.length < 2) {
                seq++;
                close();

                return;
            }

            timer = setTimeout(function () {
                lookup('q=' + encodeURIComponent(q), function (data) {
                    var versions = list(data);

                    show(versions, versions.length ? '' : EMPTY);
                });
            }, 250);
        });

        // Invio non prende mai il primo risultato: cerca il codice esatto.
        search.addEventListener('keydown', function (event) {
            var code;

            if (event.key === 'Escape') {
                close();

                return;
            }

            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();
            clearTimeout(timer);
            code = trim(search.value);

            if (code === '') {
                return;
            }

            // La casella si svuota subito: il lettore può già scrivere il codice dopo.
            search.value = '';
            seq++;
            scan(code);
        });

        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) {
                close();
            }
        });

        // Cambiando la sede, la giacenza delle righe si rilegge; nell'inventario
        // gli attesi ripartono da lì.
        if (place) {
            place.addEventListener('change', function () {
                var ids = [];
                var i;

                for (i = 0; i < rows.length; i++) {
                    ids.push(rows[i].id);
                    paint(rows[i]);
                }

                refresh();

                if (!ids.length) {
                    return;
                }

                request('ids=' + ids.join(','), function (data) {
                    var versions = list(data);
                    var j;
                    var row;

                    for (j = 0; j < versions.length; j++) {
                        row = byId(versions[j].id);

                        if (row) {
                            row.current = Number(versions[j].stock) || 0;
                            row.stock.textContent = format(row.current);
                            paint(row);
                        }
                    }

                    refresh();
                }, function () {
                    say(DOWN, 'error');
                });
            });
        }

        // Aggiungi versioni: quelle della scelta che non ci sono ancora, da contare.
        if (scope && scopeAdd) {
            scopeAdd.addEventListener('click', function () {
                var parts = String(scope.value).split(':');
                var params = 'scope=' + encodeURIComponent(parts[0]) + (parts[1] ? '&scope_id=' + encodeURIComponent(parts[1]) : '');

                scopeAdd.disabled = true;
                say('');
                request(params, function (data) {
                    var ids = data && Object.prototype.toString.call(data.ids) === '[object Array]' ? data.ids : [];
                    var versions = list(data);
                    var fresh = [];
                    var added = 0;
                    var i;

                    scopeAdd.disabled = false;

                    for (i = 0; i < ids.length; i++) {
                        if (!byId(ids[i])) {
                            fresh.push(Number(ids[i]));
                        }
                    }

                    if (rows.length + fresh.length > maxLines) {
                        say('Sarebbero ' + (rows.length + fresh.length) + CROWDED + maxLines + '. Conta per categorie o per marchi.', 'error');

                        return;
                    }

                    if (!fresh.length) {
                        say(ids.length ? 'Ci sono già tutte.' : 'Nessuna versione da aggiungere.');

                        return;
                    }

                    for (i = 0; i < versions.length; i++) {
                        if (!byId(versions[i].id) && append(versions[i], null)) {
                            added++;
                        }
                    }

                    say(added === 1 ? 'Aggiunta 1 versione.' : 'Aggiunte ' + added + ' versioni.');
                }, function () {
                    scopeAdd.disabled = false;
                    say(DOWN, 'error');
                });
            });
        }

        try {
            initial = JSON.parse(root.querySelector('[data-wi-stock-lines]').textContent) || [];
        } catch (error) {
            initial = [];
        }

        for (k = 0; k < initial.length; k++) {
            append(initial[k], initial[k].quantity);
        }

        refresh();

        return {validate: validate, totals: count, words: words, serialize: serialize};
    }

    function init() {
        var roots = document.querySelectorAll('[data-wi-stock-editor]');
        var i;

        for (i = 0; i < roots.length; i++) {
            if (!roots[i].wiStockEditor) {
                roots[i].wiStockEditor = editor(roots[i]);
            }
        }
    }

    return {init: init, parse: parse, format: format, totals: totals};
})();
// Il core ricopia i <wi-card> al caricamento e poi lancia «loaded»: prima di
// allora i nodi della pagina non sono quelli che restano.
window.addEventListener('loaded', window.wiStockEditor.init, {once: true});
if (document.readyState === 'complete') {
    window.wiStockEditor.init();
}
JS;
    }
}
