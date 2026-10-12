<?php
/** php tests/StockVersionSearchTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Stock\StockVersionSearch as Ricerca;

check('lettore: una versione, più versioni, nessuna', fn () =>
    Ricerca::outcome([['id' => 4]]) === 'one'
    && Ricerca::outcome([['id' => 4], ['id' => 5]]) === 'many'
    && Ricerca::outcome([]) === 'none'
);

check('al più 20 versioni', fn () => Ricerca::LIMIT === 20);

check('la rotta delle versioni c\'è, con il suo file', function () {
    $rotte = (string) file_get_contents(__DIR__.'/../config/routes/route.api.php');

    return str_contains($rotte, "->name('stock.versions')")
        && str_contains($rotte, "->permit(['admin', 'administrator'])")
        && is_file(Gestionale::handlerPath('api/stock/versions.php'));
});

summary();
