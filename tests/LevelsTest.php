<?php
/** php tests/LevelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\Levels;

$giacenze = [
    ['product_id' => 1, 'location_id' => 1, 'quantity' => '5.000'],
    ['product_id' => 1, 'location_id' => 1, 'quantity' => '2.000', 'batch_id' => 7],
    ['product_id' => 1, 'location_id' => 2, 'quantity' => '1.000'],
    ['product_id' => 2, 'location_id' => 1, 'quantity' => '-1.000'],
];
$prenotazioni = [
    ['product_id' => 1, 'location_id' => 1, 'quantity' => '3.000', 'released_at' => null, 'expires_at' => null],
    ['product_id' => 1, 'location_id' => 2, 'quantity' => '1.000', 'released_at' => '2026-01-01 00:00:00'],
    ['product_id' => 1, 'location_id' => 2, 'quantity' => '4.000', 'expires_at' => '2026-01-01 00:00:00'],
    ['product_id' => 2, 'location_id' => 2, 'quantity' => '0.500'],
];
$adesso = '2026-09-25 10:00:00';

check('per sede: giacenza, impegnati e disponibili, lotti sommati', fn () =>
    Levels::perLocation($giacenze, $prenotazioni, $adesso)[1] === [
        1 => ['quantity' => 7.0, 'reserved' => 3.0, 'available' => 4.0],
        2 => ['quantity' => 1.0, 'reserved' => 0.0, 'available' => 1.0],
    ]
);

check('una prenotazione su una sede senza pezzi apre la sede a zero', fn () =>
    Levels::perLocation($giacenze, $prenotazioni, $adesso)[2] === [
        1 => ['quantity' => -1.0, 'reserved' => 0.0, 'available' => -1.0],
        2 => ['quantity' => 0.0, 'reserved' => 0.5, 'available' => -0.5],
    ]
);

check('le sedi stanno in ordine di id, un prodotto senza righe non compare', fn () =>
    array_keys(Levels::perLocation($giacenze, $prenotazioni, $adesso)) === [1, 2]
    && Levels::perLocation([], [], $adesso) === []
);

check('senza database le sedi sono vuote, non un errore', fn () =>
    Levels::byLocation(1) === []
    && Levels::byLocationForProducts([1, 2]) === [1 => [], 2 => []]
    && Levels::byLocationForProducts([]) === []
);

summary();
