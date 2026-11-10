<?php

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Http\Csrf;
use Wonder\Plugin\Gestionale\Resources\Sales\QuoteResource;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeCustomerPanel;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderEditor;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderPage;
use Wonder\View\View;

/*
 * La bozza d'ordine da ufficio e il preventivo (G9 §2.2, §3). Il disegno è
 * di `OfficeOrderPage::layout()`, con i componenti del core. Posta alle rotte
 * `store`/`update` del core, che la Resource intercetta in
 * `mutateRequestValues()`: le righe viaggiano nel solo campo `lines`, scritto
 * qui sotto all'invio.
 *
 * La colonna del cliente (Contatti, Fatturazione, Indirizzi di consegna) la
 * disegna `OfficeCustomerPanel`: le sue finestre salvano sulla scheda del
 * cliente con una chiamata JSON e i riquadri si ridisegnano dalla risposta.
 * L'indirizzo spuntato scrive i campi nascosti `shipping_*` dell'ordine;
 * senza spunta si copiano quelli di fatturazione.
 *
 * La ricerca `customer` trova persone e aziende con la chiave composta
 * (`contact:12`, `company:7`); la scelta scrive i nascosti `customer_id`
 * (persona o referente) e `company_id`. Il «Referente» c'è solo per
 * un'azienda e scrive `customer_id`; anche il «Brand» (`company_brand_id`),
 * proposto dal predefinito. Cambiare il brand non cambia il referente scelto.
 *
 * Cliente, referente e brand nuovi nascono nelle finestre della creazione
 * rapida del core, senza lasciare la pagina: il cliente nato si sceglie come
 * dalla ricerca (e i riquadri si ridisegnano), referente e brand entrano
 * nella loro tendina già scelti. Le finestre di referente e brand portano
 * l'azienda scelta, scritta qui all'apertura.
 */

/** @var class-string<\Wonder\Plugin\Gestionale\Resources\Sales\OfficeOrderResource> $resource */
$resource = $RESOURCE_CLASS;
$values = (array) ($VALUES ?? []);
$json = static fn (mixed $value): string => (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);

// Il numero nel titolo, dal primo salvataggio: «Preventivo 2026/100390».
$number = is_a($resource, QuoteResource::class, true) ? QuoteResource::number($values) : trim((string) ($values['order_number'] ?? ''));
$title = (string) (((array) $resource::pageSchema()->get('titles'))['edit'] ?? '');
$number = html_entity_decode($number, ENT_QUOTES | ENT_HTML5, 'UTF-8');

View::layout('backend.form', (int) ($values['id'] ?? 0) > 0 && $number !== '' && $title !== '' ? ['TITLE' => $title.' '.$number] : []);

echo ResourceFormLayoutRenderer::render(OfficeOrderPage::layout($resource, $values), [
    'id' => 'wi-oo-form',
    'action' => (string) ($FORM_ACTION ?? ''),
    'attributes' => ['autocomplete' => 'off', 'data-wi-save-bar' => true],
    'footer' => Csrf::fieldFor('post'),
]);

// Le finestre vanno fuori dal form dell'ordine.
echo OfficeCustomerPanel::modals(OfficeOrderEditor::customerSaveUrl());

$customerId = (int) ($values['customer_id'] ?? 0);
$companyId = (int) ($values['company_id'] ?? 0);

try {
    // Le sedi del documento salvato seguono il suo brand.
    $panel = $customerId > 0 || $companyId > 0 ? OfficeCustomerPanel::payload($customerId, $companyId, $companyId > 0 && (int) ($values['id'] ?? 0) > 0 ? (int) ($values['company_brand_id'] ?? 0) : null) : [];
} catch (Throwable) {
    $panel = [];
}
?>
<script>
(function () {
    'use strict';

    var form = document.getElementById('wi-oo-form');
    var lines = form.querySelector('[data-wi-oo-lines]');
    var customersUrl = <?=$json(OfficeOrderEditor::customersUrl())?>;
    var saveUrl = <?=$json(OfficeOrderEditor::customerSaveUrl())?>;
    var quoteUrl = <?=$json(OfficeOrderEditor::shippingQuoteUrl())?>;
    var modals = <?=$json([OfficeCustomerPanel::CONTACTS_MODAL, OfficeCustomerPanel::BILLING_MODAL, \Wonder\Plugin\Gestionale\Resources\Contacts\ContactAddressResource::MODAL_ID, OfficeCustomerPanel::COMPANY_BILLING_MODAL])?>;
    var shippingKeys = <?=$json(\Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders::shippingKeys())?>;
    var billingKeys = <?=$json(\Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders::billingKeys())?>;
    // La ricerca: il valore è la chiave composta del cliente.
    var customer = form.querySelector('[name="customer"][data-wi-remote-search-value]');
    // Le azioni che non salvano le righe.
    var withoutLines = <?=$json(OfficeOrderPage::WITHOUT_LINES)?>;
    var EMPTY = '<p class="text-muted small mb-0">Scegli un cliente.</p>';
    var state = {contact: null, addresses: [], brands: <?=$json($panel['brands'] ?? [])?>};
    var seq = 0;
    // La sede l'ha scelta (o scritta) l'operatore: cambiare brand non la tocca.
    var touched = false;
    // Il brand cambiato dallo script, non dall'operatore.
    var autoBrand = false;

    function editor() {
        var root = form.querySelector('[data-wi-office-editor]');

        return root ? root.wiOfficeEditor : null;
    }

    function toast(message) {
        if (typeof window.alertToast === 'function') {
            window.alertToast('custom', 'error', 'Errore', message);
        } else {
            window.alert(message);
        }
    }

    function field(key) {
        return form.querySelector('[data-wi-oo-field="' + key + '"]');
    }

    function set(key, value) {
        var node = field(key);

        if (!node || value === null || value === undefined) {
            return;
        }

        if (node.tagName === 'SELECT' && !node.querySelector('option[value="' + String(value) + '"]')) {
            return;
        }

        node.value = String(value);
        node.dispatchEvent(new Event('change', {bubbles: true}));
    }

    // I dati della scheda del cliente riempiono la testata, con persona,
    // azienda e referente.
    function fill(data) {
        Object.keys(data).forEach(function (key) {
            set(key, data[key]);
        });
        set('referent', parseInt(data.company_id, 10) > 0 ? (data.customer_id || 0) : 0);
    }

    // I referenti dell'azienda scelta: «Nessun referente», poi le persone dell'azienda.
    function referents(list) {
        var node = field('referent');
        var primary = 0;

        if (!node) {
            return;
        }

        node.innerHTML = '';
        node.appendChild(new Option('Nessun referente', '0'));
        (list || []).forEach(function (r) {
            node.appendChild(new Option(r.label, String(r.id)));
            if (r.is_primary && !primary) {
                primary = r.id;
            }
        });
        node.value = String(primary);
        node.dispatchEvent(new Event('change', {bubbles: true}));
    }

    // I brand dell'azienda scelta: «Nessun brand», poi i suoi; proposto il
    // predefinito, altrimenti il primo (come `CompanyBrands::defaultOf()`).
    function brands(list) {
        var node = field('company_brand_id');
        var chosen = 0;

        if (!node) {
            return;
        }

        node.innerHTML = '';
        node.appendChild(new Option('Nessun brand', '0'));
        (list || []).forEach(function (b) {
            node.appendChild(new Option(b.label, String(b.id)));
            if (b.is_default && !chosen) {
                chosen = b.id;
            }
        });
        node.value = String(chosen || (list && list.length ? list[0].id : 0));
        state.brands = list || [];
        autoBrand = true;
        node.dispatchEvent(new Event('change', {bubbles: true}));
        autoBrand = false;
        logo();
    }

    // Il logo del brand scelto, se c'è: un'immagine fatta con il DOM, l'URL
    // solo http(s) o del sito, mai HTML.
    function logo() {
        var box = form.querySelector('[data-wi-oo-brand-logo]');
        var node = field('company_brand_id');
        var id = node ? parseInt(node.value, 10) || 0 : 0;
        var brand = state.brands.filter(function (b) { return b.id === id; })[0];
        var url = brand && brand.logo ? String(brand.logo) : '';
        var img;

        if (!box) {
            return;
        }

        box.textContent = '';

        if (!/^(https?:\/\/|\/(?!\/))/i.test(url)) {
            return;
        }

        img = document.createElement('img');
        img.src = url;
        img.alt = brand.label || '';
        img.className = 'img-fluid';
        img.style.maxHeight = '3rem';
        box.appendChild(img);
    }

    // Il brand del documento, per le sedi proposte.
    function brandId() {
        var node = field('company_brand_id');

        return node ? (parseInt(node.value, 10) || 0) : 0;
    }

    function panels(data) {
        var html = (data && data.html) || {};

        ['contacts', 'billing', 'addresses'].forEach(function (key) {
            var node = document.querySelector('[data-wi-oo-panel="' + key + '"]');

            if (node) {
                node.innerHTML = html[key] || EMPTY;
            }
        });

        state.contact = (data && data.contact) || null;
        state.addresses = (data && data.addresses) || [];
        document.querySelectorAll('[data-wi-oo-edit]').forEach(function (button) {
            button.disabled = !state.contact;
            // La fatturazione di un'azienda ha la sua finestra.
            if (button.getAttribute('data-wi-oo-edit') === 'billing') {
                button.setAttribute('data-bs-target', '#' + (company() ? modals[3] : modals[1]));
            }
        });
    }

    function company() {
        return !!state.contact && state.contact.kind === 'company';
    }

    function boxes() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-wi-oo-panel="addresses"] input[name="oo_address"]'));
    }

    function checked() {
        var box = boxes().filter(function (b) { return b.checked; })[0];

        return box ? parseInt(box.value, 10) : 0;
    }

    function check(id) {
        boxes().forEach(function (box) {
            box.checked = parseInt(box.value, 10) === id;
        });
    }

    function addressOf(id) {
        return state.addresses.filter(function (a) { return a.id === id; })[0] || null;
    }

    // La consegna dell'ordine: l'indirizzo spuntato, o quello di fatturazione.
    function ship() {
        var address = addressOf(checked());

        shippingKeys.forEach(function (key) {
            var value = '';

            if (address) {
                value = address[key];
            } else if (billingKeys.indexOf(key) !== -1) {
                value = (field('billing_' + key) || {}).value || '';
            }

            set('shipping_' + key, value);
        });
    }

    // La bozza riaperta: si spunta l'indirizzo uguale a quello salvato.
    // Se nessuno combacia la consegna salvata resta com'è.
    function matchSaved() {
        var same = function (a, key) {
            return String(a[key] || '').trim().toLowerCase() === String((field('shipping_' + key) || {}).value || '').trim().toLowerCase();
        };
        var found = state.addresses.filter(function (a) {
            return ['street', 'number', 'cap', 'city'].every(function (key) { return same(a, key); });
        })[0];

        check(found ? found.id : 0);
    }

    function load(key, done, brand) {
        var mine = ++seq;
        var url = customersUrl + (customersUrl.indexOf('?') === -1 ? '?' : '&') + 'key=' + encodeURIComponent(key)
            + (brand === undefined ? '' : '&oo_brand=' + encodeURIComponent(String(brand)));

        window.fetch(url, {
            credentials: 'same-origin',
            headers: {Accept: 'application/json'}
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.json();
        }).then(function (data) {
            if (mine === seq) {
                done((data && data.response) || {});
            }
        }, function () {
            toast('La scheda del cliente non risponde: riprova.');
        });
    }

    // Un cliente nuovo: testata dalla sua scheda, riquadri, spuntato il
    // predefinito (o l'unico indirizzo che c'è).
    form.addEventListener('wi:search-select', function (event) {
        if (!event.target.contains(customer)) {
            return;
        }

        load(event.detail.id, function (data) {
            var main;

            touched = false;
            referents(data.referents);
            brands(data.brands);
            fill(data.customer || {});
            panels(data);
            // La sede proposta (per un'azienda, prima quelle del brand).
            main = addressOf(parseInt(data.proposed, 10) || 0)
                || state.addresses.filter(function (a) { return a.is_default; })[0]
                || (state.addresses.length === 1 ? state.addresses[0] : null);
            check(main ? main.id : 0);
            ship();
        });
    });

    // Cambiando brand le sedi si rileggono: prima quelle del brand. La
    // proposta cambia solo se l'operatore non ha già scelto o scritto una sede.
    if (field('company_brand_id')) {
        field('company_brand_id').addEventListener('change', function () {
            logo();

            if (autoBrand || !company() || customer.value === '') {
                return;
            }

            load(customer.value, function (data) {
                var keep = checked();
                var node = document.querySelector('[data-wi-oo-panel="addresses"]');

                state.addresses = data.addresses || [];

                if (node) {
                    node.innerHTML = (data.html && data.html.addresses) || EMPTY;
                }

                if (touched) {
                    check(addressOf(keep) ? keep : 0);

                    return;
                }

                check(parseInt(data.proposed, 10) || 0);
                ship();
            }, brandId());
        });
    }

    // Il cliente nato da «Nuova persona» / «Nuova azienda»: si sceglie come
    // dalla ricerca, così testata e riquadri si riempiono dalla sua scheda.
    document.addEventListener('wi:quick-create:created', function (event) {
        var detail = event.detail || {};
        var kind = detail.trigger ? detail.trigger.getAttribute('data-wi-oo-new') : '';
        var root = customer.closest('.form-floating, [data-wi-remote-search]');

        if ((kind !== 'contact' && kind !== 'company') || !detail.id || !root || !window.wiRemoteSearch) {
            return;
        }

        window.wiRemoteSearch.select(root, {id: kind + ':' + parseInt(detail.id, 10), label: String(detail.label || '')});
    });

    // Referente e brand nuovi nascono legati all'azienda scelta adesso.
    document.addEventListener('show.bs.modal', function (event) {
        var target = event.target.querySelector ? event.target.querySelector('[data-wi-oo-qc-company]') : null;

        if (target) {
            target.value = String(parseInt((field('company_id') || {}).value, 10) || 0);
        }
    });

    customer.addEventListener('change', function () {
        if (customer.value === '') {
            ++seq;
            set('customer_id', '');
            set('company_id', '0');
            referents([]);
            brands([]);
            panels(null);
        }
    });

    // Il referente scelto è la persona del documento.
    if (field('referent')) {
        field('referent').addEventListener('change', function () {
            if (parseInt((field('company_id') || {}).value, 10) > 0) {
                set('customer_id', parseInt(field('referent').value, 10) > 0 ? field('referent').value : '0');
            }
        });
    }

    // La spunta fa da scelta singola: toglierla torna alla fatturazione.
    document.addEventListener('change', function (event) {
        var box = event.target;

        if (!box.matches || !box.matches('[data-wi-oo-panel="addresses"] input[name="oo_address"]')) {
            return;
        }

        touched = true;
        check(box.checked ? parseInt(box.value, 10) : 0);
        ship();
    });

    // Le finestre si aprono sul cliente scelto, con i valori della scheda.
    document.addEventListener('show.bs.modal', function (event) {
        var modal = event.target;
        var $ = window.jQuery;
        var target;
        var country;
        var province;

        if (modals.indexOf(modal.id) === -1 || !state.contact) {
            return;
        }

        target = modal.querySelector('[name="contact_id"]');

        if (target) {
            target.value = company() ? ((field('customer_id') || {}).value || 0) : state.contact.id;
        }

        target = modal.querySelector('[name="company_id"]');

        if (target) {
            target.value = company() ? state.contact.company_id : 0;
        }

        if (modal.id === modals[2]) {
            return;
        }

        Object.keys(state.contact).forEach(function (key) {
            // Place ID e coordinate sono nascosti: si riportano anche loro, così salvando non si perdono.
            var input = modal.querySelector('[name="' + key + '"]:not([type=hidden])')
                || (['place_id', 'lat', 'lng'].indexOf(key) !== -1 ? modal.querySelector('[name="' + key + '"][type=hidden]') : null);

            if (!input || key === 'province') {
                return;
            }

            if (input.type === 'checkbox' || input.type === 'radio') {
                return;
            }

            input.value = state.contact[key] == null ? '' : state.contact[key];
            input.dispatchEvent(new Event('change', {bubbles: true}));

            if (input.tagName === 'SELECT' && $) {
                $(input).trigger('change');
            }
        });

        country = modal.querySelector('[name="country"]');
        province = modal.querySelector('[name="province"]');

        if (country && province && typeof window.searchStates === 'function') {
            window.searchStates(country, state.contact.province || '');
        }
    });

    function post(body, done) {
        var meta = document.querySelector('meta[name="wi-csrf"]');

        if (company()) {
            body.append('oo_brand', String(brandId()));
        }

        window.fetch(saveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {Accept: 'application/json', 'X-WI-CSRF': meta ? meta.content : ''},
            body: body
        }).then(function (response) {
            return response.json().catch(function () { return {success: false, response: 'HTTP ' + response.status}; });
        }).then(function (data) {
            if (!data || !data.success) {
                toast((data && data.response) || 'Non salvato.');

                return;
            }

            done(data.response || {});
        }, function () {
            toast('Il salvataggio non risponde: riprova.');
        });
    }

    // Dopo un salvataggio: i riquadri nuovi, e la testata dell'ordine
    // allineata alla scheda (solo la parte che la finestra ha cambiato).
    function saved(action, data) {
        var customer = data.customer || {};
        var keep = checked();

        panels(data);

        if (action === 'contacts') {
            set('email', customer.email);
            set('phone', customer.phone);
        } else if (action === 'billing') {
            billingKeys.forEach(function (key) { set('billing_' + key, customer['billing_' + key]); });
        }

        if (action === 'save' && data.id) {
            keep = data.id;
            touched = true;
        } else if (action === 'delete' && !addressOf(keep)) {
            keep = 0;
        }

        check(keep);
        ship();
    }

    document.addEventListener('submit', function (event) {
        var target = event.target;
        var modal = target.closest ? target.closest('.modal') : null;
        var body;
        var action;

        if (!modal || modals.indexOf(modal.id) === -1) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        body = new FormData(target);
        action = String(body.get('action') || '');
        post(body, function (data) {
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modal).hide();
            }

            saved(action, data);
        });
    }, true);

    // Elimina: la conferma l'ha già chiesta la lib, poi parte il clic.
    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-wi-oo-address-delete]') : null;
        var body;

        if (!button || !state.contact) {
            return;
        }

        body = new FormData();
        body.append('action', 'delete');
        body.append('contact_id', state.contact.id);
        body.append('company_id', company() ? state.contact.company_id : 0);
        body.append('address_id', button.getAttribute('data-wi-oo-address-delete'));
        post(body, function (data) {
            saved('delete', data);
        });
    });

    // Con un metodo di spedizione scelto il costo è quello del listino: si
    // ricalcola quando cambiano metodo, destinazione o righe, e il campo
    // resta bloccato. All'invio si svuota, così il salvataggio rifà il
    // listino invece di scrivere un costo a mano.
    var amount = form.querySelector('[data-wi-oo-shipping-amount]');
    var amountNote = null;
    var quoteSeq = 0;
    var quoteTimer = null;

    function method() {
        var type = form.querySelector('[name="fulfillment_type"]');
        var select = form.querySelector('[name="shipping_method_id"]');

        return select && (!type || type.value === 'shipping') ? (parseInt(select.value, 10) || 0) : 0;
    }

    function note(text) {
        if (!amountNote) {
            amountNote = document.createElement('div');
            amountNote.className = 'form-text';
            (amount.closest('.form-floating') || amount).insertAdjacentElement('afterend', amountNote);
        }

        amountNote.textContent = text;
        amountNote.classList.toggle('d-none', text === '');
    }

    function quote() {
        var id = method();
        var current = editor();
        var body;
        var mine;

        if (!amount) {
            return;
        }

        if (id <= 0) {
            if (amount.readOnly) {
                amount.readOnly = false;
                amount.value = '';
            }

            note('');

            return;
        }

        amount.readOnly = true;
        body = new FormData();
        body.append('shipping_method_id', String(id));
        body.append('lines', JSON.stringify(current && current.estimate ? current.estimate() : []));
        ['country', 'province', 'city', 'cap', 'street'].forEach(function (key) {
            ['shipping_', 'billing_'].forEach(function (prefix) {
                var node = field(prefix + key) || form.querySelector('[name="' + prefix + key + '"]');

                if (node) {
                    body.append(prefix + key, node.value);
                }
            });
        });
        mine = ++quoteSeq;

        window.fetch(quoteUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {Accept: 'application/json', 'X-WI-CSRF': (document.querySelector('meta[name="wi-csrf"]') || {}).content || ''},
            body: body
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            // Un errore della busta ha il messaggio in `response`; il preventivo è un oggetto.
            var quote = data && data.success ? (data.response || {}) : {ok: false, message: (data && data.response) || ''};

            if (mine !== quoteSeq || method() <= 0) {
                return;
            }

            amount.value = quote.ok ? String(quote.amount).replace('.', ',') : '';
            note(quote.ok ? (quote.free ? 'Gratis per la soglia del listino.' : 'Dal listino del metodo.') : (quote.message || ''));
        }, function () {
            if (mine === quoteSeq) {
                note('Il costo del listino non risponde: riprova.');
            }
        });
    }

    function requote() {
        window.clearTimeout(quoteTimer);
        quoteTimer = window.setTimeout(quote, 350);
    }

    form.addEventListener('change', function (event) {
        var name = event.target && event.target.name ? event.target.name : '';

        if (name === 'shipping_method_id' || name === 'fulfillment_type' || /^(shipping|billing)_(country|province|city|cap|street)$/.test(name)) {
            requote();
        }
    });
    form.addEventListener('wi:office-change', requote);

    // Gli indirizzi di consegna servono solo quando si spedisce.
    function delivery() {
        var type = form.querySelector('[name="fulfillment_type"]');
        var panel = document.querySelector('[data-wi-oo-panel="addresses"]');
        var card = panel ? panel.closest('.card') : null;

        if (card) {
            card.classList.toggle('d-none', !!type && type.value !== 'shipping');
        }
    }

    form.addEventListener('change', function (event) {
        if (event.target && event.target.name === 'fulfillment_type') {
            delivery();
        }
    });
    delivery();

    // Come `OfficeOrderRules::billingComplete()`: chi e dove.
    function billingComplete() {
        var has = function (key) { return String((field('billing_' + key) || {}).value || '').trim() !== ''; };
        var who = (field('billing_type') || {}).value === 'business' ? has('business_name') : has('name') || has('surname');

        return who && has('street') && has('city') && has('country');
    }

    // Prima di confermare: cliente, fatturazione e, se si spedisce, la consegna.
    function confirmable() {
        var type = form.querySelector('[name="fulfillment_type"]');
        var missing = function (key) { return String((field('shipping_' + key) || {}).value || '').trim() === ''; };

        if (!(parseInt((field('customer_id') || {}).value, 10) > 0) && !(parseInt((field('company_id') || {}).value, 10) > 0)) {
            return 'Scegli il cliente dell\'ordine.';
        }

        if (!billingComplete()) {
            return 'Completa la fatturazione del cliente (intestatario, via, città e paese) prima di confermare.';
        }

        if ((!type || type.value === 'shipping') && ['street', 'city', 'country'].some(missing)) {
            return 'L\'ordine si spedisce: spunta o aggiungi l\'indirizzo di consegna.';
        }

        return '';
    }

    panels(<?=$json($panel ?: null)?>);

    if (state.contact) {
        matchSaved();
        // Un documento riaperto ha già la sua consegna: il brand non la cambia.
        touched = true;
    }

    logo();

    quote();

    // All'invio le righe dell'editor vanno nel campo `lines`; la conferma
    // l'ha già chiesta la lib, e il pulsante premuto porta il suo `intent`.
    form.addEventListener('submit', function (event) {
        var intent = event.submitter ? event.submitter.value : 'save';
        var current;
        var problem;

        if (withoutLines.indexOf(intent) !== -1) {
            lines.value = '[]';

            return;
        }

        current = editor();
        problem = intent === 'confirm' ? confirmable() : '';

        if (problem !== '') {
            toast(problem);
        }

        if (problem !== '' || !current || !current.validate()) {
            event.preventDefault();
            event.stopImmediatePropagation();

            if (typeof window.loadingSpinner === 'function') {
                window.loadingSpinner(false);
            }

            return;
        }

        lines.value = current.serialize();

        if (amount && amount.readOnly) {
            amount.value = '';
        }
    }, true);
})();
</script>
<?php View::end(); ?>
