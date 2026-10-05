<?php
/** php tests/CarriersTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Shipping\Carriers;

$corriere = static fn (string $template): array => ['tracking_url_template' => $template];

check('il segnaposto {tracking} è sostituito dal numero', fn () =>
    Carriers::trackingUrl($corriere('https://corriere.it/t?n={tracking}'), 'AB123') === 'https://corriere.it/t?n=AB123'
);

check('un template senza {tracking} resta com\'è', fn () =>
    Carriers::trackingUrl($corriere('https://corriere.it/ricerca'), 'AB123') === 'https://corriere.it/ricerca'
);

check('i caratteri speciali del tracking sono codificati', fn () =>
    Carriers::trackingUrl($corriere('https://c.it/?n={tracking}'), 'A B&C/1') === 'https://c.it/?n=A%20B%26C%2F1'
);

check('il segnaposto ripetuto è sostituito ovunque', fn () =>
    Carriers::trackingUrl($corriere('https://c.it/{tracking}?n={tracking}'), 'X1') === 'https://c.it/X1?n=X1'
);

check('template vuoto: indirizzo vuoto', fn () =>
    Carriers::trackingUrl($corriere(''), 'AB123') === ''
    && Carriers::trackingUrl([], 'AB123') === ''
);

check('tracking vuoto: indirizzo vuoto', fn () =>
    Carriers::trackingUrl($corriere('https://c.it/?n={tracking}'), '') === ''
    && Carriers::trackingUrl($corriere('https://c.it/?n={tracking}'), '   ') === ''
);

summary();
