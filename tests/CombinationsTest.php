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
$lunghezze = [
    ['id' => 30, 'label' => 'Corta'],
    ['id' => 31, 'label' => 'Lunga'],
];
$vuoto = ['variants' => [], 'products' => []];

check('due colori e tre taglie fanno due varianti e sei prodotti', function () use ($colori, $taglie, $vuoto) {
    $piano = Combinations::plan($colori, [$taglie], $vuoto);

    return count($piano['variants']) === 2 && count($piano['products']) === 6;
});

check('ogni prodotto sa da quali valori viene', function () use ($colori, $taglie, $vuoto) {
    $piano = Combinations::plan($colori, [$taglie], $vuoto);
    $primo = $piano['products'][0];

    return $primo['variant_value_id'] === 10
        && $primo['value_ids'] === [20]
        && $primo['labels'] === ['Blu', 'S'];
});

check('tre assi si moltiplicano', function () use ($colori, $taglie, $lunghezze, $vuoto) {
    $piano = Combinations::plan($colori, [$taglie, $lunghezze], $vuoto);

    return count($piano['variants']) === 2
        && count($piano['products']) === 12
        && $piano['products'][0]['value_ids'] === [20, 30]
        && $piano['products'][0]['labels'] === ['Blu', 'S', 'Corta'];
});

check('due assi senza opzione con pagina propria', function () use ($taglie, $lunghezze, $vuoto) {
    $piano = Combinations::plan([], [$taglie, $lunghezze], $vuoto);

    return $piano['variants'] === []
        && count($piano['products']) === 6
        && $piano['products'][0]['variant_value_id'] === 0;
});

check('quello che c\'è già non si rifà', function () use ($colori, $taglie) {
    $esistenti = ['variants' => [10 => 101, 11 => 102], 'products' => []];

    foreach ([10, 11] as $colore) {
        foreach ([20, 21, 22] as $taglia) {
            $esistenti['products'][Combinations::key($colore, [$taglia])] = true;
        }
    }

    $piano = Combinations::plan($colori, [$taglie], $esistenti);

    return $piano['variants'] === [] && $piano['products'] === [];
});

check('una taglia in più fa solo i due prodotti che mancano', function () use ($colori, $taglie) {
    $esistenti = ['variants' => [10 => 101, 11 => 102], 'products' => []];

    foreach ([10, 11] as $colore) {
        foreach ([20, 21] as $taglia) {
            $esistenti['products'][Combinations::key($colore, [$taglia])] = true;
        }
    }

    $piano = Combinations::plan($colori, [$taglie], $esistenti);

    return $piano['variants'] === []
        && count($piano['products']) === 2
        && $piano['products'][0]['value_ids'] === [22];
});

check('l\'ordine delle spunte non crea doppioni', function () use ($colori, $taglie, $lunghezze) {
    $esistenti = ['variants' => [10 => 101, 11 => 102], 'products' => []];

    // Le righe esistenti sono state salvate con gli id nell'ordine opposto.
    foreach ([10, 11] as $colore) {
        foreach ([20, 21, 22] as $taglia) {
            foreach ([30, 31] as $lunghezza) {
                $esistenti['products'][Combinations::key($colore, [$lunghezza, $taglia])] = true;
            }
        }
    }

    $piano = Combinations::plan($colori, [$taglie, $lunghezze], $esistenti);

    return $piano['products'] === [];
});

check('una variante che c\'è già si riusa per i prodotti nuovi', function () use ($colori, $taglie) {
    $piano = Combinations::plan($colori, [$taglie], ['variants' => [10 => 101], 'products' => []]);

    return count($piano['variants']) === 1
        && $piano['variants'][0]['value_id'] === 11
        && count($piano['products']) === 6;
});

check('senza colori i prodotti nascono sulla variante che c\'è', function () use ($taglie, $vuoto) {
    $piano = Combinations::plan([], [$taglie], $vuoto);

    return $piano['variants'] === []
        && count($piano['products']) === 3
        && $piano['products'][0]['variant_value_id'] === 0
        && $piano['products'][0]['labels'] === ['S'];
});

check('senza taglie nasce un prodotto per variante', function () use ($colori, $vuoto) {
    $piano = Combinations::plan($colori, [], $vuoto);

    return count($piano['variants']) === 2
        && count($piano['products']) === 2
        && $piano['products'][0]['value_ids'] === []
        && $piano['products'][0]['labels'] === ['Blu'];
});

check('un asse vuoto non conta', function () use ($colori, $vuoto) {
    $piano = Combinations::plan($colori, [[], []], $vuoto);

    return count($piano['products']) === 2;
});

check('senza niente da spuntare non si fa niente', function () use ($vuoto) {
    return Combinations::plan([], [], $vuoto) === ['variants' => [], 'products' => []];
});

check('la chiave ordina gli id', fn () =>
    Combinations::key(10, [31, 20]) === Combinations::key(10, [20, 31])
    && Combinations::key(0, []) === '0:'
);

summary();
