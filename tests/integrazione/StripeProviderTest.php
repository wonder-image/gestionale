<?php
/** php tests/integrazione/StripeProviderTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';
require __DIR__ . '/supporto/compra.php';

use Wonder\Sql\Transaction;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;
use Wonder\Plugin\Gestionale\Providers\Payments\StripeProvider;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
use Wonder\Plugin\Gestionale\Support\Providers\ExternalReferences;

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

require __DIR__ . '/supporto/FakeStripeHttp.php';
require __DIR__ . '/supporto/firma.php';

/** Credenziali di prova: test acceso, chiavi finte per i due ambienti. */
function chiavi(array $cambi = []): object
{
    return (object) array_replace([
        'stripe_test' => true,
        'stripe_test_key' => 'sk_test_prova',
        'stripe_test_account_id' => 'acct_prova_test',
        'stripe_test_public_key' => 'pk_test_prova',
        'stripe_test_webhook_secret' => 'whsec_prova_test',
        'stripe_private_key' => 'sk_live_prova',
        'stripe_account_id' => 'acct_prova_live',
        'stripe_public_key' => 'pk_live_prova',
        'stripe_webhook_secret' => 'whsec_prova_live',
    ], $cambi);
}

function intento(array $campi = []): array
{
    return array_replace([
        'id' => 'pi_nuovo',
        'object' => 'payment_intent',
        'client_secret' => 'pi_nuovo_secret_prova',
        'status' => 'requires_payment_method',
        'amount' => 1234,
        'currency' => 'eur',
        'metadata' => ['order_id' => '7', 'attempt' => '1'],
    ], $campi);
}

$ordine = ['id' => 7, 'code' => 'ord_prova', 'order_number' => 'W-7', 'customer_id' => 987654321, 'email' => 'cliente@example.com', 'currency' => 'EUR', 'payment_method_id' => 0];
$pagamento = ['id' => 3, 'code' => 'pay_prova', 'amount' => '12.34', 'provider_reference' => 'pay_prova'];

check('l\'ambiente attivo segue stripe_test', fn () =>
    StripeProvider::activeEnvironment(chiavi()) === 'test'
    && StripeProvider::activeEnvironment(chiavi(['stripe_test' => false])) === 'live'
);

check('collegato solo con le quattro credenziali dell\'ambiente attivo', fn () =>
    (new StripeProvider(chiavi()))->connected()
    && !(new StripeProvider(chiavi(['stripe_test_webhook_secret' => ''])))->connected()
    && !(new StripeProvider(chiavi(['stripe_test_public_key' => ''])))->connected()
    && (new StripeProvider(chiavi(['stripe_test' => false, 'stripe_test_key' => ''])))->connected()
);

check('i tipi di pagamento del metodo si leggono dalla colonna', fn () =>
    StripeProvider::methodTypes('card, klarna ,') === ['card', 'klarna']
    && StripeProvider::methodTypes('') === []
);

check('start crea il Customer, poi l\'intento sul conto collegato, in centesimi e con la chiave pay_', fn () => prova(static function () use ($ordine, $pagamento): bool {
    $http = FakeStripeHttp::install();
    $http->queue(200, ['id' => 'cus_prova', 'object' => 'customer']);
    $http->queue(200, intento());
    $avvio = (new StripeProvider(chiavi()))->start($ordine, $pagamento);
    $parametri = $http->requests[1]['params'];

    return $http->path(0) === '/v1/customers'
        && $http->path(1) === '/v1/payment_intents'
        && $http->header(1, 'Stripe-Account') === 'acct_prova_test'
        && $http->header(1, 'Idempotency-Key') === 'pay_prova'
        && (int) $parametri['amount'] === 1234
        && $parametri['currency'] === 'eur'
        && $parametri['customer'] === 'cus_prova'
        && $parametri['description'] === 'W-7'
        && $parametri['metadata']['order_id'] === '7'
        && $parametri['metadata']['payment_code'] === 'pay_prova'
        && ($parametri['automatic_payment_methods']['enabled'] ?? '') === 'true'
        && $avvio->reference === 'pi_nuovo'
        && $avvio->clientSecret === 'pi_nuovo_secret_prova'
        && $avvio->environment === 'test'
        && (ExternalReferences::find('contact', 987654321, 'stripe', 'customer', 'test')['external_id'] ?? '') === 'cus_prova';
}));

check('il Customer già salvato si riusa, senza crearne un altro', fn () => prova(static function () use ($ordine, $pagamento): bool {
    ExternalReferences::save('contact', 987654321, 'stripe', 'customer', 'cus_salvato', 'test');
    $http = FakeStripeHttp::install();
    $http->queue(200, intento());
    (new StripeProvider(chiavi()))->start($ordine, $pagamento);

    return count($http->requests) === 1
        && $http->requests[0]['params']['customer'] === 'cus_salvato';
}));

check('un intento ancora da pagare si riusa', fn () => prova(static function () use ($ordine, $pagamento): bool {
    ExternalReferences::save('contact', 987654321, 'stripe', 'customer', 'cus_salvato', 'test');
    $http = FakeStripeHttp::install();
    $http->queue(200, intento(['id' => 'pi_vecchio', 'client_secret' => 'pi_vecchio_secret']));
    $avvio = (new StripeProvider(chiavi()))->start($ordine, ['provider_reference' => 'pi_vecchio'] + $pagamento);

    return count($http->requests) === 1
        && $http->requests[0]['method'] === 'GET'
        && $avvio->reference === 'pi_vecchio'
        && $avvio->clientSecret === 'pi_vecchio_secret';
}));

check('un intento annullato lascia il posto a uno nuovo, con la chiave -2', fn () => prova(static function () use ($ordine, $pagamento): bool {
    ExternalReferences::save('contact', 987654321, 'stripe', 'customer', 'cus_salvato', 'test');
    $http = FakeStripeHttp::install();
    $http->queue(200, intento(['id' => 'pi_vecchio', 'status' => 'canceled']));
    $http->queue(200, intento(['id' => 'pi_secondo', 'metadata' => ['order_id' => '7', 'attempt' => '2']]));
    $avvio = (new StripeProvider(chiavi()))->start($ordine, ['provider_reference' => 'pi_vecchio'] + $pagamento);

    return $avvio->reference === 'pi_secondo'
        && $http->header(1, 'Idempotency-Key') === 'pay_prova-2'
        && $http->requests[1]['params']['metadata']['attempt'] === '2';
}));

check('start: un intento già incassato non ne apre un altro', fn () => prova(static function () use ($ordine, $pagamento): bool {
    ExternalReferences::save('contact', 987654321, 'stripe', 'customer', 'cus_salvato', 'test');
    $http = FakeStripeHttp::install();
    $http->queue(200, intento(['id' => 'pi_vecchio', 'status' => 'succeeded', 'amount_received' => 1234]));
    $http->queue(200, intento(['id' => 'pi_doppio']));

    try {
        (new StripeProvider(chiavi()))->start($ordine, ['provider_reference' => 'pi_vecchio'] + $pagamento);

        return false;
    } catch (RuntimeException) {
        return count($http->requests) === 1 && $http->requests[0]['method'] === 'GET';
    }
}));

check('start: un intento in verifica non ne apre un altro', fn () => prova(static function () use ($ordine, $pagamento): bool {
    ExternalReferences::save('contact', 987654321, 'stripe', 'customer', 'cus_salvato', 'test');
    $http = FakeStripeHttp::install();
    $http->queue(200, intento(['id' => 'pi_vecchio', 'status' => 'processing']));
    $http->queue(200, intento(['id' => 'pi_doppio']));

    try {
        (new StripeProvider(chiavi()))->start($ordine, ['provider_reference' => 'pi_vecchio'] + $pagamento);

        return false;
    } catch (RuntimeException) {
        return count($http->requests) === 1 && $http->requests[0]['method'] === 'GET';
    }
}));

function configurazioni(array $tipi): array
{
    $configurazione = ['id' => 'pmc_prova', 'object' => 'payment_method_configuration', 'active' => true, 'is_default' => true];
    foreach ($tipi as $tipo) {
        $configurazione[$tipo] = ['available' => true];
    }

    return ['object' => 'list', 'url' => '/v1/payment_method_configurations', 'has_more' => false, 'data' => [$configurazione]];
}

function cacheMetodi(?array $valore): void
{
    $id = (int) \Wonder\Plugin\Gestionale\Models\System\Setting::current()['id'];
    \Wonder\Plugin\Gestionale\Models\System\Setting::update(['stripe_methods_cache' => $valore === null ? '' : json_encode($valore)], $id);
}

check('senza cache i tipi si leggono da Stripe e si mettono in cache', fn () => prova(function () {
    $http = FakeStripeHttp::install();
    StripeProvider::forget();
    cacheMetodi(null);
    $http->queue(200, configurazioni(['card', 'klarna']));
    $tipi = (new StripeProvider(chiavi()))->activeTypes();
    $cache = json_decode((string) \Wonder\Plugin\Gestionale\Models\System\Setting::current()['stripe_methods_cache'], true);

    return $tipi === ['card', 'klarna']
        && $http->path(0) === '/v1/payment_method_configurations'
        && $http->header(0, 'Stripe-Account') === 'acct_prova_test'
        && $cache['environment'] === 'test' && $cache['account'] === 'acct_prova_test' && $cache['types'] === ['card', 'klarna'];
}));

check('cache valida: nessuna chiamata', fn () => prova(function () {
    $http = FakeStripeHttp::install();
    StripeProvider::forget();
    cacheMetodi(['environment' => 'test', 'account' => 'acct_prova_test', 'types' => ['card', 'paypal'], 'fetched_at' => time() - 60]);

    return (new StripeProvider(chiavi()))->activeTypes() === ['card', 'paypal'] && $http->requests === [];
}));

check('cache scaduta: si rilegge', fn () => prova(function () {
    $http = FakeStripeHttp::install();
    StripeProvider::forget();
    cacheMetodi(['environment' => 'test', 'account' => 'acct_prova_test', 'types' => ['card', 'paypal'], 'fetched_at' => time() - 601]);
    $http->queue(200, configurazioni(['card', 'klarna']));

    return (new StripeProvider(chiavi()))->activeTypes() === ['card', 'klarna'] && count($http->requests) === 1;
}));

check('cache di un altro ambiente: non vale', fn () => prova(function () {
    $http = FakeStripeHttp::install();
    StripeProvider::forget();
    cacheMetodi(['environment' => 'test', 'account' => 'acct_prova_test', 'types' => ['card', 'paypal'], 'fetched_at' => time()]);
    $http->queue(200, configurazioni(['card']));
    $tipi = (new StripeProvider(chiavi(['stripe_test' => false])))->activeTypes();

    return $tipi === ['card'] && $http->header(0, 'Stripe-Account') === 'acct_prova_live';
}));

check('cache di un altro conto: non vale', fn () => prova(function () {
    $http = FakeStripeHttp::install();
    StripeProvider::forget();
    cacheMetodi(['environment' => 'test', 'account' => 'acct_vecchio', 'types' => ['card', 'paypal'], 'fetched_at' => time()]);
    $http->queue(200, configurazioni(['card', 'klarna']));

    return (new StripeProvider(chiavi()))->activeTypes() === ['card', 'klarna'];
}));

check('chiamata fallita con cache scaduta: si usa la cache', fn () => prova(function () {
    $http = FakeStripeHttp::install();
    StripeProvider::forget();
    cacheMetodi(['environment' => 'test', 'account' => 'acct_prova_test', 'types' => ['card', 'paypal'], 'fetched_at' => time() - 9999]);
    $http->queue(500, ['error' => ['message' => 'giù']]);

    return (new StripeProvider(chiavi()))->activeTypes() === ['card', 'paypal'];
}));

check('chiamata fallita senza cache: solo la carta', fn () => prova(function () {
    $http = FakeStripeHttp::install();
    StripeProvider::forget();
    cacheMetodi(null);
    $http->queue(500, ['error' => ['message' => 'giù']]);

    return (new StripeProvider(chiavi()))->activeTypes() === ['card'];
}));

check('non collegato: solo la carta, senza chiamate', fn () => prova(function () {
    $http = FakeStripeHttp::install();
    StripeProvider::forget();
    cacheMetodi(null);

    return (new StripeProvider(chiavi(['stripe_test_key' => ''])))->activeTypes() === ['card'] && $http->requests === [];
}));

check('nella stessa richiesta Stripe si chiama una volta sola', fn () => prova(function () {
    $http = FakeStripeHttp::install();
    StripeProvider::forget();
    cacheMetodi(null);
    $http->queue(200, configurazioni(['card', 'klarna']));
    $provider = new StripeProvider(chiavi());
    $provider->activeTypes();
    cacheMetodi(null);

    return $provider->activeTypes() === ['card', 'klarna'] && count($http->requests) === 1;
}));

/** Avvia un pagamento con un Customer già salvato; restituisce l'avvio e il finto HTTP. */
function avviaConScelta(array $campiPagamento, array $risposte): array
{
    global $ordine, $pagamento;
    ExternalReferences::save('contact', 987654321, 'stripe', 'customer', 'cus_salvato', 'test');
    $http = FakeStripeHttp::install();
    foreach ($risposte as $risposta) {
        $http->queue(200, $risposta);
    }
    $avvio = (new StripeProvider(chiavi()))->start($ordine, $campiPagamento + $pagamento);

    return [$avvio, $http];
}

check('start con provider_method klarna: solo klarna', fn () => prova(static function (): bool {
    [, $http] = avviaConScelta(['provider_method' => 'klarna'], [intento()]);
    $parametri = $http->requests[0]['params'];

    return $http->path(0) === '/v1/payment_intents'
        && $parametri['payment_method_types'] === ['klarna']
        && !isset($parametri['automatic_payment_methods']);
}));

check('start con provider_method card: solo la carta', fn () => prova(static function (): bool {
    [, $http] = avviaConScelta(['provider_method' => 'card'], [intento()]);

    return $http->requests[0]['params']['payment_method_types'] === ['card'];
}));

check('start con provider_method link: solo link', fn () => prova(static function (): bool {
    [, $http] = avviaConScelta(['provider_method' => 'link'], [intento()]);

    return $http->requests[0]['params']['payment_method_types'] === ['link'];
}));

check('start senza provider_method e senza CSV: decide Stripe', fn () => prova(static function (): bool {
    [, $http] = avviaConScelta([], [intento()]);
    $parametri = $http->requests[0]['params'];

    return ($parametri['automatic_payment_methods']['enabled'] ?? '') === 'true'
        && !isset($parametri['payment_method_types']);
}));

check('riuso con tipi diversi fa update dei tipi, non un secondo intento', fn () => prova(static function (): bool {
    [$avvio, $http] = avviaConScelta(
        ['provider_reference' => 'pi_vecchio', 'provider_method' => 'klarna'],
        [intento(['id' => 'pi_vecchio', 'payment_method_types' => ['card']]), intento(['id' => 'pi_vecchio', 'client_secret' => 'pi_vecchio_secret', 'payment_method_types' => ['klarna']])]
    );

    return count($http->requests) === 2
        && $http->requests[0]['method'] === 'GET'
        && $http->requests[1]['method'] === 'POST'
        && $http->path(1) === '/v1/payment_intents/pi_vecchio'
        && $http->requests[1]['params']['payment_method_types'] === ['klarna']
        && $avvio->reference === 'pi_vecchio'
        && $avvio->clientSecret === 'pi_vecchio_secret';
}));

check('riuso con gli stessi tipi, in altro ordine: niente update', fn () => prova(static function (): bool {
    [$avvio, $http] = avviaConScelta(
        ['provider_reference' => 'pi_vecchio', 'provider_method' => 'card'],
        [intento(['id' => 'pi_vecchio', 'payment_method_types' => ['card']])]
    );

    return count($http->requests) === 1 && $http->requests[0]['method'] === 'GET' && $avvio->reference === 'pi_vecchio';
}));

check('riuso senza scelta: l\'intento resta com\'è', fn () => prova(static function (): bool {
    [$avvio, $http] = avviaConScelta(
        ['provider_reference' => 'pi_vecchio'],
        [intento(['id' => 'pi_vecchio', 'payment_method_types' => ['card', 'link']])]
    );

    return count($http->requests) === 1 && $http->requests[0]['method'] === 'GET' && $avvio->reference === 'pi_vecchio';
}));

check('status traduce lo stato e porta importo, valuta e ordine', function () {
    $http = FakeStripeHttp::install();
    $http->queue(200, intento(['status' => 'succeeded', 'amount_received' => 1234]));
    $http->queue(200, intento(['status' => 'requires_action']));
    $provider = new StripeProvider(chiavi());
    $riuscito = $provider->status('pi_nuovo');
    $altro = $provider->status('pi_nuovo');

    return $riuscito->status === PaymentState::SUCCEEDED
        && $riuscito->amount === 1234
        && $riuscito->currency === 'eur'
        && $riuscito->orderId === 7
        && $altro->status === PaymentState::OTHER;
});

check('evento con la firma dell\'ambiente attivo: valori tradotti', function () {
    $corpo = eventoStripe('payment_intent.succeeded', ['metadata' => ['order_id' => '7']]);
    $evento = (new StripeProvider(chiavi()))->event($corpo, firmaStripe($corpo, 'whsec_prova_test'));

    return $evento !== null
        && $evento->id === 'evt_prova_succeeded'
        && $evento->type === 'payment_intent.succeeded'
        && $evento->environment === 'test'
        && $evento->reference === 'pi_prova'
        && $evento->amount === 5000
        && $evento->orderId === 7
        && $evento->chargeId === 'ch_prova';
});

check('firma sbagliata o scaduta: nessun evento', function () {
    $corpo = eventoStripe('payment_intent.succeeded');
    $provider = new StripeProvider(chiavi());

    return $provider->event($corpo, firmaStripe($corpo, 'whsec_altro')) === null
        && $provider->event($corpo, firmaStripe($corpo, 'whsec_prova_test', time() - 3600)) === null
        && $provider->event($corpo, '') === null;
});

check('firmato col segreto dell\'altro ambiente: l\'evento dice quell\'ambiente', function () {
    $corpo = eventoStripe('payment_intent.succeeded', [], ['livemode' => true]);
    $evento = (new StripeProvider(chiavi()))->event($corpo, firmaStripe($corpo, 'whsec_prova_live'));

    return $evento !== null && $evento->environment === 'live';
});

check('livemode in disaccordo con la firma: ambiente vuoto', function () {
    $corpo = eventoStripe('payment_intent.succeeded', [], ['livemode' => true]);
    $evento = (new StripeProvider(chiavi()))->event($corpo, firmaStripe($corpo, 'whsec_prova_test'));

    return $evento !== null && $evento->environment === '';
});

check('rimborso con l\'elenco nella carica', function () {
    $corpo = eventoStripe('charge.refunded');
    $evento = (new StripeProvider(chiavi()))->event($corpo, firmaStripe($corpo, 'whsec_prova_test'));

    return $evento !== null
        && $evento->reference === 'pi_prova'
        && $evento->chargeId === 'ch_prova'
        && $evento->refunds === [['id' => 're_prova_1', 'amount' => 2000, 'status' => 'succeeded']];
});

check('rimborso senza elenco nella carica: lo chiede a Stripe', function () {
    $corpo = eventoStripe('charge.refunded', ['refunds' => null]);
    $http = FakeStripeHttp::install();
    $http->queue(200, ['object' => 'list', 'url' => '/v1/refunds', 'has_more' => false, 'data' => [
        ['id' => 're_prova_2', 'object' => 'refund', 'amount' => 700, 'status' => 'succeeded'],
    ]]);
    $evento = (new StripeProvider(chiavi()))->event($corpo, firmaStripe($corpo, 'whsec_prova_test'));

    return $http->path(0) === '/v1/refunds'
        && $evento !== null
        && $evento->refunds === [['id' => 're_prova_2', 'amount' => 700, 'status' => 'succeeded']];
});

check('replay rifà l\'evento dal payload salvato, senza firma', function () {
    $dati = json_decode(eventoStripe('payment_intent.succeeded', ['metadata' => ['order_id' => '7']]), true);
    $evento = (new StripeProvider(chiavi()))->replay($dati, 'test');

    return $evento !== null
        && $evento->id === 'evt_prova_succeeded'
        && $evento->environment === 'test'
        && $evento->orderId === 7
        && (new StripeProvider(chiavi()))->replay(['id' => 'evt_rotto'], 'test') === null;
});

check('cancel annulla l\'intento sul conto collegato', function () {
    $http = FakeStripeHttp::install();
    $http->queue(200, intento(['status' => 'canceled']));
    (new StripeProvider(chiavi()))->cancel('pi_nuovo');

    return $http->path(0) === '/v1/payment_intents/pi_nuovo/cancel'
        && $http->header(0, 'Stripe-Account') === 'acct_prova_test';
});

check('senza registrazioni, il registro dà lo StripeProvider', function () {
    PaymentProviders::reset();

    return PaymentProviders::get('stripe') instanceof StripeProvider;
});

summary();
