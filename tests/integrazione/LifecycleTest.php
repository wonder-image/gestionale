<?php
/** php tests/integrazione/LifecycleTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

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

    return $esito;
}

/**
 * Un ordine in attesa, con la merce già prenotata: il punto in cui il
 * checkout lo lascia.
 *
 * @return array{0: int, 1: int}
 */
function ordinePrenotato(float $pezzi = 5, float $quantita = 2, float $totale = 50.0): array
{
    $prodotto = articoloConGiacenza($pezzi, 'TST-VITA-'.substr((string) microtime(true), -6));
    $ordine = ordineDiProva($totale);
    Order::update(['email' => 'cliente@example.com', 'ordered_at' => date('Y-m-d H:i:s')], $ordine);
    $riga = OrderItem::create([
        'order_id' => $ordine,
        'type' => 'product',
        'product_id' => $prodotto,
        'position' => 1,
        'name' => 'Crema da prova',
        'quantity' => number_format($quantita, 3, '.', ''),
        'unit_price' => '25.00',
        'line_total' => number_format($totale, 2, '.', ''),
    ]);

    Allocation::reserve([
        'product_id' => $prodotto,
        'quantity' => $quantita,
        'order_id' => $ordine,
        'order_item_id' => (int) ($riga->insert_id ?? 0),
    ]);

    return [$ordine, $prodotto];
}

/**
 * Un ordine in attesa con una confezione: una madre da 2 pezzi e due figlie,
 * con la merce delle sole figlie prenotata.
 *
 * @return array{order: int, mother: int, children: list<array{item: int, product: int}>}
 */
function ordineConfezione(): array
{
    $madre = articoloConGiacenza(9, 'TST-CONF-M'.substr((string) microtime(true), -6));
    $ordine = ordineDiProva(50.0);
    Order::update(['email' => 'cliente@example.com', 'ordered_at' => date('Y-m-d H:i:s')], $ordine);
    $rigaMadre = (int) (OrderItem::create([
        'order_id' => $ordine, 'type' => 'product', 'product_id' => $madre, 'position' => 1,
        'name' => 'Confezione', 'quantity' => '2.000', 'unit_price' => '25.00', 'line_total' => '50.00',
    ])->insert_id ?? 0);
    $figlie = [];

    foreach ([1 => 4.0, 2 => 2.0] as $posizione => $quantita) {
        $prodotto = articoloConGiacenza(20, 'TST-CONF-F'.$posizione.substr((string) microtime(true), -5));
        $riga = (int) (OrderItem::create([
            'order_id' => $ordine, 'type' => 'product', 'product_id' => $prodotto, 'position' => 1 + $posizione,
            'parent_item_id' => $rigaMadre, 'name' => 'Componente '.$posizione,
            'quantity' => number_format($quantita, 3, '.', ''), 'unit_price' => '0.00', 'line_total' => '0.00',
        ])->insert_id ?? 0);
        Allocation::reserve(['product_id' => $prodotto, 'quantity' => $quantita, 'order_id' => $ordine, 'order_item_id' => $riga]);
        $figlie[] = ['item' => $riga, 'product' => $prodotto];
    }

    return ['order' => $ordine, 'mother' => $madre, 'children' => $figlie];
}

/** Le email non devono uscire dalla prova. */
function senzaPosta(callable $corpo): mixed
{
    Mailer::useTransport(static fn (): bool => true);

    try {
        return $corpo();
    } finally {
        Mailer::useTransport(null);
    }
}

check('la conferma incassa, scarica e porta l\'ordine a confermato', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordinePrenotato();
        $prima = Levels::of($prodotto);

        $esito = senzaPosta(static fn (): array => Lifecycle::confirm($ordine, [
            'provider' => 'stripe',
            'provider_reference' => 'pi_'.uniqid(),
        ]));

        $dopo = Levels::of($prodotto);

        return $esito['status'] === 'confirmed'
            && $esito['payment_status'] === 'paid'
            && $esito['committed'] === 1
            && $dopo['quantity'] === round($prima['quantity'] - 2, 3)
            && $dopo['reserved'] === 0.0;
    });
});

check('confermare due volte non scarica due volte', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordinePrenotato();
        $riferimento = 'pi_'.uniqid();

        senzaPosta(static fn (): array => Lifecycle::confirm($ordine, [
            'provider' => 'stripe',
            'provider_reference' => $riferimento,
        ]));
        $giacenza = Levels::of($prodotto)['quantity'];
        $seconda = senzaPosta(static fn (): array => Lifecycle::confirm($ordine, [
            'provider' => 'stripe',
            'provider_reference' => $riferimento,
        ]));

        return $seconda['changed'] === false
            && $seconda['status'] === 'confirmed'
            && Levels::of($prodotto)['quantity'] === $giacenza;
    });
});

check('il contrassegno conferma con il pagamento ancora in attesa', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordinePrenotato();

        $esito = senzaPosta(static fn (): array => Lifecycle::confirm($ordine, ['payment' => false]));

        return $esito['status'] === 'confirmed'
            && $esito['payment_status'] !== 'paid'
            && Levels::of($prodotto)['quantity'] === 3.0;
    });
});

check('annullato prima della conferma: la merce torna libera, la giacenza non si muove', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordinePrenotato();
        $prima = Levels::of($prodotto);

        $esito = senzaPosta(static fn (): array => Lifecycle::cancel($ordine, ['reason' => 'a mano']));
        $dopo = Levels::of($prodotto);

        return $esito['status'] === 'cancelled'
            && $esito['released'] === 1
            && $esito['restored'] === 0
            && $dopo['quantity'] === $prima['quantity']
            && $dopo['available'] === $prima['quantity']
            && (int) sqlCount(StockReservation::$table, "order_id = {$ordine} AND released_at IS NULL AND deleted = 'false'") === 0;
    });
});

check('annullato dopo la conferma: la merce rientra a magazzino', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordinePrenotato();
        senzaPosta(static fn (): array => Lifecycle::confirm($ordine, ['payment' => false]));
        $scaricato = Levels::of($prodotto)['quantity'];

        $esito = senzaPosta(static fn (): array => Lifecycle::cancel($ordine));

        return $esito['status'] === 'cancelled'
            && $esito['restored'] === 1
            && Levels::of($prodotto)['quantity'] === round($scaricato + 2, 3);
    });
});

check('annullare due volte non rimette la merce due volte', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordinePrenotato();
        senzaPosta(static fn (): array => Lifecycle::confirm($ordine, ['payment' => false]));
        senzaPosta(static fn (): array => Lifecycle::cancel($ordine));
        $giacenza = Levels::of($prodotto)['quantity'];

        $seconda = senzaPosta(static fn (): array => Lifecycle::cancel($ordine));

        return $seconda['changed'] === false && Levels::of($prodotto)['quantity'] === $giacenza;
    });
});

check('un ordine annullato non si può confermare', function () {
    return prova(static function (): bool {
        [$ordine] = ordinePrenotato();
        senzaPosta(static fn (): array => Lifecycle::cancel($ordine));

        try {
            senzaPosta(static fn (): array => Lifecycle::confirm($ordine, ['payment' => false]));
        } catch (UserError) {
            return true;
        }

        return false;
    });
});

check('pagato ed evaso si chiude da solo', function () {
    return prova(static function (): bool {
        [$ordine] = ordinePrenotato();
        senzaPosta(static fn (): array => Lifecycle::confirm($ordine, [
            'provider' => 'stripe',
            'provider_reference' => 'pi_'.uniqid(),
        ]));

        $evaso = Lifecycle::fulfill($ordine, 'fulfilled');
        $riga = Order::findById($ordine);

        // `fulfill()` chiude l'ordine da sé: chi lavora gli ordini segna
        // «evaso» e non deve ricordarsi di un secondo passaggio.
        return $evaso['fulfillment_status'] === 'fulfilled'
            && $evaso['status'] === 'completed'
            && trim((string) $riga['completed_at']) !== '';
    });
});

check('l\'evasione a metà non chiude l\'ordine e resta scritta nella storia', function () {
    return prova(static function (): bool {
        [$ordine] = ordinePrenotato();
        senzaPosta(static fn (): array => Lifecycle::confirm($ordine, [
            'provider' => 'stripe',
            'provider_reference' => 'pi_'.uniqid(),
        ]));

        $primo = Lifecycle::fulfill($ordine, 'partially_fulfilled', ['user_id' => 1]);
        // Ripetere lo stesso stato non scrive una seconda riga di storia.
        $secondo = Lifecycle::fulfill($ordine, 'partially_fulfilled');

        $righe = (int) sqlCount(
            OrderStatusLog::$table,
            "order_id = {$ordine} AND field = 'fulfillment_status' AND deleted = 'false'"
        );

        return $primo['status'] === 'confirmed'
            && $primo['changed'] === true
            && $secondo['changed'] === false
            && $righe === 1;
    });
});

check('uno stato di evasione inventato si rifiuta', function () {
    return prova(static function (): bool {
        [$ordine] = ordinePrenotato();

        try {
            Lifecycle::fulfill($ordine, 'spedito');
        } catch (UserError) {
            return true;
        }

        return false;
    });
});

check('l\'annullamento dice quanto denaro resta da restituire', function () {
    return prova(static function (): bool {
        [$ordine] = ordinePrenotato();
        senzaPosta(static fn (): array => Lifecycle::confirm($ordine, [
            'provider' => 'stripe',
            'provider_reference' => 'pi_'.uniqid(),
        ]));

        return senzaPosta(static fn (): array => Lifecycle::cancel($ordine))['refundable'] === '50.00';
    });
});

check('il pagamento che arriva dopo l\'evasione chiude l\'ordine', function () {
    return prova(static function (): bool {
        [$ordine] = ordinePrenotato();
        senzaPosta(static fn (): array => Lifecycle::confirm($ordine, ['payment' => false]));
        $evaso = Lifecycle::fulfill($ordine, 'fulfilled');

        // Evaso ma non pagato: l'ordine aspetta i soldi.
        $aspetta = $evaso['status'] === 'confirmed';

        Ledger::register(['order_id' => $ordine, 'amount' => 50.0]);
        $riga = Order::findById($ordine);

        return $aspetta
            && $riga['payment_status'] === 'paid'
            && $riga['status'] === 'completed'
            && trim((string) $riga['completed_at']) !== ''
            && (int) sqlCount(
                OrderStatusLog::$table,
                "order_id = {$ordine} AND field = 'status' AND to_value = 'completed' AND deleted = 'false'"
            ) === 1;
    });
});

check('il pagamento che arriva prima dell\'evasione non chiude l\'ordine', function () {
    return prova(static function (): bool {
        [$ordine] = ordinePrenotato();
        senzaPosta(static fn (): array => Lifecycle::confirm($ordine, ['payment' => false]));

        Ledger::register(['order_id' => $ordine, 'amount' => 50.0]);

        return Order::findById($ordine)['status'] === 'confirmed';
    });
});

check('la conferma di una confezione scarica le figlie e non la madre', function () {
    return prova(static function (): bool {
        $c = ordineConfezione();
        $madre = Levels::of($c['mother'])['quantity'];
        $prime = array_map(static fn (array $f): float => Levels::of($f['product'])['quantity'], $c['children']);

        $esito = senzaPosta(static fn (): array => Lifecycle::confirm($c['order'], ['payment' => false]));

        return $esito['committed'] === 2
            && Levels::of($c['mother'])['quantity'] === $madre
            && Levels::of($c['children'][0]['product'])['quantity'] === round($prime[0] - 4, 3)
            && Levels::of($c['children'][1]['product'])['quantity'] === round($prime[1] - 2, 3);
    });
});

check('annullare una confezione dopo il reso di una figlia rimette quantità meno reso', function () {
    return prova(static function (): bool {
        $c = ordineConfezione();
        senzaPosta(static fn (): array => Lifecycle::confirm($c['order'], ['payment' => false]));
        $reso = resoDiProva($c['order'], Locations::mainId());
        SalesReturnItem::create(['sales_return_id' => $reso, 'order_item_id' => $c['children'][0]['item'], 'quantity' => '1.000']);
        $madre = Levels::of($c['mother'])['quantity'];
        $prima = array_map(static fn (array $f): float => Levels::of($f['product'])['quantity'], $c['children']);

        $esito = senzaPosta(static fn (): array => Lifecycle::cancel($c['order']));

        return $esito['restored'] === 2
            && Levels::of($c['mother'])['quantity'] === $madre
            && Levels::of($c['children'][0]['product'])['quantity'] === round($prima[0] + 3, 3)
            && Levels::of($c['children'][1]['product'])['quantity'] === round($prima[1] + 2, 3);
    });
});

summary();
