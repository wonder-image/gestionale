<?php
/** php tests/ContactExportsTest.php — formati di esportazione di clienti e fornitori. */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Contacts\ContactExports;

echo "ContactExports\n";

check('solo i formati con la funzionalità attiva', function () {
    return ContactExports::granted(static fn (string $f): bool => $f === 'export_contacts_csv') === ['csv' => 'CSV (.csv)']
        && ContactExports::granted(static fn (): bool => true) === ['xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV (.csv)']
        && ContactExports::granted(static fn (): bool => false) === [];
});

check('ogni formato ha la sua funzionalità nel catalogo, area Esportazioni', function () {
    $catalogo = require __DIR__ . '/../config/features.php';
    foreach (ContactExports::FEATURES as $feature) {
        if (($catalogo[$feature]['area'] ?? '') !== 'Esportazioni') {
            return false;
        }
    }
    return true;
});

summary();
