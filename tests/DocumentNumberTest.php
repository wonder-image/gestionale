<?php
/** php tests/DocumentNumberTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Documents\DocumentNumber;

check('formato anno/mese e progressivo a quattro cifre', fn () =>
    DocumentNumber::format(2026, 9, 1) === '2026/090001'
    && DocumentNumber::format(2026, 12, 9999) === '2026/129999'
);

check('oltre i 9999 documenti del mese si aggiunge una cifra', fn () =>
    DocumentNumber::format(2026, 9, 10000) === '2026/0910000'
);

check('parse legge anno, mese e progressivo', fn () =>
    DocumentNumber::parse('2026/090001') === ['year' => 2026, 'month' => 9, 'number' => 1]
    && DocumentNumber::parse('2026/0910000') === ['year' => 2026, 'month' => 9, 'number' => 10000]
);

check('parse rifiuta ciò che non è un numero di documento', fn () =>
    DocumentNumber::parse('ciao') === null
    && DocumentNumber::parse('2026/09') === null
    && DocumentNumber::parse('') === null
);

summary();
