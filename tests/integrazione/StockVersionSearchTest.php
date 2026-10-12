<?php
/** php tests/integrazione/StockVersionSearchTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\StockVersionSearch as Ricerca;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Esegue `$corpo` in una transazione che poi si annulla: il database resta com'era. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    Locations::reset();

    return $esito;
}

/** Un codice che nessun'altra versione ha. */
function codice(string $prefisso = 'TST-VER-'): string
{
    return $prefisso.strtoupper(substr(uniqid(), -7)).random_int(10, 99);
}

check('lettore: lo SKU esatto di una versione dà quella, con giacenza nella sede e foto', function () {
    return prova(static function (): bool {
        $sku = codice();
        $id = articoloConGiacenza(7, $sku);
        $esito = Ricerca::scan($sku, 0);
        $v = $esito['versions'][0] ?? [];

        return $esito['result'] === 'one'
            && count($esito['versions']) === 1
            && $v['id'] === $id
            && $v['sku'] === $sku
            && $v['stock'] === 7.0
            && $v['inactive'] === false
            && is_string($v['photo'])
            && stripos($v['label'], 'Prova vendite '.$sku) !== false;
    });
});

check('lettore: l\'EAN esatto vale come lo SKU', function () {
    return prova(static function (): bool {
        $id = articoloConGiacenza(1, codice());
        $ean = '80'.random_int(10000000000, 99999999999);
        Product::update(['ean' => $ean], $id);
        $esito = Ricerca::scan($ean, 0);

        return $esito['result'] === 'one' && ($esito['versions'][0]['id'] ?? 0) === $id;
    });
});

check('lettore: un codice di due versioni dà «many» e le due versioni', function () {
    return prova(static function (): bool {
        $ean = '80'.random_int(10000000000, 99999999999);
        $a = articoloConGiacenza(1, codice());
        $b = articoloConGiacenza(1, codice());
        Product::update(['ean' => $ean], $a);
        Product::update(['ean' => $ean], $b);
        $esito = Ricerca::scan($ean, 0);
        $ids = array_column($esito['versions'], 'id');
        sort($ids);

        return $esito['result'] === 'many' && $ids === [$a, $b];
    });
});

check('lettore: un codice sconosciuto o vuoto dà «none»', fn () =>
    Ricerca::scan(codice('NESSUNO-'), 0) === ['result' => 'none', 'versions' => []]
    && Ricerca::scan('   ', 0) === ['result' => 'none', 'versions' => []]
);

check('lettore: lo SKU è esatto, non un pezzo', function () {
    return prova(static function (): bool {
        $sku = codice();
        articoloConGiacenza(1, $sku);

        return Ricerca::scan(substr($sku, 0, -1), 0)['result'] === 'none';
    });
});

check('ricerca: parola per parola su nome dell\'articolo e SKU', function () {
    return prova(static function (): bool {
        $sku = codice();
        $id = articoloConGiacenza(1, $sku);
        $trovate = array_column(Ricerca::search('vendite '.substr($sku, 4), 0), 'id');

        return $trovate === [$id];
    });
});

check('ricerca: niente multiprodotti, niente versioni cancellate', function () {
    return prova(static function (): bool {
        $sku = codice();
        $a = articoloConGiacenza(1, $sku);
        $bundle = multiprodottoDiProva('fixed', [['product_id' => $a, 'quantity' => 1]], []);
        Product::update(['sku' => $sku.'-B'], $bundle);
        $cancellata = articoloConGiacenza(1, $sku.'-C');
        Product::query()->Update(Product::$table, ['deleted' => 'true'], 'id', $cancellata);

        return array_column(Ricerca::search($sku, 0), 'id') === [$a]
            && Ricerca::scan($sku.'-B', 0)['result'] === 'none'
            && Ricerca::scan($sku.'-C', 0)['result'] === 'none'
            && Ricerca::describe([$bundle, $cancellata], 0) === [];
    });
});

check('ricerca: %, _ e apici si cercano come caratteri', function () {
    return prova(static function (): bool {
        $sku = codice();
        articoloConGiacenza(1, $sku);

        return Ricerca::search('%', 0) === array_values(array_filter(Ricerca::search('%', 0), static fn (array $v): bool => str_contains($v['label'].$v['sku'].$v['ean'], '%')))
            && Ricerca::search('_'.substr($sku, 1), 0) === []
            && Ricerca::search($sku."'", 0) === []
            && Ricerca::search("' OR '1'='1", 0) === []
            && Ricerca::scan("' OR '1'='1", 0)['result'] === 'none';
    });
});

check('ricerca: al più 20 versioni', function () {
    return prova(static function (): bool {
        $prefisso = codice('TST-MOLTE-');

        for ($i = 0; $i < 21; $i++) {
            articoloConGiacenza(0, $prefisso.'-'.$i);
        }

        return count(Ricerca::search($prefisso, 0)) === 20;
    });
});

check('ricerca: una versione ferma ha il segno', function () {
    return prova(static function (): bool {
        $sku = codice();
        $id = articoloConGiacenza(1, $sku);
        Product::update(['active' => 'false'], $id);

        return (Ricerca::search($sku, 0)[0]['inactive'] ?? false) === true;
    });
});

summary();
