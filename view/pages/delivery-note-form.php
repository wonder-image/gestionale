<?php

use Wonder\Elements\Components\Tooltip;
use Wonder\Http\Csrf;
use Wonder\Plugin\Gestionale\Resources\Sales\DeliveryNoteResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Support\Documents\DeliveryNotePdf;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/*
 * Il DDT (G9 §4.2): la bozza con destinazione, trasporto e quantità; l'emesso
 * in sola lettura con PDF, invio e annullo. Posta alla rotta `update` del core,
 * che la Resource intercetta in `mutateRequestValues()`.
 */

$values = (array) ($VALUES ?? []);
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$json = static fn (mixed $value): string => (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
$v = static fn (string $key): string => html_entity_decode((string) ($values[$key] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$id = (int) ($values['id'] ?? 0);
$status = (string) ($values['status'] ?? 'draft');
$draft = $status === 'draft';
$disabled = $draft ? '' : ' disabled';
$order = (array) ($values['order'] ?? []);
$lines = (array) ($values['lines'] ?? []);
$carriers = ['0' => 'Nessuno'] + \Wonder\Plugin\Gestionale\Resources\System\SettingResource::carriers();

$started = substr(str_replace(' ', 'T', (string) ($values['transport_started_at'] ?? '')), 0, 16);
$started = str_starts_with($started, '0000') ? '' : $started;
$number = static fn (mixed $value): string => (float) $value > 0 ? rtrim(rtrim(number_format((float) $value, 3, ',', ''), '0'), ',') : '';

$input = static fn (string $key, string $label, int $span = 3, string $type = 'text', string $value = '') => '<div class="col-12 col-md-'.$span.'">'
    .'<label class="form-label" for="wi-dn-'.$e($key).'">'.$e($label).'</label>'
    .'<input type="'.$e($type).'" class="form-control" id="wi-dn-'.$e($key).'" name="'.$e($key).'" value="'.$e($value).'"'.$disabled.'>'
    .'</div>';
$select = static function (string $key, string $label, array $options, string $selected, int $span = 3) use ($e, $disabled): string {
    $html = '<div class="col-12 col-md-'.$span.'"><label class="form-label" for="wi-dn-'.$e($key).'">'.$e($label).'</label>'
        .'<select class="form-select" id="wi-dn-'.$e($key).'" name="'.$e($key).'"'.$disabled.'>';

    foreach ($options as $value => $text) {
        $html .= '<option value="'.$e($value).'"'.((string) $value === $selected ? ' selected' : '').'>'.$e($text).'</option>';
    }

    return $html.'</select></div>';
};
$card = static fn (string $title, string $body, string $help = ''): string => '<div class="col-12"><div class="card border"><div class="card-body row g-3">'
    .'<div class="col-12"><h5 class="mb-0">'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').($help !== '' ? ' '.Tooltip::make($help)->render() : '').'</h5></div>'
    .$body.'</div></div></div>';

$rows = '';

foreach ($lines as $line) {
    $lineId = (int) $line['id'];
    $quantity = rtrim(rtrim(number_format((float) $line['quantity'], 3, ',', ''), '0'), ',');
    $rows .= '<tr>'
        .'<td>'.$e($line['description'] ?? '').'</td>'
        .'<td class="d-none d-md-table-cell">'.$e($line['sku'] ?? '').'</td>'
        .'<td>'.$e(($line['batch_number'] ?? '') !== '' ? $line['batch_number'] : '—').'</td>'
        .'<td class="text-end" style="width: 8rem">'.($draft
            ? '<input type="text" class="form-control form-control-sm text-end" name="qty_'.$lineId.'" value="'.$e($quantity).'" inputmode="decimal" aria-label="Quantità">'
            : $e($quantity)).'</td>'
        .'<td>'.$e($line['unit'] ?? '').'</td>'
        .'</tr>';
}

\Wonder\View\View::layout('backend.form');
?>
<form id="wi-dn-form" method="POST" action="<?=$e($FORM_ACTION ?? '')?>" autocomplete="off">
    <?=Csrf::fieldFor('post')?>
    <input type="hidden" name="intent" value="save">
    <div class="row g-3">
        <?=$card('Documento',
            '<div class="col-12"><dl class="row mb-0">'
                .'<dt class="col-6 col-md-2">Stato</dt><dd class="col-6 col-md-2">'.DeliveryNoteResource::badge($status).'</dd>'
                .'<dt class="col-6 col-md-2">Numero</dt><dd class="col-6 col-md-2">'.$e(trim((string) ($values['number'] ?? '')) !== '' ? $values['number'] : '—').'</dd>'
                .'<dt class="col-6 col-md-2">Data</dt><dd class="col-6 col-md-2">'.$e(substr(OrderSheet::date((string) ($values['date'] ?? '')), 0, 10) ?: '—').'</dd>'
                .'<dt class="col-6 col-md-2">Ordine</dt><dd class="col-6 col-md-2">'.($order !== []
                    ? '<a href="'.$e(OrderResource::detailUrl((int) $order['id'])).'">'.$e((string) ($order['order_number'] ?? '')).'</a>'
                    : '—').'</dd>'
                .'<dt class="col-6 col-md-2">Destinatario</dt><dd class="col-6 col-md-6">'.$e(DeliveryNoteResource::customerOf($values)).'</dd>'
            .'</dl></div>',
            'Il DDT accompagna la merce: non muove il magazzino, che è già uscito alla conferma dell\'ordine. Emesso, prende numero e data e non si modifica più.'
        )?>
        <?=$card('Luogo di consegna',
            $input('shipping_name', 'Destinatario: nome', 3, 'text', $v('shipping_name'))
            .$input('shipping_surname', 'Cognome', 3, 'text', $v('shipping_surname'))
            .$input('shipping_street', 'Via', 4, 'text', $v('shipping_street'))
            .$input('shipping_number', 'Civico', 2, 'text', $v('shipping_number'))
            .$input('shipping_cap', 'CAP', 2, 'text', $v('shipping_cap'))
            .$input('shipping_city', 'Città', 4, 'text', $v('shipping_city'))
            .$input('shipping_province', 'Provincia', 2, 'text', $v('shipping_province'))
            .$input('shipping_country', 'Paese', 2, 'text', $v('shipping_country') ?: 'IT'),
            'Dove va la merce: parte dall\'ordine e si può cambiare finché il DDT è in bozza.'
        )?>
        <?=$card('Trasporto',
            $select('transport_by', 'A cura del', DeliveryNotePdf::TRANSPORT_BY, (string) ($values['transport_by'] ?? 'sender'))
            .$select('carrier_id', 'Vettore', $carriers, (string) ($values['carrier_id'] ?? '0'))
            .$input('transport_started_at', 'Inizio trasporto', 3, 'datetime-local', $started)
            .$select('freight_terms', 'Porto', DeliveryNotePdf::FREIGHT_TERMS, (string) ($values['freight_terms'] ?? 'prepaid'))
            .$input('goods_appearance', 'Aspetto dei beni', 3, 'text', $v('goods_appearance'))
            .$input('packages_count', 'Colli', 3, 'number', (int) ($values['packages_count'] ?? 0) > 0 ? (string) (int) $values['packages_count'] : '')
            .$input('gross_weight', 'Peso lordo (kg)', 3, 'text', $number($values['gross_weight'] ?? 0))
            .$input('net_weight', 'Peso netto (kg)', 3, 'text', $number($values['net_weight'] ?? 0))
            .'<div class="col-12"><label class="form-label" for="wi-dn-document_note">Note</label>'
                .'<textarea class="form-control" id="wi-dn-document_note" name="document_note" rows="2"'.$disabled.'>'.$e($v('document_note')).'</textarea></div>',
            'I valori di partenza si scelgono in Impostazioni → DDT. L\'inizio del trasporto vuoto è il momento dell\'emissione.'
        )?>
        <?=$card('Righe',
            '<div class="col-12 table-responsive"><table class="table table-sm align-middle mb-0">'
                .'<thead><tr><th>Descrizione</th><th class="d-none d-md-table-cell">Codice</th><th>Lotto</th><th class="text-end">Quantità</th><th>U.M.</th></tr></thead>'
                .'<tbody>'.($rows !== '' ? $rows : '<tr><td colspan="5" class="text-body-secondary">Nessuna riga.</td></tr>').'</tbody></table></div>',
            $draft ? 'Le righe vengono dall\'ordine, una per lotto. Abbassa la quantità per consegnare una parte; zero toglie la riga. Il resto si mette in un altro DDT.' : ''
        )?>
        <div class="col-12"><div class="card border"><div class="card-body d-flex flex-wrap align-items-center justify-content-end gap-2">
            <?php if ($draft) { ?>
            <button type="button" class="btn btn-outline-danger me-auto" data-wi-dn-action="delete"><i class="bi bi-trash"></i> Elimina bozza</button>
            <button type="submit" class="btn btn-outline-dark">Salva bozza</button>
            <button type="button" class="btn btn-dark" data-wi-dn-action="issue"><i class="bi bi-check2-circle"></i> Emetti</button>
            <?php } else { ?>
                <?php if ($status === 'issued') { ?>
                <button type="button" class="btn btn-outline-danger me-auto" data-wi-dn-action="cancel"><i class="bi bi-x-circle"></i> Annulla DDT</button>
                <button type="button" class="btn btn-outline-secondary" data-wi-dn-action="send"><i class="bi bi-envelope"></i> Invia per email</button>
                <?php } ?>
                <?php if (trim((string) ($values['pdf_file'] ?? '')) !== '') { ?>
                <a class="btn btn-dark" target="_blank" rel="noopener" href="<?=$e(DeliveryNoteResource::pdfUrl($id))?>"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
                <?php } ?>
            <?php } ?>
        </div></div></div>
    </div>
</form>
<script>
(function () {
    'use strict';

    var form = document.getElementById('wi-dn-form');
    var intent = form.querySelector('input[name="intent"]');
    var actions = <?=$json([
        'issue' => ['title' => 'Emetti il DDT', 'text' => 'Il DDT prende numero e data e si archivia il PDF; l\'ordine risulta evaso per queste righe. Dopo non si modifica più: si può solo annullare.', 'ok' => 'Emetti'],
        'delete' => ['title' => 'Elimina bozza', 'text' => 'La bozza del DDT si elimina: le righe restano da consegnare.', 'ok' => 'Elimina'],
        'cancel' => ['title' => 'Annulla DDT', 'text' => 'Il DDT resta con il suo numero, annullato: le sue righe tornano da consegnare e l\'evasione dell\'ordine torna indietro.', 'ok' => 'Annulla DDT'],
        'send' => ['title' => 'Invia per email', 'text' => 'Il cliente riceve il DDT in PDF all\'indirizzo dell\'ordine.', 'ok' => 'Invia'],
    ])?>;

    function ask(options) {
        if (window.wi && typeof window.wi.confirm === 'function') {
            return window.wi.confirm(options);
        }

        return Promise.resolve(window.confirm(options.text));
    }

    form.addEventListener('click', function (event) {
        var button = event.target.closest('[data-wi-dn-action]');
        var what = button ? button.getAttribute('data-wi-dn-action') : '';
        var action = actions[what];

        if (!action) {
            return;
        }

        ask({title: action.title, text: action.text, ok: action.ok, cancel: 'Indietro'}).then(function (ok) {
            if (!ok) {
                return;
            }

            // Le caselle spente dell'emesso non partono: si riaccendono per l'invio.
            Array.prototype.forEach.call(form.querySelectorAll('[disabled]'), function (node) {
                node.disabled = false;
            });
            intent.value = what;

            if (typeof window.loadingSpinner === 'function') {
                window.loadingSpinner();
            }

            form.submit();
        });
    });
})();
</script>

<?php \Wonder\View\View::end(); ?>
