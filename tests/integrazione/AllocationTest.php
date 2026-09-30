<?php
/** php tests/integrazione/AllocationTest.php */
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
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Models\System\Feature;
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

/** Un reso di prova sull'ordine dato: serve un id vero per i movimenti. */
function resoDiProva(int $ordine, int $sede): int
{
    $reso = SalesReturn::create([
        'code' => Code::make(SalesReturn::class, Codes::SALES_RETURN),
        'number' => 'RES/'.date('Y').'/'.substr((string) microtime(true), -6),
        'order_id' => $ordine,
        'location_id' => $sede,
        'status' => 'received',
        'received_at' => date('Y-m-d H:i:s'),
    ]);

    return (int) ($reso->insert_id ?? 0);
}

/**
 * Accende delle funzionalità per la prova.
 *
 * Lo stato sta nel database e `Gestionale` lo tiene in cache: dopo la
 * scrittura la cache va buttata, altrimenti si continua a leggere quella di
 * prima. Tutto dentro la transazione, quindi alla fine non resta niente.
 *
 * @param list<string> $chiavi
 */
function accendiFunzionalita(array $chiavi): void
{
    foreach ($chiavi as $chiave) {
        $riga = Feature::find(['feature_key' => $chiave, 'deleted' => 'false'], 1);

        if (is_array($riga) && isset($riga['id'])) {
            Feature::update(['enabled' => 'true'], (int) $riga['id']);
        } else {
            Feature::create(['feature_key' => $chiave, 'enabled' => 'true']);
        }
    }

    Gestionale::reset();
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

        check('lo scarico consuma la prenotazione e abbassa la giacenza', function () use ($sede) {
            $productId = articoloConGiacenza(10, 'TST-CMT-1');
            $ordine = ordineDiProva();

            Allocation::reserve(['product_id' => $productId, 'quantity' => 4, 'location_id' => $sede, 'order_id' => $ordine]);
            $esito = Allocation::commit([
                'product_id' => $productId,
                'quantity' => 4,
                'location_id' => $sede,
                'order_id' => $ordine,
            ]);

            $livelli = Levels::of($productId);

            return $esito['movement_id'] > 0
                && $esito['after'] === 6.0
                && $esito['oversold'] === false
                && $livelli['quantity'] === 6.0
                && $livelli['reserved'] === 0.0;
        });

        check('lo scarico scrive un movimento di vendita legato all\'ordine', function () use ($sede) {
            $productId = articoloConGiacenza(3, 'TST-CMT-2');
            $ordine = ordineDiProva();

            Allocation::reserve(['product_id' => $productId, 'quantity' => 1, 'location_id' => $sede, 'order_id' => $ordine]);
            $esito = Allocation::commit(['product_id' => $productId, 'quantity' => 1, 'location_id' => $sede, 'order_id' => $ordine]);

            $movimento = StockMovement::findById($esito['movement_id']);

            return is_array($movimento)
                && $movimento['type'] === 'sale'
                && $movimento['reference_type'] === 'order'
                && (int) $movimento['reference_id'] === $ordine
                && (float) $movimento['quantity'] === -1.0;
        });

        check('con la prenotazione in mano si scarica anche a magazzino vuoto', function () use ($sede) {
            $productId = articoloConGiacenza(1, 'TST-CMT-3');
            $ordine = ordineDiProva();

            Allocation::reserve(['product_id' => $productId, 'quantity' => 1, 'location_id' => $sede, 'order_id' => $ordine]);

            // Intanto qualcuno rompe l'ultimo pezzo e lo toglie dal magazzino.
            Stock::apply(['product_id' => $productId, 'quantity' => -1, 'reason' => 'damaged']);

            $esito = Allocation::commit(['product_id' => $productId, 'quantity' => 1, 'location_id' => $sede, 'order_id' => $ordine]);

            return $esito['after'] === -1.0 && $esito['oversold'] === true;
        });

        check('senza prenotazione ma con merce lo scarico è normale', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-CMT-4');

            $esito = Allocation::commit([
                'product_id' => $productId,
                'quantity' => 2,
                'location_id' => $sede,
                'order_id' => ordineDiProva(),
            ]);

            return $esito['after'] === 3.0
                && $esito['reserved'] === 0.0
                && $esito['merchant_alert'] === false;
        });

        check('senza prenotazione, senza merce e senza pagamento l\'ordine resta in attesa', function () use ($sede) {
            $productId = articoloConGiacenza(1, 'TST-CMT-5');

            try {
                Allocation::commit([
                    'product_id' => $productId,
                    'quantity' => 2,
                    'location_id' => $sede,
                    'order_id' => ordineDiProva(),
                    'payment_ok' => false,
                ]);
            } catch (UserError $errore) {
                // Niente scarico: la giacenza è rimasta quella di prima.
                return Levels::of($productId)['quantity'] === 1.0
                    && $errore->getMessage() !== '';
            }

            return false;
        });

        check('un ordine già pagato si scarica lo stesso e avvisa il commerciante', function () use ($sede) {
            $productId = articoloConGiacenza(1, 'TST-CMT-6');
            $ordine = ordineDiProva();

            $esito = Allocation::commit([
                'product_id' => $productId,
                'quantity' => 3,
                'location_id' => $sede,
                'order_id' => $ordine,
                'payment_ok' => true,
            ]);

            $log = OrderStatusLog::find("order_id = {$ordine} AND field = 'stock'");

            return $esito['after'] === -2.0
                && $esito['oversold'] === true
                && $esito['merchant_alert'] === true
                && is_array($log) && $log !== [];
        });

        check('lo scarico di zero pezzi non si fa', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-CMT-7');

            try {
                Allocation::commit(['product_id' => $productId, 'quantity' => 0, 'location_id' => $sede, 'order_id' => ordineDiProva()]);
            } catch (UserError) {
                return Levels::of($productId)['quantity'] === 5.0;
            }

            return false;
        });

        check('la prenotazione consumata non serve una seconda volta', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-CMT-8');
            $ordine = ordineDiProva();

            Allocation::reserve(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $ordine]);
            Allocation::commit(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $ordine]);
            $secondo = Allocation::commit(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $ordine]);

            // Il secondo scarico c'è comunque — la merce c'era — ma senza
            // prenotazione da consumare.
            return $secondo['reserved'] === 0.0 && Levels::of($productId)['quantity'] === 1.0;
        });

        check('con la vendita senza giacenza accesa si scarica sotto zero senza avvisi', function () use ($sede) {
            $productId = articoloConGiacenza(1, 'TST-CMT-9');
            Product::update(['allow_backorder' => 'true'], $productId);
            accendiFunzionalita(['orders', 'backorders']);

            if (!Gestionale::feature('backorders')) {
                return false;
            }

            $esito = Allocation::commit([
                'product_id' => $productId,
                'quantity' => 4,
                'location_id' => $sede,
                'order_id' => ordineDiProva(),
            ]);

            // La cache delle funzionalità resta accesa per questo processo:
            // spegnila subito, così i prossimi check ripartono puliti.
            Gestionale::reset();

            return $esito['after'] === -3.0
                && $esito['oversold'] === true
                && $esito['merchant_alert'] === false;
        });

        check('l\'annullamento fa rientrare la merce con un movimento di vendita annullata', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-RST-1');
            $ordine = ordineDiProva();

            Allocation::commit(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $ordine]);
            $esito = Allocation::restore(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $ordine]);

            $movimento = StockMovement::findById($esito['movement_id']);

            return $esito['after'] === 5.0
                && is_array($movimento)
                && $movimento['type'] === 'sale_cancel'
                && $movimento['reference_type'] === 'order'
                && (int) $movimento['reference_id'] === $ordine;
        });

        check('l\'annullamento risana anche una giacenza sotto zero', function () use ($sede) {
            $productId = articoloConGiacenza(1, 'TST-RST-2');
            $ordine = ordineDiProva();

            Allocation::commit(['product_id' => $productId, 'quantity' => 3, 'location_id' => $sede, 'order_id' => $ordine, 'payment_ok' => true]);
            $esito = Allocation::restore(['product_id' => $productId, 'quantity' => 3, 'location_id' => $sede, 'order_id' => $ordine]);

            return $esito['before'] === -2.0 && $esito['after'] === 1.0;
        });

        check('l\'annullamento di zero pezzi non si fa', function () use ($sede) {
            $productId = articoloConGiacenza(4, 'TST-RST-3');

            try {
                Allocation::restore(['product_id' => $productId, 'quantity' => 0, 'location_id' => $sede, 'order_id' => ordineDiProva()]);
            } catch (UserError) {
                return Levels::of($productId)['quantity'] === 4.0;
            }

            return false;
        });

        check('il reso rimette la merce in magazzino', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-RET-1');
            $ordine = ordineDiProva();
            $reso = resoDiProva($ordine, $sede);

            Allocation::commit(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $ordine]);
            $esito = Allocation::returnGoods([
                'product_id' => $productId,
                'quantity' => 2,
                'location_id' => $sede,
                'order_id' => $ordine,
                'sales_return_id' => $reso,
                'restock' => true,
            ]);

            $movimento = StockMovement::findById($esito['movement_id']);

            return $esito['restocked'] === true
                && $esito['after'] === 5.0
                && is_array($movimento)
                && $movimento['type'] === 'return'
                && $movimento['reference_type'] === 'sales_return'
                && (int) $movimento['reference_id'] === $reso;
        });

        check('la merce rotta non rientra in magazzino', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-RET-2');
            $ordine = ordineDiProva();
            $reso = resoDiProva($ordine, $sede);

            Allocation::commit(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $ordine]);
            $esito = Allocation::returnGoods([
                'product_id' => $productId,
                'quantity' => 2,
                'location_id' => $sede,
                'order_id' => $ordine,
                'sales_return_id' => $reso,
                'restock' => false,
            ]);

            return $esito['restocked'] === false
                && $esito['movement_id'] === 0
                && Levels::of($productId)['quantity'] === 3.0;
        });

        check('il reso senza ricarico non scrive nessun movimento', function () use ($sede) {
            $productId = articoloConGiacenza(2, 'TST-RET-3');
            $ordine = ordineDiProva();
            $reso = resoDiProva($ordine, $sede);

            Allocation::returnGoods([
                'product_id' => $productId,
                'quantity' => 1,
                'location_id' => $sede,
                'order_id' => $ordine,
                'sales_return_id' => $reso,
                'restock' => false,
            ]);

            $movimenti = StockMovement::find("product_id = {$productId} AND type = 'return'");

            return !is_array($movimenti) || $movimenti === [];
        });

        check('il reso di zero pezzi non si fa', function () use ($sede) {
            $productId = articoloConGiacenza(4, 'TST-RET-4');
            $ordine = ordineDiProva();

            try {
                Allocation::returnGoods([
                    'product_id' => $productId,
                    'quantity' => 0,
                    'location_id' => $sede,
                    'order_id' => $ordine,
                    'sales_return_id' => resoDiProva($ordine, $sede),
                ]);
            } catch (UserError) {
                return Levels::of($productId)['quantity'] === 4.0;
            }

            return false;
        });

        summary();

        throw new Annulla('Fine della prova: niente resta scritto.');
    });
} catch (Annulla) {
    // Tutto annullato: era una prova.
}
