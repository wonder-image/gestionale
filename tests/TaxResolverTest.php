<?php
/** php tests/TaxResolverTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Tax\TaxResolver;

$regole = [
    ['country' => 'IT', 'customer_type' => 'private', 'tax_category_id' => 1, 'tax_id' => 10],
    ['country' => 'IT', 'customer_type' => 'business', 'tax_category_id' => 1, 'tax_id' => 10],
    ['country' => 'IT', 'customer_type' => 'private', 'tax_category_id' => 2, 'tax_id' => 11],
    ['country' => 'DE', 'customer_type' => 'business', 'tax_category_id' => 1, 'tax_id' => 12],
];

check('corrispondenza esatta di paese, cliente e tipo fiscale', fn () =>
    TaxResolver::resolve($regole, 'IT', 'private', 1, 99) === 10
    && TaxResolver::resolve($regole, 'IT', 'private', 2, 99) === 11
    && TaxResolver::resolve($regole, 'DE', 'business', 1, 99) === 12
);

check('il paese si confronta senza badare alle maiuscole', fn () =>
    TaxResolver::resolve($regole, 'it', 'private', 1, 99) === 10
    && TaxResolver::resolve($regole, ' It ', 'PRIVATE', 1, 99) === 10
);

check('senza regola si usa l\'aliquota di ripiego', fn () =>
    TaxResolver::resolve($regole, 'FR', 'private', 1, 99) === 99
    && TaxResolver::resolve($regole, 'DE', 'private', 1, 99) === 99
    && TaxResolver::resolve($regole, 'IT', 'private', 3, 99) === 99
    && TaxResolver::resolve([], 'IT', 'private', 1, 99) === 99
);

check('una regola senza aliquota non vale', fn () =>
    TaxResolver::resolve([
        ['country' => 'IT', 'customer_type' => 'private', 'tax_category_id' => 1, 'tax_id' => 0],
    ], 'IT', 'private', 1, 99) === 99
);

check('con due regole uguali vince la prima', fn () =>
    TaxResolver::resolve([
        ['country' => 'IT', 'customer_type' => 'private', 'tax_category_id' => 1, 'tax_id' => 10],
        ['country' => 'IT', 'customer_type' => 'private', 'tax_category_id' => 1, 'tax_id' => 20],
    ], 'IT', 'private', 1, 99) === 10
);

summary();
