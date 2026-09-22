<?php
/** php tests/integrazione/StockPagesTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Stock\StockLevelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$movimenti = static function (int $productId): int {
    $righe = StockMovement::find(['product_id' => $productId, 'deleted' => 'false']);
    $righe = isset($righe['id']) ? [$righe] : (array) $righe;

    return count(array_filter($righe, 'is_array'));
};

try {
    Transaction::run(static function () use ($movimenti): void {
        $modello = ProductModel::create([
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => 'Prova giacenze',
            'slug' => Slug::make('prova-giacenze-'.uniqid()),
            'sku' => 'TST-LVL',
            'unit' => 'pz',
            'type' => 'simple',
            'visible' => 'true',
            'position' => 1,
        ]);
        $scheletro = Skeleton::forModel((int) ($modello->insert_id ?? 0), 'Prova giacenze', 'TST-LVL');
        $productId = $scheletro['product_id'];

        $_GET['cerca'] = 'TST-LVL';
        StockLevelResource::forget();

        check('la ricerca trova la versione di prova', function () use ($productId) {
            foreach (StockLevelResource::rows() as $row) {
                if ((int) $row['id'] === $productId) {
                    // Il nome dell'articolo lo scrive il framework con le
                    // iniziali maiuscole: "Prova Giacenze".
                    return $row['quantity'] === 0.0
                        && strtolower((string) $row['article']) === 'prova giacenze';
                }
            }

            return false;
        });

        check('scrivere una quantità crea il movimento', function () use ($productId) {
            $messaggio = StockLevelResource::submitFormPage([
                'reason' => 'initial_stock',
                'quantity_'.$productId => '8',
            ]);

            return $messaggio === 'Una giacenza aggiornata.'
                && Levels::of($productId)['quantity'] === 8.0;
        });

        check('il movimento porta la causale della schermata', function () use ($productId) {
            $riga = StockMovement::find(['product_id' => $productId], 1, 'id', 'DESC');

            return ($riga['reason'] ?? '') === 'initial_stock'
                && (float) ($riga['quantity'] ?? 0) === 8.0;
        });

        check('salvare senza cambiare niente non scrive movimenti', function () use ($productId, $movimenti) {
            $prima = $movimenti($productId);
            $messaggio = StockLevelResource::submitFormPage([
                'reason' => 'inventory',
                'quantity_'.$productId => '8',
            ]);

            return $messaggio === 'Nessuna giacenza cambiata.' && $movimenti($productId) === $prima;
        });

        check('una casella vuota lascia la riga com\'è', function () use ($productId) {
            StockLevelResource::submitFormPage([
                'reason' => 'inventory',
                'quantity_'.$productId => '',
            ]);

            return Levels::of($productId)['quantity'] === 8.0;
        });

        check('più righe insieme, un movimento per ognuna', function () use ($productId, $movimenti) {
            $secondo = Skeleton::forModel(
                (int) (ProductModel::find(['sku' => 'TST-LVL', 'deleted' => 'false'], 1)['id'] ?? 0),
                'Prova giacenze 2',
                'TST-LVL-2'
            );
            $altro = $secondo['product_id'];
            $prima = $movimenti($productId) + $movimenti($altro);

            $messaggio = StockLevelResource::submitFormPage([
                'reason' => 'inventory',
                'quantity_'.$productId => '10',
                'quantity_'.$altro => '4',
            ]);

            return $messaggio === '2 giacenze aggiornate.'
                && Levels::of($productId)['quantity'] === 10.0
                && Levels::of($altro)['quantity'] === 4.0
                && $movimenti($productId) + $movimenti($altro) === $prima + 2;
        });

        check('scrivere zero svuota davvero', function () use ($productId) {
            StockLevelResource::submitFormPage([
                'reason' => 'inventory',
                'quantity_'.$productId => '0',
            ]);

            return Levels::of($productId)['quantity'] === 0.0;
        });

        unset($_GET['cerca']);
        StockLevelResource::forget();

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento non resta niente', function () {
    $riga = ProductModel::find(['sku' => 'TST-LVL', 'deleted' => 'false'], 1);

    return !is_array($riga) || $riga === [];
});

summary();
