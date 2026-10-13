<?php

use Wonder\Elements\Components\Tooltip;
use Wonder\Http\Csrf;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderEditor;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderForm;

/*
 * La bozza d'ordine da ufficio (G9 §2.2): cliente, fatturazione, consegna, le
 * righe, pagamento e note, e tre bottoni. Posta alle rotte `store`/`update` del
 * core, che la Resource intercetta in `mutateRequestValues()`: le righe
 * viaggiano nel solo campo `lines`, scritto dal JS all'invio.
 */

/** @var class-string<\Wonder\Plugin\Gestionale\Resources\Sales\OfficeOrderResource> $resource */
$resource = $RESOURCE_CLASS;
$values = (array) ($VALUES ?? []);
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$json = static fn (mixed $value): string => (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
// I testi salvati arrivano già passati da htmlspecialchars: si riportano come li ha scritti l'operatore.
$v = static fn (string $key, string $default = ''): string => html_entity_decode((string) ($values[$key] ?? $default), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$editing = (int) ($values['id'] ?? 0) > 0;
$editor = (array) ($values['editor'] ?? []);
$customerId = (int) ($values['customer_id'] ?? 0);
$customer = $customerId > 0 ? Contact::findById($customerId) : null;
$customerLabel = is_array($customer) && $customer !== [] ? Contacts::displayName($customer) : '';
$fulfillment = (string) ($values['fulfillment_type'] ?? 'shipping');
$locations = $resource::locations();
$shippingMethods = $resource::shippingMethods();
$methods = $resource::paymentMethods();
$terms = $resource::paymentTerms();
$discountType = (string) ($values['manual_discount_type'] ?? 'none');
$discountValue = (float) ($values['manual_discount_value'] ?? 0);
$newCustomer = '/backend/'.CustomerResource::path().'/create/';

if (function_exists('__r')) {
    try {
        $newCustomer = (string) __r('backend.resource.'.CustomerResource::slug().'.create') ?: $newCustomer;
    } catch (Throwable) {
        // Rotta non registrata: resta il percorso.
    }
}

$field = static function (string $key, string $label, int $span = 3, string $type = 'text', string $help = '') use ($e, $v): string {
    $value = $v($key);
    // Il paese parte dall'Italia, come negli indirizzi del core.
    $value = $value === '' && str_ends_with($key, '_country') ? 'IT' : $value;

    return '<div class="col-12 col-md-'.$span.'">'
        .'<label class="form-label" for="wi-oo-'.$e($key).'">'.$e($label).($help !== '' ? ' '.Tooltip::make($help)->render() : '').'</label>'
        .'<input type="'.$e($type).'" class="form-control" id="wi-oo-'.$e($key).'" name="'.$e($key).'" value="'.$e($value).'" data-wi-oo-field="'.$e($key).'">'
        .'</div>';
};
$select = static function (string $key, string $label, array $options, string $selected, int $span = 3, string $extra = '', string $wrapper = '') use ($e): string {
    $html = '<div class="col-12 col-md-'.$span.'"'.$wrapper.'>'
        .'<label class="form-label" for="wi-oo-'.$e($key).'">'.$e($label).'</label>'
        .'<select class="form-select" id="wi-oo-'.$e($key).'" name="'.$e($key).'" data-wi-oo-field="'.$e($key).'"'.$extra.'>';

    foreach ($options as $value => $text) {
        $html .= '<option value="'.$e($value).'"'.((string) $value === $selected ? ' selected' : '').'>'.$e($text).'</option>';
    }

    return $html.'</select></div>';
};
$card = static fn (string $title, string $body, string $help = '', string $attributes = ''): string => '<div class="col-12"'.$attributes.'><div class="card border"><div class="card-body row g-3">'
    .'<div class="col-12"><h5 class="mb-0">'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').($help !== '' ? ' '.Tooltip::make($help)->render() : '').'</h5></div>'
    .$body
    .'</div></div></div>';

\Wonder\View\View::layout('backend.form');
?>
<form id="wi-oo-form" method="POST" action="<?=$e($FORM_ACTION ?? '')?>" autocomplete="off">
    <?=Csrf::fieldFor('post')?>
    <input type="hidden" name="intent" value="save">
    <input type="hidden" name="lines" value="">
    <div class="row g-3">
        <?=$card('Cliente',
            '<div class="col-12 col-md-6 position-relative">'
                .'<label class="form-label" for="wi-oo-customer-search">Cliente</label>'
                .'<input type="hidden" name="customer_id" value="'.$e($customerId ?: '').'" data-wi-oo-customer-id>'
                .'<div class="input-group">'
                .'<span class="input-group-text"><i class="bi bi-person" aria-hidden="true"></i></span>'
                .'<input type="search" class="form-control" id="wi-oo-customer-search" placeholder="Cerca per nome, email o partita IVA" value="'.$e($customerLabel).'" data-wi-oo-customer-search>'
                .'</div>'
                .'<div class="list-group position-absolute shadow d-none" style="z-index: 1050; left: .75rem; right: .75rem; max-height: 18rem; overflow-y: auto" data-wi-oo-customer-results></div>'
                .'<div class="small mt-1 text-body-secondary" data-wi-oo-customer-message></div>'
            .'</div>'
            .'<div class="col-12 col-md-6 d-flex align-items-end">'
                .'<a class="btn btn-outline-secondary" href="'.$e($newCustomer).'" target="_blank" rel="noopener"><i class="bi bi-person-plus"></i> Nuovo cliente</a>'
            .'</div>'
            .$field('email', 'Email', 6, 'email')
            .$field('phone', 'Telefono', 6),
            'Scegliendo il cliente si riempiono fatturazione, consegna e pagamento dalla sua scheda: puoi cambiarli per questo ordine. Il cliente nuovo si crea in un\'altra scheda; poi cercalo qui.'
        )?>
        <?=$card('Fatturazione',
            $select('billing_type', 'Tipo', ['private' => 'Privato', 'business' => 'Azienda'], (string) ($values['billing_type'] ?? 'private'))
            .$field('billing_business_name', 'Ragione sociale', 9)
            .$field('billing_name', 'Nome')
            .$field('billing_surname', 'Cognome')
            .$field('billing_cf', 'Codice fiscale')
            .$field('billing_pi', 'Partita IVA')
            .$field('billing_sdi', 'Codice SDI')
            .$field('billing_pec', 'PEC', 3, 'email')
            .$field('billing_street', 'Via', 4)
            .$field('billing_number', 'Civico', 2)
            .$field('billing_cap', 'CAP', 2)
            .$field('billing_city', 'Città', 2)
            .$field('billing_province', 'Provincia', 1)
            .$field('billing_country', 'Paese', 1)
        )?>
        <?=$card('Consegna',
            $select('fulfillment_type', 'Come arriva la merce', ['shipping' => 'Spedizione', 'pickup' => 'Ritiro in sede', 'none' => 'Nessuna consegna'], $fulfillment, 3, ' data-wi-oo-fulfillment')
            .($locations !== [] ? $select('location_id', 'Sede che evade', $locations, (string) ($values['location_id'] ?? ''), 3, ' data-wi-office-location') : '')
            .($shippingMethods !== [] ? $select('shipping_method_id', 'Metodo di spedizione', ['0' => 'Nessuno'] + $shippingMethods, (string) ($values['shipping_method_id'] ?? '0'), 3, '', ' data-wi-oo-shipping') : '')
            .'<div class="col-12 col-md-3" data-wi-oo-shipping>'
                .'<label class="form-label" for="wi-oo-shipping_amount">Costo di spedizione (€) '.Tooltip::make('Vuoto = il costo del listino del metodo di spedizione. Scrivilo per fissarlo a mano, anche zero.')->render().'</label>'
                .'<input type="text" class="form-control" id="wi-oo-shipping_amount" name="shipping_amount" inputmode="decimal" value="'.$e($v('shipping_amount')).'">'
            .'</div>'
            .'<div class="col-12" data-wi-oo-shipping><div class="row g-3">'
                .$field('shipping_name', 'Destinatario: nome')
                .$field('shipping_surname', 'Cognome')
                .$field('shipping_phone', 'Telefono')
                .'<div class="col-12 col-md-3"></div>'
                .$field('shipping_street', 'Via', 4)
                .$field('shipping_number', 'Civico', 2)
                .$field('shipping_cap', 'CAP', 2)
                .$field('shipping_city', 'Città', 2)
                .$field('shipping_province', 'Provincia', 1)
                .$field('shipping_country', 'Paese', 1)
            .'</div></div>',
            'Con la spedizione serve l\'indirizzo di consegna; con il ritiro la merce esce dalla sede scelta.'
        )?>
        <div class="col-12"><div class="card border"><div class="card-body row g-3">
            <div class="col-12"><h5 class="mb-0">Righe <?=Tooltip::make('Prezzo vuoto = quello del listino o della campagna in corso. Lo sconto vale sulla riga. «Disponibili» è la merce della sede: se non basta la conferma si ferma, salvo gli articoli che si vendono senza giacenza.')->render()?></h5></div>
            <?=OfficeOrderEditor::render(
                (array) ($editor['lines'] ?? []),
                OfficeOrderEditor::searchUrl(),
                OfficeOrderForm::taxCategories(),
                Gestionale::feature('batch_tracking'),
                Gestionale::feature('purchasing')
            )?>
        </div></div></div>
        <?=$card('Pagamento e sconto',
            $select('payment_method_id', 'Metodo di pagamento', ['0' => 'Nessuno'] + $methods, (string) ($values['payment_method_id'] ?? '0'))
            .(count($terms) > 1 ? $select('payment_term_id', 'Condizione di pagamento', ['0' => 'Nessuna (a vista)'] + $terms, (string) ($values['payment_term_id'] ?? '0')) : '')
            .$select('manual_discount_type', 'Sconto sul totale', ['none' => 'Nessuno', 'percent' => 'Percentuale', 'amount' => 'Importo'], $discountType, 3)
            .'<div class="col-12 col-md-3">'
                .'<label class="form-label" for="wi-oo-manual_discount_value">Valore dello sconto</label>'
                .'<input type="text" class="form-control" id="wi-oo-manual_discount_value" name="manual_discount_value" inputmode="decimal" value="'.$e($discountValue > 0 ? number_format($discountValue, 2, ',', '') : '').'">'
            .'</div>',
            'La condizione dice in quante rate e quando si paga: alla conferma nascono le rate nello Scadenzario.'
        )?>
        <?=$card('Note',
            '<div class="col-12 col-md-4"><label class="form-label" for="wi-oo-document_note">Nota stampata '.Tooltip::make('Compare sul documento che riceve il cliente.')->render().'</label>'
                .'<textarea class="form-control" id="wi-oo-document_note" name="document_note" rows="2">'.$e($v('document_note')).'</textarea></div>'
            .'<div class="col-12 col-md-4"><label class="form-label" for="wi-oo-customer_note">Nota del cliente</label>'
                .'<textarea class="form-control" id="wi-oo-customer_note" name="customer_note" rows="2">'.$e($v('customer_note')).'</textarea></div>'
            .'<div class="col-12 col-md-4"><label class="form-label" for="wi-oo-internal_note">Nota interna '.Tooltip::make('La vede solo chi lavora nel gestionale.')->render().'</label>'
                .'<textarea class="form-control" id="wi-oo-internal_note" name="internal_note" rows="2">'.$e($v('internal_note')).'</textarea></div>'
        )?>
        <?php if ($editing) { ?>
        <?=$card('Totali',
            '<div class="col-12"><dl class="row mb-0">'
                .'<dt class="col-6 col-md-3">Prodotti</dt><dd class="col-6 col-md-3 text-end">'.$e(OrderSheet::money($values['products_total'] ?? 0)).'</dd>'
                .'<dt class="col-6 col-md-3">Sconto</dt><dd class="col-6 col-md-3 text-end">'.$e(OrderSheet::money($values['discount_total'] ?? 0)).'</dd>'
                .'<dt class="col-6 col-md-3">Spedizione</dt><dd class="col-6 col-md-3 text-end">'.$e(OrderSheet::money($values['shipping_total'] ?? 0)).'</dd>'
                .'<dt class="col-6 col-md-3">di cui IVA</dt><dd class="col-6 col-md-3 text-end">'.$e(OrderSheet::money($values['tax_total'] ?? 0)).'</dd>'
                .'<dt class="col-6 col-md-3 fs-5">Totale</dt><dd class="col-6 col-md-3 text-end fs-5 fw-semibold">'.$e(OrderSheet::money($values['total'] ?? 0)).'</dd>'
            .'</dl></div>',
            'I totali dell\'ultimo salvataggio, con listino, campagne, sconti e spedizione. Dopo una modifica salva la bozza per vederli aggiornati.'
        )?>
        <?php } ?>
        <div class="col-12"><div class="card border"><div class="card-body d-flex flex-wrap align-items-center justify-content-end gap-2">
            <?php if ($editing) { ?>
            <button type="button" class="btn btn-outline-danger me-auto" data-wi-oo-delete><i class="bi bi-trash"></i> Elimina bozza</button>
            <?php } ?>
            <div class="form-check me-2">
                <input class="form-check-input" type="checkbox" id="wi-oo-notify" name="notify" value="true">
                <label class="form-check-label" for="wi-oo-notify">Alla conferma scrivi al cliente</label>
            </div>
            <button type="submit" class="btn btn-outline-dark">Salva bozza</button>
            <button type="button" class="btn btn-dark" data-wi-oo-confirm>Conferma ordine</button>
        </div></div></div>
    </div>
</form>
<script>
(function () {
    'use strict';

    var form = document.getElementById('wi-oo-form');
    var intent = form.querySelector('input[name="intent"]');
    var lines = form.querySelector('input[name="lines"]');
    var customersUrl = <?=$json(OfficeOrderEditor::customersUrl())?>;
    var customerId = form.querySelector('[data-wi-oo-customer-id]');
    var customerSearch = form.querySelector('[data-wi-oo-customer-search]');
    var customerResults = form.querySelector('[data-wi-oo-customer-results]');
    var customerMessage = form.querySelector('[data-wi-oo-customer-message]');
    var fulfillment = form.querySelector('[data-wi-oo-fulfillment]');
    var sending = false;
    var timer = null;
    var seq = 0;

    function editor() {
        var root = form.querySelector('[data-wi-office-editor]');

        return root ? root.wiOfficeEditor : null;
    }

    function ask(options) {
        if (window.wi && typeof window.wi.confirm === 'function') {
            return window.wi.confirm(options);
        }

        return Promise.resolve(window.confirm(options.text));
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

            if (node.tagName === 'SELECT') {
                if (node.querySelector('option[value="' + String(customer[key]) + '"]')) {
                    node.value = String(customer[key]);
                }
            } else {
                node.value = String(customer[key]);
            }
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

    // Indirizzo, metodo e costo di spedizione solo quando si spedisce.
    function shippingShown() {
        var on = fulfillment.value === 'shipping';

        Array.prototype.forEach.call(form.querySelectorAll('[data-wi-oo-shipping]'), function (node) {
            node.classList.toggle('d-none', !on);
        });
    }

    fulfillment.addEventListener('change', shippingShown);
    shippingShown();

    function lock(on) {
        Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"], [data-wi-oo-confirm], [data-wi-oo-delete]'), function (button) {
            button.disabled = on;
        });
        sending = on;
    }

    function prepare() {
        var current = editor();

        if (sending || !current || !current.validate()) {
            return false;
        }

        lines.value = current.serialize();
        lock(true);

        if (typeof window.loadingSpinner === 'function') {
            window.loadingSpinner();
        }

        return true;
    }

    function send(what) {
        intent.value = what;

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else if (prepare()) {
            form.submit();
        } else {
            intent.value = 'save';
        }
    }

    form.addEventListener('submit', function (event) {
        if (intent.value === 'delete') {
            lines.value = '[]';
            lock(true);

            return;
        }

        if (!prepare()) {
            event.preventDefault();
            intent.value = 'save';
        }
    });

    form.addEventListener('click', function (event) {
        var current;

        if (event.target.closest('[data-wi-oo-confirm]')) {
            current = editor();

            if (sending || !current || !current.validate()) {
                return;
            }

            ask({
                title: 'Conferma ordine',
                text: 'L\'ordine prende il numero, la merce esce dal magazzino e nascono le rate. Dopo le righe non si modificano più: si possono solo annullare.',
                ok: 'Conferma ordine',
                cancel: 'Torna alla bozza'
            }).then(function (ok) {
                if (ok) {
                    send('confirm');
                }
            });
        } else if (event.target.closest('[data-wi-oo-delete]')) {
            ask({
                title: 'Elimina bozza',
                text: 'La bozza si elimina con le sue righe. Non ha numero né movimenti di magazzino: non resta niente.',
                ok: 'Elimina',
                cancel: 'Annulla'
            }).then(function (ok) {
                if (ok) {
                    send('delete');
                }
            });
        }
    });

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            lock(false);
            intent.value = 'save';

            if (typeof window.loadingSpinner === 'function') {
                window.loadingSpinner(false);
            }
        }
    });
})();
</script>
