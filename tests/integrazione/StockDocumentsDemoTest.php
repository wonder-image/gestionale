<?php
/** php tests/integrazione/StockDocumentsDemoTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';
require __DIR__ . '/supporto/compra.php';
require __DIR__ . '/supporto/dns-fixture.php';

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Seeding\CatalogDemo;
use Wonder\Plugin\Gestionale\Seeding\ContactsDemo;
use Wonder\Plugin\Gestionale\Seeding\Demo;
use Wonder\Plugin\Gestionale\Seeding\DemoCode;
use Wonder\Plugin\Gestionale\Seeding\StockDocumentsDemo;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** @return list<array<string, mixed>> */
$righe = static function (string $model, string|array $dove): array {
    $rows = $model::find($dove, null, 'id', 'ASC');

    return !is_array($rows) || $rows === [] ? [] : (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array')));
};

/** La giacenza di ogni versione di prova, per id. */
$giacenze = static function () use ($righe): array {
    $out = [];
    $prefisso = DemoCode::prefixOf(ProductModel::class);

    foreach ($righe(ProductModel::class, "code LIKE '".$prefisso."demo-%' AND deleted = 'false'") as $modello) {
        foreach ($righe(Product::class, ['product_model_id' => (int) $modello['id'], 'deleted' => 'false']) as $versione) {
            $out[(int) $versione['id']] = round(Levels::of((int) $versione['id'])['quantity'], 3);
        }
    }

    return $out;
};

// `CatalogDemo` scrive foto sul disco e la transazione non le rimette: l'istantanea sì.
$foto = Istantanea::di(rtrim((string) ($GLOBALS['ROOT'] ?? ''), '/').'/assets/upload'.ProductImages::folder());
$esito = [];

try {
    Transaction::run(static function () use (&$esito, $righe, $giacenze): void {
        DemoData::reset();
        Demo::registerAll();
        $esito['registrato'] = array_keys(DemoData::all());

        // Si parte puliti, come `--fresh`: prima l'ultimo registrato.
        foreach (DemoData::inClearOrder() as $dati) {
            ($dati['clear'])();
        }

        ContactsDemo::create();
        CatalogDemo::create();
        $esito['prima'] = $giacenze();

        $esito['create'] = StockDocumentsDemo::create();
        $esito['secondo'] = StockDocumentsDemo::create();
        $esito['dopo_create'] = $giacenze();

        $documenti = $righe(StockDocument::class, "code IN ('".DemoCode::forModel(StockDocument::class, 'carico')."', '".DemoCode::forModel(StockDocument::class, 'scarico')."', '".DemoCode::forModel(StockDocument::class, 'inventario')."')");
        $esito['documenti'] = array_map(static fn (array $d): array => [
            'type' => (string) $d['type'],
            'status' => (string) $d['status'],
            'number' => (string) $d['number'],
            'reason' => (string) $d['reason'],
        ], $documenti);
        $ids = array_map(static fn (array $d): int => (int) $d['id'], $documenti);
        $in = $ids === [] ? '0' : implode(',', $ids);
        $esito['inventario'] = [];

        foreach ($documenti as $d) {
            if ((string) $d['type'] === 'stocktake') {
                foreach ($righe(StockDocumentItem::class, ['stock_document_id' => (int) $d['id']]) as $riga) {
                    $esito['inventario'][] = [
                        $riga['quantity'] === null ? null : (float) $riga['quantity'],
                        $riga['expected_quantity'] === null ? null : (float) $riga['expected_quantity'],
                    ];
                }
            }
        }
        $esito['movimenti'] = count($righe(StockMovement::class, "reference_type = 'stock_document' AND reference_id IN (".$in.')'));

        // La pulizia toglie documenti, righe, storia e movimenti, e la giacenza torna com'era.
        $esito['pulite'] = StockDocumentsDemo::clear();
        $esito['dopo_pulizia'] = $giacenze();
        $esito['restano'] = count($righe(StockDocument::class, 'id IN ('.$in.')'))
            + count($righe(StockDocumentItem::class, 'stock_document_id IN ('.$in.')'))
            + count($righe(StockDocumentStatusLog::class, 'stock_document_id IN ('.$in.')'))
            + count($righe(StockMovement::class, "reference_type IN ('stock_document', 'stock_document_cancel') AND reference_id IN (".$in.')'));
        $esito['seconda_pulizia'] = StockDocumentsDemo::clear();

        // Senza articoli di prova non si crea niente e il comando lo dice.
        CatalogDemo::clear();
        DemoData::notes();
        $esito['senza_catalogo'] = StockDocumentsDemo::create();
        $esito['nota'] = DemoData::notes();

        throw new Annulla();
    });
} catch (Annulla) {
} finally {
    Wonder\Plugin\Gestionale\Gestionale::reset();
    $foto->ripristina();
}

$variazioni = static function (array $prima, array $dopo): array {
    $out = [];

    foreach ($dopo as $id => $quantita) {
        $differenza = round($quantita - ($prima[$id] ?? 0.0), 3);

        if ($differenza !== 0.0) {
            $out[] = $differenza;
        }
    }

    return $out;
};

check('i documenti di prova sono nel registro, dopo il catalogo', function () use ($esito) {
    $chiavi = $esito['registrato'] ?? [];

    return in_array(StockDocumentsDemo::KEY, $chiavi, true)
        && array_search(StockDocumentsDemo::KEY, $chiavi, true) > array_search(CatalogDemo::KEY, $chiavi, true);
});

check('create fa un carico e uno scarico confermati e numerati, un inventario in bozza, e un secondo giro non ne duplica', function () use ($esito) {
    $stati = [];

    foreach ($esito['documenti'] ?? [] as $documento) {
        $stati[$documento['type']] = $documento['status'];
    }

    ksort($stati);
    $confermati = array_filter($esito['documenti'] ?? [], static fn (array $d): bool => $d['status'] === 'completed');

    return ($esito['create'] ?? 0) === 3 && ($esito['secondo'] ?? -1) === 0
        && $stati === ['issue' => 'completed', 'receipt' => 'completed', 'stocktake' => 'draft']
        && !in_array('', array_column($confermati, 'number'), true);
});

check('l\'inventario ha una riga contata con una differenza e una non contata', function () use ($esito) {
    [$contata, $vuota] = ($esito['inventario'] ?? []) + [[0, 0], [0, 0]];

    return count($esito['inventario'] ?? []) === 2
        && $contata[0] === 3.0 && $contata[1] !== null && $contata[1] !== 3.0
        && $vuota === [null, null];
});

check('lo scarico ha la causale «danneggiato»', function () use ($esito) {
    foreach ($esito['documenti'] ?? [] as $documento) {
        if ($documento['type'] === 'issue') {
            return $documento['reason'] === 'damaged';
        }
    }

    return false;
});

check('la giacenza si muove: +8 su una versione (10 caricati, 2 scaricati) e +5 su un\'altra, con tre movimenti', function () use ($esito, $variazioni) {
    $mosse = $variazioni($esito['prima'] ?? [], $esito['dopo_create'] ?? []);
    sort($mosse);

    return $mosse === [5.0, 8.0] && ($esito['movimenti'] ?? 0) === 3;
});

check('la pulizia toglie i tre documenti con righe, storia e movimenti, e la giacenza torna com\'era', fn () =>
    ($esito['pulite'] ?? 0) === 3 && ($esito['restano'] ?? -1) === 0
    && $variazioni($esito['prima'] ?? [], $esito['dopo_pulizia'] ?? []) === []
    && ($esito['seconda_pulizia'] ?? -1) === 0
);

check('senza articoli di prova non crea niente e lascia una nota', fn () =>
    ($esito['senza_catalogo'] ?? -1) === 0 && ($esito['nota'] ?? []) !== []
);

summary();
