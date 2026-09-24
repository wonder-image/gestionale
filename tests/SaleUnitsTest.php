<?php
/** php tests/SaleUnitsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\SaleUnits;

check('le unità con cui si vende sono quelle della select', fn () =>
    array_keys(SaleUnits::all()) === ['pz', 'conf', 'kg', 'g', 'l', 'ml', 'm']
    && SaleUnits::all()['pz'] === 'Pezzi'
    && SaleUnits::all()['kg'] === 'Chilogrammi'
);

check('pezzi, confezioni, grammi e millilitri si contano interi', fn () =>
    SaleUnits::decimals('pz') === 0
    && SaleUnits::decimals('conf') === 0
    && SaleUnits::decimals('g') === 0
    && SaleUnits::decimals('ml') === 0
);

check('chili, litri e metri hanno tre decimali', fn () =>
    SaleUnits::decimals('kg') === 3
    && SaleUnits::decimals('l') === 3
    && SaleUnits::decimals('m') === 3
);

check('un\'unità che non si conosce tiene tre decimali, per non perderne', fn () =>
    SaleUnits::decimals('quintali') === 3
    && SaleUnits::decimals('') === 3
);

check('l\'unità si legge anche scritta male', fn () =>
    SaleUnits::decimals(' PZ ') === 0
    && SaleUnits::suffix(' KG ') === ' kg'
);

check('ogni unità ha i suoi decimali, nella mappa per il browser', function () {
    $mappa = SaleUnits::decimalsMap();

    return array_keys($mappa) === array_keys(SaleUnits::all())
        && $mappa['pz'] === 0
        && $mappa['kg'] === 3;
});

check('l\'unità sta in coda al numero, staccata', fn () =>
    SaleUnits::suffix('pz') === ' pz'
    && SaleUnits::suffix('kg') === ' kg'
    && SaleUnits::suffix('') === ''
);

check('una giacenza con decimali su un\'unità intera tiene i decimali', fn () =>
    SaleUnits::decimalsFor('pz', 2.5) === 3
    && SaleUnits::decimalsFor('pz', 3.0, 0.001) === 3
);

check('una giacenza intera su un\'unità intera resta intera', fn () =>
    SaleUnits::decimalsFor('pz', 3.0, 12.0) === 0
    && SaleUnits::decimalsFor('pz') === 0
);

check('sui chili i decimali ci sono comunque', fn () =>
    SaleUnits::decimalsFor('kg', 3.0) === 3
);

check('una quantità è frazionaria solo entro i millesimi', fn () =>
    SaleUnits::isFractional(2.5)
    && SaleUnits::isFractional(-0.25)
    && !SaleUnits::isFractional(3.0)
    && !SaleUnits::isFractional(3.0000001)
);

summary();
