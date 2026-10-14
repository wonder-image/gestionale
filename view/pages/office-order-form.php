<?php

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Http\Csrf;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderEditor;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderPage;
use Wonder\View\View;

/*
 * La bozza d'ordine da ufficio e il preventivo (G9 §2.2, §3). Il disegno è
 * di `OfficeOrderPage::layout()`, con i componenti del core. Posta alle rotte
 * `store`/`update` del core, che la Resource intercetta in
 * `mutateRequestValues()`: le righe viaggiano nel solo campo `lines`, scritto
 * qui sotto all'invio. Lo script cerca anche il cliente e ne riempie i dati.
 */

/** @var class-string<\Wonder\Plugin\Gestionale\Resources\Sales\OfficeOrderResource> $resource */
$resource = $RESOURCE_CLASS;
$values = (array) ($VALUES ?? []);
$json = static fn (mixed $value): string => (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);

View::layout('backend.form');

echo ResourceFormLayoutRenderer::render(OfficeOrderPage::layout($resource, $values), [
    'id' => 'wi-oo-form',
    'action' => (string) ($FORM_ACTION ?? ''),
    'attributes' => ['autocomplete' => 'off', 'data-wi-save-bar' => true],
    'footer' => Csrf::fieldFor('post'),
]);
?>
<script>
(function () {
    'use strict';

    var form = document.getElementById('wi-oo-form');
    var lines = form.querySelector('[data-wi-oo-lines]');
    var customersUrl = <?=$json(OfficeOrderEditor::customersUrl())?>;
    var customerId = form.querySelector('[data-wi-oo-customer-id]');
    var customerSearch = form.querySelector('[data-wi-oo-customer-search]');
    var customerResults = form.querySelector('[data-wi-oo-customer-results]');
    var customerMessage = form.querySelector('[data-wi-oo-customer-message]');
    // Le azioni che non salvano le righe.
    var withoutLines = <?=$json(OfficeOrderPage::WITHOUT_LINES)?>;
    var timer = null;
    var seq = 0;

    function editor() {
        var root = form.querySelector('[data-wi-office-editor]');

        return root ? root.wiOfficeEditor : null;
    }

    function get(params, done) {
        var mine = ++seq;

        window.fetch(customersUrl + (customersUrl.indexOf('?') === -1 ? '?' : '&') + params, {
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
            customerMessage.textContent = 'La ricerca non risponde: riprova.';
        });
    }

    function closeCustomers() {
        customerResults.classList.add('d-none');

        while (customerResults.firstChild) {
            customerResults.removeChild(customerResults.firstChild);
        }
    }

    // I dati della scheda del cliente riempiono la testata.
    function fill(customer) {
        Object.keys(customer).forEach(function (key) {
            var node = form.querySelector('[data-wi-oo-field="' + key + '"]');

            if (!node || customer[key] === null || customer[key] === undefined) {
                return;
            }

            if (node.tagName === 'SELECT' && !node.querySelector('option[value="' + String(customer[key]) + '"]')) {
                return;
            }

            node.value = String(customer[key]);
            node.dispatchEvent(new Event('change', {bubbles: true}));
        });
    }

    function pick(item) {
        customerId.value = String(item.id);
        customerSearch.value = item.label;
        customerMessage.textContent = item.detail || '';
        closeCustomers();
        get('id=' + encodeURIComponent(item.id), function (data) {
            fill(data.customer || {});
        });
    }

    customerSearch.addEventListener('input', function () {
        var q = customerSearch.value.replace(/^\s+|\s+$/g, '');

        window.clearTimeout(timer);
        customerId.value = '';

        if (q.length < 2) {
            closeCustomers();

            return;
        }

        timer = window.setTimeout(function () {
            get('q=' + encodeURIComponent(q), function (data) {
                var found = data.customers || [];

                closeCustomers();
                customerMessage.textContent = found.length ? '' : 'Nessun cliente trovato: crealo con «Nuovo cliente».';

                found.forEach(function (item) {
                    var button = document.createElement('button');
                    var name = document.createElement('div');
                    var detail = document.createElement('div');

                    button.type = 'button';
                    button.className = 'list-group-item list-group-item-action';
                    name.textContent = item.label;
                    detail.className = 'small text-body-secondary';
                    detail.textContent = item.detail || '';
                    button.appendChild(name);
                    button.appendChild(detail);
                    button.addEventListener('click', function () {
                        pick(item);
                    });
                    customerResults.appendChild(button);
                });

                customerResults.classList.toggle('d-none', found.length === 0);
            });
        }, 250);
    });

    customerSearch.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
        } else if (event.key === 'Escape') {
            closeCustomers();
        }
    });

    document.addEventListener('click', function (event) {
        if (!customerResults.contains(event.target) && event.target !== customerSearch) {
            closeCustomers();
        }
    });

    // All'invio le righe dell'editor vanno nel campo `lines`; la conferma
    // l'ha già chiesta la lib, e il pulsante premuto porta il suo `intent`.
    form.addEventListener('submit', function (event) {
        var intent = event.submitter ? event.submitter.value : 'save';
        var current;

        if (withoutLines.indexOf(intent) !== -1) {
            lines.value = '[]';

            return;
        }

        current = editor();

        if (!current || !current.validate()) {
            event.preventDefault();
            event.stopImmediatePropagation();

            if (typeof window.loadingSpinner === 'function') {
                window.loadingSpinner(false);
            }

            return;
        }

        lines.value = current.serialize();
    }, true);
})();
</script>
<?php View::end(); ?>
