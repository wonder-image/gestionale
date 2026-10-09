<?php
/** php tests/integrazione/ReconcileTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';
require __DIR__ . '/supporto/compra.php';
require __DIR__ . '/supporto/FakePaymentProvider.php';
require __DIR__ . '/supporto/posta.php';

use Wonder\Sql\Transaction;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\System\ProviderEvent;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentEvent;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
use Wonder\Plugin\Gestionale\Support\Payments\Reconcile;
use Wonder\Plugin\Gestionale\Support\Providers\ProviderEvents;

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

// Registrato anche se `Reconcile` lo riceve come argomento: `Lifecycle::cancel`
// lo cerca nel registro, e senza il finto chiamerebbe Stripe davvero.
$finto = new FakePaymentProvider();
PaymentProviders::register($finto);

/** Ordine in attesa col pagamento legato all'intento, come dopo il «Paga». */
function intentoAperto(float $totale = 50.0, string $ambiente = 'test'): array
{
    $ordine = ordineDiProva($totale);
    $pagamento = Ledger::open(['order_id' => $ordine, 'amount' => $totale, 'provider' => 'stripe'])['payment_id'];
    $intento = 'pi_ric_'.uniqid();
    Ledger::attach($pagamento, 'stripe', $intento, $ambiente);

    return [$ordine, $pagamento, $intento];
}

/** Quello che `status()` direbbe di un intento riuscito. */
function riuscito(int $ordine, int $centesimi = 5000): PaymentState
{
    return new PaymentState(PaymentState::SUCCEEDED, $centesimi, 'eur', $ordine);
}

/** Un evento normalizzato; `$cambi` sostituisce i campi per nome. */
function evento(string $tipo, string $intento, int $ordine, int $centesimi, array $cambi = []): PaymentEvent
{
    $id = 'evt_'.uniqid();

    return new PaymentEvent(...array_merge([
        'id' => $id,
        'type' => $tipo,
        'environment' => 'test',
        'reference' => $intento,
        'amount' => $centesimi,
        'currency' => 'eur',
        'orderId' => $ordine,
        'refunds' => [],
        'chargeId' => 'ch_prova',
        'payload' => ['id' => $id, 'type' => $tipo],
    ], $cambi));
}

/** L'evento salvato com'era rimasto: stato, tentativi e da quanti secondi. */
function salvato(FakePaymentProvider $finto, PaymentEvent $evento, string $stato, int $tentativi, int $secondiFa): void
{
    ProviderEvents::receive('stripe', $evento->id, $evento->type, $evento->payload, 'test');
    $quando = date('Y-m-d H:i:s', time() - $secondiFa);
    sqlModify(ProviderEvent::$table, [
        'status' => $stato,
        'attempts' => $tentativi,
        'creation' => $quando,
        'last_modified' => $quando,
    ], 'id', (int) ProviderEvents::find('stripe', $evento->id, 'test')['id']);
    $finto->replays[$evento->id] = $evento;
}

function stato(int $ordine): string
{
    return (string) (Order::findById($ordine)['status'] ?? '');
}

function riga(int $pagamento): string
{
    return (string) (Payment::findById($pagamento)['status'] ?? '');
}

function evStato(PaymentEvent $evento): array
{
    $riga = ProviderEvents::find('stripe', $evento->id, 'test') ?? [];

    return [(string) ($riga['status'] ?? ''), (int) ($riga['attempts'] ?? 0)];
}

check('l\'intento riuscito di cui non è arrivato il webhook conferma l\'ordine', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto();
    $finto->states[$intento] = riuscito($ordine);
    Reconcile::run($finto);

    return stato($ordine) === 'confirmed' && riga($pagamento) === 'paid';
}));

check('anche dopo una carta rifiutata: il secondo tentativo riuscito si recupera', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto();
    Ledger::fail($pagamento, 'Carta rifiutata');
    $finto->states[$intento] = riuscito($ordine);
    Reconcile::run($finto);

    return stato($ordine) === 'confirmed';
}));

check('l\'intento annullato chiude la riga in attesa', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto();
    $finto->states[$intento] = new PaymentState(PaymentState::CANCELED, 5000, 'eur', $ordine);
    Reconcile::run($finto);

    return riga($pagamento) === 'failed' && stato($ordine) === 'pending';
}));

check('l\'ordine non più in attesa non si tocca', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto();
    $finto->states[$intento] = riuscito($ordine);
    Lifecycle::cancel($ordine, ['notify' => false]);
    Reconcile::run($finto);

    return stato($ordine) === 'cancelled' && riga($pagamento) === 'failed';
}));

check('l\'altro ambiente non si interroga', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto(50.0, 'live');
    $finto->states[$intento] = riuscito($ordine);
    Reconcile::run($finto);

    return stato($ordine) === 'pending' && riga($pagamento) === 'pending';
}));

check('un importo diverso non conferma, chiude la riga e al giro dopo non si ripete', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto();
    $finto->states[$intento] = riuscito($ordine, 4000);
    destinatariCommerciante('negozio@example.com');

    $primo = avvisiPagamento(conPosta(static function () use ($finto): void {
        Reconcile::run($finto);
    }));
    $dopoIlPrimo = riga($pagamento);

    $secondo = avvisiPagamento(conPosta(static function () use ($finto): void {
        Reconcile::run($finto);
    }));

    return $dopoIlPrimo === 'failed'
        && stato($ordine) === 'pending'
        && riga($pagamento) === 'failed'
        && count($primo) === 1
        && $primo[0]['to'] === 'negozio@example.com'
        && str_contains($primo[0]['body'], $intento)
        && $secondo === [];
}));

check('un evento ricevuto da più di 10 minuti si rielabora', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000);
    salvato($finto, $evento, 'received', 0, 700);
    Reconcile::run($finto);

    return stato($ordine) === 'confirmed' && evStato($evento)[0] === 'processed';
}));

check('un evento appena ricevuto lo lascia al webhook', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000);
    salvato($finto, $evento, 'received', 0, 60);
    Reconcile::run($finto);

    return stato($ordine) === 'pending' && evStato($evento)[0] === 'received';
}));

check('un evento fallito aspetta il suo turno: 5 minuti dopo il primo tentativo', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $presto = evento('payment_intent.succeeded', $intento, $ordine, 5000);
    salvato($finto, $presto, 'failed', 1, 240);
    Reconcile::run($finto);
    $primo = stato($ordine);

    sqlModify(ProviderEvent::$table, ['last_modified' => date('Y-m-d H:i:s', time() - 360)], 'id', (int) ProviderEvents::find('stripe', $presto->id, 'test')['id']);
    Reconcile::run($finto);

    return $primo === 'pending' && stato($ordine) === 'confirmed';
}));

check('al quarto tentativo l\'evento non si riprova più', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000);
    salvato($finto, $evento, 'failed', ProviderEvents::MAX_ATTEMPTS, 86400);
    Reconcile::run($finto);

    return stato($ordine) === 'pending' && evStato($evento) === ['failed', ProviderEvents::MAX_ATTEMPTS];
}));

check('un payload che non si rilegge finisce in «Da controllare»', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000);
    salvato($finto, $evento, 'received', 0, 700);
    unset($finto->replays[$evento->id]);
    Reconcile::run($finto);

    return evStato($evento) === ['failed', ProviderEvents::MAX_ATTEMPTS];
}));

check('con il fornitore non collegato non fa nulla', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $finto->states[$intento] = riuscito($ordine);
    $finto->connected = false;

    try {
        $esito = Reconcile::run($finto);
    } finally {
        $finto->connected = true;
    }

    return $esito === ['payments' => 0, 'events' => 0] && stato($ordine) === 'pending';
}));

check('Reconcile non scrive SQL a mano: le condizioni passano dal core', static fn (): bool => !str_contains((string) file_get_contents(__DIR__.'/../../src/Support/Payments/Reconcile.php'), 'addslashes'));

check('nessuna email vera: finiti i test la posta resta quella finta', static fn (): bool => (new ReflectionProperty(\Wonder\Plugin\Gestionale\Support\Mail\Mailer::class, 'transport'))->getValue() !== null);

PaymentProviders::reset();

summary();
