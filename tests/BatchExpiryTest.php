<?php
/** php tests/BatchExpiryTest.php — G3.20, scadenze dei lotti, pagina Lotti e riquadro */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Backend\Widgets\ExpiringBatchesWidget;
use Wonder\Plugin\Gestionale\Resources\Stock\BatchResource;
use Wonder\Plugin\Gestionale\Support\Stock\BatchExpiry;

$oggi = '2026-10-13';

check('status: scaduto da ieri, oggi si vende ancora, in scadenza entro i giorni, poi ok', fn () =>
    BatchExpiry::status('2026-10-12', $oggi, 30) === 'expired'
    && BatchExpiry::status('2026-10-13', $oggi, 30) === 'expiring'
    && BatchExpiry::status('2026-11-12', $oggi, 30) === 'expiring'
    && BatchExpiry::status('2026-11-13', $oggi, 30) === 'ok'
    && BatchExpiry::status('', $oggi, 30) === 'none'
    && BatchExpiry::status('0000-00-00', $oggi, 30) === 'none'
    && BatchExpiry::status('2026-10-14', $oggi, 0) === 'ok'
);

check('badge: rosso scaduto, giallo in scadenza, testo semplice altrimenti, trattino senza', fn () =>
    BatchExpiry::badge('2026-10-01', $oggi, 30) === '<span class="badge text-bg-danger">01/10/2026 · scaduto</span>'
    && BatchExpiry::badge('2026-10-20', $oggi, 30) === '<span class="badge text-bg-warning">20/10/2026</span>'
    && BatchExpiry::badge('2027-01-01', $oggi, 30) === '01/01/2027'
    && BatchExpiry::badge(null, $oggi, 30) === '—'
);

check('filtro Stato: con pezzi, in scadenza e scaduti sui soli lotti con pezzi, esauriti, tutti', function () use ($oggi) {
    $con = BatchResource::statusCondition(['con'], $oggi, 30);
    $scadenza = BatchResource::statusCondition(['scadenza'], $oggi, 30);
    $scaduti = BatchResource::statusCondition(['scaduti'], $oggi, 30);

    return str_ends_with($con, ' > 0')
        && str_contains($scadenza, ">= '2026-10-13'") && str_contains($scadenza, "<= '2026-11-12'") && str_contains($scadenza, ' > 0 AND ')
        && str_contains($scaduti, "< '2026-10-13'")
        && str_ends_with(BatchResource::statusCondition(['esauriti'], $oggi, 30), ' <= 0')
        && BatchResource::statusCondition([''], $oggi, 30) === ''
        && BatchResource::statusCondition(['x; DROP'], $oggi, 30) === '';
});

check('filtro Sede: solo id interi', fn () =>
    str_contains(BatchResource::locationCondition(['2', '3', 'x', '-1']), '`location_id` IN (2, 3)')
    && BatchResource::locationCondition(['abc']) === ''
);

check('pezzi: interi senza decimali, il resto con la virgola', fn () =>
    BatchResource::pieces(10.0) === '10' && BatchResource::pieces(1200.0) === '1.200'
    && BatchResource::pieces(2.5) === '2,5' && BatchResource::pieces(0.0) === '0'
);

check('riquadro: niente senza lotti; nomi escapati, scadenza colorata, «e altri»', function () use ($oggi) {
    $html = ExpiringBatchesWidget::markup([
        ['number' => 'L<1>', 'product' => 'Latte', 'expiry' => '2026-10-01', 'pieces' => '3', 'url' => '/b/1/'],
    ], 4, $oggi, 30, '/lotti/');

    return ExpiringBatchesWidget::markup([], 0, $oggi, 30, '/lotti/') === ''
        && str_contains($html, 'L&lt;1&gt;') && !str_contains($html, 'L<1>')
        && str_contains($html, 'text-bg-danger')
        && str_contains($html, 'entro 30 giorni')
        && str_contains($html, 'Apri i Lotti e altri 3');
});

check('senza database la funzionalità è bloccata: la pagina Lotti non ha pagine e il riquadro non c\'è', fn () =>
    !in_array(true, (array) BatchResource::pageSchema()->get('pages'), true)
    && (new ExpiringBatchesWidget())->render() === ''
);

summary();
