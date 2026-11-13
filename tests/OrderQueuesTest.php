<?php
/** php tests/OrderQueuesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\AttentionWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\OrdersWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\SetupWidget;
use Wonder\Plugin\Gestionale\Support\Orders\OrderQueues;

$urls = [
    'mese' => '/backend/ordini/?gst_orders__periodo=questo-mese',
    'in_attesa' => '/backend/ordini/?gst_orders__situazione=in_attesa',
    'da_evadere' => '/backend/ordini/?gst_orders__situazione=da_evadere',
    'da_pagare' => '/backend/ordini/?gst_orders__situazione=da_pagare',
];

check('le tre code hanno una condizione, una chiave sconosciuta nessuna', fn () =>
    array_keys(OrderQueues::LABELS) === ['in_attesa', 'da_evadere', 'da_pagare']
    && OrderQueues::condition('in_attesa') === "`status` = 'pending'"
    && str_contains(OrderQueues::condition('da_evadere'), "'confirmed', 'processing'")
    && str_contains(OrderQueues::condition('da_evadere'), "'unfulfilled', 'partially_fulfilled'")
    && str_contains(OrderQueues::condition('da_pagare'), "'unpaid', 'pending', 'partially_paid'")
    && OrderQueues::condition('altro') === ''
);

check('si contano le stesse righe dell\'elenco Ordini', fn () =>
    str_contains(OrderQueues::BASE, "`stage` = 'order'")
    && str_contains(OrderQueues::BASE, "'awaiting_payment', 'abandoned'")
    && str_contains(OrderQueues::BASE, "`deleted` = 'false'")
);

check('una sola query conta code, mese e oggi', function () {
    $sql = OrderQueues::countSql(new DateTimeImmutable('2026-11-13 10:00:00'));

    return str_starts_with($sql, 'SELECT ')
        && substr_count($sql, 'SELECT') === 1
        && str_contains($sql, 'AS `in_attesa`')
        && str_contains($sql, 'AS `da_evadere`')
        && str_contains($sql, 'AS `da_pagare`')
        && str_contains($sql, 'AS `mese`')
        && str_contains($sql, 'AS `oggi`')
        && str_contains($sql, '`gst_orders`')
        && str_ends_with($sql, OrderQueues::BASE);
});

check('i numeri tornano interi e mai negativi', fn () =>
    OrderQueues::normalize(['in_attesa' => '3', 'da_pagare' => '-1', 'oggi' => null]) === [
        'in_attesa' => 3, 'da_evadere' => 0, 'da_pagare' => 0, 'mese' => 0, 'oggi' => 0,
    ]
);

check('«Ordini» è un riquadro della home per chi vede le vendite', fn () =>
    is_subclass_of(OrdersWidget::class, HomeWidget::class)
    && (new OrdersWidget)->title() === 'Ordini'
    && (new OrdersWidget)->authorities() === (new AttentionWidget)->authorities()
);

check('«Ordini» viene dopo i Primi passi e prima di «Da controllare»', function () {
    $widgets = (require __DIR__.'/../config/module.php')['backend']['home_widgets'];

    return (new OrdersWidget)->order() > (new SetupWidget)->order()
        && (new OrdersWidget)->order() < (new AttentionWidget)->order()
        && array_search(OrdersWidget::class, $widgets, true) === array_search(SetupWidget::class, $widgets, true) + 1;
});

check('il riquadro mostra quattro card con i link agli elenchi filtrati', function () use ($urls) {
    $html = OrdersWidget::markup(['in_attesa' => 2, 'da_evadere' => 1, 'da_pagare' => 0, 'mese' => 14, 'oggi' => 3], $urls);

    return substr_count($html, 'col-xl-3') === 4
        && str_starts_with($html, '<div class="col-12"><div class="row g-3">')
        && str_contains($html, 'Ordini del mese')
        && str_contains($html, '14')
        && str_contains($html, '3 arrivati oggi.')
        && str_contains($html, '2 ordini')
        && str_contains($html, '1 ordine<')
        && str_contains($html, '0 ordini')
        && str_contains($html, 'gst_orders__situazione=da_evadere')
        && str_contains($html, 'gst_orders__periodo=questo-mese');
});

check('una coda vuota è in verde, una piena in giallo', function () use ($urls) {
    $html = OrdersWidget::markup(['in_attesa' => 5, 'da_evadere' => 0, 'da_pagare' => 0, 'mese' => 0, 'oggi' => 0], $urls);

    return str_contains($html, 'warning') && str_contains($html, 'success');
});

check('il singolare e lo zero di oggi si leggono bene', function () use ($urls) {
    $uno = OrdersWidget::markup(['mese' => 1, 'oggi' => 1], $urls);
    $zero = OrdersWidget::markup([], $urls);

    return str_contains($uno, '1 arrivato oggi.')
        && str_contains($zero, 'Nessuno oggi.')
        && !str_contains($zero, '—');
});

check('senza indirizzi le card non diventano link vuoti', fn () =>
    !str_contains(OrdersWidget::markup([], []), 'href=')
);

summary();
