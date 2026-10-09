<?php
/** php tests/integrazione/OnlinePaymentsTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';
require __DIR__ . '/supporto/compra.php';
require __DIR__ . '/supporto/FakePaymentProvider.php';

use Wonder\Sql\Transaction;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Payments\OnlinePayments;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;

final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    }

    return $esito;
}

$finto = new FakePaymentProvider();
PaymentProviders::register($finto);

/** L'ordine come lo lascia `Checkout::place` con un metodo Stripe. */
function ordineStripe(float $totale = 50.0): array
{
    $ordine = ordineDiProva($totale);
    $pagamento = Ledger::open(['order_id' => $ordine, 'amount' => $totale, 'provider' => 'stripe'])['payment_id'];

    return [$ordine, $pagamento];
}

check('start lega l\'intento alla riga aperta, con l\'ambiente del fornitore', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento] = ordineStripe();
    $avvio = OnlinePayments::start($ordine);
    $riga = Payment::findById($pagamento);

    return str_starts_with($avvio->reference, 'pi_finto_')
        && $avvio->clientSecret === $avvio->reference.'_secret_prova'
        && ($riga['provider_reference'] ?? '') === $avvio->reference
        && ($riga['environment'] ?? '') === 'test'
        && (int) (end($finto->started)['payment']['id'] ?? 0) === $pagamento;
}));

check('payment trova la riga online dell\'ordine', fn () => prova(static function (): bool {
    [$ordine, $pagamento] = ordineStripe();

    return (int) (OnlinePayments::payment($ordine)['id'] ?? 0) === $pagamento
        && OnlinePayments::payment(ordineDiProva()) === null;
}));

check('un ordine annullato non riparte', fn () => prova(static function (): bool {
    [$ordine] = ordineStripe();
    Lifecycle::cancel($ordine, ['notify' => false]);

    try {
        OnlinePayments::start($ordine);
    } catch (RuntimeException) {
        return true;
    }

    return false;
}));

check('con il fornitore spento non parte nulla', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento] = ordineStripe();
    $avviati = count($finto->started);
    $finto->connected = false;

    try {
        OnlinePayments::start($ordine);
    } catch (RuntimeException) {
        return !str_starts_with((string) (Payment::findById($pagamento)['provider_reference'] ?? ''), 'pi_')
            && count($finto->started) === $avviati;
    } finally {
        $finto->connected = true;
    }

    return false;
}));

PaymentProviders::reset();
summary();
