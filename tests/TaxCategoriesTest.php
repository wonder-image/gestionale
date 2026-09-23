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

check('i tipi visibili, in ordine, con il nome', function () {
    return TaxCategories::optionsIn([
        ['id' => 3, 'name' => 'Ordinaria'],
        ['id' => 5, 'name' => 'Libri'],
    ]) === ['3' => 'Ordinaria', '5' => 'Libri'];
});

check('un tipo nascosto in uso resta in coda, segnato', function () {
    return TaxCategories::optionsIn(
        [['id' => 3, 'name' => 'Ordinaria']],
        ['id' => 8, 'name' => 'Alimentari']
    ) === ['3' => 'Ordinaria', '8' => 'Alimentari (nascosto)'];
});

check('un tipo in uso che è già fra i visibili non si ripete', function () {
    return TaxCategories::optionsIn(
        [['id' => 3, 'name' => 'Ordinaria']],
        ['id' => 3, 'name' => 'Ordinaria']
    ) === ['3' => 'Ordinaria'];
});

check('la stella vale solo su una categoria spuntata', function () {
    return ProductModelResource::mainAmong([4, 9], 9) === 9
        // Una stella rimasta su una categoria tolta cade sulla prima spuntata.
        && ProductModelResource::mainAmong([4, 9], 7) === 4
        && ProductModelResource::mainAmong([], 7) === 0;
});

summary();
