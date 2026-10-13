<?php

use Wonder\Elements\Components\Tooltip;
use Wonder\Http\Csrf;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentEditor;
use Wonder\Plugin\Gestionale\Support\Stock\StockVersionSearch;

/*
 * La bozza di carico, scarico o inventario (G2b-bis 4.2): Sede · Causale · Nota, le righe
 * e due bottoni. Posta alle rotte `store`/`update` del core, che la Resource
 * intercetta in `mutateRequestValues()`: le righe viaggiano nel solo campo
 * `lines`, scritto dal JS all'invio.
 */

/** @var class-string<\Wonder\Plugin\Gestionale\Resources\Stock\StockDocumentResource> $resource */
$resource = $RESOURCE_CLASS;
$values = (array) ($VALUES ?? []);
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$json = static fn (mixed $value): string => (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
$locationId = (int) ($values['location_id'] ?? 0) ?: Locations::mainId();
$reason = (string) ($values['reason'] ?? '');
$note = html_entity_decode((string) ($values['note'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$editor = (array) ($values['editor'] ?? []);
$type = $resource::type();
$noteHelp = match ($type) {
    'issue' => 'Per esempio a chi è andata la merce o perché.',
    'stocktake' => 'Per esempio quale scaffale o reparto hai contato.',
    default => 'Per esempio il numero della bolla o della fattura del fornitore.',
};

\Wonder\View\View::layout('backend.form');
?>
<form id="wi-doc-form" method="POST" action="<?=$e($FORM_ACTION ?? '')?>" autocomplete="off">
    <?=Csrf::fieldFor('post')?>
    <input type="hidden" name="intent" value="save">
    <input type="hidden" name="lines" value="">
    <div class="row g-3">
        <wi-card class="col-12">
            <?php if ($resource::showsLocation()) { ?>
            <div class="col-12 col-md-3">
                <label class="form-label" for="wi-doc-location">Sede</label>
                <select class="form-select" id="wi-doc-location" name="location_id" data-wi-stock-location>
                    <?php foreach ($resource::locationOptions() as $id => $label) { ?>
                    <option value="<?=$e($id)?>"<?=(int) $id === $locationId ? ' selected' : ''?>><?=$e($label)?></option>
                    <?php } ?>
                </select>
            </div>
            <?php } ?>
            <?php if ($type === 'issue') { ?>
            <div class="col-12 col-md-3">
                <label class="form-label" for="wi-doc-reason">Causale</label>
                <select class="form-select" id="wi-doc-reason" name="reason">
                    <option value="">Scegli…</option>
                    <?php foreach (Reasons::forDocument('issue') as $key => $label) { ?>
                    <option value="<?=$e($key)?>"<?=(string) $key === $reason ? ' selected' : ''?>><?=$e($label)?></option>
                    <?php } ?>
                </select>
            </div>
            <?php } ?>
            <div class="col-12 col-md">
                <label class="form-label" for="wi-doc-note">Nota <?=Tooltip::make($noteHelp)->render()?></label>
                <input type="text" class="form-control" id="wi-doc-note" name="note" maxlength="500" value="<?=$e($note)?>">
            </div>
        </wi-card>
        <wi-card class="col-12">
            <?=StockDocumentEditor::render(
                (array) ($editor['lines'] ?? []),
                StockDocumentEditor::searchUrl(),
                $type,
                $type === 'stocktake' ? StockVersionSearch::scopeOptions() : []
            )?>
        </wi-card>
        <wi-card class="col-12">
            <div class="col-12 d-flex flex-wrap justify-content-end gap-2">
                <button type="submit" class="btn btn-outline-dark">Salva bozza</button>
                <button type="button" class="btn btn-dark" data-wi-doc-confirm><?=$e($resource::confirmLabel())?></button>
            </div>
        </wi-card>
    </div>
</form>
<script>
(function () {
    'use strict';

    var form = document.getElementById('wi-doc-form');
    var intent = form.querySelector('input[name="intent"]');
    var lines = form.querySelector('input[name="lines"]');
    var question = <?=$json($resource::confirmQuestion())?>;
    var label = <?=$json($resource::confirmLabel())?>;
    var sending = false;

    function editor() {
        var root = form.querySelector('[data-wi-stock-editor]');

        return root ? root.wiStockEditor : null;
    }

    // I bottoni stanno nei <wi-card>, che il core ricopia al caricamento: si cercano ogni volta.
    function lock(on) {
        var buttons = form.querySelectorAll('button[type="submit"], [data-wi-doc-confirm]');
        var i;

        sending = on;

        for (i = 0; i < buttons.length; i++) {
            buttons[i].disabled = on;
        }
    }

    function ask(options) {
        if (window.wi && typeof window.wi.confirm === 'function') {
            return window.wi.confirm(options);
        }

        return Promise.resolve(window.confirm(options.text));
    }

    // Una sola partenza: all'invio le righe vanno in `lines` e i bottoni si spengono.
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

    function send() {
        intent.value = 'confirm';

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else if (prepare()) {
            form.submit();
        } else {
            intent.value = 'save';
        }
    }

    form.addEventListener('submit', function (event) {
        if (!prepare()) {
            event.preventDefault();
            intent.value = 'save';
        }
    });

    // Il click si ascolta sul form, che resta: il bottone viene ricopiato con il suo <wi-card>.
    form.addEventListener('click', function (event) {
        var current;
        var words;

        if (!event.target.closest('[data-wi-doc-confirm]')) {
            return;
        }

        current = editor();

        if (sending || !current || !current.validate()) {
            return;
        }

        words = current.words();

        // Senza righe (o senza righe contate) niente domanda: risponde il servizio.
        if (words === null) {
            send();

            return;
        }

        ask({
            title: label,
            text: question.replace(/\{(\w+)\}/g, function (all, key) {
                return Object.prototype.hasOwnProperty.call(words, key) ? words[key] : all;
            }),
            ok: label,
            cancel: 'Torna alla bozza'
        }).then(function (ok) {
            if (ok) {
                send();
            }
        });
    });

    // Tornando indietro alla pagina dalla cache del browser i bottoni si riaccendono.
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
<?=$resource::hiddenForms($values)?>
<?php \Wonder\View\View::end(); ?>
