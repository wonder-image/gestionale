<?php
/** php tests/integrazione/OnlinePaymentsTest.php */
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
use Wonder\Plugin\Gestionale\Models\Payments\PaymentStatusLog;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentEvent;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;
use Wonder\Plugin\Gestionale\Providers\Payments\StripeProvider;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentEvents;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
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
/** L'ordine online appena inviato: «In pagamento», col pagamento aperto. */
function ordineStripe(float $totale = 50.0): array
{
    $ordine = ordineDiProva($totale);
    Order::update(['status' => 'awaiting_payment'], $ordine);
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

/** Ordine in attesa col pagamento già legato a un intento. */
function intentoLegato(float $totale = 50.0): array
{
    [$ordine, $pagamento] = ordineStripe($totale);
    $intento = 'pi_av_'.uniqid();
    Ledger::attach($pagamento, 'stripe', $intento, 'test');

    return [$ordine, $pagamento, $intento];
}

check('un incasso su un ordine annullato avvisa il commerciante con payment.review', fn () => prova(static function (): bool {
    [$ordine, , $intento] = intentoLegato();
    Lifecycle::cancel($ordine, ['notify' => false]);
    destinatariCommerciante('negozio@example.com');

    $avvisi = avvisiPagamento(conPosta(static function () use ($ordine, $intento): void {
        OnlinePayments::succeeded('stripe', $intento, 5000, 'eur', $ordine, 'webhook');
    }));

    return count($avvisi) === 1
        && $avvisi[0]['to'] === 'negozio@example.com'
        && $avvisi[0]['subject'] === '[payment.review] Pagamento da controllare'
        && str_contains($avvisi[0]['body'], $intento)
        && str_contains($avvisi[0]['body'], 'dashboard di Stripe');
}));

check('lo stesso incasso ripassato non manda un secondo avviso', fn () => prova(static function (): bool {
    [$ordine, , $intento] = intentoLegato();
    Lifecycle::cancel($ordine, ['notify' => false]);
    destinatariCommerciante('negozio@example.com');

    $avvisi = avvisiPagamento(conPosta(static function () use ($ordine, $intento): void {
        OnlinePayments::succeeded('stripe', $intento, 5000, 'eur', $ordine, 'webhook');
        OnlinePayments::succeeded('stripe', $intento, 5000, 'eur', $ordine, 'cron');
    }));

    return count($avvisi) === 1;
}));

check('senza destinatari non parte niente e non si lancia niente', fn () => prova(static function (): bool {
    [$ordine, , $intento] = intentoLegato();
    Lifecycle::cancel($ordine, ['notify' => false]);
    destinatariCommerciante('');

    $partite = conPosta(static function () use ($ordine, $intento): void {
        OnlinePayments::succeeded('stripe', $intento, 5000, 'eur', $ordine, 'webhook');
        OnlinePayments::alert('stripe', 'payment.mismatch', 'Prova', ['order_id' => $ordine]);
    });

    return avvisiPagamento($partite) === [];
}));

check('succeeded due volte sullo stesso intento: una riga pagata, ordine pagato e in attesa', fn () => prova(static function (): bool {
    [$ordine, $pagamento, $intento] = intentoLegato();
    destinatariCommerciante('');

    $primo = OnlinePayments::succeeded('stripe', $intento, 5000, 'eur', $ordine, 'webhook');
    $secondo = OnlinePayments::succeeded('stripe', $intento, 5000, 'eur', $ordine, 'return');
    $trovate = Payment::find(['order_id' => $ordine, 'type' => 'payment', 'deleted' => 'false']);
    $righe = is_array($trovate) && isset($trovate['id']) ? [$trovate] : array_values(array_filter((array) $trovate, 'is_array'));

    return ($primo['changed'] ?? false) === true
        && ($secondo['changed'] ?? true) === false
        && ($secondo['status'] ?? '') === 'pending'
        && ($secondo['payment_status'] ?? '') === 'paid'
        && count($righe) === 1
        && (int) $righe[0]['id'] === $pagamento
        && $righe[0]['status'] === 'paid';
}));

check('annullare l\'ordine annulla l\'intento e chiude la riga', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento] = ordineStripe();
    $avvio = OnlinePayments::start($ordine);
    $esito = Lifecycle::cancel($ordine, ['notify' => false]);

    return in_array($avvio->reference, $finto->cancelled, true)
        && ($esito['status'] ?? '') === 'cancelled'
        && !array_key_exists('intents', $esito)
        && (Payment::findById($pagamento)['status'] ?? '') === 'failed';
}));

check('l\'intento dell\'altro ambiente non si tocca', fn () => prova(static function () use ($finto): bool {
    [$ordine] = ordineStripe();
    $avvio = OnlinePayments::start($ordine);
    $finto->environment = 'live';

    try {
        Lifecycle::cancel($ordine, ['notify' => false]);
    } finally {
        $finto->environment = 'test';
    }

    return !in_array($avvio->reference, $finto->cancelled, true);
}));

check('una riga senza intento non chiama il fornitore', fn () => prova(static function () use ($finto): bool {
    [$ordine] = ordineStripe();
    $prima = count($finto->cancelled);
    Lifecycle::cancel($ordine, ['notify' => false]);

    return count($finto->cancelled) === $prima;
}));

check('se Stripe non risponde l\'ordine si annulla lo stesso', fn () => prova(static function () use ($finto): bool {
    [$ordine] = ordineStripe();
    OnlinePayments::start($ordine);
    $finto->failCancel = true;

    try {
        $esito = Lifecycle::cancel($ordine, ['notify' => false]);
    } finally {
        $finto->failCancel = false;
    }

    return ($esito['changed'] ?? false) === true
        && (Order::findById($ordine)['status'] ?? '') === 'cancelled';
}));

check('inTest riconosce l\'ordine pagato con le chiavi di prova', fn () => prova(static function (): bool {
    [$prova] = ordineStripe();
    OnlinePayments::start($prova);
    $vero = ordineDiProva(20.0);
    Ledger::open(['order_id' => $vero, 'amount' => 20.0, 'provider' => 'manual']);

    return OnlinePayments::inTest($prova) && !OnlinePayments::inTest($vero) && !OnlinePayments::inTest(0);
}));

/** Le istruzioni mandate finora sulla connessione del gestionale. */
function domande(): int
{
    return (int) (Order::query()->mysqli->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1] ?? 0);
}

check('inTest su una pagina di ordini legge i pagamenti di prova una volta sola', fn () => prova(static function (): bool {
    [$prova] = ordineStripe();
    OnlinePayments::start($prova);
    OnlinePayments::inTest($prova);
    $prima = domande();
    $esiti = array_map(static fn (int $id): bool => OnlinePayments::inTest($id), range($prova, $prova + 29));

    // Una per leggere il contatore.
    return domande() - $prima <= 1 && $esiti[0] && !in_array(true, array_slice($esiti, 1), true);
}));

/** I rifiuti scritti nella storia del pagamento, dal primo. @return list<string> */
function rifiuti(int $pagamento): array
{
    $righe = PaymentStatusLog::find(['payment_id' => $pagamento, 'to_value' => 'failed'], null, 'id', 'ASC');
    $righe = is_array($righe) && isset($righe['id']) ? [$righe] : array_values(array_filter((array) $righe, 'is_array'));

    return array_map(static fn (array $riga): string => (string) $riga['message'], $righe);
}

/** Un evento del gateway come lo darebbe la firma verificata. */
function evento(string $tipo, string $intento, int $ordine, string $motivo = '', string $tentativo = ''): PaymentEvent
{
    return new PaymentEvent('evt_'.uniqid(), $tipo, 'test', $intento, 5000, 'eur', $ordine, [], '', [], $motivo, $tentativo);
}

check('ogni tentativo rifiutato resta nella storia del pagamento, una volta sola', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoLegato();
    OnlinePayments::fail('stripe', $intento, 'Pagamento rifiutato: fondi insufficienti (insufficient_funds)', 'ch_1');
    // Lo stesso tentativo raccontato dal webhook dopo il browser.
    PaymentEvents::process($finto, evento('payment_intent.payment_failed', $intento, $ordine, 'Pagamento rifiutato: fondi insufficienti (insufficient_funds)', 'ch_1'));
    PaymentEvents::process($finto, evento('payment_intent.payment_failed', $intento, $ordine, 'Pagamento rifiutato: carta scaduta (expired_card)', 'ch_2'));

    return rifiuti($pagamento) === [
        'Pagamento rifiutato: fondi insufficienti (insufficient_funds)',
        'Pagamento rifiutato: carta scaduta (expired_card)',
    ] && (Payment::findById($pagamento)['status'] ?? '') === 'failed';
}));

check('un fallimento senza tentativo sulla riga già fallita non si ripete', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoLegato();
    OnlinePayments::fail('stripe', $intento, 'Pagamento rifiutato (card_declined)', 'ch_1');
    PaymentEvents::process($finto, evento('payment_intent.canceled', $intento, $ordine));

    return rifiuti($pagamento) === ['Pagamento rifiutato (card_declined)'];
}));

check('il webhook senza motivo scrive il fallimento generico', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoLegato();
    PaymentEvents::process($finto, evento('payment_intent.payment_failed', $intento, $ordine));

    return rifiuti($pagamento) === ['Pagamento non riuscito'];
}));

check('Stripe: il motivo del rifiuto e il tentativo vengono da last_payment_error', static function (): bool {
    $stripe = new StripeProvider();
    $intento = static fn (?array $errore): array => ['id' => 'evt_1', 'type' => 'payment_intent.payment_failed', 'data' => ['object' => [
        'object' => 'payment_intent', 'id' => 'pi_1', 'amount' => 5000, 'currency' => 'eur', 'metadata' => ['order_id' => 7],
        'last_payment_error' => $errore,
    ]]];
    $carta = $stripe->replay($intento(['code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'charge' => 'ch_9', 'message' => 'Your card has insufficient funds.']), 'test');
    $verifica = $stripe->replay($intento(['code' => 'payment_intent_authentication_failure', 'payment_method' => ['id' => 'pm_3'], 'message' => 'x']), 'test');
    $ignoto = $stripe->replay($intento(['code' => 'strano', 'message' => 'Something odd.', 'charge' => 'ch_8']), 'test');
    $nuovo = $stripe->replay($intento(null), 'test');

    return $carta->failure === 'Pagamento rifiutato: fondi insufficienti (insufficient_funds)' && $carta->attempt === 'ch_9'
        && $verifica->failure === 'Pagamento rifiutato: verifica della banca non completata (payment_intent_authentication_failure)'
        && $verifica->attempt === 'pm_3:payment_intent_authentication_failure'
        && $ignoto->failure === 'Pagamento rifiutato: Something odd. (strano)'
        && $nuovo->failure === '' && $nuovo->attempt === '';
});

check('reopen annulla l\'intento prima di riaprire', fn () => prova(static function () use ($finto): bool {
    [$ordine] = ordineStripe();
    $avvio = OnlinePayments::start($ordine);
    Lifecycle::reopen($ordine, ['reason' => 'Modificato dal cliente nel checkout']);

    return in_array($avvio->reference, $finto->cancelled, true)
        && (Order::findById($ordine)['stage'] ?? '') === 'cart';
}));

/** Riapre con Stripe che non annulla e dice quello stato; il codice dell'errore, o '' se ha riaperto. */
function riapriSenzaAnnullo(FakePaymentProvider $finto, int $ordine, string $intento, string $stato): string
{
    $finto->failCancel = true;
    $finto->states[$intento] = new PaymentState($stato, 5000, 'eur', $ordine);

    try {
        Lifecycle::reopen($ordine);

        return '';
    } catch (UserError $errore) {
        return $errore->key();
    } finally {
        $finto->failCancel = false;
        unset($finto->states[$intento]);
    }
}

check('reopen: se Stripe non annulla, riapre solo un intento già annullato', fn () => prova(static function () use ($finto): bool {
    $esiti = [];

    foreach ([PaymentState::CANCELED, PaymentState::SUCCEEDED, PaymentState::PROCESSING, PaymentState::OTHER] as $stato) {
        [$ordine] = ordineStripe();
        $avvio = OnlinePayments::start($ordine);
        $errore = riapriSenzaAnnullo($finto, $ordine, $avvio->reference, $stato);
        $riga = Order::findById($ordine);
        $esiti[] = $errore.'|'.$riga['stage'].'|'.$riga['status'].'|'.OnlinePayments::payment($ordine)['status'];
    }

    return $esiti === [
        '|cart|draft|failed',
        'order.payment_in_progress|order|awaiting_payment|pending',
        'order.payment_in_progress|order|awaiting_payment|pending',
        'order.reopen_retry|order|awaiting_payment|pending',
    ];
}));

check('un incasso su un ordine tornato carrello avvisa una volta e non si riprova', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoLegato();
    Lifecycle::reopen($ordine);
    destinatariCommerciante('negozio@example.com');
    $esito = null;

    $avvisi = avvisiPagamento(conPosta(static function () use ($finto, $ordine, $intento, &$esito): void {
        $esito = PaymentEvents::process($finto, evento('payment_intent.succeeded', $intento, $ordine));
    }));

    return $esito === true
        && count($avvisi) === 1
        && str_contains($avvisi[0]['body'], 'tornato carrello')
        && (OnlinePayments::payment($ordine)['status'] ?? '') === 'failed';
}));

check('il primo incasso dà all\'ordine numero e data, e lo passa al commerciante', fn () => prova(static function (): bool {
    [$ordine, , $intento] = intentoLegato();
    destinatariCommerciante('');
    $prima = Order::findById($ordine);

    OnlinePayments::succeeded('stripe', $intento, 5000, 'eur', $ordine, 'webhook');
    $dopo = Order::findById($ordine);

    return (string) $prima['status'] === 'awaiting_payment'
        && (string) $dopo['status'] === 'pending'
        && trim((string) $dopo['order_number']) !== ''
        && trim((string) $dopo['ordered_at']) !== '';
}));

check('un incasso su un ordine abbandonato avvisa il commerciante e non lo riapre', fn () => prova(static function (): bool {
    [$ordine, , $intento] = intentoLegato();
    Lifecycle::abandon($ordine);
    destinatariCommerciante('negozio@example.com');
    $esito = [];

    $avvisi = avvisiPagamento(conPosta(static function () use ($ordine, $intento, &$esito): void {
        $esito = OnlinePayments::succeeded('stripe', $intento, 5000, 'eur', $ordine, 'webhook');
    }));

    return count($avvisi) === 1
        && ($esito['status'] ?? '') === 'abandoned'
        && (string) Order::findById($ordine)['status'] === 'abandoned'
        && trim((string) Order::findById($ordine)['order_number']) === '';
}));

check('abandon annulla l\'intento, chiude la riga e non scrive se Stripe sta incassando', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento] = ordineStripe();
    $avvio = OnlinePayments::start($ordine);
    $uno = Lifecycle::abandon($ordine, ['reason' => 'Prenotazione scaduta']);
    $abbandonato = Order::findById($ordine);

    [$altro] = ordineStripe();
    $intento = OnlinePayments::start($altro)->reference;
    $finto->failCancel = true;
    $finto->states[$intento] = new PaymentState(PaymentState::PROCESSING, 5000, 'eur', $altro);

    try {
        $due = Lifecycle::abandon($altro);
    } finally {
        $finto->failCancel = false;
        unset($finto->states[$intento]);
    }

    return $uno === true
        && in_array($avvio->reference, $finto->cancelled, true)
        && (string) $abbandonato['status'] === 'abandoned'
        && (string) $abbandonato['stage'] === 'order'
        && (string) (Payment::findById($pagamento)['status'] ?? '') === 'failed'
        && Lifecycle::abandon($ordine) === false
        && $due === false
        && (string) Order::findById($altro)['status'] === 'awaiting_payment';
}));

check('l\'ordine abbandonato torna carrello senza richiamare Stripe', fn () => prova(static function () use ($finto): bool {
    [$ordine] = ordineStripe();
    OnlinePayments::start($ordine);
    Lifecycle::abandon($ordine);
    $annullati = count($finto->cancelled);
    $finto->failCancel = true;

    try {
        Lifecycle::reopen($ordine, ['reason' => 'Ordine modificato dal cliente']);
    } finally {
        $finto->failCancel = false;
    }

    $riga = Order::findById($ordine);

    return $riga['stage'] === 'cart' && $riga['status'] === 'draft' && count($finto->cancelled) === $annullati;
}));

PaymentProviders::reset();
check('succeeded rifiuta un incasso che non copre più il totale dell\'ordine', fn () => prova(static function (): bool {
    [$ordine, , $intento] = intentoLegato(50.0);
    destinatariCommerciante('');
    // Il totale è cambiato dopo l'avvio del pagamento.
    Order::update(['total' => '60.00'], $ordine);

    try {
        OnlinePayments::succeeded('stripe', $intento, 5000, 'eur', $ordine, 'webhook');
    } catch (\Wonder\Plugin\Gestionale\Support\Payments\PaymentMismatch) {
        return true;
    }

    return false;
}));

check('succeeded accetta il residuo quando parte dell\'ordine è già pagata', fn () => prova(static function (): bool {
    $ordine = ordineDiProva(50.0);
    destinatariCommerciante('');
    Ledger::register(['order_id' => $ordine, 'amount' => 10.0, 'provider' => 'manual', 'source' => 'manual']);
    $pagamento = Ledger::open(['order_id' => $ordine, 'amount' => 40.0, 'provider' => 'stripe'])['payment_id'];
    $intento = 'pi_res_'.uniqid();
    Ledger::attach($pagamento, 'stripe', $intento, 'test');

    return (OnlinePayments::succeeded('stripe', $intento, 4000, 'eur', $ordine, 'webhook')['payment_status'] ?? '') === 'paid';
}));

/** Le chiavi delle email partite, nell'ordine. */
function chiaviPosta(array $partite): array
{
    return array_map(static fn (array $p): string => preg_match('/^\[([^\]]+)\]/', $p['subject'], $m) ? $m[1] : '', $partite);
}

check('processing: l\'ordine arriva al commerciante col numero, una volta sola', fn () => prova(static function (): bool {
    [$ordine, , $intento] = intentoLegato();
    Order::update(['email' => 'cliente@example.com'], $ordine);
    destinatariCommerciante('negozio@example.com');
    $esiti = [];

    $partite = conPosta(static function () use ($ordine, $intento, &$esiti): void {
        $esiti[] = OnlinePayments::processing('stripe', $intento, 5000, 'eur', $ordine, 'webhook');
        $esiti[] = OnlinePayments::processing('stripe', $intento, 5000, 'eur', $ordine, 'cron');
    });
    $dopo = Order::findById($ordine);

    return chiaviPosta($partite) === ['order.processing', 'order.merchant_new']
        && $esiti[0]['changed'] === true && $esiti[1]['changed'] === false
        && (string) $dopo['status'] === 'pending'
        && trim((string) $dopo['order_number']) !== ''
        && (OnlinePayments::payment($ordine)['status'] ?? '') !== 'paid';
}));

check('processing e poi succeeded: pagato, ordine in attesa, email del pagamento', fn () => prova(static function (): bool {
    [$ordine, , $intento] = intentoLegato();
    Order::update(['email' => 'cliente@example.com'], $ordine);
    destinatariCommerciante('negozio@example.com');
    OnlinePayments::processing('stripe', $intento, 5000, 'eur', $ordine, 'webhook');

    $partite = conPosta(static function () use ($ordine, $intento): void {
        OnlinePayments::succeeded('stripe', $intento, 5000, 'eur', $ordine, 'webhook');
    });

    return chiaviPosta($partite) === ['order.paid', 'order.merchant_paid']
        && (string) Order::findById($ordine)['status'] === 'pending'
        && (OnlinePayments::payment($ordine)['status'] ?? '') === 'paid';
}));

check('fallimento dopo processing: ordine in attesa, pagamento fallito, avviso', fn () => prova(static function (): bool {
    [$ordine, , $intento] = intentoLegato();
    destinatariCommerciante('negozio@example.com');
    OnlinePayments::processing('stripe', $intento, 5000, 'eur', $ordine, 'webhook');

    $avvisi = avvisiPagamento(conPosta(static function () use ($intento): void {
        OnlinePayments::fail('stripe', $intento, 'Addebito SEPA respinto');
    }));

    return count($avvisi) === 1
        && str_contains($avvisi[0]['body'], 'dopo l&#039;elaborazione')
        && (string) Order::findById($ordine)['status'] === 'pending'
        && (OnlinePayments::payment($ordine)['status'] ?? '') === 'failed';
}));

check('due succeeded sullo stesso intento: una mail cliente e una merchant', fn () => prova(static function (): bool {
    [$ordine, , $intento] = intentoLegato();
    Order::update(['email' => 'cliente@example.com'], $ordine);
    destinatariCommerciante('negozio@example.com');

    $partite = conPosta(static function () use ($ordine, $intento): void {
        OnlinePayments::succeeded('stripe', $intento, 5000, 'eur', $ordine, 'webhook');
        OnlinePayments::succeeded('stripe', $intento, 5000, 'eur', $ordine, 'return');
    });

    return chiaviPosta($partite) === ['order.paid', 'order.merchant_paid'];
}));

check('succeeded che fallisce dentro la transazione: niente email né avvisi, niente scritto', fn () => prova(static function (): bool {
    [$ordine, $pagamento, $intento] = intentoLegato();
    Order::update(['email' => 'cliente@example.com'], $ordine);
    destinatariCommerciante('negozio@example.com');
    $fallito = false;

    $partite = conPosta(static function () use ($ordine, $intento, &$fallito): void {
        try {
            // Un centesimo in meno: PaymentMismatch dopo il lock, la transazione torna indietro.
            OnlinePayments::succeeded('stripe', $intento, 4999, 'eur', $ordine, 'webhook');
        } catch (\Wonder\Plugin\Gestionale\Support\Payments\PaymentMismatch) {
            $fallito = true;
        }
    });

    return $fallito && $partite === []
        && (string) (Payment::findById($pagamento)['status'] ?? '') !== 'paid'
        && (string) (Order::findById($ordine)['status'] ?? '') === 'awaiting_payment';
}));

check('nessuna email vera: finiti i test la posta resta quella finta', static fn (): bool => (new ReflectionProperty(\Wonder\Plugin\Gestionale\Support\Mail\Mailer::class, 'transport'))->getValue() !== null);

summary();
