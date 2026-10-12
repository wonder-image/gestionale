<?php
/** php tests/integrazione/StockVersionSearchTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
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

/** Un marchio nuovo; torna l'id. */
function marchioDiProva(): int
{
    $nome = 'Prova Marchio Inventario '.uniqid();
    $riga = Brand::create([
        'code' => Code::make(Brand::class, Codes::BRAND),
        'name' => $nome,
        'slug' => Slug::unique($nome, Brand::class),
        'position' => 1,
        'visible' => 'true',
    ]);

    return (int) ($riga->insert_id ?? 0);
}

/** Una categoria nuova sotto `$padre`; torna l'id. */
function categoriaDiProva(int $padre = 0): int
{
    $nome = 'Prova Categoria Inventario '.uniqid();
    $riga = Category::create([
        'code' => Code::make(Category::class, Codes::CATEGORY),
        'name' => $nome,
        'slug' => Slug::unique($nome, Category::class),
        'parent_id' => $padre,
        'position' => 900,
        'visible' => 'true',
    ]);

    return (int) ($riga->insert_id ?? 0);
}

check('aggiungi versioni: la categoria porta anche le sottocategorie', function () {
    return prova(static function (): bool {
        $padre = categoriaDiProva();
        $figlia = categoriaDiProva($padre);
        $altra = categoriaDiProva();
        $a = articoloConGiacenza(1, codice());
        $b = articoloConGiacenza(0, codice());
        $fuori = articoloConGiacenza(1, codice());
        ProductModelCategory::create(['product_model_id' => modelloDi($a), 'category_id' => $padre, 'is_main' => 'true', 'position' => 1]);
        ProductModelCategory::create(['product_model_id' => modelloDi($b), 'category_id' => $figlia, 'is_main' => 'true', 'position' => 1]);
        ProductModelCategory::create(['product_model_id' => modelloDi($fuori), 'category_id' => $altra, 'is_main' => 'true', 'position' => 1]);
        $padreTrova = Ricerca::scope('category', $padre, 0);
        $figliaTrova = Ricerca::scope('category', $figlia, 0);

        // Le versioni vanno in ordine di nome, che qui è casuale: si confrontano per id.
        $pezzi = array_column($padreTrova['versions'], 'stock', 'id');
        ksort($pezzi);

        return $padreTrova['ids'] === [$a, $b]
            && $padreTrova['count'] === 2
            && $pezzi === [$a => 1.0, $b => 0.0]
            && $figliaTrova['ids'] === [$b]
            && count($figliaTrova['versions']) === 1;
    });
});

check('aggiungi versioni: il marchio; le ferme solo con pezzi nella sede; mai multiprodotti né cancellate', function () {
    return prova(static function (): bool {
        $marchio = marchioDiProva();
        $inVendita = articoloConGiacenza(0, codice());
        $fermaConPezzi = articoloConGiacenza(2, codice());
        $fermaSenza = articoloConGiacenza(0, codice());
        $cancellata = articoloConGiacenza(1, codice());
        $bundle = multiprodottoDiProva('fixed', [['product_id' => $inVendita, 'quantity' => 1]], []);
        Product::update(['active' => 'false'], $fermaConPezzi);
        Product::update(['active' => 'false'], $fermaSenza);
        Product::query()->Update(Product::$table, ['deleted' => 'true'], 'id', $cancellata);

        foreach ([$inVendita, $fermaConPezzi, $fermaSenza, $cancellata, $bundle] as $id) {
            ProductModel::update(['brand_id' => $marchio], modelloDi($id));
        }

        $trovate = Ricerca::scope('brand', $marchio, 0);
        $ferma = array_values(array_filter($trovate['versions'], static fn (array $v): bool => $v['id'] === $fermaConPezzi));

        return $trovate['ids'] === [$inVendita, $fermaConPezzi]
            && count($trovate['versions']) === 2
            && ($ferma[0]['inactive'] ?? false) === true
            && ($ferma[0]['stock'] ?? 0) === 2.0;
    });
});

check('aggiungi versioni: scelta sconosciuta o senza id, niente', fn () =>
    Ricerca::scope('colore', 1, 0) === ['count' => 0, 'ids' => [], 'versions' => []]
    && Ricerca::scope('category', 0, 0)['count'] === 0
    && Ricerca::scope('brand', 0, 0)['count'] === 0
);

check('aggiungi versioni: oltre 500 solo il conteggio', function () {
    return prova(static function (): bool {
        $marchio = marchioDiProva();
        $prefisso = codice('TST-INV-');

        for ($i = 0; $i < 501; $i++) {
            ProductModel::update(['brand_id' => $marchio], modelloDi(articoloConGiacenza(0, $prefisso.'-'.$i)));
        }

        $trovate = Ricerca::scope('brand', $marchio, 0);

        return $trovate['count'] === 501 && count($trovate['ids']) === 501 && $trovate['versions'] === [];
    });
});

check('aggiungi versioni: le scelte hanno categorie e marchi', function () {
    return prova(static function (): bool {
        $marchio = marchioDiProva();
        $categoria = categoriaDiProva();
        $scelte = Ricerca::scopeOptions();

        return isset($scelte['brands'][(string) $marchio], $scelte['categories'][(string) $categoria])
            && !isset($scelte['categories']['']);
    });
});

summary();
