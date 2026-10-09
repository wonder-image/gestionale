<?php
/** php tests/StockBadgeTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\StockBadge;

check('zero o sotto zero è rosso, anche se è sotto la scorta minima', fn () =>
    StockBadge::tone(0.0, false) === 'danger'
    && StockBadge::tone(-2.0, false) === 'danger'
    && StockBadge::tone(0.0, true) === 'danger'
);

check('sotto la scorta minima è giallo, altrimenti grigio', fn () =>
    StockBadge::tone(3.0, true) === 'warning'
    && StockBadge::tone(3.0, false) === 'secondary'
    && StockBadge::tone(0.001, false) === 'secondary'
);

check('una sede è bassa quando il disponibile arriva alla soglia, come per gli avvisi', fn () =>
    StockBadge::isLow([1 => ['available' => 5.0]], [1 => 5.0]) === true
    && StockBadge::isLow([1 => ['available' => 6.0]], [1 => 5.0]) === false
);

check('basta una sede bassa; una sede con la soglia e senza merce conta zero', fn () =>
    StockBadge::isLow([1 => ['available' => 50.0], 2 => ['available' => 1.0]], [1 => 5.0, 2 => 2.0]) === true
    && StockBadge::isLow([1 => ['available' => 50.0]], [1 => 5.0, 2 => 2.0]) === true
);

check('senza soglie nulla è basso', fn () =>
    StockBadge::isLow([1 => ['available' => 0.0]], []) === false
);

check('il totale somma le opzioni ed è basso se una lo è', function (): bool {
    $totale = StockBadge::total([
        1 => ['available' => 4.0, 'low' => false],
        2 => ['available' => 1.5, 'low' => true],
    ]);

    return $totale === ['available' => 5.5, 'low' => true, 'unlimited' => false]
        && StockBadge::total([]) === ['available' => 0.0, 'low' => false, 'unlimited' => false];
});

check('il badge scrive il numero senza zeri inutili e con la virgola', fn () =>
    StockBadge::html(12.0, false) === '<span class="badge text-bg-secondary">12</span>'
    && StockBadge::html(2.5, false) === '<span class="badge text-bg-secondary">2,5</span>'
    && StockBadge::html(-1.0, false) === '<span class="badge text-bg-danger">-1</span>'
);

check('il badge giallo spiega perché', fn () =>
    str_contains(StockBadge::html(3.0, true), 'text-bg-warning')
    && str_contains(StockBadge::html(3.0, true), 'data-bs-toggle="tooltip"')
    && str_contains(StockBadge::html(3.0, true), 'Sotto la scorta minima')
);

check('senza limiti il badge dice infinito, in grigio', fn () =>
    StockBadge::html(StockBadge::UNLIMITED, false, true) === '<span class="badge text-bg-secondary">∞</span>'
);

summary();
