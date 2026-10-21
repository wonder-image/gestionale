<?php

namespace Wonder\Plugin\Gestionale\Support\Sales;

use Throwable;
use Wonder\Backend\Support\RowDrag;
use Wonder\Plugin\Gestionale\Support\SearchClient;

/**
 * Le righe del nuovo ordine da ufficio (G9 §2.2), sul modello di
 * `StockDocumentEditor`: il PHP disegna lo scheletro, il JS costruisce le
 * righe da un JSON con `textContent`, così il nome di una versione non
 * diventa mai HTML.
 *
 * Tre tipi di riga: la versione («Cerca o scansiona»), la riga libera
 * (descrizione, prezzo, categoria IVA) e quella di solo testo. Prezzo vuoto =
 * quello del listino, che compare come suggerimento. Il disponibile della sede
 * avvisa senza bloccare: decide la conferma. I totali della pagina sono una
 * stima; quelli veri li scrive il server al salvataggio.
 */
final class OfficeOrderEditor
{
    /**
     * @param list<array<string, mixed>> $lines righe per il JS (`OfficeOrderForm::editorLines()`)
     * @param array<int|string, string> $taxCategories categorie IVA delle righe libere
     */
    public static function render(array $lines, string $searchUrl, array $taxCategories, bool $batches = false, bool $suppliers = false): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $json = static fn (mixed $value): string => (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        $taxes = [];

        foreach ($taxCategories as $id => $label) {
            $taxes[] = ['id' => (int) $id, 'label' => (string) $label];
        }

        $source = implode(' · ', array_filter([$batches ? 'Lotto' : '', $suppliers ? 'Fornitore' : '']));

        return '<div class="col-12" data-wi-office-editor'
            .' data-search-url="'.$e($searchUrl).'"'
            .($batches ? ' data-lots="1"' : '')
            .($suppliers ? ' data-suppliers="1"' : '')
            .' data-max-lines="'.OfficeOrderRules::MAX_LINES.'">'
            .'<div class="position-relative">'
            .'<div class="input-group">'
            .'<span class="input-group-text"><i class="bi bi-upc-scan" aria-hidden="true"></i></span>'
            .'<input type="search" class="form-control" data-wi-office-search placeholder="Cerca o scansiona"'
            .' aria-label="Cerca una versione o scansiona un codice" autocomplete="off" enterkeyhint="search">'
            .'</div>'
            .'<div class="list-group position-absolute w-100 shadow d-none" style="z-index: 1050; max-height: 20rem; overflow-y: auto" data-wi-office-results></div>'
            .'</div>'
            .'<div class="d-flex flex-wrap gap-2 mt-2">'
            .'<button type="button" class="btn btn-sm btn-outline-dark" data-wi-office-add="custom"><i class="bi bi-plus-lg"></i> Riga libera</button>'
            .'<button type="button" class="btn btn-sm btn-outline-dark" data-wi-office-add="text"><i class="bi bi-plus-lg"></i> Testo</button>'
            .'</div>'
            .'<div class="small mt-1" role="status" aria-live="polite" data-wi-office-message></div>'
            .'<div class="table-responsive mt-3">'
            .'<table class="table table-sm align-middle mb-0">'
            .'<thead><tr>'
            .'<th style="width: 1.5rem"><span class="visually-hidden">Sposta</span></th>'
            .'<th style="min-width: 14rem">Articolo</th>'
            .'<th class="d-none d-md-table-cell text-end">Disponibili</th>'
            .'<th class="text-end" style="width: 7rem">Quantità</th>'
            .'<th class="text-end" style="width: 8rem">Prezzo</th>'
            .'<th style="width: 11rem">Sconto</th>'
            .($source !== '' ? '<th style="min-width: 12rem">'.$e($source).'</th>' : '')
            .'<th class="text-end d-none d-md-table-cell" style="width: 7rem">Totale</th>'
            .'<th style="width: 2.5rem"><span class="visually-hidden">Togli</span></th>'
            .'</tr></thead>'
            .'<tbody data-wi-office-rows></tbody>'
            .'</table>'
            .'</div>'
            .'<p class="text-body-secondary my-3'.($lines === [] ? '' : ' d-none').'" data-wi-office-empty>Nessuna riga: cerca una versione, scansiona un codice o aggiungi una riga libera.</p>'
            .'<div class="text-end fw-semibold mt-2" data-wi-office-totals></div>'
            .'<script type="application/json" data-wi-office-lines>'.$json($lines).'</script>'
            .'<script type="application/json" data-wi-office-taxes>'.$json($taxes).'</script>'
            .RowDrag::script()
            .SearchClient::script()
            .'<script>'.self::script().'</script>'
            .'</div>';
    }

    /** La rotta JSON delle versioni con prezzo e disponibile. */
    public static function searchUrl(): string
    {
        return self::route('api.gestionale.backend.sales.versions', '/api/gestionale/backend/vendite/versioni/');
    }

    /** La rotta JSON dei clienti. */
    public static function customersUrl(): string
    {
        return self::route('api.gestionale.backend.sales.customers', '/api/gestionale/backend/vendite/clienti/');
    }

    /** Dove le finestre del cliente salvano la sua scheda (POST, JSON). */
    public static function customerSaveUrl(): string
    {
        return self::route('api.gestionale.backend.sales.customer_save', '/api/gestionale/backend/vendite/clienti/salva/');
    }

    /** Il costo di spedizione del listino per la bozza (POST, JSON). */
    public static function shippingQuoteUrl(): string
    {
        return self::route('api.gestionale.backend.sales.shipping_quote', '/api/gestionale/backend/vendite/spedizione/');
    }

    private static function route(string $name, string $fallback): string
    {
        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $url = (string) __r($name);
        } catch (Throwable) {
            return $fallback;
        }

        return $url !== '' ? $url : $fallback;
    }

    /**
     * Il JS dell'editor, ES5 in una IIFE. `window.wiOfficeEditor` si
     * definisce una volta; `init()` lega ogni `[data-wi-office-editor]`.
     */
    public static function script(): string
    {
        return <<<'JS'
window.wiOfficeEditor = window.wiOfficeEditor || (function () {
    'use strict';

    var MANY = 'Più versioni con questo codice: scegline una';
    var NONE = 'Nessuna versione in vendita con questo codice';
    var EMPTY = 'Nessuna versione in vendita trovata';
    var DOWN = 'La ricerca non risponde: riprova.';
    var BAD = 'Controlla le caselle in rosso.';
    var AUTO_LOT = 'Lotto automatico';
    var AUTO_SUPPLIER = 'Fornitore automatico';
    var TRAILING = /[A-Za-zªµºÀ-ɏͰ-ϿЀ-ӿ$¢-¥₠-⃏%]+$/;
    var NUMERIC = /^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/;

    function trim(text) {
        return String(text == null ? '' : text).replace(/^\s+|\s+$/g, '');
    }

    // Come Numbers::fromForm(): null = vuota, NaN = non si legge.
    function parse(raw) {
        var text = trim(raw);

        if (text === '') {
            return null;
        }

        text = text.replace(/[\s  ]/g, '').replace(TRAILING, '');

        if (text.indexOf(',') !== -1) {
            text = text.replace(/\./g, '');
        }

        text = text.replace(/,/g, '.');

        return NUMERIC.test(text) ? parseFloat(text) : NaN;
    }

    function format(number) {
        var parts = (Math.round(number * 1000) / 1000).toFixed(3).split('.');
        var decimals = parts[1].replace(/0+$/, '');
        var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');

        return decimals === '' ? whole : whole + ',' + decimals;
    }

    function money(number) {
        var parts = (Math.round(number * 100) / 100).toFixed(2).split('.');

        return parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + parts[1] + ' €';
    }

    // Il prezzo nella casella: «12,5», due decimali al massimo.
    function priceText(number) {
        return String(Math.round(number * 100) / 100).replace('.', ',');
    }

    // Un valore dal server («8.00», 2.5) si riscrive all'italiana; uno
    // rimandato dalla pagina («8,00», «abc») torna com'era scritto.
    function shown(value, asPrice) {
        var text = value == null ? '' : String(value);

        if (/^\d+(\.\d+)?$/.test(text) || typeof value === 'number') {
            return asPrice ? priceText(Number(value)) : format(Number(value));
        }

        return text;
    }

    function day(date) {
        var parts = String(date || '').split('-');

        return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : String(date || '');
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

    function input(className, value, label) {
        var node = el('input', className);

        node.type = 'text';
        node.value = value == null ? '' : String(value);
        node.setAttribute('aria-label', label);
        node.autocomplete = 'off';

        return node;
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
        var search = root.querySelector('[data-wi-office-search]');
        var results = root.querySelector('[data-wi-office-results]');
        var message = root.querySelector('[data-wi-office-message]');
        var body = root.querySelector('[data-wi-office-rows]');
        var empty = root.querySelector('[data-wi-office-empty]');
        var summary = root.querySelector('[data-wi-office-totals]');
        var url = root.getAttribute('data-search-url');
        var maxLines = parseInt(root.getAttribute('data-max-lines'), 10) || 300;
        var lots = root.getAttribute('data-lots') === '1';
        var pickSuppliers = root.getAttribute('data-suppliers') === '1';
        var form = root.closest('form');
        var place = form ? form.querySelector('[data-wi-office-location]') : null;
        var taxes = [];
        var rows = [];
        var timer = null;
        var initial = [];

        try {
            taxes = JSON.parse(root.querySelector('[data-wi-office-taxes]').textContent) || [];
            initial = JSON.parse(root.querySelector('[data-wi-office-lines]').textContent) || [];
        } catch (error) {
            initial = [];
        }

        // Righe spostate: l'ordine di `rows` segue quello della tabella,
        // perché il salvataggio numera le posizioni in quell'ordine.
        if (window.wiRowDrag) {
            window.wiRowDrag(body, function () {
                var order = Array.prototype.slice.call(body.children);

                rows.sort(function (a, b) { return order.indexOf(a.tr) - order.indexOf(b.tr); });
                refresh();
            });
        }

        var client = window.wiSearchClient({message: message, url: url, place: place, close: close, down: DOWN});
        var say = client.say;
        var request = client.request;
        var lookup = client.lookup;

        function close() {
            results.classList.add('d-none');

            while (results.firstChild) {
                results.removeChild(results.firstChild);
            }
        }

        function option(version) {
            var item = el('button', 'list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2');
            var text = el('div', 'min-w-0');
            var side = el('div', 'small text-nowrap text-body-secondary', money(Number(version.price) || 0) + ' · ' + format(Number(version.available) || 0) + ' disp.');

            item.type = 'button';
            text.appendChild(el('div', 'text-truncate', version.label));

            if (codes(version)) {
                text.appendChild(el('div', 'small text-body-secondary', codes(version)));
            }

            item.appendChild(text);
            item.appendChild(side);
            item.addEventListener('click', function () {
                add({type: 'product', version: version, quantity: 1});
                close();
                search.value = '';
                search.focus();
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

        function stay(node) {
            node.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    search.focus();
                }
            });
        }

        function choices(select, auto, items, text, keep) {
            var found = false;
            var node;
            var i;

            while (select.firstChild) {
                select.removeChild(select.firstChild);
            }

            node = el('option', '', auto);
            node.value = '';
            select.appendChild(node);

            for (i = 0; i < items.length; i++) {
                node = el('option', items[i].expired ? 'text-danger' : '', text(items[i]));
                node.value = String(items[i].id);
                select.appendChild(node);
                found = found || Number(items[i].id) === keep;
            }

            if (keep > 0 && !found) {
                node = el('option', '', '#' + keep + ' · nessun pezzo');
                node.value = String(keep);
                select.appendChild(node);
                found = true;
            }

            select.value = found ? String(keep) : '';
        }

        function discountCell(row, line) {
            var cell = el('td');
            var group = el('div', 'input-group input-group-sm');

            row.discountType = el('select', 'form-select form-select-sm');
            row.discountType.setAttribute('aria-label', 'Tipo di sconto');
            [['none', '—'], ['percent', '%'], ['amount', '€']].forEach(function (pair) {
                var node = el('option', '', pair[1]);
                node.value = pair[0];
                row.discountType.appendChild(node);
            });
            row.discountType.value = line.discount_type || 'none';
            row.discountValue = input('form-control form-control-sm text-end', Number(line.discount_value) === 0 ? '' : shown(line.discount_value, true), 'Sconto');
            row.discountValue.setAttribute('inputmode', 'decimal');
            group.appendChild(row.discountType);
            group.appendChild(row.discountValue);
            cell.appendChild(group);
            row.discountType.addEventListener('change', refresh);
            row.discountValue.addEventListener('input', refresh);
            stay(row.discountValue);

            return cell;
        }

        function sourceCell(row, line) {
            var cell = el('td');

            if (!(lots || pickSuppliers) || row.type !== 'product') {
                return cell;
            }

            if (lots && row.version.tracks_batches) {
                row.batch = el('select', 'form-select form-select-sm mb-1');
                row.batch.setAttribute('aria-label', 'Lotto');
                choices(row.batch, AUTO_LOT, row.version.lots || [], function (lot) {
                    return lot.number + (lot.expiry ? ' · ' + day(lot.expiry) : '') + ' · ' + format(Number(lot.quantity) || 0) + ' pz' + (lot.expired ? ' · scaduto' : '');
                }, Number(line.batch_id) || 0);
                cell.appendChild(row.batch);
            }

            if (pickSuppliers && (row.version.suppliers || []).length) {
                row.supplier = el('select', 'form-select form-select-sm');
                row.supplier.setAttribute('aria-label', 'Fornitore');
                choices(row.supplier, AUTO_SUPPLIER, row.version.suppliers || [], function (item) {
                    return item.name + ' · ' + format(Number(item.quantity) || 0) + ' pz';
                }, Number(line.supplier_id) || 0);
                cell.appendChild(row.supplier);
            }

            return cell;
        }

        // La maniglia per trascinare la riga, in testa.
        function dragCell() {
            var cell = el('td', 'pe-0');

            if (window.wiRowDrag) {
                cell.appendChild(window.wiRowDrag.handle());
            }

            return cell;
        }

        // La X per togliere la riga.
        function removeCell(row) {
            var cell = el('td', 'text-end text-nowrap');
            var button = el('button', 'btn btn-sm btn-link text-danger');

            button.type = 'button';
            button.setAttribute('aria-label', 'Togli la riga');
            button.appendChild(el('i', 'bi bi-x-lg'));
            button.addEventListener('click', function () {
                rows.splice(rows.indexOf(row), 1);
                body.removeChild(row.tr);
                refresh();
                search.focus();
            });
            cell.appendChild(button);

            return cell;
        }

        // Una riga nuova, da una versione trovata o dal JSON della pagina.
        function add(line) {
            var type = line.type || 'product';
            var row = {type: type, version: line.version || null};
            var tr = el('tr');
            var name = el('td');
            var cols = 5 + (lots || pickSuppliers ? 1 : 0);

            if (rows.length >= maxLines) {
                say('Un ordine tiene al massimo ' + maxLines + ' righe: dividilo in due.', 'error');

                return null;
            }

            row.tr = tr;

            if (type === 'text') {
                row.name = el('textarea', 'form-control form-control-sm');
                row.name.rows = 1;
                row.name.value = line.name || '';
                row.name.setAttribute('aria-label', 'Testo della riga');
                row.name.placeholder = 'Testo che compare nell\'ordine';
                name.colSpan = cols + 1;
                name.appendChild(row.name);
                tr.appendChild(dragCell());
                tr.appendChild(name);
                tr.appendChild(removeCell(row));
                rows.push(row);
                body.appendChild(tr);
                refresh();

                return row;
            }

            if (type === 'custom') {
                row.name = input('form-control form-control-sm', line.name || '', 'Descrizione');
                row.name.placeholder = 'Cosa vendi';
                row.tax = el('select', 'form-select form-select-sm mt-1');
                row.tax.setAttribute('aria-label', 'Categoria IVA');
                taxes.forEach(function (tax) {
                    var node = el('option', '', tax.label);
                    node.value = String(tax.id);
                    row.tax.appendChild(node);
                });
                row.tax.value = String(line.tax_category_id || (taxes[0] ? taxes[0].id : ''));
                name.appendChild(row.name);
                name.appendChild(row.tax);
                stay(row.name);
            } else {
                name.appendChild(el('div', 'text-truncate', row.version.label));

                if (codes(row.version)) {
                    name.appendChild(el('div', 'small text-body-secondary', codes(row.version)));
                }

                row.warning = el('div', 'small text-warning-emphasis');
                name.appendChild(row.warning);
            }

            tr.appendChild(dragCell());
            tr.appendChild(name);
            tr.appendChild(el('td', 'd-none d-md-table-cell text-end', type === 'product' ? format(Number(row.version.available) || 0) : ''));
            row.quantity = input('form-control form-control-sm text-end', line.quantity != null ? shown(line.quantity, false) : '1', 'Quantità');
            row.quantity.setAttribute('inputmode', 'decimal');
            row.quantity.addEventListener('input', refresh);
            stay(row.quantity);
            tr.appendChild(el('td')).appendChild(row.quantity);
            row.price = input('form-control form-control-sm text-end', shown(line.unit_price, true), 'Prezzo unitario');
            row.price.setAttribute('inputmode', 'decimal');

            if (type === 'product') {
                row.price.placeholder = priceText(Number(row.version.price) || 0);
                row.price.title = 'Vuoto = il prezzo del listino';
            }

            row.price.addEventListener('input', refresh);
            stay(row.price);
            tr.appendChild(el('td')).appendChild(row.price);
            tr.appendChild(discountCell(row, line));

            if (lots || pickSuppliers) {
                tr.appendChild(sourceCell(row, line));
            }

            row.total = el('td', 'text-end d-none d-md-table-cell text-nowrap');
            tr.appendChild(row.total);
            tr.appendChild(removeCell(row));
            rows.push(row);
            body.appendChild(tr);
            refresh();

            return row;
        }

        // Prezzo × quantità meno lo sconto: la stima della riga.
        function lineTotal(row) {
            var quantity = parse(row.quantity.value);
            var price = parse(row.price.value);
            var value = parse(row.discountValue.value);
            var gross;
            var off = 0;

            if (price === null && row.type === 'product') {
                price = Number(row.version.price) || 0;
            }

            if (quantity === null || isNaN(quantity) || price === null || isNaN(price)) {
                return null;
            }

            gross = price;

            if (value !== null && !isNaN(value) && value > 0) {
                off = row.discountType.value === 'percent' ? Math.round(gross * Math.min(value, 100)) / 100 : (row.discountType.value === 'amount' ? Math.min(value, gross) : 0);
            }

            return Math.round((gross - off) * quantity * 100) / 100;
        }

        function bad(node, wrong) {
            if (wrong) {
                node.classList.add('is-invalid');
            } else {
                node.classList.remove('is-invalid');
            }
        }

        function check(row) {
            var quantity;
            var price;
            var value;
            var wrong = false;

            if (row.type === 'text') {
                return true;
            }

            quantity = parse(row.quantity.value);
            price = parse(row.price.value);
            value = parse(row.discountValue.value);
            bad(row.quantity, quantity === null || isNaN(quantity) || quantity <= 0);
            bad(row.price, (price !== null && (isNaN(price) || price < 0)) || (row.type === 'custom' && price === null));
            bad(row.discountValue, value !== null && (isNaN(value) || value < 0 || (row.discountType.value === 'percent' && value > 100)));

            if (row.type === 'custom') {
                bad(row.name, trim(row.name.value) === '');
            }

            wrong = row.tr.querySelector('.is-invalid') !== null;

            if (row.warning) {
                row.warning.textContent = quantity !== null && !isNaN(quantity) && quantity > (Number(row.version.available) || 0)
                    ? 'Disponibili ' + format(Number(row.version.available) || 0) + ': la conferma lo controlla.'
                    : '';
            }

            return !wrong;
        }

        function refresh() {
            var sum = 0;
            var count = 0;
            var i;
            var value;

            for (i = 0; i < rows.length; i++) {
                check(rows[i]);

                if (rows[i].type === 'text') {
                    continue;
                }

                count++;
                value = lineTotal(rows[i]);
                rows[i].total.textContent = value === null ? '' : money(value);
                sum += value || 0;
            }

            empty.classList.toggle('d-none', rows.length > 0);
            root.dispatchEvent(new CustomEvent('wi:office-change', {bubbles: true}));
            summary.textContent = rows.length ? count + ' ' + (count === 1 ? 'riga' : 'righe') + ' · ' + money(sum) + ' prima di spedizione e sconti' : '';
        }

        function scan(code) {
            lookup('code=' + encodeURIComponent(code), function (data) {
                var versions = list(data);

                if (versions.length === 1) {
                    add({type: 'product', version: versions[0], quantity: 1});
                    close();
                    say('');
                    search.value = '';
                } else {
                    show(versions, versions.length ? MANY : NONE, versions.length ? '' : 'error');
                }
            });
        }

        search.addEventListener('input', function () {
            var q = trim(search.value);

            window.clearTimeout(timer);

            if (q.length < 2) {
                close();
                say('');

                return;
            }

            timer = window.setTimeout(function () {
                lookup('q=' + encodeURIComponent(q), function (data) {
                    var versions = list(data);

                    show(versions, versions.length ? '' : EMPTY);
                });
            }, 250);
        });

        search.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                window.clearTimeout(timer);

                if (trim(search.value) !== '') {
                    scan(trim(search.value));
                }
            } else if (event.key === 'Escape') {
                close();
            }
        });

        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) {
                close();
            }
        });

        Array.prototype.forEach.call(root.querySelectorAll('[data-wi-office-add]'), function (button) {
            button.addEventListener('click', function () {
                var row = add({type: button.getAttribute('data-wi-office-add'), quantity: 1});

                if (row) {
                    row.name.focus();
                }
            });
        });

        // Cambiando la sede si rilegge il disponibile delle righe.
        if (place) {
            place.addEventListener('change', function () {
                var ids = rows.filter(function (row) {
                    return row.type === 'product';
                }).map(function (row) {
                    return row.version.id;
                });

                if (!ids.length) {
                    return;
                }

                request('ids=' + ids.join(','), function (data) {
                    var fresh = {};

                    list(data).forEach(function (version) {
                        fresh[version.id] = version;
                    });
                    rows.forEach(function (row) {
                        if (row.type === 'product' && fresh[row.version.id]) {
                            row.version.available = fresh[row.version.id].available;
                            row.tr.children[1].textContent = format(Number(row.version.available) || 0);
                        }
                    });
                    refresh();
                }, function () {
                    say(DOWN, 'error');
                });
            });
        }

        initial.forEach(function (line) {
            add(line);
        });

        return {
            validate: function () {
                var ok = true;

                rows.forEach(function (row) {
                    ok = check(row) && ok;
                });

                if (!ok) {
                    say(BAD, 'error');
                }

                return ok;
            },
            serialize: function () {
                return JSON.stringify(rows.map(function (row) {
                    if (row.type === 'text') {
                        return {type: 'text', name: row.name.value};
                    }

                    return {
                        type: row.type,
                        product_id: row.type === 'product' ? row.version.id : 0,
                        name: row.type === 'custom' ? row.name.value : '',
                        quantity: row.quantity.value,
                        unit_price: row.price.value,
                        discount_type: row.discountType.value,
                        discount_value: row.discountValue.value,
                        tax_category_id: row.tax ? row.tax.value : 0,
                        batch_id: row.batch ? row.batch.value : 0,
                        supplier_id: row.supplier ? row.supplier.value : 0
                    };
                }));
            },
            // Le righe come le pesa la spedizione: articolo, quantità e
            // totale stimato.
            estimate: function () {
                return rows.filter(function (row) { return row.type !== 'text'; }).map(function (row) {
                    return {
                        type: row.type,
                        product_id: row.type === 'product' ? row.version.id : 0,
                        quantity: parse(row.quantity.value) || 0,
                        line_total: lineTotal(row) || 0
                    };
                });
            },
            count: function () {
                return rows.length;
            }
        };
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-wi-office-editor]'), function (root) {
            if (!root.wiOfficeEditor) {
                root.wiOfficeEditor = editor(root);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return {init: init, parse: parse, format: format};
})();
window.wiOfficeEditor.init();
JS;
    }
}
