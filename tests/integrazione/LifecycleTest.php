<?php
/** php tests/integrazione/LifecycleTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
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

summary();
