<?php
/** php tests/ScopeMatcherTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Promotions\ScopeMatcher;

$scope = static fn (array $parti = []): array => $parti + [
    'all' => false,
    'categories' => [],
    'tags' => [],
    'brands' => [],
    'models' => [],
    'excluded_models' => [],
];

$fatti = static fn (array $parti = []): array => $parti + [
    'categories' => [],
    'tags' => [],
    'brand_id' => 0,
    'model_id' => 1,
];

check('tutto il catalogo prende qualsiasi prodotto', fn () =>
    ScopeMatcher::matches($scope(['all' => true]), $fatti())
    && ScopeMatcher::matches($scope(['all' => true]), $fatti(['categories' => [4], 'brand_id' => 9]))
);

check('una selezione vuota senza «tutto» non prende nessuno', fn () =>
    !ScopeMatcher::matches($scope(), $fatti(['categories' => [4], 'tags' => [2], 'brand_id' => 9]))
);

check('una categoria scelta prende i prodotti che ci stanno', fn () =>
    ScopeMatcher::matches($scope(['categories' => [4]]), $fatti(['categories' => [4]]))
    && !ScopeMatcher::matches($scope(['categories' => [4]]), $fatti(['categories' => [5]]))
);

check('una categoria scelta prende anche chi sta in una sottocategoria (gli antenati sono nei fatti)', fn () =>
    ScopeMatcher::matches($scope(['categories' => [4]]), $fatti(['categories' => [7, 4]]))
);

check('un tag scelto prende i prodotti che lo hanno', fn () =>
    ScopeMatcher::matches($scope(['tags' => [2]]), $fatti(['tags' => [1, 2]]))
    && !ScopeMatcher::matches($scope(['tags' => [2]]), $fatti(['tags' => [1]]))
);

check('un marchio scelto prende i prodotti di quel marchio', fn () =>
    ScopeMatcher::matches($scope(['brands' => [9]]), $fatti(['brand_id' => 9]))
    && !ScopeMatcher::matches($scope(['brands' => [9]]), $fatti(['brand_id' => 8]))
);

check('un prodotto senza marchio non combacia con nessun marchio scelto', fn () =>
    !ScopeMatcher::matches($scope(['brands' => [9]]), $fatti(['brand_id' => 0]))
);

check('un articolo incluso uno per uno è preso', fn () =>
    ScopeMatcher::matches($scope(['models' => [1]]), $fatti(['model_id' => 1]))
    && !ScopeMatcher::matches($scope(['models' => [1]]), $fatti(['model_id' => 2]))
);

check('basta uno dei criteri: sono in unione, non in intersezione', fn () =>
    ScopeMatcher::matches($scope(['categories' => [4], 'tags' => [2]]), $fatti(['tags' => [2]]))
);

check('un articolo escluso è fuori anche se la categoria lo prenderebbe', fn () =>
    !ScopeMatcher::matches($scope(['categories' => [4], 'excluded_models' => [1]]), $fatti(['categories' => [4], 'model_id' => 1]))
);

check('un articolo escluso è fuori anche con «tutto il catalogo»', fn () =>
    !ScopeMatcher::matches($scope(['all' => true, 'excluded_models' => [1]]), $fatti(['model_id' => 1]))
    && ScopeMatcher::matches($scope(['all' => true, 'excluded_models' => [1]]), $fatti(['model_id' => 2]))
);

check('un prodotto in due categorie, una scelta e una no, è preso', fn () =>
    ScopeMatcher::matches($scope(['categories' => [4]]), $fatti(['categories' => [4, 5]]))
);

summary();
