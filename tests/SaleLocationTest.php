<?php
/** php tests/SaleLocationTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\Locations;

check('il reso con Più sedi spenta rientra sempre nella principale', fn () =>
    Locations::serving(7, 1, false) === 1
    && Locations::serving(0, 1, false) === 1
    && Locations::serving(1, 1, false) === 1
);

check('il reso con Più sedi accesa rientra nella sede scelta; senza sede, nella principale', fn () =>
    Locations::serving(7, 1, true) === 7
    && Locations::serving(0, 1, true) === 1
    && Locations::serving(-3, 1, true) === 1
);

summary();
