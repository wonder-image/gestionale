<?php
/** php tests/integrazione/PaymentEventsTest.php */
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
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentEvent;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Payments\OnlinePayments;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentEvents;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
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

$finto = new FakePaymentProvider();
PaymentProviders::register($finto);

/** Ordine in attesa col pagamento legato all'intento, come dopo il «Paga». */
function intentoAperto(float $totale = 50.0): array
{
    $ordine = ordineDiProva($totale);
    $pagamento = Ledger::open(['order_id' => $ordine, 'amount' => $totale, 'provider' => 'stripe'])['payment_id'];
    $intento = 'pi_ev_'.uniqid();
    Ledger::attach($pagamento, 'stripe', $intento, 'test');

    return [$ordine, $pagamento, $intento];
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

/** Manda l'evento come farebbe Stripe, con la firma che il finto accetta. */
function arriva(FakePaymentProvider $finto, PaymentEvent $evento): int
{
    $finto->nextEvent = $evento;

    return PaymentEvents::handle($finto, '{}', 'valida');
}

function stato(int $ordine): string
{
    return (string) (Order::findById($ordine)['status'] ?? '');
}

function righe(int $ordine, string $tipo = 'payment'): array
{
    $trovate = Payment::find(['order_id' => $ordine, 'type' => $tipo, 'deleted' => 'false']);

    return is_array($trovate) && isset($trovate['id']) ? [$trovate] : array_values(array_filter((array) $trovate, 'is_array'));
}

check('firma non valida: 400 e nessun evento registrato', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $finto->nextEvent = evento('payment_intent.succeeded', $intento, $ordine, 5000);

    return PaymentEvents::handle($finto, '{}', 'falsa') === 400
        && ProviderEvents::find('stripe', $finto->nextEvent->id, 'test') === null;
}));

check('evento dell\'altro ambiente o incerto: 200 e niente di fatto', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $live = evento('payment_intent.succeeded', $intento, $ordine, 5000, ['environment' => 'live']);
    $incerto = evento('payment_intent.succeeded', $intento, $ordine, 5000, ['environment' => '']);

    return arriva($finto, $live) === 200
        && arriva($finto, $incerto) === 200
        && ProviderEvents::find('stripe', $live->id, 'live') === null
        && stato($ordine) === 'pending';
}));

check('succeeded conferma l\'ordine e chiude la stessa riga', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto(50.0);
    $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000);
    $righe = null;

    $esito = arriva($finto, $evento);
    $righe = righe($ordine);

    return $esito === 200
        && stato($ordine) === 'confirmed'
        && count($righe) === 1
        && (int) $righe[0]['id'] === $pagamento
        && $righe[0]['status'] === 'paid'
        && (ProviderEvents::find('stripe', $evento->id, 'test')['status'] ?? '') === 'processed';
}));

check('lo stesso evento ripetuto non fa nulla due volte', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto(50.0);
    $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000);
    arriva($finto, $evento);

    return arriva($finto, $evento) === 200 && count(righe($ordine)) === 1;
}));

foreach ([
    'importo diverso' => ['amount' => 4999],
    'valuta diversa' => ['currency' => 'usd'],
    'ordine diverso' => ['orderId' => 999999999],
] as $caso => $cambio) {
    check("{$caso}: niente conferma, evento fermo in «Da controllare» e 200", fn () => prova(static function () use ($finto, $cambio): bool {
        [$ordine, $pagamento, $intento] = intentoAperto(50.0);
        $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000, $cambio);
        $riga = null;

        $esito = arriva($finto, $evento);
        $riga = ProviderEvents::find('stripe', $evento->id, 'test');

        return $esito === 200
            && stato($ordine) === 'pending'
            && (Payment::findById($pagamento)['status'] ?? '') === 'pending'
            && ($riga['status'] ?? '') === 'failed'
            && (int) ($riga['attempts'] ?? 0) === ProviderEvents::MAX_ATTEMPTS;
    }));
}

check('incasso su un ordine annullato: il denaro si registra, l\'ordine resta annullato', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto(50.0);
    Lifecycle::cancel($ordine, ['notify' => false]);
    $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000);

    return arriva($finto, $evento) === 200
        && stato($ordine) === 'cancelled'
        && (Payment::findById($pagamento)['status'] ?? '') === 'paid'
        && (ProviderEvents::find('stripe', $evento->id, 'test')['status'] ?? '') === 'processed';
}));

check('carta rifiutata: riga fallita, ordine in attesa; poi lo stesso intento riesce', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto(50.0);
    arriva($finto, evento('payment_intent.payment_failed', $intento, $ordine, 5000));
    $fallita = (string) (Payment::findById($pagamento)['status'] ?? '');
    $attesa = stato($ordine);
    arriva($finto, evento('payment_intent.succeeded', $intento, $ordine, 5000));

    return $fallita === 'failed'
        && $attesa === 'pending'
        && stato($ordine) === 'confirmed'
        && count(righe($ordine)) === 1;
}));

check('il fallimento che arriva dopo l\'incasso non tocca niente', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto(50.0);
    arriva($finto, evento('payment_intent.succeeded', $intento, $ordine, 5000));
    $esito = arriva($finto, evento('payment_intent.canceled', $intento, $ordine, 5000));

    return $esito === 200 && (Payment::findById($pagamento)['status'] ?? '') === 'paid';
}));

check('charge.refunded registra i rimborsi riusciti, una volta sola', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto(50.0);
    arriva($finto, evento('payment_intent.succeeded', $intento, $ordine, 5000));
    $rimborsi = [
        ['id' => 're_'.uniqid(), 'amount' => 2000, 'status' => 'succeeded'],
        ['id' => 're_'.uniqid(), 'amount' => 1000, 'status' => 'failed'],
    ];
    arriva($finto, evento('charge.refunded', $intento, $ordine, 5000, ['refunds' => $rimborsi]));
    $ancora = OnlinePayments::refunded('stripe', $intento, $rimborsi, 'eur', 'cron');
    $scritti = righe($ordine, 'refund');

    return count($scritti) === 1
        && $ancora === 1
        && $scritti[0]['provider_reference'] === $rimborsi[0]['id']
        && (float) $scritti[0]['amount'] === 20.0;
}));

check('un tipo che non serve si segna elaborato', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $evento = evento('payment_intent.created', $intento, $ordine, 5000);

    return arriva($finto, $evento) === 200
        && (ProviderEvents::find('stripe', $evento->id, 'test')['status'] ?? '') === 'processed';
}));

check('un errore nostro risponde 500; l\'evento fallito si rielabora e riesce', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto(50.0);
    $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000);
    // Un carrello non si conferma: `Lifecycle::confirm` lancia.
    Order::update(['stage' => 'cart'], $ordine);
    $primo = arriva($finto, $evento);
    $riga = ProviderEvents::find('stripe', $evento->id, 'test');
    Order::update(['stage' => 'order'], $ordine);
    $secondo = arriva($finto, $evento);

    return $primo === 500
        && ($riga['status'] ?? '') === 'failed'
        && (int) ($riga['attempts'] ?? 0) === 1
        && $secondo === 200
        && stato($ordine) === 'confirmed';
}));

check('la rotta del webhook è registrata e punta al suo file', static function (): bool {
    $modulo = json_decode((string) file_get_contents(Gestionale::manifestPath()), true);
    $rotte = (string) file_get_contents(Gestionale::root().'/config/routes/route.api.php');

    return ($modulo['routes']['api'] ?? '') === 'config/routes/route.api.php'
        && str_contains($rotte, "Route::post('/stripe/webhook/'")
        && is_file(Gestionale::handlerPath('api/stripe/webhook.php'));
});

PaymentProviders::reset();
summary();
