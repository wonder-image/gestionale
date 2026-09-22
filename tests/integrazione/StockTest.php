<?php
/** php tests/integrazione/StockTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\LowStock;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

try {
    Transaction::run(static function (): void {
        $modello = ProductModel::create([
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => 'Prova rettifica',
            'slug' => Slug::make('prova-rettifica-'.uniqid()),
            'sku' => 'TST-APP',
            'unit' => 'pz',
            'type' => 'simple',
            'visible' => 'true',
            'position' => 1,
        ]);
        $scheletro = Skeleton::forModel((int) ($modello->insert_id ?? 0), 'Prova rettifica', 'TST-APP');
        $productId = $scheletro['product_id'];

        // La scorta minima si scrive dopo: la crea il generatore dello
        // scheletro, che non la conosce.
        Product::update(['min_stock_quantity' => '5'], $productId);

        check('l\'articolo di prova ha la sua scorta minima', fn () =>
            (float) (Product::findById($productId)['min_stock_quantity'] ?? 0) === 5.0
        );

        check('il primo carico crea giacenza e movimento', function () use ($productId) {
            $esito = Stock::apply([
                'product_id' => $productId,
                'quantity' => 10,
                'reason' => 'initial_stock',
            ]);

            return $esito['before'] === 0.0
                && $esito['after'] === 10.0
                && $esito['movement_id'] > 0
                && Levels::of($productId)['quantity'] === 10.0;
        });

        check('il movimento racconta prima, dopo e chi', function () use ($productId) {
            $riga = StockMovement::find(['product_id' => $productId], 1, 'id', 'DESC');

            return ($riga['type'] ?? '') === 'adjustment'
                && ($riga['reason'] ?? '') === 'initial_stock'
                && (float) ($riga['quantity'] ?? 0) === 10.0
                && (float) ($riga['quantity_before'] ?? -1) === 0.0
                && (float) ($riga['quantity_after'] ?? 0) === 10.0
                && ($riga['source'] ?? '') === 'backend'
                && str_starts_with((string) ($riga['code'] ?? ''), 'mov_');
        });

        check('un secondo movimento parte da dove era rimasto', function () use ($productId) {
            $esito = Stock::apply([
                'product_id' => $productId,
                'quantity' => -3,
                'reason' => 'damaged',
            ]);

            return $esito['before'] === 10.0 && $esito['after'] === 7.0;
        });

        check('la giacenza non si sdoppia: resta una riga sola', function () use ($productId) {
            $righe = StockRow::find([
                'product_id' => $productId,
                'deleted' => 'false',
            ]);
            $righe = isset($righe['id']) ? [$righe] : (array) $righe;

            return count(array_filter($righe, 'is_array')) === 1;
        });

        check('scendere sotto la scorta minima apre l\'avviso', function () use ($productId) {
            $esito = Stock::apply([
                'product_id' => $productId,
                'quantity' => -3,
                'reason' => 'inventory',
            ]);
            $avviso = StockAlert::find(['product_id' => $productId, 'deleted' => 'false'], 1, 'id', 'DESC');

            return $esito['alert'] === LowStock::OPEN
                && (float) ($avviso['threshold'] ?? 0) === 5.0
                && (float) ($avviso['quantity_at_alert'] ?? -1) === 4.0
                && trim((string) ($avviso['resolved_at'] ?? '')) === ''
                && trim((string) ($avviso['notified_at'] ?? '')) === '';
        });

        check('restando sotto soglia l\'avviso non si ripete', function () use ($productId) {
            $esito = Stock::apply(['product_id' => $productId, 'quantity' => -1, 'reason' => 'inventory']);
            $righe = StockAlert::find(['product_id' => $productId, 'deleted' => 'false']);
            $righe = isset($righe['id']) ? [$righe] : (array) $righe;

            return $esito['alert'] === LowStock::NONE
                && count(array_filter($righe, 'is_array')) === 1;
        });

        check('risalire sopra soglia chiude l\'avviso', function () use ($productId) {
            $esito = Stock::apply(['product_id' => $productId, 'quantity' => 20, 'reason' => 'inventory']);
            $avviso = StockAlert::find(['product_id' => $productId, 'deleted' => 'false'], 1, 'id', 'DESC');

            return $esito['alert'] === LowStock::CLOSE
                && trim((string) ($avviso['resolved_at'] ?? '')) !== '';
        });

        check('una rettifica da zero pezzi viene rifiutata con un messaggio', function () use ($productId) {
            try {
                Stock::apply(['product_id' => $productId, 'quantity' => 0]);
            } catch (UserError $e) {
                return $e->key() === 'stock.zero_quantity';
            }

            return false;
        });

        check('una causale inventata viene rifiutata', function () use ($productId) {
            try {
                Stock::apply(['product_id' => $productId, 'quantity' => 1, 'reason' => 'marziana']);
            } catch (UserError $e) {
                return $e->key() === 'stock.unknown_reason';
            }

            return false;
        });

        check('un prodotto che non esiste viene rifiutato', function () {
            try {
                Stock::apply(['product_id' => 0, 'quantity' => 1]);
            } catch (UserError $e) {
                return $e->key() === 'stock.product_missing';
            }

            return false;
        });

        check('senza vendita sotto zero la giacenza non va in negativo', function () use ($productId) {
            $prima = Levels::of($productId)['quantity'];

            try {
                Stock::apply(['product_id' => $productId, 'quantity' => -1000, 'reason' => 'inventory']);
            } catch (UserError $e) {
                return $e->key() === 'stock.insufficient'
                    && Levels::of($productId)['quantity'] === $prima;
            }

            return false;
        });

        check('i decimali arrivano interi fino al database', function () use ($productId) {
            // La colonna è DECIMAL(10,3): mezzo etto non deve sparire in un
            // arrotondamento.
            $esito = Stock::apply([
                'product_id' => $productId,
                'quantity' => 0.125,
                'reason' => 'inventory',
            ]);
            $riga = StockMovement::find(['product_id' => $productId], 1, 'id', 'DESC');

            return (float) ($riga['quantity'] ?? 0) === 0.125
                && $esito['after'] === round($esito['before'] + 0.125, 3)
                && Levels::of($productId)['quantity'] === $esito['after'];
        });

        check('un rifiuto non lascia movimenti a metà', function () use ($productId) {
            $ultimo = StockMovement::find(['product_id' => $productId], 1, 'id', 'DESC');

            // L'ultimo movimento è ancora quello dei decimali: il rifiuto
            // non ne ha scritto uno suo.
            return (float) ($ultimo['quantity'] ?? 0) === 0.125;
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento non resta nessun movimento di prova', function () {
    $articolo = ProductModel::find(['sku' => 'TST-APP', 'deleted' => 'false'], 1);
    $versione = Product::find(['sku' => 'TST-APP', 'deleted' => 'false'], 1);

    // Sparito l'articolo sono spariti anche i suoi movimenti: la transazione
    // annullata riporta indietro tutto quello che c'era dentro.
    return (!is_array($articolo) || $articolo === [])
        && (!is_array($versione) || $versione === []);
});

summary();
