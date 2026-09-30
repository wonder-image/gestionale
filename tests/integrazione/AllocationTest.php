<?php
/** php tests/integrazione/AllocationTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Un articolo nuovo con la giacenza chiesta sulla sede principale. */
function articoloConGiacenza(float $pezzi, string $sku): int
{
    $modello = ProductModel::create([
        'code' => Code::make(ProductModel::class, Codes::MODEL),
        'name' => 'Prova vendite '.$sku,
        'slug' => Slug::make('prova-vendite-'.uniqid()),
        'sku' => $sku,
        'unit' => 'pz',
        'type' => 'simple',
        'visible' => 'true',
        'position' => 1,
    ]);
    $scheletro = Skeleton::forModel((int) ($modello->insert_id ?? 0), 'Prova vendite', $sku);
    $productId = $scheletro['product_id'];

    if ($pezzi > 0) {
        Stock::apply([
            'product_id' => $productId,
            'quantity' => $pezzi,
            'type' => 'purchase',
            'reason' => 'initial_stock',
        ]);
    }

    return $productId;
}

/** L'ordine di prova: serve un id vero per le prenotazioni e i log. */
function ordineDiProva(float $totale = 100.0): int
{
    $ordine = Order::create([
        'code' => Code::make(Order::class, Codes::ORDER),
        'stage' => 'order',
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => number_format($totale, 2, '.', ''),
    ]);

    return (int) ($ordine->insert_id ?? 0);
}

try {
    Transaction::run(static function (): void {
        $sede = Locations::mainId();

        check('la prenotazione toglie dal disponibile senza toccare la giacenza', function () use ($sede) {
            $productId = articoloConGiacenza(10, 'TST-ALL-1');
            $ordine = ordineDiProva();

            $esito = Allocation::reserve([
                'product_id' => $productId,
                'quantity' => 3,
                'location_id' => $sede,
                'order_id' => $ordine,
                'order_item_id' => 0,
            ]);

            $livelli = Levels::of($productId);

            return $esito['reservation_id'] > 0
                && $livelli['quantity'] === 10.0
                && $livelli['reserved'] === 3.0
                && $livelli['available'] === 7.0;
        });

        check('la prenotazione nasce con la scadenza delle impostazioni', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-ALL-2');

            $esito = Allocation::reserve([
                'product_id' => $productId,
                'quantity' => 1,
                'location_id' => $sede,
                'order_id' => ordineDiProva(),
            ]);

            // Trenta minuti è il predefinito; basta che sia nel futuro.
            return $esito['expires_at'] !== ''
                && $esito['expires_at'] > date('Y-m-d H:i:s');
        });

        check('non si prenota più di quello che c\'è', function () use ($sede) {
            $productId = articoloConGiacenza(2, 'TST-ALL-3');

            try {
                Allocation::reserve([
                    'product_id' => $productId,
                    'quantity' => 3,
                    'location_id' => $sede,
                    'order_id' => ordineDiProva(),
                ]);
            } catch (UserError $errore) {
                return $errore->getMessage() !== '';
            }

            return false;
        });

        check('due prenotazioni di fila non superano il disponibile', function () use ($sede) {
            $productId = articoloConGiacenza(2, 'TST-ALL-4');
            $ordine = ordineDiProva();

            Allocation::reserve([
                'product_id' => $productId,
                'quantity' => 2,
                'location_id' => $sede,
                'order_id' => $ordine,
            ]);

            try {
                Allocation::reserve([
                    'product_id' => $productId,
                    'quantity' => 1,
                    'location_id' => $sede,
                    'order_id' => ordineDiProva(),
                ]);
            } catch (UserError) {
                return Levels::of($productId)['available'] === 0.0;
            }

            return false;
        });

        check('una prenotazione di zero pezzi non si scrive', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-ALL-5');

            try {
                Allocation::reserve([
                    'product_id' => $productId,
                    'quantity' => 0,
                    'location_id' => $sede,
                    'order_id' => ordineDiProva(),
                ]);
            } catch (UserError) {
                return Levels::of($productId)['reserved'] === 0.0;
            }

            return false;
        });

        check('una prenotazione negativa non si scrive', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-ALL-6');

            try {
                Allocation::reserve([
                    'product_id' => $productId,
                    'quantity' => -2,
                    'location_id' => $sede,
                    'order_id' => ordineDiProva(),
                ]);
            } catch (UserError) {
                return Levels::of($productId)['reserved'] === 0.0;
            }

            return false;
        });

        check('il rilascio restituisce il disponibile', function () use ($sede) {
            $productId = articoloConGiacenza(4, 'TST-ALL-7');
            $ordine = ordineDiProva();

            Allocation::reserve([
                'product_id' => $productId,
                'quantity' => 4,
                'location_id' => $sede,
                'order_id' => $ordine,
            ]);

            $chiuse = Allocation::release(['order_id' => $ordine]);

            return $chiuse === 1 && Levels::of($productId)['available'] === 4.0;
        });

        check('il rilascio di un ordine non tocca le prenotazioni di un altro', function () use ($sede) {
            $productId = articoloConGiacenza(6, 'TST-ALL-8');
            $mio = ordineDiProva();
            $altrui = ordineDiProva();

            Allocation::reserve(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $mio]);
            Allocation::reserve(['product_id' => $productId, 'quantity' => 3, 'location_id' => $sede, 'order_id' => $altrui]);
            Allocation::release(['order_id' => $mio]);

            return Levels::of($productId)['reserved'] === 3.0;
        });

        check('il rilascio si può restringere a una riga dell\'ordine', function () use ($sede) {
            $productId = articoloConGiacenza(6, 'TST-ALL-9');
            $ordine = ordineDiProva();

            Allocation::reserve(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $ordine, 'order_item_id' => 11]);
            Allocation::reserve(['product_id' => $productId, 'quantity' => 1, 'location_id' => $sede, 'order_id' => $ordine, 'order_item_id' => 22]);

            $chiuse = Allocation::release(['order_id' => $ordine, 'order_item_id' => 11]);

            return $chiuse === 1 && Levels::of($productId)['reserved'] === 1.0;
        });

        check('rilasciare due volte non chiude niente la seconda', function () use ($sede) {
            $productId = articoloConGiacenza(3, 'TST-ALL-10');
            $ordine = ordineDiProva();

            Allocation::reserve(['product_id' => $productId, 'quantity' => 3, 'location_id' => $sede, 'order_id' => $ordine]);
            Allocation::release(['order_id' => $ordine]);

            return Allocation::release(['order_id' => $ordine]) === 0;
        });

        check('una prenotazione scaduta non impegna più niente', function () use ($sede) {
            $productId = articoloConGiacenza(3, 'TST-ALL-11');

            Allocation::reserve([
                'product_id' => $productId,
                'quantity' => 3,
                'location_id' => $sede,
                'order_id' => ordineDiProva(),
                'expires_at' => date('Y-m-d H:i:s', time() - 60),
            ]);

            return Levels::of($productId)['available'] === 3.0;
        });

        check('un articolo che non esiste non si prenota', function () use ($sede) {
            try {
                Allocation::reserve(['product_id' => 0, 'quantity' => 1, 'location_id' => $sede, 'order_id' => 0]);
            } catch (UserError) {
                return true;
            }

            return false;
        });

        summary();

        throw new Annulla('Fine della prova: niente resta scritto.');
    });
} catch (Annulla) {
    // Tutto annullato: era una prova.
}
