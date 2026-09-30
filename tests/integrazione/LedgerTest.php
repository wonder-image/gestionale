<?php
/** php tests/integrazione/LedgerTest.php */
declare(strict_types=1);

/** I pagamenti: incassi, rimborsi e lo stato che ne discende. */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/incassa.php';

try {
    Transaction::run(static function (): void {
        check('il pagamento aperto lascia l\'ordine in attesa', function () {
            $ordine = ordineDiProva(100.0);
            $esito = Ledger::open(['order_id' => $ordine, 'amount' => 100.0]);

            $riga = Payment::findById($esito['payment_id']);

            return $esito['created'] === true
                && $esito['payment_status'] === 'pending'
                && is_array($riga)
                && $riga['status'] === 'pending'
                && $riga['type'] === 'payment'
                && statoPagamento($ordine) === 'pending';
        });

        check('l\'incasso pieno segna l\'ordine pagato', function () {
            $ordine = ordineDiProva(100.0);
            $esito = Ledger::register([
                'order_id' => $ordine,
                'amount' => 100.0,
                'provider' => 'stripe',
                'provider_reference' => 'pi_'.uniqid(),
            ]);

            $riga = Payment::findById($esito['payment_id']);

            return $esito['payment_status'] === 'paid'
                && is_array($riga)
                && $riga['status'] === 'paid'
                && trim((string) $riga['paid_at']) !== ''
                && statoPagamento($ordine) === 'paid';
        });

        check('un acconto lascia l\'ordine pagato in parte', function () {
            $ordine = ordineDiProva(100.0);
            $esito = Ledger::register(['order_id' => $ordine, 'amount' => 40.0]);

            return $esito['payment_status'] === 'partially_paid'
                && statoPagamento($ordine) === 'partially_paid';
        });

        check('l\'incasso chiude un pagamento già aperto invece di aggiungerne un altro', function () {
            $ordine = ordineDiProva(100.0);
            $riferimento = 'pi_'.uniqid();

            $aperto = Ledger::open([
                'order_id' => $ordine,
                'amount' => 100.0,
                'provider' => 'stripe',
                'provider_reference' => $riferimento,
            ]);
            $incassato = Ledger::register([
                'order_id' => $ordine,
                'amount' => 100.0,
                'provider' => 'stripe',
                'provider_reference' => $riferimento,
            ]);

            $righe = sqlCount(Payment::$table, "order_id = {$ordine}");

            return $incassato['payment_id'] === $aperto['payment_id']
                && $incassato['created'] === false
                && (int) $righe === 1
                && statoPagamento($ordine) === 'paid';
        });

        check('la stessa notifica due volte non incassa due volte', function () {
            $ordine = ordineDiProva(100.0);
            $riferimento = 'pi_'.uniqid();
            $dati = [
                'order_id' => $ordine,
                'amount' => 100.0,
                'provider' => 'stripe',
                'provider_reference' => $riferimento,
            ];

            $prima = Ledger::register($dati);
            $seconda = Ledger::register($dati);

            return $seconda['payment_id'] === $prima['payment_id']
                && $seconda['created'] === false
                && (int) sqlCount(Payment::$table, "order_id = {$ordine}") === 1
                && statoPagamento($ordine) === 'paid';
        });

        check('due incassi a mano senza riferimento restano due incassi', function () {
            $ordine = ordineDiProva(100.0);

            $prima = Ledger::register(['order_id' => $ordine, 'amount' => 50.0]);
            $seconda = Ledger::register(['order_id' => $ordine, 'amount' => 50.0]);

            return $seconda['payment_id'] !== $prima['payment_id']
                && $seconda['created'] === true
                && statoPagamento($ordine) === 'paid';
        });

        check('l\'incasso senza riferimento si firma con il proprio codice', function () {
            $esito = Ledger::register(['order_id' => ordineDiProva(30.0), 'amount' => 30.0]);
            $riga = Payment::findById($esito['payment_id']);

            return is_array($riga)
                && $riga['provider'] === 'manual'
                && $riga['provider_reference'] === $riga['code']
                && str_starts_with((string) $riga['code'], 'pay_');
        });

        check('un incasso di zero non si registra', function () {
            $ordine = ordineDiProva(100.0);

            try {
                Ledger::register(['order_id' => $ordine, 'amount' => 0]);
            } catch (UserError) {
                return (int) sqlCount(Payment::$table, "order_id = {$ordine}") === 0;
            }

            return false;
        });

        check('il pagamento fallito riporta l\'ordine a non pagato', function () {
            $ordine = ordineDiProva(100.0);
            $aperto = Ledger::open(['order_id' => $ordine, 'amount' => 100.0]);

            $stato = Ledger::fail($aperto['payment_id'], 'carta rifiutata');
            $riga = Payment::findById($aperto['payment_id']);

            return $stato === 'unpaid'
                && is_array($riga)
                && $riga['status'] === 'failed'
                && statoPagamento($ordine) === 'unpaid';
        });

        check('il rimborso pieno segna l\'ordine rimborsato', function () {
            $ordine = ordineDiProva(100.0);
            Ledger::register(['order_id' => $ordine, 'amount' => 100.0]);

            $esito = Ledger::refund(['order_id' => $ordine, 'amount' => 100.0]);
            $riga = Payment::findById($esito['payment_id']);

            return $esito['payment_status'] === 'refunded'
                && is_array($riga)
                && $riga['type'] === 'refund'
                && $riga['status'] === 'paid'
                && statoPagamento($ordine) === 'refunded';
        });

        check('il rimborso di una parte lascia l\'ordine rimborsato in parte', function () {
            $ordine = ordineDiProva(100.0);
            Ledger::register(['order_id' => $ordine, 'amount' => 100.0]);

            return Ledger::refund(['order_id' => $ordine, 'amount' => 30.0])['payment_status'] === 'partially_refunded'
                && statoPagamento($ordine) === 'partially_refunded';
        });

        check('l\'ordine a importo zero è pagato senza incassi', function () {
            $ordine = ordineDiProva(0.0);

            return Ledger::sync($ordine) === 'paid' && statoPagamento($ordine) === 'paid';
        });

        check('il cambio di stato dell\'ordine finisce nel registro', function () {
            $ordine = ordineDiProva(100.0);
            Ledger::register(['order_id' => $ordine, 'amount' => 100.0]);

            $log = ultimoLog($ordine, 'payment_status');

            return $log !== []
                && $log['from_value'] === 'unpaid'
                && $log['to_value'] === 'paid'
                && $log['source'] === 'system';
        });

        check('lo stato che non cambia non scrive righe nel registro', function () {
            $ordine = ordineDiProva(100.0);
            Ledger::register(['order_id' => $ordine, 'amount' => 100.0]);
            Ledger::sync($ordine);
            Ledger::sync($ordine);

            $righe = sqlCount(OrderStatusLog::$table, "order_id = {$ordine} AND field = 'payment_status'");

            return (int) $righe === 1;
        });

        check('anche il pagamento ha la sua storia', function () {
            $ordine = ordineDiProva(100.0);
            $aperto = Ledger::open(['order_id' => $ordine, 'amount' => 100.0]);
            Ledger::fail($aperto['payment_id'], 'carta rifiutata');

            $righe = sqlSelect(PaymentStatusLog::$table, 'payment_id = '.$aperto['payment_id'])->row;

            return is_array($righe) && $righe !== [];
        });

        throw new Annulla();
    });
} catch (Annulla) {
    // Il database torna com'era.
}

summary();
