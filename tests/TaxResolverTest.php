<?php
/** php tests/TaxResolverTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Tax\TaxResolver;

$regole = [
    ['country' => 'IT', 'customer_type' => 'private', 'tax_category_id' => 1, 'tax_id' => 10],
    ['country' => 'IT', 'customer_type' => 'business', 'tax_category_id' => 1, 'tax_id' => 10],
    ['country' => 'IT', 'customer_type' => 'private', 'tax_category_id' => 2, 'tax_id' => 11],
    ['country' => 'DE', 'customer_type' => 'business', 'tax_category_id' => 1, 'tax_id' => 12],
];

/** Il messaggio dell'errore, o '' se `resolve()` non si ferma. */
$errore = function (array $regole, string $paese, string $tipo, int $categoria, string $prodotto = ''): string {
    try {
        TaxResolver::resolve($regole, $paese, $tipo, $categoria, $prodotto);
    } catch (UserError $e) {
        return $e->key() === 'tax.rule_missing' ? $e->getMessage() : '';
    }

    return '';
};

check('corrispondenza esatta di paese, cliente e tipo fiscale', fn () =>
    TaxResolver::resolve($regole, 'IT', 'private', 1) === 10
    && TaxResolver::resolve($regole, 'IT', 'private', 2) === 11
    && TaxResolver::resolve($regole, 'DE', 'business', 1) === 12
);

check('il paese si confronta senza badare alle maiuscole', fn () =>
    TaxResolver::resolve($regole, 'it', 'private', 1) === 10
    && TaxResolver::resolve($regole, ' It ', 'PRIVATE', 1) === 10
);

check('senza regola niente aliquota: un errore', fn () =>
    $errore($regole, 'FR', 'private', 1) !== ''
    && $errore($regole, 'DE', 'private', 1) !== ''
    && $errore($regole, 'IT', 'private', 3) !== ''
    && $errore([], 'IT', 'private', 1) !== ''
);

check('l\'errore nomina prodotto, paese e tipo di cliente', function () use ($errore, $regole) {
    $testo = $errore($regole, 'fr', 'business', 1, 'Tazza blu');

    return str_contains($testo, 'Tazza blu') && str_contains($testo, 'FR') && str_contains($testo, 'azienda');
});

check('find() senza regola ridà null, con la regola l\'aliquota', fn () =>
    TaxResolver::find($regole, 'FR', 'private', 1) === null
    && TaxResolver::find($regole, 'IT', 'private', 2) === 11
);

check('una regola senza aliquota non vale', fn () =>
    TaxResolver::find([
        ['country' => 'IT', 'customer_type' => 'private', 'tax_category_id' => 1, 'tax_id' => 0],
    ], 'IT', 'private', 1) === null
);

check('con due regole uguali vince la prima', fn () =>
    TaxResolver::resolve([
        ['country' => 'IT', 'customer_type' => 'private', 'tax_category_id' => 1, 'tax_id' => 10],
        ['country' => 'IT', 'customer_type' => 'private', 'tax_category_id' => 1, 'tax_id' => 20],
    ], 'IT', 'private', 1) === 10
);

check('una regola disattiva non vale', fn () =>
    TaxResolver::find([
        ['country' => 'IT', 'customer_type' => 'private', 'tax_category_id' => 1, 'tax_id' => 10, 'active' => 'false'],
    ], 'IT', 'private', 1) === null
    && TaxResolver::resolve([
        ['country' => 'IT', 'customer_type' => 'private', 'tax_category_id' => 1, 'tax_id' => 10, 'active' => 'true'],
    ], 'IT', 'private', 1) === 10
);

summary();
