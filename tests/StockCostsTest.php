<?php
/** php tests/StockCostsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\StockCosts;

check('il fornitore noto vale il suo costo, o niente se non ne ha', fn () =>
    StockCosts::of(3, [3 => 4.5, 4 => 2.0]) === 4.5
    && StockCosts::of(9, [3 => 4.5]) === null
);

check('il fornitore 0 vale il costo più basso fra quelli della versione', fn () =>
    StockCosts::of(0, [3 => 4.5, 4 => 2.0]) === 2.0
    && StockCosts::of(0, [3 => 0.0, 4 => 2.0]) === 0.0
);

check('senza nessun costo il secchio 0 non ha costo', fn () =>
    StockCosts::of(0, []) === null
);

check('senza database forProduct non esplode', fn () =>
    StockCosts::forProduct(0) === []
);

summary();
