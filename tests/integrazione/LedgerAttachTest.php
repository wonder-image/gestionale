<?php
/** php tests/integrazione/LedgerAttachTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';
require __DIR__ . '/supporto/compra.php';

use Wonder\Sql\Transaction;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;

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

/** Ordine di prova con il suo pagamento stripe in attesa, come lo lascia `Checkout::place`. */
function aperto(float $totale = 50.0): array
{
    $ordine = ordineDiProva($totale);
    $pagamento = Ledger::open(['order_id' => $ordine, 'amount' => $totale, 'provider' => 'stripe'])['payment_id'];

    return [$ordine, $pagamento];
}

check('un pagamento nuovo nasce in produzione', fn () => prova(static function (): bool {
    [, $pagamento] = aperto();

    return (Payment::findById($pagamento)['environment'] ?? '') === 'live';
}));

check('attach scrive riferimento e ambiente sulla riga in attesa', fn () => prova(static function (): bool {
    [, $pagamento] = aperto();
    Ledger::attach($pagamento, 'stripe', 'pi_prova_1', 'test');
    $riga = Payment::findById($pagamento);

    return ($riga['provider_reference'] ?? '') === 'pi_prova_1'
        && ($riga['environment'] ?? '') === 'test'
        && ($riga['status'] ?? '') === 'pending';
}));

check('byReference trova la riga attaccata', fn () => prova(static function (): bool {
    [, $pagamento] = aperto();
    Ledger::attach($pagamento, 'stripe', 'pi_prova_2', 'test');

    return (int) (Ledger::byReference('stripe', 'pi_prova_2')['id'] ?? 0) === $pagamento
        && Ledger::byReference('stripe', 'pi_mai_visto') === null;
}));

check('la conferma con lo stesso pi_ chiude quella riga, senza aprirne una seconda', fn () => prova(static function (): bool {
    [$ordine, $pagamento] = aperto(50.0);
    Ledger::attach($pagamento, 'stripe', 'pi_prova_3', 'test');
    $esito = Ledger::register(['order_id' => $ordine, 'amount' => 50.0, 'provider' => 'stripe', 'provider_reference' => 'pi_prova_3', 'source' => 'webhook']);
    $righe = Payment::find(['order_id' => $ordine, 'deleted' => 'false']);

    return $esito['payment_id'] === $pagamento
        && $esito['created'] === false
        && is_array($righe)
        && count($righe) === 1
        && ($righe[0]['status'] ?? '') === 'paid'
        && ($righe[0]['environment'] ?? '') === 'test';
}));

check('una riga fallita si può riattaccare: la carta rifiutata lascia l\'intento riusabile', fn () => prova(static function (): bool {
    [, $pagamento] = aperto();
    Ledger::attach($pagamento, 'stripe', 'pi_prova_4', 'test');
    Ledger::fail($pagamento, 'Carta rifiutata');
    Ledger::attach($pagamento, 'stripe', 'pi_prova_5', 'test');

    return (Payment::findById($pagamento)['provider_reference'] ?? '') === 'pi_prova_5';
}));

check('una riga pagata non si riattacca', fn () => prova(static function (): bool {
    [$ordine, $pagamento] = aperto(50.0);
    Ledger::register(['order_id' => $ordine, 'amount' => 50.0, 'provider' => 'stripe', 'provider_reference' => 'pi_prova_6']);

    try {
        Ledger::attach($pagamento, 'stripe', 'pi_altro', 'test');
    } catch (RuntimeException) {
        return (Payment::findById($pagamento)['provider_reference'] ?? '') === 'pi_prova_6';
    }

    return false;
}));

summary();
