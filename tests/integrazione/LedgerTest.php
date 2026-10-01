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

        check('lo stesso riferimento su un altro ordine non dirotta l\'incasso', function () {
            // Una notifica che porta il riferimento di un incasso già di un
            // altro ordine: presa per buona, pagherebbe l'ordine sbagliato e
            // lascerebbe questo senza una riga di denaro.
            $primo = ordineDiProva(100.0);
            $secondo = ordineDiProva(100.0);
            $riferimento = 'pi_'.uniqid();
            $dati = [
                'amount' => 100.0,
                'provider' => 'stripe',
                'provider_reference' => $riferimento,
            ];

            Ledger::register(['order_id' => $primo] + $dati);

            try {
                Ledger::register(['order_id' => $secondo] + $dati);
            } catch (UserError) {
                return (int) sqlCount(Payment::$table, "order_id = {$secondo}") === 0
                    && (int) sqlCount(Payment::$table, "order_id = {$primo}") === 1
                    && statoPagamento($primo) === 'paid'
                    && statoPagamento($secondo) === 'unpaid';
            }

            return false;
        });

        check('il rimborso che porta il riferimento dell\'incasso resta un rimborso', function () {
            // Certi gateway rimandano il riferimento dell'incasso anche quando
            // restituiscono i soldi: se il rimborso si confondesse con quello,
            // il denaro uscito non risulterebbe da nessuna parte.
            $ordine = ordineDiProva(100.0);
            $riferimento = 'pi_'.uniqid();
            $dati = [
                'order_id' => $ordine,
                'amount' => 100.0,
                'provider' => 'stripe',
                'provider_reference' => $riferimento,
            ];

            $incasso = Ledger::register($dati);
            $rimborso = Ledger::refund($dati);

            $riga = Payment::findById($rimborso['payment_id']);

            return $rimborso['payment_id'] !== $incasso['payment_id']
                && $rimborso['created'] === true
                && is_array($riga)
                && $riga['type'] === 'refund'
                && statoPagamento($ordine) === 'refunded';
        });

        check('una cattura minore corregge l\'importo della riga aperta', function () {
            // Si autorizzano cento e se ne incassano sessanta: la riga vale
            // sessanta, e l'ordine non è pagato del tutto.
            $ordine = ordineDiProva(100.0);
            $riferimento = 'pi_'.uniqid();

            Ledger::open([
                'order_id' => $ordine,
                'amount' => 100.0,
                'provider' => 'stripe',
                'provider_reference' => $riferimento,
            ]);
            $incassato = Ledger::register([
                'order_id' => $ordine,
                'amount' => 60.0,
                'provider' => 'stripe',
                'provider_reference' => $riferimento,
            ]);

            $riga = Payment::findById($incassato['payment_id']);

            return $incassato['created'] === false
                && is_array($riga)
                && (float) $riga['amount'] === 60.0
                && $incassato['payment_status'] === 'partially_paid'
                && statoPagamento($ordine) === 'partially_paid';
        });

        check('un pagamento riuscito non si fa dichiarare fallito', function () {
            // La notifica di fallimento che arriva in ritardo, dopo l'incasso:
            // presa per buona, rimetterebbe a «non pagato» un ordine che i
            // soldi li ha già portati.
            $ordine = ordineDiProva(100.0);
            $incassato = Ledger::register(['order_id' => $ordine, 'amount' => 100.0]);

            try {
                Ledger::fail($incassato['payment_id'], 'notifica in ritardo');
            } catch (UserError) {
                $riga = Payment::findById($incassato['payment_id']);

                return is_array($riga)
                    && $riga['status'] === 'paid'
                    && statoPagamento($ordine) === 'paid';
            }

            return false;
        });

        check('un gateway scritto con la maiuscola è lo stesso gateway', function () {
            // Il webhook manda «Stripe», la conferma «stripe»: se fossero due
            // gateway diversi la riga aperta non si ritroverebbe, e l'ordine
            // finirebbe con due incassi per un pagamento solo.
            $ordine = ordineDiProva(100.0);
            $riferimento = 'pi_'.uniqid();

            $aperto = Ledger::open([
                'order_id' => $ordine,
                'amount' => 100.0,
                'provider' => 'Stripe',
                'provider_reference' => $riferimento,
            ]);
            $incassato = Ledger::register([
                'order_id' => $ordine,
                'amount' => 100.0,
                'provider' => 'stripe',
                'provider_reference' => $riferimento,
            ]);

            $riga = Payment::findById($aperto['payment_id']);

            return $incassato['payment_id'] === $aperto['payment_id']
                && $incassato['created'] === false
                && is_array($riga)
                && $riga['provider'] === 'stripe'
                && (int) sqlCount(Payment::$table, "order_id = {$ordine}") === 1;
        });

        check('un gateway che non conosciamo non passa per un incasso a mano', function () {
            // Scritto storto, il gateway diventava «manual»: la riga restava
            // firmata da un altro, e la notifica giusta non la ritrovava più.
            $ordine = ordineDiProva(100.0);

            try {
                Ledger::register([
                    'order_id' => $ordine,
                    'amount' => 100.0,
                    'provider' => 'stripee',
                    'provider_reference' => 'pi_'.uniqid(),
                ]);
            } catch (UserError) {
                return (int) sqlCount(Payment::$table, "order_id = {$ordine}") === 0;
            }

            return false;
        });

        check('la data dell\'incasso passata si scrive, senza resta «adesso»', function () {
            $ieri = date('Y-m-d', strtotime('-3 days'));
            $a = ordineDiProva(50.0);
            $b = ordineDiProva(50.0);
            $conData = Ledger::register(['order_id' => $a, 'amount' => 50.0, 'paid_at' => $ieri]);
            $senza = Ledger::register(['order_id' => $b, 'amount' => 50.0]);

            return str_starts_with((string) Payment::findById($conData['payment_id'])['paid_at'], $ieri)
                && str_starts_with((string) Payment::findById($senza['payment_id'])['paid_at'], date('Y-m-d'));
        });

        check('la data dell\'incasso vale anche quando chiude un pagamento già aperto', function () {
            $ieri = date('Y-m-d', strtotime('-2 days'));
            $ordine = ordineDiProva(50.0);
            $aperto = Ledger::open(['order_id' => $ordine, 'amount' => 50.0]);
            $chiuso = Ledger::register(['order_id' => $ordine, 'amount' => 50.0, 'paid_at' => $ieri]);

            return $chiuso['payment_id'] === $aperto['payment_id']
                && str_starts_with((string) Payment::findById($chiuso['payment_id'])['paid_at'], $ieri);
        });

        check('una data nel futuro è rifiutata e non scrive niente', function () {
            $ordine = ordineDiProva(50.0);

            try {
                Ledger::register(['order_id' => $ordine, 'amount' => 50.0, 'paid_at' => date('Y-m-d', strtotime('+2 days'))]);
            } catch (UserError $e) {
                return str_contains($e->getMessage(), 'futuro')
                    && (int) sqlCount(Payment::$table, "order_id = {$ordine}") === 0;
            }

            return false;
        });

        check('una data che non è una data è rifiutata', function () {
            $ordine = ordineDiProva(50.0);

            try {
                Ledger::register(['order_id' => $ordine, 'amount' => 50.0, 'paid_at' => 'ieri sera']);
            } catch (UserError) {
                return (int) sqlCount(Payment::$table, "order_id = {$ordine}") === 0;
            }

            return false;
        });

        throw new Annulla();
    });
} catch (Annulla) {
    // Il database torna com'era.
}

summary();
