<?php
/** php tests/NegativeStockTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\NegativeStock;

$prodotti = [
    1 => ['id' => 1, 'product_model_id' => 10, 'name' => 'Rossa, M', 'sku' => 'MAG-R-M'],
    2 => ['id' => 2, 'product_model_id' => 20, 'name' => 'Borraccia', 'sku' => 'BOR'],
    3 => ['id' => 3, 'product_model_id' => 10, 'name' => 'Blu, L', 'sku' => 'MAG-B-L'],
];
$articoli = [10 => 'Maglia', 20 => 'Borraccia'];

check('una riga negativa diventa un prodotto da controllare', function () use ($prodotti, $articoli) {
    $items = NegativeStock::group([['product_id' => 1, 'location_id' => 1, 'quantity' => '-3.000']], $prodotti, $articoli);

    return $items === [[
        'product_id' => 1,
        'article' => 'Maglia',
        'option' => 'Rossa, M',
        'sku' => 'MAG-R-M',
        'quantity' => -3.0,
        'locations' => 1,
    ]];
});

check('due sedi in negativo fanno una riga sola, con la somma e il numero delle sedi', function () use ($prodotti, $articoli) {
    $items = NegativeStock::group([
        ['product_id' => 1, 'location_id' => 1, 'quantity' => '-3.000'],
        ['product_id' => 1, 'location_id' => 2, 'quantity' => '-1.250'],
    ], $prodotti, $articoli);

    return count($items) === 1 && $items[0]['quantity'] === -4.25 && $items[0]['locations'] === 2;
});

check('due righe della stessa sede (un altro lotto, un altro fornitore) restano una sede', function () use ($prodotti, $articoli) {
    $items = NegativeStock::group([
        ['product_id' => 1, 'location_id' => 1, 'batch_id' => 1, 'quantity' => '-1.000'],
        ['product_id' => 1, 'location_id' => 1, 'batch_id' => 2, 'quantity' => '-2.000'],
    ], $prodotti, $articoli);

    return count($items) === 1 && $items[0]['quantity'] === -3.0 && $items[0]['locations'] === 1;
});

check('le righe a zero o sopra non contano, anche se arrivano', fn () =>
    NegativeStock::group([
        ['product_id' => 1, 'location_id' => 1, 'quantity' => '0.000'],
        ['product_id' => 3, 'location_id' => 1, 'quantity' => '4.000'],
    ], $prodotti, $articoli) === []
);

check('un prodotto sparito o tolto dalla griglia non si segnala', fn () =>
    NegativeStock::group([
        ['product_id' => 99, 'location_id' => 1, 'quantity' => '-2.000'],
        ['product_id' => 2, 'location_id' => 1, 'quantity' => '-2.000'],
    ], [2 => array_merge($prodotti[2], ['deleted' => 'true'])], $articoli) === []
);

check('l\'articolo senza varianti non ripete il nome, e l\'elenco è in ordine di articolo', function () use ($prodotti, $articoli) {
    $items = NegativeStock::group([
        ['product_id' => 3, 'location_id' => 1, 'quantity' => '-1.000'],
        ['product_id' => 2, 'location_id' => 1, 'quantity' => '-1.000'],
        ['product_id' => 1, 'location_id' => 1, 'quantity' => '-1.000'],
    ], $prodotti, $articoli);

    return array_column($items, 'sku') === ['BOR', 'MAG-B-L', 'MAG-R-M']
        && $items[0]['option'] === '';
});

summary();
