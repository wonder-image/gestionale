<?php
/** php tests/ShippingCountryTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Shipping\Shipping;

// Il carrello nasce con il paese predefinito ma senza indirizzo.
$vuoto = ['billing_country' => 'IT', 'shipping_country' => 'IT'];

check('senza indirizzo e senza riserva: il paese del carrello', fn () =>
    Shipping::country($vuoto) === 'IT');

check('senza indirizzo: il paese di riserva', fn () =>
    Shipping::country($vuoto, 'fr') === 'FR');

check('con un indirizzo di consegna: il suo paese, non la riserva', fn () =>
    Shipping::country($vuoto + ['shipping_country' => 'DE', 'shipping_city' => 'Berlin'], 'FR') === 'IT'
    && Shipping::country(['shipping_country' => 'DE', 'shipping_city' => 'Berlin'], 'FR') === 'DE');

check('con la sola fatturazione: il paese della fatturazione', fn () =>
    Shipping::country(['billing_country' => 'ES', 'billing_street' => 'Calle 1', 'shipping_country' => 'IT'], 'FR') === 'ES');

check('riserva vuota: come prima', fn () =>
    Shipping::country([], '') === 'IT');

summary();
