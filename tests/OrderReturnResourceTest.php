<?php
/** php tests/OrderReturnResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Sales\OrderReturnResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderReturnTableResource;
use Wonder\Plugin\Gestionale\Support\Returns\ReturnRules;

$righe = [
    ['order_item_id' => 11, 'name' => '<b>x</b> Crema', 'ordered' => 3.0, 'returned' => 1.0, 'max' => 2.0],
    ['order_item_id' => 12, 'name' => 'Sapone', 'ordered' => 1.0, 'returned' => 1.0, 'max' => 0.0],
];

check('«Registra reso» è una pagina-form fuori dal menu, per gli amministratori', function () {
    $nav = OrderReturnResource::navigationSchema()->toArray();

    return OrderReturnResource::isFormPage()
        && OrderReturnResource::path() === 'app/gestionale/ordine-reso'
        && empty($nav['enabled'])
        && str_contains(json_encode(OrderReturnResource::permissionSchema()->toArray()), 'administrator');
});

check('la pagina segue la funzionalità «returns»', fn () => OrderReturnResource::$feature === 'returns');

check('il link porta l\'ordine e la strada del ritorno, codificata', function () {
    $url = OrderReturnResource::urlFor(9, '/backend/app/gestionale/ordini/9/?torna=%2Fx');

    return str_contains($url, '?ordine=9') && str_contains($url, '&torna='.rawurlencode('/backend/app/gestionale/ordini/9/?torna=%2Fx'))
        && !str_contains(OrderReturnResource::urlFor(9), 'torna');
});

check('la tabella elenca le righe col loro massimo, e il nome del prodotto non è HTML', function () use ($righe) {
    $html = OrderReturnResource::linesHtml($righe);

    return str_contains($html, '&lt;b&gt;x&lt;/b&gt; Crema') && !str_contains($html, '<b>x</b>')
        && str_contains($html, 'name="lines[11][quantity]"') && str_contains($html, 'max="2"')
        && str_contains($html, 'name="lines[11][reason]"') && str_contains($html, 'name="lines[11][restock]"');
});

check('la tabella mostra la personalizzazione sotto il nome, escapata', function () {
    $html = OrderReturnResource::linesHtml([[
        'order_item_id' => 21, 'name' => 'Penna', 'ordered' => 1.0, 'returned' => 0.0, 'max' => 1.0,
        'customization' => [['customization_id' => 1, 'label' => 'Incisione', 'value' => '<i>Marco</i>', 'option_id' => 0, 'surcharge' => '5.00']],
    ]]);

    return str_contains($html, 'Incisione: &lt;i&gt;Marco&lt;/i&gt;') && !str_contains($html, '<i>Marco')
        && !str_contains(OrderReturnResource::linesHtml([['order_item_id' => 22, 'name' => 'Penna', 'ordered' => 1.0, 'returned' => 0.0, 'max' => 1.0]]), 'Incisione');
});

check('una riga già resa per intero è grigia e senza campi', function () use ($righe) {
    $html = OrderReturnResource::linesHtml($righe);

    return str_contains($html, 'Sapone') && !str_contains($html, 'lines[12]') && str_contains($html, 'text-muted');
});

check('ogni motivo è un\'opzione, e dice se di norma ricarica', function () use ($righe) {
    $html = OrderReturnResource::linesHtml($righe);

    foreach (ReturnRules::REASON_LABELS as $motivo => $etichetta) {
        $ricarica = ReturnRules::defaultRestock($motivo) ? '1' : '0';

        if (!str_contains($html, '<option value="'.$motivo.'" data-restock="'.$ricarica.'"') || !str_contains($html, '>'.$etichetta.'</option>')) {
            return false;
        }
    }

    return true;
});

check('la spunta del ricarico parte accesa per il motivo preimpostato', function () use ($righe) {
    $html = OrderReturnResource::linesHtml($righe);

    return preg_match('/name="lines\[11\]\[restock\]"[^>]*\schecked/', $html) === 1
        && str_contains($html, 'name="lines[11][restock]" value=""');
});

check('senza righe da rendere la pagina lo dice', fn () =>
    str_contains(OrderReturnResource::linesHtml([]), 'Nessun prodotto')
    && str_contains(OrderReturnResource::linesHtml([$righe[1]]), 'Niente da rendere')
);

check('la sede si sceglie solo se ce n\'è più d\'una', function () {
    $una = OrderReturnResource::locationField([['id' => 4, 'label' => '']], 4);
    $due = OrderReturnResource::locationField([['id' => 4, 'label' => 'Negozio'], ['id' => 5, 'label' => 'Deposito <1>']], 5);

    return $una === ''
        && str_contains($due, 'name="location_id"') && str_contains($due, 'Negozio')
        && str_contains($due, 'Deposito &lt;1&gt;') && str_contains($due, '<option value="5" selected');
});

check('la tabella dei resi offre Chiudi e Annulla solo per un reso ricevuto', function () {
    $azioni = array_values(array_filter(
        OrderReturnTableResource::tableSchema(),
        static fn ($c): bool => (string) $c->name === 'actions'
    ))[0]->schema['formatter'];
    $ricevuto = $azioni(['id' => 7, 'order_id' => 9, 'status' => 'received']);

    return str_contains($ricevuto, 'name="action" value="complete"') && str_contains($ricevuto, 'name="action" value="cancel"')
        && str_contains($ricevuto, 'name="return_id" value="7"') && str_contains($ricevuto, 'Chiudi') && str_contains($ricevuto, 'Annulla')
        && $azioni(['id' => 7, 'order_id' => 9, 'status' => 'completed']) === ''
        && $azioni(['id' => 7, 'order_id' => 9, 'status' => 'cancelled']) === '';
});

summary();
