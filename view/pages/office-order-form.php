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

try {
    $panel = $customerId > 0 ? OfficeCustomerPanel::payload($customerId) : [];
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
    var modals = <?=$json([OfficeCustomerPanel::CONTACTS_MODAL, OfficeCustomerPanel::BILLING_MODAL, \Wonder\Plugin\Gestionale\Resources\Contacts\ContactAddressResource::MODAL_ID])?>;
    var shippingKeys = <?=$json(\Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders::shippingKeys())?>;
    var billingKeys = <?=$json(\Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders::billingKeys())?>;
    var customerId = form.querySelector('[name="customer_id"][data-wi-remote-search-value]');
    // Le azioni che non salvano le righe.
    var withoutLines = <?=$json(OfficeOrderPage::WITHOUT_LINES)?>;
    var EMPTY = '<p class="text-muted small mb-0">Scegli un cliente.</p>';
    var state = {contact: null, addresses: []};
    var seq = 0;

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

    // I dati della scheda del cliente riempiono la testata.
    function fill(customer) {
        Object.keys(customer).forEach(function (key) {
            set(key, customer[key]);
        });
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
        });
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

    function load(id, done) {
        var mine = ++seq;

        window.fetch(customersUrl + (customersUrl.indexOf('?') === -1 ? '?' : '&') + 'id=' + encodeURIComponent(id), {
            credentials: 'same-origin',
            headers: {Accept: 'application/json'}
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.json();
        }).then(function (data) {
            if (mine === seq) {
                done(data || {});
            }
        }, function () {
            toast('La scheda del cliente non risponde: riprova.');
        });
    }

    // Un cliente nuovo: testata dalla sua scheda, riquadri, spuntato il
    // predefinito (o l'unico indirizzo che c'è).
    form.addEventListener('wi:search-select', function (event) {
        if (!event.target.contains(customerId)) {
            return;
        }

        load(event.detail.id, function (data) {
            var main;

            fill(data.customer || {});
            panels(data);
            main = state.addresses.filter(function (a) { return a.is_default; })[0]
                || (state.addresses.length === 1 ? state.addresses[0] : null);
            check(main ? main.id : 0);
            ship();
        });
    });

    customerId.addEventListener('change', function () {
        if (customerId.value === '') {
            ++seq;
            panels(null);
        }
    });

    // La spunta fa da scelta singola: toglierla torna alla fatturazione.
    document.addEventListener('change', function (event) {
        var box = event.target;

        if (!box.matches || !box.matches('[data-wi-oo-panel="addresses"] input[name="oo_address"]')) {
            return;
        }

        check(box.checked ? parseInt(box.value, 10) : 0);
        ship();
    });

    // Le finestre si aprono sul cliente scelto, con i valori della scheda.
    function billingEmpty() {
        return ['business_name', 'cf', 'pi', 'street'].every(function (key) {
            return !String(state.contact[key] || '').trim();
        });
    }

    function setType(modal, value) {
        var radio = modal.querySelector('[name="type"][value="' + value + '"]');
        var input = radio || modal.querySelector('[name="type"]:not([type=hidden])');

        if (!input) {
            return;
        }

        if (radio) {
            radio.checked = true;
        } else {
            input.value = value;
        }

        input.dispatchEvent(new Event('change', {bubbles: true}));

        if (input.tagName === 'SELECT' && window.jQuery) {
            window.jQuery(input).trigger('change');
        }
    }

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
            target.value = state.contact.id;
        }

        if (modal.id === modals[2]) {
            return;
        }

        Object.keys(state.contact).forEach(function (key) {
            var input = modal.querySelector('[name="' + key + '"]:not([type=hidden])');

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

        // La tipologia va scelta a parte (le caselle a spunta sopra si
        // saltano); con la fatturazione ancora vuota si parte da Società.
        if (modal.id === modals[1]) {
            setType(modal, billingEmpty() ? 'business' : (state.contact.type || 'business'));
        }

        country = modal.querySelector('[name="country"]');
        province = modal.querySelector('[name="province"]');

        if (country && province && typeof window.searchStates === 'function') {
            window.searchStates(country, state.contact.province || '');
        }
    });

    function post(body, done) {
        var meta = document.querySelector('meta[name="wi-csrf"]');

        window.fetch(saveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {Accept: 'application/json', 'X-WI-CSRF': meta ? meta.content : ''},
            body: body
        }).then(function (response) {
            return response.json().catch(function () { return {ok: false, message: 'HTTP ' + response.status}; });
        }).then(function (data) {
            if (!data.ok) {
                toast(data.message || 'Non salvato.');

                return;
            }

            done(data);
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
            if (mine !== quoteSeq || method() <= 0) {
                return;
            }

            amount.value = data.ok ? String(data.amount).replace('.', ',') : '';
            note(data.ok ? (data.free ? 'Gratis per la soglia del listino.' : 'Dal listino del metodo.') : (data.message || ''));
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

        if (customerId.value === '') {
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
    }

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
