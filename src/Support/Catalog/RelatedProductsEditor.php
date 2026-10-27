<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Throwable;
use Wonder\Backend\Support\RowDrag;
use Wonder\Plugin\Gestionale\Support\SearchClient;

/**
 * Un elenco di *Articoli collegati* (suggeriti o alternativi) come la tabella
 * di «Nuovo carico»: la casella «Cerca un articolo» sopra, le righe da
 * trascinare sotto. Il PHP disegna lo scheletro; le righe le costruisce il JS
 * da un JSON con `textContent`: il nome di un articolo non diventa mai HTML.
 *
 * Il campo resta il repeater di `relatedFields()`: ogni riga manda
 * `{input}[N][id]` e `{input}[N][related_model_id]` nell'ordine della tabella,
 * e il core le salva come le righe di un repeater. Un elenco svuotato non
 * manda niente, e il core toglie i collegamenti di quel tipo.
 */
final class RelatedProductsEditor
{
    /**
     * @param list<array<string, mixed>> $rows le righe salvate: `id` della riga e `related_model_id`
     */
    public static function render(string $input, string $label, array $rows, int $modelId, string $searchUrl): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $lines = self::lines($rows);
        $json = (string) json_encode($lines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);

        return '<div class="col-12 mb-4" data-wi-related-editor'
            .' data-input="'.$e($input).'"'
            .' data-model="'.$modelId.'"'
            .' data-search-url="'.$e($searchUrl).'">'
            .'<h6 class="mb-2">'.$e($label).'</h6>'
            .'<div class="position-relative">'
            .'<div class="input-group">'
            .'<span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>'
            .'<input type="search" class="form-control" data-wi-related-search placeholder="Cerca un articolo"'
            .' aria-label="'.$e('Cerca un articolo da aggiungere ai '.mb_strtolower($label)).'" autocomplete="off" enterkeyhint="search">'
            .'</div>'
            .'<div class="list-group position-absolute w-100 shadow d-none" style="z-index: 1050; max-height: 20rem; overflow-y: auto" data-wi-related-results></div>'
            .'</div>'
            .'<div class="small mt-1" role="status" aria-live="polite" data-wi-related-message></div>'
            .'<div class="table-responsive mt-2">'
            .'<table class="table table-sm align-middle mb-0">'
            .'<thead><tr>'
            .'<th style="width: 1.5rem"><span class="visually-hidden">Sposta</span></th>'
            .'<th>Articolo</th>'
            .'<th style="width: 3rem"><span class="visually-hidden">Togli</span></th>'
            .'</tr></thead>'
            .'<tbody data-wi-related-rows></tbody>'
            .'</table>'
            .'</div>'
            .'<p class="text-body-secondary small my-2'.($lines === [] ? '' : ' d-none').'" data-wi-related-empty>Nessun articolo: cercane uno qui sopra.</p>'
            .'<script type="application/json" data-wi-related-lines>'.$json.'</script>'
            .'</div>';
    }

    /**
     * Le righe salvate con nome, codice e foto dell'articolo; quelle di un
     * articolo eliminato non ci sono più.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function lines(array $rows): array
    {
        $ids = array_map(static fn (array $row): int => (int) ($row['related_model_id'] ?? 0), array_filter($rows, 'is_array'));
        $models = RelatedModelSearch::describe(array_values($ids));
        $out = [];

        foreach ($rows as $row) {
            $model = is_array($row) ? ($models[(int) ($row['related_model_id'] ?? 0)] ?? null) : null;

            if ($model !== null) {
                $out[] = ['row' => (string) ($row['id'] ?? '')] + $model;
            }
        }

        return $out;
    }

    /** La rotta JSON degli articoli. */
    public static function searchUrl(): string
    {
        $fallback = '/api/gestionale/backend/catalogo/articoli/';

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $url = (string) __r('api.gestionale.backend.catalog.models');
        } catch (Throwable) {
            return $fallback;
        }

        return $url !== '' ? $url : $fallback;
    }

    /** Gli script, una volta per la scheda: servono a tutti e due gli elenchi. */
    public static function scripts(): string
    {
        return RowDrag::script().SearchClient::script().'<script>'.self::script().'</script>';
    }

    /**
     * Il JS degli elenchi, ES5 in una IIFE. `window.wiRelatedEditor` si
     * definisce una volta sola; `init()` lega ogni `[data-wi-related-editor]`
     * non ancora legato.
     */
    public static function script(): string
    {
        return <<<'JS'
window.wiRelatedEditor = window.wiRelatedEditor || (function () {
    'use strict';

    var EMPTY = 'Nessun articolo trovato';
    var DOWN = 'La ricerca non risponde: riprova.';
    var TWICE = 'Questo articolo è già nell\'elenco';

    function trim(text) {
        return String(text == null ? '' : text).replace(/^\s+|\s+$/g, '');
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

    function hidden(value) {
        var input = el('input');

        input.type = 'hidden';
        input.value = String(value == null ? '' : value);

        return input;
    }

    // Foto, nome, codice e il segno «nascosto»: uguale nei risultati e nelle righe.
    function article(model) {
        var wrap = el('div', 'd-flex align-items-center gap-2 min-w-0');
        var text = el('div', 'min-w-0');
        var photo;

        if (model.photo) {
            photo = el('img', 'rounded flex-shrink-0');
            photo.src = model.photo;
            photo.alt = '';
            photo.width = 32;
            photo.height = 32;
            photo.style.objectFit = 'cover';
            wrap.appendChild(photo);
        }

        text.appendChild(el('div', 'text-truncate', model.label));

        if (model.sku) {
            text.appendChild(el('div', 'small text-body-secondary', model.sku));
        }

        if (model.hidden) {
            text.appendChild(el('span', 'badge text-bg-warning', 'nascosto al sito'));
        }

        wrap.appendChild(text);

        return wrap;
    }

    function editor(root) {
        var name = root.getAttribute('data-input');
        var self = Number(root.getAttribute('data-model')) || 0;
        var search = root.querySelector('[data-wi-related-search]');
        var results = root.querySelector('[data-wi-related-results]');
        var message = root.querySelector('[data-wi-related-message]');
        var body = root.querySelector('[data-wi-related-rows]');
        var empty = root.querySelector('[data-wi-related-empty]');
        var found = [];
        var timer = null;
        var initial;
        var i;

        function close() {
            results.classList.add('d-none');

            while (results.firstChild) {
                results.removeChild(results.firstChild);
            }

            found = [];
        }

        var client = window.wiSearchClient({message: message, url: root.getAttribute('data-search-url'), close: close, down: DOWN});

        // I nomi seguono l'ordine della tabella: è quello che si salva.
        function refresh() {
            var rows = body.children;
            var k;

            for (k = 0; k < rows.length; k++) {
                rows[k].querySelector('[data-row]').name = name + '[' + k + '][id]';
                rows[k].querySelector('[data-model]').name = name + '[' + k + '][related_model_id]';
            }

            empty.classList.toggle('d-none', rows.length > 0);
        }

        function has(id) {
            return !!body.querySelector('[data-model][value="' + Number(id) + '"]');
        }

        function append(model, rowId) {
            var tr = el('tr');
            var grip = el('td', 'pe-0');
            var cell = el('td');
            var tools = el('td', 'text-end');
            var remove = el('button', 'btn btn-sm btn-link text-danger p-1');
            var row = hidden(rowId);
            var related = hidden(model.id);

            row.setAttribute('data-row', '');
            related.setAttribute('data-model', '');

            if (window.wiRowDrag) {
                grip.appendChild(window.wiRowDrag.handle());
            }

            cell.appendChild(article(model));
            cell.appendChild(row);
            cell.appendChild(related);

            remove.type = 'button';
            remove.setAttribute('aria-label', 'Togli ' + model.label);
            remove.appendChild(el('i', 'bi bi-trash'));
            remove.addEventListener('click', function () {
                body.removeChild(tr);
                refresh();
                search.focus();
            });
            tools.appendChild(remove);

            tr.appendChild(grip);
            tr.appendChild(cell);
            tr.appendChild(tools);
            body.appendChild(tr);
        }

        function picked(model) {
            close();
            search.value = '';

            if (has(model.id)) {
                client.say(TWICE, 'error');
            } else {
                client.say('');
                append(model, '');
                refresh();
            }

            search.focus();
        }

        function option(model) {
            var item = el('button', 'list-group-item list-group-item-action');

            item.type = 'button';
            item.appendChild(article(model));

            if (has(model.id)) {
                item.classList.add('text-body-secondary');
                item.appendChild(el('div', 'small', 'già nell\'elenco'));
            }

            item.addEventListener('click', function () {
                picked(model);
            });

            return item;
        }

        function show(models) {
            var k;

            close();
            client.say(models.length ? '' : EMPTY);
            found = models;

            for (k = 0; k < models.length; k++) {
                results.appendChild(option(models[k]));
            }

            if (models.length) {
                results.classList.remove('d-none');
            }
        }

        if (window.wiRowDrag) {
            window.wiRowDrag(body, refresh);
        }

        search.addEventListener('input', function () {
            var q = trim(search.value);

            clearTimeout(timer);

            if (q.length < 2) {
                client.cancel();
                close();

                return;
            }

            timer = setTimeout(function () {
                client.lookup('q=' + encodeURIComponent(q) + '&exclude=' + self, function (data) {
                    var models = data && Object.prototype.toString.call(data.models) === '[object Array]' ? data.models : [];

                    show(models);
                });
            }, 250);
        });

        // Invio non invia la scheda: con un risultato solo lo aggiunge.
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();

                return;
            }

            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();

            if (found.length === 1) {
                picked(found[0]);
            }
        });

        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) {
                close();
            }
        });

        try {
            initial = JSON.parse(root.querySelector('[data-wi-related-lines]').textContent) || [];
        } catch (error) {
            initial = [];
        }

        for (i = 0; i < initial.length; i++) {
            append(initial[i], initial[i].row);
        }

        refresh();

        return {refresh: refresh};
    }

    function init() {
        var roots = document.querySelectorAll('[data-wi-related-editor]');
        var i;

        for (i = 0; i < roots.length; i++) {
            if (!roots[i].wiRelatedEditor) {
                roots[i].wiRelatedEditor = editor(roots[i]);
            }
        }
    }

    return {init: init};
})();
// Si parte con «loaded» del backend, o subito se la pagina è già pronta.
window.addEventListener('loaded', window.wiRelatedEditor.init, {once: true});
if (document.readyState === 'complete') {
    window.wiRelatedEditor.init();
}
JS;
    }
}
