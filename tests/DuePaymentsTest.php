<?php
/** php tests/DuePaymentsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Backend\Widgets\DuePaymentsWidget;
use Wonder\Plugin\Gestionale\Resources\Sales\ScheduleResource;
use Wonder\Plugin\Gestionale\Support\Payments\DuePayments;

$oggi = '2026-10-13';

check('scaduta prima di oggi, in scadenza entro i giorni, oggi compreso', fn () =>
    DuePayments::status('2026-10-12', $oggi, 7) === 'expired'
    && DuePayments::status('2026-10-13', $oggi, 7) === 'expiring'
    && DuePayments::status('2026-10-20', $oggi, 7) === 'expiring'
    && DuePayments::status('2026-10-21', $oggi, 7) === 'ok'
    && DuePayments::status('', $oggi, 7) === 'none'
);

check('il badge: rosso con «scaduta», giallo in scadenza, testo semplice dopo', fn () =>
    DuePayments::badge('2026-10-01', $oggi, 7) === '<span class="badge text-bg-danger">01/10/2026 · scaduta</span>'
    && DuePayments::badge('2026-10-15', $oggi, 7) === '<span class="badge text-bg-warning">15/10/2026</span>'
    && DuePayments::badge('2026-12-31', $oggi, 7) === '31/12/2026'
);

check('il filtro: in attesa senza scelta, le altre con le loro date', function () use ($oggi) {
    $vuoto = DuePayments::filterCondition([], $oggi, 7);
    $scadute = DuePayments::filterCondition(['scadute'], $oggi, 7);
    $scadenza = DuePayments::filterCondition(['scadenza'], $oggi, 7);
    $pagate = DuePayments::filterCondition(['pagate'], $oggi, 7);

    return $vuoto === "`gst_payments`.`status` = 'pending'"
        && DuePayments::filterCondition(['attesa'], $oggi, 7) === $vuoto
        && str_contains($scadute, "`due_date` < '2026-10-13'")
        && str_contains($scadenza, "`due_date` >= '2026-10-13'") && str_contains($scadenza, "`due_date` <= '2026-10-20'")
        && str_contains($pagate, "`status` = 'paid'") && str_contains($pagate, "`paid_at` >= '2026-07-15'")
        && DuePayments::filterCondition(['boh'], $oggi, 7) === $vuoto;
});

check('due scelte insieme si uniscono con OR', fn () =>
    str_contains(DuePayments::filterCondition(['scadute', 'scadenza'], $oggi, 7), ' OR ')
);

check('lo scadenzario guarda solo gli incassi con la scadenza', fn () =>
    str_contains(DuePayments::baseCondition(), "type = 'payment'")
    && str_contains(DuePayments::baseCondition(), 'due_date IS NOT NULL')
    && ScheduleResource::querySchema()['condition'] === DuePayments::baseCondition()
);

check('il riepilogo conta scadute e in scadenza, e lascia le lontane', function () use ($oggi) {
    $r = DuePayments::summary([
        ['amount' => '100.00', 'due_date' => '2026-10-01'],
        ['amount' => '50.50', 'due_date' => '2026-10-15'],
        ['amount' => '999.00', 'due_date' => '2026-12-31'],
    ], $oggi, 7);

    return $r['count'] === 2 && $r['total'] === 150.5 && $r['expired'] === 100.0;
});

check('lo scadenzario sta in Vendite, con «Vendita in ufficio», solo elenco', function () {
    $nav = ScheduleResource::navigationSchema()->toArray();

    return ScheduleResource::$feature === 'office_sales'
        && ($nav['section_key'] ?? '') === 'vendite'
        && ScheduleResource::path() === 'app/gestionale/scadenzario';
});

check('il riquadro tace senza rate, e con le rate dice il totale', function () use ($oggi) {
    $vuoto = DuePaymentsWidget::markup([], 0, 0.0, 0.0, $oggi, 7, '/scadenzario');
    $pieno = DuePaymentsWidget::markup([
        ['customer' => 'Rossi & C.', 'note' => 'Rata 1 di 2', 'amount' => 100.0, 'due_date' => '2026-10-01', 'url' => '/ordine/1'],
    ], 3, 250.0, 100.0, $oggi, 7, '/scadenzario');

    return $vuoto === ''
        && str_contains($pieno, 'Rossi &amp; C.')
        && str_contains($pieno, 'scaduta')
        && str_contains($pieno, '250,00')
        && str_contains($pieno, 'e altre 2')
        && str_contains($pieno, 'href="/scadenzario"');
});

summary();
