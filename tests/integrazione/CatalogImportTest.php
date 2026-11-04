<?php
/** php tests/integrazione/CatalogImportTest.php — #84, il caricamento del catalogo sui dati demo */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\App\Import\Context;
use Wonder\App\Import\Importer;
use Wonder\App\Import\Store;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Catalog\Import\CatalogCells;
use Wonder\Plugin\Gestionale\Support\Catalog\Import\CatalogLayout;
use Wonder\Plugin\Gestionale\Support\Catalog\Import\RemoteImage;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductExport;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Il corpo gira in una transazione che torna sempre indietro. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    } finally {
        Gestionale::reset();
    }

    return $esito;
}

/** Una riga dell'esportazione come la rilegge il file: tutto testo. */
function cella(array $riga): array
{
    $out = [];

    foreach ($riga as $chiave => $valore) {
        if (is_array($valore)) {
            continue; // «images»: non è una colonna del file
        }

        $out[$chiave] = in_array($chiave, CatalogCells::NUMBERS, true)
            ? ProductExport::number($chiave, $valore)
            : (string) ($valore ?? '');
    }

    return $out;
}

function riga(array $data, int $line = 2): array
{
    return ['line' => $line, 'data' => $data];
}

function primo(mixed $righe): ?array
{
    if (!is_array($righe) || $righe === []) {
        return null;
    }

    return isset($righe['id']) ? $righe : (array_values(array_filter($righe, 'is_array'))[0] ?? null);
}

$layout = new CatalogLayout();
$export = ProductExport::of(true)['rows'];
$perSku = [];

foreach ($export as $r) {
    $perSku[(string) $r['sku']] = cella($r);
}

$nero = null;

foreach ($export as $r) {
    if ($r['group'] === 'IMP-1' && str_starts_with((string) $r['option'], 'Nero')) {
        $nero = cella($r);
    }
}

$iva = ProductExport::pricesIncludeTax();

check('dati demo: l\'impermeabile nero e il KIT-1 ci sono', fn () => $nero !== null && isset($perSku['KIT-1']));

check('abbinamento: senza la colonna SKU non si va avanti', fn () =>
    $layout->mappingErrors([0 => 'article', 1 => 'price'], ['Articolo', 'Prezzo']) !== []
);

check('abbinamento: un prezzo con l\'IVA diversa dal catalogo si ferma', fn () =>
    $layout->mappingErrors([0 => 'sku', 1 => 'price'], ['SKU', $iva ? 'Prezzo (IVA esclusa)' : 'Prezzo (IVA inclusa)']) !== []
    && $layout->mappingErrors([0 => 'sku', 1 => 'price'], ['SKU', 'Prezzo']) === []
);

check('controllo: l\'esportazione ricaricata così com\'è non cambia niente', function () use ($layout, $perSku) {
    $righe = [];
    $line = 2;

    foreach ($perSku as $data) {
        $righe[] = riga($data, $line++);
    }

    $esiti = $layout->validate($righe, new Context(1, 0));
    $diversi = array_filter($esiti, static fn (array $e): bool => $e['action'] !== 'unchanged' || $e['errors'] !== []);

    return $diversi === [] ?: array_map(static fn (array $e): string => $e['data']['sku'].': '.$e['action'].' '.implode('; ', $e['errors']), $diversi);
});

check('controllo: SKU doppio, numero sbagliato, tipo nuovo e codice articolo diverso', function () use ($layout, $nero) {
    $esiti = $layout->validate([
        riga(['sku' => $nero['sku'], 'price' => '12'], 2),
        riga(['sku' => $nero['sku'], 'price' => '13'], 3),
        riga(['sku' => 'IMP-NUOVO-X', 'article' => 'Nuovo', 'type' => 'Multiprodotto'], 4),
        riga(['sku' => $nero['sku'].'-B', 'article' => 'Altro', 'price' => 'dieci'], 5),
        riga(['sku' => 'KIT-1', 'group' => 'IMP-1'], 6),
    ], new Context(1, 0));

    return $esiti[0]['action'] === 'update' && $esiti[0]['errors'] === []
        && $esiti[1]['errors'] !== []
        && $esiti[2]['errors'] !== []
        && $esiti[3]['errors'] !== []
        && $esiti[4]['errors'] !== [];
});

check('controllo: SKU nuovo su un codice articolo che c\'è è una versione; nuovo del tutto è un articolo', function () use ($layout) {
    $esiti = $layout->validate([
        riga(['sku' => 'IMP-1-VIOLA', 'group' => 'IMP-1', 'option' => 'Viola'], 2),
        riga(['sku' => 'NUOVO-1', 'article' => 'Ombrello', 'image' => 'http://esempio.it/foto.jpg'], 3),
        riga(['sku' => 'NUOVO-2'], 4),
    ], new Context(1, 0));

    return $esiti[0]['data']['_mode'] === 'new_version' && $esiti[0]['action'] === 'create'
        && $esiti[1]['data']['_mode'] === 'new_model' && $esiti[1]['errors'] === [] && $esiti[1]['warnings'] !== []
        && $esiti[2]['errors'] !== [];
});

check('controllo: i disponibili di un multiprodotto non si scrivono', function () use ($layout) {
    $esiti = $layout->validate([riga(['sku' => 'KIT-1', 'available' => '5'], 2)], new Context(1, 0));

    return $esiti[0]['errors'] !== [];
});

check('scrittura e annullo: il prezzo dell\'impermeabile nero torna com\'era', fn () => prova(function () use ($layout, $nero) {
    $context = new Context(1, 0);
    $esito = $layout->validate([riga(['sku' => $nero['sku'], 'price' => '91,50', 'sale_price' => ''], 2)], $context)[0];
    $prima = Product::findById((int) $esito['data']['_product_id']);
    $fatto = $layout->apply($esito['data'], $context);
    $dopo = Product::findById((int) $esito['data']['_product_id']);

    foreach (array_reverse($fatto['changes']) as $change) {
        $layout->undo($change, $context);
    }

    $tornato = Product::findById((int) $esito['data']['_product_id']);

    return $fatto['action'] === 'update'
        && abs((float) $dopo['price'] - (float) $prima['price']) > 0.001
        && (string) $tornato['price'] === (string) $prima['price']
        && (string) $tornato['sale_price'] === (string) $prima['sale_price'];
}));

check('giacenza: «Disponibili» muove il magazzino col motivo import, l\'annullo lo rimette', fn () => prova(function () use ($layout, $nero) {
    $context = new Context(1, 0);
    $id = (int) $layout->validate([riga(['sku' => $nero['sku']], 2)], $context)[0]['data']['_product_id'];
    $prima = (float) Levels::of($id)['available'];
    $esito = $layout->validate([riga(['sku' => $nero['sku'], 'available' => (string) ($prima + 7)], 2)], $context)[0];
    $fatto = $layout->apply($esito['data'], $context);
    $stock = array_values(array_filter($fatto['changes'], static fn ($c): bool => $c->action === 'stock'));
    $movimento = $stock !== [] ? StockMovement::findById((int) $stock[0]->stockMovementId) : null;
    $dopo = (float) Levels::of($id)['available'];

    foreach (array_reverse($fatto['changes']) as $change) {
        $layout->undo($change, $context);
    }

    return abs($dopo - $prima - 7) < 0.001
        && is_array($movimento) && $movimento['reason'] === 'import'
        && abs((float) Levels::of($id)['available'] - $prima) < 0.001;
}));

$cartella = rtrim(SITE, '/').'/assets/upload'.ProductImages::folder();
$foto = Istantanea::di($cartella);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
RemoteImage::useFetcher(static fn (string $url): string => $png);

try {
    check('articolo nuovo: categorie «A › B» create, foto in coda, annullo che cancella', fn () => prova(function () use ($layout) {
        $context = new Context(1, 0);
        $esito = $layout->validate([riga([
            'sku' => 'IMPORT-TEST-1',
            'article' => 'Ombrello da prova',
            'price' => '19,90',
            'categories' => 'Prova import › Ombrelli',
            'image' => 'https://esempio.it/ombrello.png',
        ], 2)], $context)[0];

        if ($esito['errors'] !== [] || $esito['data']['_mode'] !== 'new_model') {
            return $esito['errors'];
        }

        $fatto = $layout->apply($esito['data'], $context);
        $prodotto = primo(Product::find(['sku' => 'IMPORT-TEST-1', 'deleted' => 'false']));
        $modello = $prodotto !== null ? ProductModel::findById((int) $prodotto['product_model_id']) : null;
        $figlia = primo(Category::find(['name' => 'Ombrelli', 'deleted' => 'false']));
        $immagine = $modello !== null ? primo(ProductImage::find(['product_model_id' => (int) $modello['id'], 'deleted' => 'false'])) : null;
        $rifatto = $layout->validate([riga(['sku' => 'IMPORT-TEST-1', 'image' => 'https://esempio.it/ombrello.png'], 2)], new Context(1, 0))[0];

        foreach (array_reverse($fatto['changes']) as $change) {
            $layout->undo($change, $context);
        }

        return $fatto['action'] === 'create'
            && $modello !== null && (string) $modello['type'] === 'simple'
            && abs((float) $prodotto['price'] - 19.9) < 0.001
            && $figlia !== null && (int) ($figlia['parent_id'] ?? 0) > 0
            && $immagine !== null && $immagine['status'] === 'pending'
            && is_file(rtrim(SITE, '/').'/assets/upload'.ProductImages::folder().json_decode((string) $immagine['file'], true)[0])
            && $rifatto['data']['_mode'] === 'update'
            && primo(Product::find(['sku' => 'IMPORT-TEST-1', 'deleted' => 'false'])) === null;
    }));

    check('versione nuova: la viola si aggiunge all\'impermeabile', fn () => prova(function () use ($layout) {
        $context = new Context(1, 0);
        $esito = $layout->validate([riga(['sku' => 'IMP-1-VIOLA', 'group' => 'IMP-1', 'option' => 'Viola', 'price' => '89'], 2)], $context)[0];
        $fatto = $layout->apply($esito['data'], $context);
        $prodotto = primo(Product::find(['sku' => 'IMP-1-VIOLA', 'deleted' => 'false']));
        $modello = primo(ProductModel::find(['sku' => 'IMP-1', 'deleted' => 'false']));

        return $esito['errors'] === [] && $fatto['action'] === 'create'
            && $prodotto !== null && $modello !== null
            && (int) $prodotto['product_model_id'] === (int) $modello['id'];
    }));

    check('giro completo: file → abbinamento → scrittura → annullo', fn () => prova(function () use ($nero, $iva) {
        $csv = ProductExport::csv([
            ['sku' => $nero['sku'], 'price' => 77.0],
            ['sku' => 'IMPORT-TEST-2', 'article' => 'Borraccia da prova', 'price' => 9.0],
        ], $iva, array_values(array_filter(
            ProductExport::columns($iva),
            static fn ($c): bool => in_array($c->key, ['sku', 'article', 'price'], true)
        )));
        $file = tempnam(sys_get_temp_dir(), 'wi-import').'.csv';
        file_put_contents($file, $csv);

        try {
            $jobId = Importer::upload($file, 'catalogo.csv', CatalogLayout::KEY, 1);
        } finally {
            @unlink($file);
        }

        $mapping = Importer::mapping(Store::job($jobId));
        $errori = Importer::map($jobId, $mapping['columns']);

        if ($errori !== []) {
            return $errori;
        }

        Importer::start($jobId);
        $passo = Importer::step($jobId);
        $prezzo = (float) Product::findById((int) $nero['id'])['price'];
        $creato = primo(Product::find(['sku' => 'IMPORT-TEST-2', 'deleted' => 'false']));
        Importer::undo($jobId, 1);

        return $passo['status'] === 'done' && $passo['created'] === 1 && $passo['updated'] === 1
            && abs($prezzo - 77.0) < 0.001 && $creato !== null
            && abs((float) Product::findById((int) $nero['id'])['price'] - (float) $nero['price']) < 0.001
            && primo(Product::find(['sku' => 'IMPORT-TEST-2', 'deleted' => 'false'])) === null
            && Store::job($jobId)['status'] === 'undone';
    }));
} finally {
    RemoteImage::useFetcher(null);
    $foto->ripristina();
}

summary();
