<?php
/** php tests/integrazione/InstallmentsTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentTerm;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentTermInstallment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\Payments\PaymentTermResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderPaymentResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\Installments;
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

/** Una condizione con le sue rate: `[[giorni, fine mese, percentuale], ...]`. */
function condizione(array $rate): int
{
    $termine = PaymentTerm::create([
        'code' => Code::make(PaymentTerm::class, Codes::PAYMENT_TERM),
        'name' => 'Prova rate '.uniqid(),
        'sdi_condition' => 'TP01',
        'position' => 99,
    ]);
    $id = (int) ($termine->insert_id ?? 0);

    foreach ($rate as $i => [$giorni, $fineMese, $percentuale]) {
        PaymentTermInstallment::create([
            'payment_term_id' => $id,
            'days' => $giorni,
            'end_of_month' => $fineMese ? 'true' : 'false',
            'percentage' => number_format($percentuale, 2, '.', ''),
            'position' => $i + 1,
        ]);
    }

    return $id;
}

/** Un ordine da 300 € con tre rate a 30/60/90 giorni fine mese, confermato il 10 ottobre. */
function ordineATreRate(float $totale = 300.0): int
{
    $ordine = ordineDiProva($totale);
    Order::update(['payment_term_id' => condizione([[30, true, 33.33], [60, true, 33.33], [90, true, 33.34]])], $ordine);
    Installments::create($ordine, new DateTimeImmutable('2026-10-10'));

    return $ordine;
}

/** Le righe di denaro dell'ordine, per stato: `[stato => [[importo, scadenza], ...]]`. */
function righe(int $ordine): array
{
    $per = [];
    $rows = Payment::find(['order_id' => $ordine, 'deleted' => 'false'], null, 'id', 'ASC');
    $rows = is_array($rows) ? (isset($rows['id']) ? [$rows] : $rows) : [];

    foreach ($rows as $r) {
        $per[(string) $r['status']][] = [round((float) $r['amount'], 2), substr((string) $r['due_date'], 0, 10)];
    }

    return $per;
}

function statoPagamento(int $ordine): string
{
    return (string) (Order::findById($ordine)['payment_status'] ?? '');
}

function rifiutata(callable $azione): bool
{
    try {
        $azione();
    } catch (UserError) {
        return true;
    }

    return false;
}

check('tre rate a 30/60/90 fine mese: importi, scadenze e somma, l\'ordine in attesa', function () {
    return prova(static function (): bool {
        $ordine = ordineATreRate();
        $r = righe($ordine);

        return ($r['pending'] ?? []) === [[99.99, '2026-11-30'], [99.99, '2026-12-31'], [100.02, '2027-01-31']]
            && statoPagamento($ordine) === 'pending';
    });
});

check('ripetuta, la creazione non scrive altre rate', function () {
    return prova(static function (): bool {
        $ordine = ordineATreRate();

        return Installments::create($ordine) === [] && count(righe($ordine)['pending'] ?? []) === 3;
    });
});

check('senza condizione, una rata sola a vista', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(80.0);
        Installments::create($ordine, new DateTimeImmutable('2026-10-10'));

        return (righe($ordine)['pending'] ?? []) === [[80.0, '2026-10-10']];
    });
});

check('la richiesta aperta senza scadenza la sostituiscono le rate', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(300.0);
        $aperta = Wonder\Plugin\Gestionale\Support\Payments\Ledger::open(['order_id' => $ordine, 'amount' => 300.0])['payment_id'];
        Order::update(['payment_term_id' => condizione([[30, true, 50], [60, true, 50]])], $ordine);
        Installments::create($ordine, new DateTimeImmutable('2026-10-10'));

        return (string) Payment::findById($aperta)['status'] === 'cancelled'
            && count(Installments::pending($ordine)) === 2
            && round(array_sum(array_column(Installments::pending($ordine), 'amount')), 2) === 300.0;
    });
});

check('pagamento uguale alla rata: pagata, le altre restano', function () {
    return prova(static function (): bool {
        $ordine = ordineATreRate();
        Installments::register(Installments::pending($ordine)[0]['id'], 99.99);
        $r = righe($ordine);

        return ($r['paid'] ?? []) === [[99.99, '2026-11-30']]
            && count($r['pending'] ?? []) === 2
            && statoPagamento($ordine) === 'partially_paid';
    });
});

check('pagamento minore: il resto resta con la stessa scadenza', function () {
    return prova(static function (): bool {
        $ordine = ordineATreRate();
        Installments::register(Installments::pending($ordine)[0]['id'], 60.0);
        $attesa = array_map(static fn (array $r): array => [$r['amount'], $r['due_date']], Installments::pending($ordine));

        return (righe($ordine)['paid'] ?? []) === [[60.0, '2026-11-30']]
            && $attesa === [[39.99, '2026-11-30'], [99.99, '2026-12-31'], [100.02, '2027-01-31']];
    });
});

check('pagamento maggiore: scala le successive, quella azzerata si annulla', function () {
    return prova(static function (): bool {
        $ordine = ordineATreRate();
        Installments::register(Installments::pending($ordine)[0]['id'], 250.0);
        $r = righe($ordine);

        return ($r['paid'] ?? []) === [[250.0, '2026-11-30']]
            && ($r['cancelled'] ?? []) === [[99.99, '2026-12-31']]
            && ($r['pending'] ?? []) === [[50.0, '2027-01-31']];
    });
});

check('oltre il da pagare: rifiutato, niente scritto', function () {
    return prova(static function (): bool {
        $ordine = ordineATreRate();
        $prima = righe($ordine);

        return rifiutata(static fn () => Installments::register(Installments::pending($ordine)[0]['id'], 300.01))
            && righe($ordine) === $prima;
    });
});

check('una rata già pagata non si paga due volte', function () {
    return prova(static function (): bool {
        $ordine = ordineATreRate();
        $rata = Installments::pending($ordine)[0]['id'];
        Installments::register($rata, 99.99);

        return rifiutata(static fn () => Installments::register($rata, 10.0));
    });
});

check('pagate tutte, l\'ordine è pagato', function () {
    return prova(static function (): bool {
        $ordine = ordineATreRate();
        Installments::register(Installments::pending($ordine)[0]['id'], 300.0);

        return statoPagamento($ordine) === 'paid' && Installments::pending($ordine) === [];
    });
});

check('ricalcolo dopo un totale più basso: proporzionale, la somma torna', function () {
    return prova(static function (): bool {
        $ordine = ordineATreRate();
        Installments::register(Installments::pending($ordine)[0]['id'], 99.99);
        Order::update(['total' => '250.00'], $ordine);
        Installments::recalculate($ordine);
        $attesa = Installments::pending($ordine);
        $somma = round(array_sum(array_column($attesa, 'amount')), 2);

        return count($attesa) === 2 && $somma === 150.01 && $attesa[0]['due_date'] === '2026-12-31';
    });
});

check('ricalcolo con il pagato oltre il nuovo totale: le rate in attesa si annullano', function () {
    return prova(static function (): bool {
        $ordine = ordineATreRate();
        Installments::register(Installments::pending($ordine)[0]['id'], 150.0);
        Order::update(['total' => '120.00'], $ordine);
        Installments::recalculate($ordine);

        return Installments::pending($ordine) === [] && count(righe($ordine)['cancelled'] ?? []) >= 2;
    });
});

check('il registro libero sull\'ordine chiude prima la rata più vicina', function () {
    return prova(static function (): bool {
        $ordine = ordineATreRate();
        $esito = OrderPaymentResource::run($ordine, ['amount' => '120,00', 'paid_at' => date('d/m/Y')], 0);
        $r = righe($ordine);

        return $esito['ok'] === true
            && ($r['paid'] ?? []) === [[120.0, '2026-11-30']]
            && ($r['pending'] ?? []) === [[79.98, '2026-12-31'], [100.02, '2027-01-31']];
    });
});

check('una condizione usata da un ordine non si elimina', function () {
    return prova(static function (): bool {
        $ordine = ordineATreRate();
        $termine = (int) Order::findById($ordine)['payment_term_id'];

        try {
            PaymentTermResource::assertDeletable($termine);
        } catch (RuntimeException) {
            return PaymentTermResource::usage($termine) === 1;
        }

        return false;
    });
});

summary();
