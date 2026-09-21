<?php
/** php tests/CombinationsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\Combinations;

$colori = [
    ['id' => 10, 'label' => 'Blu'],
    ['id' => 11, 'label' => 'Rosso'],
];
$taglie = [
    ['id' => 20, 'label' => 'S'],
    ['id' => 21, 'label' => 'M'],
    ['id' => 22, 'label' => 'L'],
];

check('due colori e tre taglie fanno due varianti e sei prodotti', function () use ($colori, $taglie) {
    $piano = Combinations::plan($colori, $taglie, ['variants' => [], 'products' => []]);

    return count($piano['variants']) === 2 && count($piano['products']) === 6;
});

check('ogni prodotto sa da quale colore e da quale taglia viene', function () use ($colori, $taglie) {
    $piano = Combinations::plan($colori, $taglie, ['variants' => [], 'products' => []]);
    $primo = $piano['products'][0];

    return $primo['variant_value_id'] === 10
        && $primo['product_value_id'] === 20
        && $primo['labels'] === ['Blu', 'S'];
});

check('quello che c\'è già non si rifà', function () use ($colori, $taglie) {
    $esistenti = [
        'variants' => [10 => 101, 11 => 102],
        'products' => ['10-20' => true, '10-21' => true, '10-22' => true,
                       '11-20' => true, '11-21' => true, '11-22' => true],
    ];

    $piano = Combinations::plan($colori, $taglie, $esistenti);

    return $piano['variants'] === [] && $piano['products'] === [];
});

check('una taglia in più fa solo i due prodotti che mancano', function () use ($colori, $taglie) {
    $esistenti = [
        'variants' => [10 => 101, 11 => 102],
        'products' => ['10-20' => true, '10-21' => true, '11-20' => true, '11-21' => true],
    ];

    $piano = Combinations::plan($colori, $taglie, $esistenti);

    return $piano['variants'] === []
        && count($piano['products']) === 2
        && array_column($piano['products'], 'product_value_id') === [22, 22];
});

check('una variante che c\'è già si riusa per i prodotti nuovi', function () use ($colori, $taglie) {
    $esistenti = ['variants' => [10 => 101], 'products' => []];
    $piano = Combinations::plan($colori, $taglie, $esistenti);

    return count($piano['variants']) === 1
        && $piano['variants'][0]['value_id'] === 11
        && count($piano['products']) === 6;
});

check('senza colori i prodotti nascono sulla variante che c\'è', function () use ($taglie) {
    $piano = Combinations::plan([], $taglie, ['variants' => [], 'products' => []]);

    return $piano['variants'] === []
        && count($piano['products']) === 3
        && $piano['products'][0]['variant_value_id'] === 0
        && $piano['products'][0]['labels'] === ['S'];
});

check('senza taglie nasce un prodotto per variante', function () use ($colori) {
    $piano = Combinations::plan($colori, [], ['variants' => [], 'products' => []]);

    return count($piano['variants']) === 2
        && count($piano['products']) === 2
        && $piano['products'][0]['product_value_id'] === 0
        && $piano['products'][0]['labels'] === ['Blu'];
});

check('senza niente da spuntare non si fa niente', function () {
    $piano = Combinations::plan([], [], ['variants' => [], 'products' => []]);

    return $piano === ['variants' => [], 'products' => []];
});

summary();
