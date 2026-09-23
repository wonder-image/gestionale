<?php
/** php tests/TaxCategoriesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Tax\TaxCategories;

check('un articolo nuovo parte dal tipo segnato predefinito', function () {
    return TaxCategories::defaultIn([
        ['id' => 3, 'is_default' => 'false'],
        ['id' => 5, 'is_default' => 'true'],
    ]) === 5;
});

check('senza predefinito parte dal primo in elenco', function () {
    return TaxCategories::defaultIn([
        ['id' => 3, 'is_default' => 'false'],
        ['id' => 5],
    ]) === 3;
});

check('senza tipi fiscali non parte da nessuno', function () {
    return TaxCategories::defaultIn([]) === 0;
});

check('la stella vale solo su una categoria spuntata', function () {
    return ProductModelResource::mainAmong([4, 9], 9) === 9
        // Una stella rimasta su una categoria tolta cade sulla prima spuntata.
        && ProductModelResource::mainAmong([4, 9], 7) === 4
        && ProductModelResource::mainAmong([], 7) === 0;
});

summary();
