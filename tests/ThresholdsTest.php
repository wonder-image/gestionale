<?php
/** php tests/ThresholdsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\Thresholds;

// Senza database le letture tornano vuote, come `Levels`: chi mostra una
// scheda o un elenco non deve esplodere.
check('senza database un prodotto non ha soglie', fn () =>
    Thresholds::forProduct(1) === []
    && Thresholds::forProduct(0) === []
);

check('senza database ogni prodotto chiesto ha la sua voce vuota', fn () =>
    Thresholds::forProducts([1, 2, 2, 0]) === [1 => [], 2 => []]
    && Thresholds::forProducts([]) === []
);

check('senza database non c\'è niente da togliere', fn () =>
    Thresholds::dropFor([1, 2]) === 0 && Thresholds::dropFor([]) === 0
);

$scritte = [
    3 => ['id' => 10, 'quantity' => '5.000'],
    4 => ['id' => 11, 'quantity' => '2.000'],
];

check('il piano di scrittura: nuove, cambiate, tolte', fn () =>
    Thresholds::plan($scritte, [3 => 5.0, 5 => 1.5, 4 => 0.0]) === [
        'insert' => [5 => 1.5],
        'update' => [],
        'delete' => [11],
    ]
);

check('una soglia cambiata si aggiorna sulla sua riga', fn () =>
    Thresholds::plan($scritte, [3 => 6.0, 4 => 2.0]) === [
        'insert' => [],
        'update' => [10 => 6.0],
        'delete' => [],
    ]
);

check('una sede che manca dalle righe perde la soglia', fn () =>
    Thresholds::plan($scritte, [3 => 5.0]) === ['insert' => [], 'update' => [], 'delete' => [11]]
    && Thresholds::plan($scritte, []) === ['insert' => [], 'update' => [], 'delete' => [10, 11]]
);

check('vuoto, zero e sotto zero non diventano righe', fn () =>
    Thresholds::plan([], [1 => null, 2 => 0.0, 3 => -1.0, 4 => '']) === ['insert' => [], 'update' => [], 'delete' => []]
    && Thresholds::plan([], [1 => '2,5']) === ['insert' => [1 => 2.5], 'update' => [], 'delete' => []]
);

check('la quantità si confronta a tre decimali', fn () =>
    Thresholds::plan([3 => ['id' => 10, 'quantity' => '5.000']], [3 => 5.0004]) === ['insert' => [], 'update' => [], 'delete' => []]
);

summary();
