<?php
/** php tests/integrazione/LowStockTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Stock\Alerts;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

// Le funzionalità si leggono una volta per richiesta: si accende solo quella
// che serve, lasciando le altre come le ha il sito.
Gestionale::feature('low_stock_alerts');
$stato = new ReflectionProperty(Gestionale::class, 'features');
$stato->setValue(null, array_merge((array) $stato->getValue(), ['low_stock_alerts' => true]));

/**
 * Un articolo senza varianti. Con una giacenza scritta nasce col suo carico
 * iniziale; senza, non ha nessun movimento e si può ancora eliminare.
 *
 * @return array{0: int, 1: int} id del modello e dell'unico prodotto
 */
function articoloDiProva(string $sku, string $giacenza = ''): array
{
    $modello = ProductModel::create([
        'code' => Code::make(ProductModel::class, Codes::MODEL),
        'name' => 'Prova scorta '.$sku,
        'slug' => Slug::make('prova-scorta-'.uniqid()),
        'sku' => $sku,
        'unit' => 'pz',
        'type' => 'simple',
        'visible' => 'true',
        'visible_online' => 'true',
        'position' => 1,
    ]);
    $modelId = (int) ($modello->insert_id ?? 0);
    $scheletro = Skeleton::forModel($modelId, 'Prova scorta '.$sku, $sku);

    ProductModelResource::forgetCatalogCache();
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_stock' => $giacenza], $sku, [], true);

    return [$modelId, (int) $scheletro['product_id']];
}

/** Un controllo dentro una transazione che poi si annulla: il sito resta com'era. */
function annullando(callable $prova): bool
{
    $esito = false;

    try {
        Transaction::run(static function () use ($prova, &$esito): void {
            $esito = (bool) $prova();

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
}

check('alzare la soglia sopra il disponibile apre l\'avviso subito, senza movimenti', fn () => annullando(function (): bool {
    [$modelId, $productId] = articoloDiProva('LOW-1', '3');
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => '5'], 'LOW-1');
    $avviso = Alerts::openRow($productId);
    $prodotto = Product::findById($productId);

    return $avviso !== []
        && (float) $avviso['threshold'] === 5.0
        && (float) $avviso['quantity_at_alert'] === 3.0
        && (float) ($prodotto['min_stock_quantity'] ?? 0) === 5.0;
}));

check('abbassarla sotto il disponibile chiude l\'avviso', fn () => annullando(function (): bool {
    [$modelId, $productId] = articoloDiProva('LOW-2', '3');
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => '5'], 'LOW-2');
    $aperto = Alerts::openRow($productId) !== [];
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => '2'], 'LOW-2');

    return $aperto && Alerts::openRow($productId) === [];
}));

check('con la soglia a zero l\'avviso aperto si chiude', fn () => annullando(function (): bool {
    [$modelId, $productId] = articoloDiProva('LOW-3', '1');
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => '4'], 'LOW-3');
    $aperto = Alerts::openRow($productId) !== [];
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => ''], 'LOW-3');

    return $aperto && Alerts::openRow($productId) === [];
}));

check('un articolo che nasce già sotto la sua scorta minima ha l\'avviso', fn () => annullando(function (): bool {
    $modello = ProductModel::create([
        'code' => Code::make(ProductModel::class, Codes::MODEL),
        'name' => 'Prova scorta LOW-4',
        'slug' => Slug::make('prova-scorta-'.uniqid()),
        'sku' => 'LOW-4',
        'unit' => 'pz',
        'type' => 'simple',
        'visible' => 'true',
        'visible_online' => 'true',
        'position' => 1,
    ]);
    $modelId = (int) ($modello->insert_id ?? 0);
    $scheletro = Skeleton::forModel($modelId, 'Prova scorta LOW-4', 'LOW-4');
    ProductModelResource::forgetCatalogCache();
    ProductModelResource::saveExtras(
        $modelId,
        ['has_variants' => 'false', 'product_stock' => '2', 'product_min_stock' => '4'],
        'LOW-4',
        [],
        true
    );

    return Alerts::openRow((int) $scheletro['product_id']) !== [];
}));

check('un articolo con l\'avviso aperto e nessun movimento si elimina', fn () => annullando(function (): bool {
    [$modelId, $productId] = articoloDiProva('LOW-5');
    Product::update(['min_stock_quantity' => '5.000'], $productId);
    $aperto = Alerts::refresh($productId) === 'open';
    $esito = ProductModelResource::deleteRecord($modelId);
    $rimasti = StockAlert::find(['product_id' => $productId]);

    return $aperto && !empty($esito->success) && (!is_array($rimasti) || $rimasti === []);
}));

check('anche una versione con l\'avviso aperto si elimina dalla sua scheda', fn () => annullando(function (): bool {
    [, $productId] = articoloDiProva('LOW-6');
    Product::update(['min_stock_quantity' => '5.000'], $productId);
    Alerts::refresh($productId);
    $esito = ProductResource::deleteRecord($productId);

    return !empty($esito->success) && Product::findById($productId) === [];
}));

summary();
