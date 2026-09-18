<?php
/** php tests/CodesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Codes;

check('ogni entità ha il suo prefisso', fn () =>
    Codes::PRODUCT === 'pro_'
    && Codes::ORDER === 'ord_'
    && Codes::INVOICE === 'inv_'
    && Codes::LOCATION === 'loc_'
    && Codes::CONTACT === 'con_'
);

check('all() elenca tutte le costanti della classe', function () {
    $constants = (new ReflectionClass(Codes::class))->getConstants();

    return Codes::all() === $constants && $constants !== [];
});

check('i prefissi sono tutti diversi', fn () =>
    count(array_unique(Codes::all())) === count(Codes::all())
);

check('il formato è tre lettere minuscole e un trattino basso', function () {
    foreach (Codes::all() as $prefix) {
        if (preg_match('/^[a-z]{3}_$/', (string) $prefix) !== 1) {
            return false;
        }
    }

    return true;
});

summary();
