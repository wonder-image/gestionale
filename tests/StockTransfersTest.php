<?php
/** php tests/StockTransfersTest.php — G3 piano 4, trasferimenti e guardia di «Più sedi» */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Stock\StockDocumentResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockTransferResource;
use Wonder\Plugin\Gestionale\Support\Features\FeatureGuards;
use Wonder\Plugin\Gestionale\Support\Stock\LevelsSql;

check('guardia: niente da dire senza pezzi fuori dalla principale né trasferimenti in viaggio', fn () =>
    FeatureGuards::where([], 0) === ''
);

check('guardia: le sedi con pezzi, al massimo tre per nome, e i trasferimenti in viaggio', fn () =>
    FeatureGuards::where(['Negozio'], 0) === 'ci sono pezzi in Negozio'
    && FeatureGuards::where(['Negozio', 'Deposito'], 0) === 'ci sono pezzi in Negozio e Deposito'
    && FeatureGuards::where(['A', 'B', 'C'], 0) === 'ci sono pezzi in A, B e C'
    && FeatureGuards::where(['A', 'B', 'C', 'D'], 0) === 'ci sono pezzi in A, B, C e un\'altra sede'
    && FeatureGuards::where(['A', 'B', 'C', 'D', 'E'], 0) === 'ci sono pezzi in A, B, C e altre 2 sedi'
    && FeatureGuards::where([], 1) === 'c\'è 1 trasferimento in viaggio'
    && FeatureGuards::where(['Negozio'], 3) === 'ci sono pezzi in Negozio e ci sono 3 trasferimenti in viaggio'
);

check('guardia: guarda solo «Più sedi» che si spegne', function () {
    // Senza database non si legge niente: qui basta che non tocchi gli altri casi.
    FeatureGuards::assertAllowed(['multi_location' => false], ['multi_location' => false]);
    FeatureGuards::assertAllowed(['multi_location' => true], ['multi_location' => true]);
    FeatureGuards::assertAllowed(['purchasing' => true], ['purchasing' => false]);

    return true;
});

check('In viaggio: le uscite dei trasferimenti in viaggio, col segno girato', function () {
    $sql = LevelsSql::inTransit('p.id');

    return str_contains($sql, "m.type = 'transfer_out'")
        && str_contains($sql, "d.status = 'in_transit'")
        && str_contains($sql, "d.type = 'transfer'")
        && str_contains($sql, '-SUM(m.quantity)')
        && str_contains($sql, 'm.product_id = p.id');
});

check('trasferimenti: la Resource è del tipo giusto e senza «Più sedi» non ha pagine né menu', fn () =>
    StockTransferResource::type() === 'transfer'
    && StockDocumentResource::classFor('transfer') === StockTransferResource::class
    && StockTransferResource::$feature === 'multi_location'
    && !in_array(true, (array) StockTransferResource::pageSchema()->get('pages'), true)
);

check('trasferimenti: stati e domande', fn () =>
    array_keys(StockTransferResource::statusOptions()) === ['', 'draft', 'in_transit', 'completed', 'cancelled']
    && StockTransferResource::cancellable('in_transit')
    && StockTransferResource::cancellable('completed')
    && !StockTransferResource::cancellable('draft')
    && str_contains(StockTransferResource::cancelQuestion('in_transit'), 'torna nella sede di partenza')
    && str_contains(StockTransferResource::confirmQuestion(), '{pezzi}')
    && StockTransferResource::confirmLabel() === 'Invia'
);

check('trasferimenti: il filtro Sede guarda partenza e arrivo, solo id interi', fn () =>
    StockTransferResource::locationFilter(['3']) === '(`location_id` IN (3) OR `to_location_id` IN (3))'
    && StockTransferResource::locationFilter(['x']) === ''
);

check('elenchi dei documenti: il numero apre la scheda (la bozza la modifica), lo stato sta nella sua colonna', function () {
    $confermato = StockTransferResource::numberCell(['id' => 7, 'type' => 'transfer', 'status' => 'in_transit', 'number' => '2026/100009']);
    $bozza = StockTransferResource::numberCell(['id' => 8, 'type' => 'transfer', 'status' => 'draft', 'number' => '']);

    return str_contains($confermato, '/7/">2026/100009</a>') && !str_contains($confermato, 'badge')
        && str_contains($bozza, '/8/edit/') && str_contains($bozza, '>Bozza</a>') && !str_contains($bozza, 'badge')
        && str_contains(StockTransferResource::statusCell(['status' => 'in_transit']), 'In viaggio')
        && str_contains(StockTransferResource::statusCell(['status' => 'completed']), 'Ricevuto')
        && str_contains(StockDocumentResource::statusCell(['status' => 'cancelled']), 'text-bg-danger');
});

check('form nascosti: POST del core con le conferme, Duplica senza domanda, niente window.confirm', function () {
    $html = StockTransferResource::hiddenForms(['id' => 7, 'status' => 'in_transit', 'number' => '2026/100009']);
    $form = static function (string $id) use ($html): string {
        preg_match('~<form id="'.$id.'"[^>]*>.*?</form>~s', $html, $m);

        return $m[0] ?? '';
    };
    $duplica = $form('wi-doc-duplicate');
    $ricevi = $form('wi-doc-receive');
    $annulla = $form('wi-doc-cancel');
    $campi = static fn (string $f, string $what): bool => str_contains($f, ' hidden ')
        && str_contains($f, 'method="post" action="/backend/app/gestionale/documento-magazzino-azione"')
        && str_contains($f, '<input type="hidden" name="action" value="'.$what.'">')
        && str_contains($f, '<input type="hidden" name="document_id" value="7">');

    return $campi($duplica, 'duplica') && !str_contains($duplica, 'data-wi-confirm')
        && $campi($ricevi, 'ricevi')
        && str_contains($ricevi, 'data-wi-confirm="Il trasferimento 2026/100009 arriva: tutta la merce entra nella sede d&#039;arrivo."')
        && str_contains($ricevi, 'data-wi-confirm-title="Ricevi trasferimento" data-wi-confirm-ok="Ricevi"')
        && str_contains($ricevi, 'data-wi-confirm-cancel="Torna indietro"') && !str_contains($ricevi, 'data-wi-confirm-variant')
        && $campi($annulla, 'annulla')
        && str_contains($annulla, 'data-wi-confirm-title="Annulla trasferimento" data-wi-confirm-ok="Annulla il trasferimento" data-wi-confirm-variant="danger"')
        && str_contains($annulla, 'data-wi-confirm-cancel="Torna indietro"')
        && str_contains($html, "window.wi.confirm({title: 'Duplica'") && !str_contains($html, 'window.confirm(')
        && StockTransferResource::hiddenForms(['id' => 0]) === ''
        && !str_contains(StockTransferResource::hiddenForms(['id' => 7, 'status' => 'draft']), 'wi-doc-cancel')
        && !str_contains((string) file_get_contents(__DIR__.'/../view/pages/stock-document-form.php'), 'window.confirm(');
});

summary();
