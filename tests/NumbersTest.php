<?php
/** php tests/NumbersTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Numbers;

check('una casella vuota resta vuota, non diventa zero', fn () =>
    Numbers::fromForm('') === null
    && Numbers::fromForm(null) === null
    && Numbers::fromForm('   ') === null
    && Numbers::fromForm(['1']) === null
);

check('il numero grezzo di AutoNumeric passa com\'è', fn () =>
    Numbers::fromForm('19.90') === '19.90'
    && Numbers::fromForm('1234.5') === '1234.5'
    && Numbers::fromForm('0') === '0'
);

check('la virgola è un decimale e il punto separa le migliaia', fn () =>
    Numbers::fromForm('19,90') === '19.90'
    && Numbers::fromForm('1.234,50') === '1234.50'
);

check('il simbolo della valuta in coda non rompe il numero', fn () =>
    Numbers::fromForm('1.234,50 €') === '1234.50'
    && Numbers::fromForm('19,90€') === '19.90'
    && Numbers::fromForm("1.299,90\u{a0}€") === '1299.90'
);

check('l\'unità di misura in coda non rompe il numero', fn () =>
    Numbers::fromForm('12 pz') === '12'
    && Numbers::fromForm('2,5 kg') === '2.5'
    && Numbers::fromForm('3 conf') === '3'
    && Numbers::fromForm("2,500\u{202f}kg") === '2.500'
);

check('quello che non è un numero non diventa un numero', fn () =>
    Numbers::fromForm('abc') === null
    && Numbers::fromForm('€') === null
    && Numbers::fromForm('--3') === null
    && Numbers::fromForm('kg 12') === null
);

summary();
