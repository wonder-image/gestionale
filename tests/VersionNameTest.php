<?php
/** php tests/VersionNameTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\VersionName;

check('due valori diventano un nome leggibile', fn () =>
    VersionName::from(['Blu', 'M']) === 'Blu / M'
);

check('un valore solo resta se stesso', fn () =>
    VersionName::from(['M']) === 'M'
);

check('tre valori si incolonnano nell\'ordine dato', fn () =>
    VersionName::from(['Blu', 'M', 'Corta']) === 'Blu / M / Corta'
);

check('i vuoti non lasciano separatori appesi', fn () =>
    VersionName::from(['Blu', '', '  ', 'M']) === 'Blu / M'
);

check('senza valori si tiene il ripiego', fn () =>
    VersionName::from([], 'TSH-1') === 'TSH-1'
    && VersionName::from(['', ''], ' TSH-1 ') === 'TSH-1'
);

check('senza valori e senza ripiego si torna vuoti', fn () =>
    VersionName::from([]) === ''
);

summary();
