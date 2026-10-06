<?php
/** php tests/ShippingRatesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Shipping\ShippingRates;

/** Un listino con i valori «spenti» che salvo diversa indicazione. */
$listino = static fn (array $extra = []): array => $extra + [
    'excess_mode' => 'total_weight',
    'fuel_surcharge_percent' => 0,
    'markup_percent' => 0,
    'rounding_step' => null,
    'min_price' => 0,
    'free_over_amount' => null,
    'free_under_weight' => null,
];

$scaglione = static fn (float $max, float $importo): array => ['type' => 'price', 'max_weight' => $max, 'amount' => $importo];
$eccesso = static fn (float $perKg): array => ['type' => 'excess', 'max_weight' => 0.0, 'amount' => $perKg];

/** Tre scaglioni: fino a 2 kg 5 €, fino a 5 kg 10 €, fino a 10 kg 15 €. */
$tre = [$scaglione(2, 5), $scaglione(5, 10), $scaglione(10, 15)];

$importo = static fn (?array $r): ?float => $r === null ? null : $r['amount'];

// — scaglioni —

check('un peso dentro lo scaglione paga quello scaglione', fn () =>
    $importo(ShippingRates::price($listino(), $tre, 3.0, 0.0)) === 10.0
);

check('il peso uguale a max_weight resta nello scaglione', fn () =>
    $importo(ShippingRates::price($listino(), $tre, 5.0, 0.0)) === 10.0
);

check('appena sopra max_weight passa allo scaglione successivo', fn () =>
    $importo(ShippingRates::price($listino(), $tre, 5.001, 0.0)) === 15.0
);

check('il peso zero paga il primo scaglione', fn () =>
    $importo(ShippingRates::price($listino(), $tre, 0.0, 0.0)) === 5.0
);

check('gli scaglioni fuori ordine si ordinano per peso', fn () =>
    $importo(ShippingRates::price($listino(), [$scaglione(10, 15), $scaglione(2, 5), $scaglione(5, 10)], 3.0, 0.0)) === 10.0
);

check('senza scaglioni il listino non copre niente', fn () =>
    ShippingRates::price($listino(), [], 1.0, 0.0) === null
    && ShippingRates::price($listino(), [$eccesso(2)], 1.0, 0.0) === null
);

check('oltre l\'ultimo scaglione e senza riga excess il listino non copre il peso', fn () =>
    ShippingRates::price($listino(), $tre, 10.5, 0.0) === null
);

check('total_weight: oltre l\'ultimo scaglione tutto il peso a tariffa al kg', fn () =>
    // 6 kg × 4 €/kg
    $importo(ShippingRates::price($listino(), [$scaglione(5, 10), $eccesso(4)], 6.0, 0.0)) === 24.0
);

check('excess_only: ultimo scaglione più la sola parte oltre', fn () =>
    // 10 + (6 − 5) × 4
    $importo(ShippingRates::price($listino(['excess_mode' => 'excess_only']), [$scaglione(5, 10), $eccesso(4)], 6.0, 0.0)) === 14.0
);

check('total_weight con 8 kg a 2 €/kg dà 16,00', fn () =>
    $importo(ShippingRates::price($listino(), [$scaglione(5, 10), $eccesso(2)], 8.0, 0.0)) === 16.0
);

check('la riga excess non sposta gli scaglioni: dentro l\'ultimo si paga lo scaglione', fn () =>
    $importo(ShippingRates::price($listino(), [$scaglione(5, 10), $eccesso(4)], 5.0, 0.0)) === 10.0
);

// — carburante, margine —

check('carburante 10 % e margine −5 % su 10,00 danno 10,45', fn () =>
    $importo(ShippingRates::price($listino(['fuel_surcharge_percent' => 10, 'markup_percent' => -5]), [$scaglione(5, 10)], 1.0, 0.0)) === 10.45
);

check('si arrotonda a ogni passo, non a catena su float grezzi (5,01 → 5,23 e non 5,24)', fn () =>
    // 5,01 × 1,10 = 5,511 → 5,51; 5,51 × 0,95 = 5,2345 → 5,23. Sul grezzo: 5,23545 → 5,24.
    $importo(ShippingRates::price($listino(['fuel_surcharge_percent' => 10, 'markup_percent' => -5]), [$scaglione(5, 5.01)], 1.0, 0.0)) === 5.23
);

check('i valori del listino arrivano dal database come stringhe', fn () =>
    $importo(ShippingRates::price($listino(['fuel_surcharge_percent' => '10.00', 'markup_percent' => '-5.00', 'min_price' => '0.00']), [['type' => 'price', 'max_weight' => '5.000', 'amount' => '10.00']], 1.0, 0.0)) === 10.45
);

// — arrotondamento, minimo —

check('arrotondamento per eccesso a 0,50', fn () =>
    $importo(ShippingRates::price($listino(['rounding_step' => 0.5]), [$scaglione(5, 10.01)], 1.0, 0.0)) === 10.5
    && $importo(ShippingRates::price($listino(['rounding_step' => 0.5]), [$scaglione(5, 10.5)], 1.0, 0.0)) === 10.5
    && $importo(ShippingRates::price($listino(['rounding_step' => 0.5]), [$scaglione(5, 10.51)], 1.0, 0.0)) === 11.0
);

check('arrotondamento per eccesso a 1,00', fn () =>
    $importo(ShippingRates::price($listino(['rounding_step' => 1]), [$scaglione(5, 10.45)], 1.0, 0.0)) === 11.0
    && $importo(ShippingRates::price($listino(['rounding_step' => 1]), [$scaglione(5, 11.0)], 1.0, 0.0)) === 11.0
);

check('gradino vuoto o zero: al centesimo e basta', fn () =>
    $importo(ShippingRates::price($listino(['rounding_step' => null]), [$scaglione(5, 10.45)], 1.0, 0.0)) === 10.45
    && $importo(ShippingRates::price($listino(['rounding_step' => 0]), [$scaglione(5, 10.45)], 1.0, 0.0)) === 10.45
);

check('il minimo alza il prezzo, mai lo abbassa', fn () =>
    $importo(ShippingRates::price($listino(['min_price' => 8]), [$scaglione(5, 5)], 1.0, 0.0)) === 8.0
    && $importo(ShippingRates::price($listino(['min_price' => 8]), [$scaglione(5, 10)], 1.0, 0.0)) === 10.0
);

check('un margine che porta sotto zero non dà mai un prezzo negativo', fn () =>
    $importo(ShippingRates::price($listino(['markup_percent' => -150]), [$scaglione(5, 10)], 1.0, 0.0)) === 0.0
    && $importo(ShippingRates::price($listino(['markup_percent' => -150, 'min_price' => 3]), [$scaglione(5, 10)], 1.0, 0.0)) === 3.0
);

// — soglie gratuite —

check('nessuna soglia: mai gratuita, anche con totale enorme e peso zero', function () use ($listino, $tre) {
    $r = ShippingRates::price($listino(), $tre, 0.0, 99999.0);

    return $r !== null && $r['free'] === false && $r['amount'] === 5.0;
});

check('solo importo: uguale alla soglia non basta, appena sopra sì', function () use ($listino, $tre) {
    $l = $listino(['free_over_amount' => 100]);
    $uguale = ShippingRates::price($l, $tre, 3.0, 100.0);
    $sopra = ShippingRates::price($l, $tre, 3.0, 100.01);
    $sotto = ShippingRates::price($l, $tre, 3.0, 99.99);

    return $uguale['free'] === false && $uguale['amount'] === 10.0
        && $sopra['free'] === true && $sopra['amount'] === 0.0
        && $sotto['free'] === false;
});

check('solo peso: uguale alla soglia non basta, appena sotto sì', function () use ($listino, $tre) {
    $l = $listino(['free_under_weight' => 3]);
    $uguale = ShippingRates::price($l, $tre, 3.0, 0.0);
    $sotto = ShippingRates::price($l, $tre, 2.999, 0.0);

    return $uguale['free'] === false && $sotto['free'] === true && $sotto['amount'] === 0.0;
});

check('entrambe le soglie: devono valere tutte e due', function () use ($listino, $tre) {
    $l = $listino(['free_over_amount' => 100, 'free_under_weight' => 3]);

    return ShippingRates::price($l, $tre, 2.0, 150.0)['free'] === true
        && ShippingRates::price($l, $tre, 4.0, 150.0)['free'] === false
        && ShippingRates::price($l, $tre, 2.0, 50.0)['free'] === false
        && ShippingRates::price($l, $tre, 4.0, 50.0)['free'] === false;
});

check('la soglia a zero è un valore vero (gratuita sempre), diverso da non impostata', function () use ($listino, $tre) {
    return ShippingRates::price($listino(['free_over_amount' => 0]), $tre, 3.0, 0.01)['free'] === true
        && ShippingRates::price($listino(['free_over_amount' => null]), $tre, 3.0, 0.01)['free'] === false
        && ShippingRates::price($listino(['free_over_amount' => '']), $tre, 3.0, 0.01)['free'] === false;
});

check('la gratuità non nasconde un peso che il listino non copre', fn () =>
    ShippingRates::price($listino(['free_over_amount' => 10]), $tre, 50.0, 500.0) === null
);

// — prezzo fisso —

check('il prezzo fisso non guarda il peso e non ha scaglioni', fn () =>
    $importo(ShippingRates::price($listino(['price_type' => 'fixed', 'fixed_price' => '7.50']), [], 42.0, 0.0)) === 7.5
    && $importo(ShippingRates::price($listino(['price_type' => 'fixed', 'fixed_price' => '7.50']), $tre, 0.0, 0.0)) === 7.5
);

check('il prezzo fisso ignora carburante, margine, arrotondamento, minimo e gratuità', fn () =>
    ShippingRates::price($listino([
        'price_type' => 'fixed', 'fixed_price' => '7.50',
        'fuel_surcharge_percent' => 10, 'markup_percent' => 20, 'rounding_step' => 5,
        'min_price' => 20, 'free_over_amount' => 1, 'free_under_weight' => 100,
    ]), [], 3.0, 500.0) === ['amount' => 7.5, 'free' => false]
);

check('il prezzo fisso senza importo non copre niente', fn () =>
    ShippingRates::price($listino(['price_type' => 'fixed', 'fixed_price' => null]), [], 1.0, 0.0) === null
);

check('un listino senza price_type resta a scaglioni', fn () =>
    $importo(ShippingRates::price($listino(), $tre, 3.0, 0.0)) === 10.0
    && $importo(ShippingRates::price($listino(['price_type' => 'brackets']), $tre, 3.0, 0.0)) === 10.0
);

summary();
