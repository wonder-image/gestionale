<?php
/** php tests/FulfilledLinesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Sales\FulfilledLines;

check('DDT e spedizione della stessa merce: contata una volta, il massimo', fn () =>
    FulfilledLines::of([1 => 5], [1 => 3], [1 => 3]) === [1 => 3.0]
    && FulfilledLines::of([1 => 5], [1 => 2], [1 => 4]) === [1 => 4.0]
    && FulfilledLines::of([1 => 5], [1 => 5], []) === [1 => 5.0]
);

check('mai oltre l\'ordinato, mai sotto zero', fn () =>
    FulfilledLines::of([1 => 2], [1 => 3], [1 => 9]) === [1 => 2.0]
    && FulfilledLines::of([1 => 2], [], []) === [1 => 0.0]
);

check('il resto di ogni riga', fn () =>
    FulfilledLines::remaining([1 => 5, 2 => '1.500'], [1 => 2], [2 => '0.5']) === [1 => 3.0, 2 => 1.0]
);

check('le righe che non sono ordinate non contano', fn () =>
    FulfilledLines::of([1 => 1], [7 => 4], [8 => 2]) === [1 => 0.0]
);

summary();
