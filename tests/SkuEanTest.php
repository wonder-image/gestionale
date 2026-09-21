<?php
/** php tests/SkuEanTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\Ean;
use Wonder\Plugin\Gestionale\Support\Catalog\Sku;

check('lo SKU proposto mette insieme modello e valori', fn () =>
    Sku::propose('TSH-1', ['Blu', 'M']) === 'TSH-1-BLU-M'
);

check('senza SKU del modello non si propone niente', fn () =>
    Sku::propose('', ['Blu', 'M']) === ''
);

check('senza valori lo SKU resta quello del modello', fn () =>
    Sku::propose('TSH-1', []) === 'TSH-1'
);

check('lo SKU non porta spazi né accenti', fn () =>
    Sku::part('Blu scuro') === 'BLUSCURO'
    && Sku::part('Città') === 'CITTA'
    && Sku::part('1/2 kg') === '12KG'
);

check('un valore vuoto non lascia un trattino a vuoto', fn () =>
    Sku::propose('TSH-1', ['', 'M']) === 'TSH-1-M'
);

check('un EAN è di 8 o 13 cifre', fn () =>
    Ean::isValid('12345678')
    && Ean::isValid('1234567890123')
    && !Ean::isValid('123456789012')
    && !Ean::isValid('1234567a')
);

check('un EAN vuoto va bene: è facoltativo', fn () => Ean::isValid(''));

summary();
