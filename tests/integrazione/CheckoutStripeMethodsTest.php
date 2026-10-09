<?php
/** php tests/integrazione/CheckoutStripeMethodsTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';
require __DIR__ . '/supporto/compra.php';
require __DIR__ . '/supporto/spedizioni.php';
require __DIR__ . '/supporto/FakeStripeHttp.php';

use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Providers\Payments\StripeProvider;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            accendiFunzionalita(['orders', 'coupons']);
            spegniFunzionalita(['shipping']);
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    } finally {
        PaymentProviders::reset();
    }

    return $esito;
}

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

function cacheMetodi(?array $valore): void
{
    $id = (int) Setting::current()['id'];
    Setting::update(['stripe_methods_cache' => $valore === null ? '' : json_encode($valore)], $id);
}

/**
 * Stripe collegato (chiavi finte) con questi tipi in cache: nessuna chiamata
 * di rete. Con `$scaduta` la cache è vecchia e una lettura partirebbe: il
 * finto restituito le registra.
 */
function stripeConTipi(array $tipi, bool $scaduta = false): FakeStripeHttp
{
    $http = FakeStripeHttp::install();
    StripeProvider::forget();
    PaymentProviders::reset();
    PaymentProviders::register(new StripeProvider(chiavi()));
    cacheMetodi(['environment' => 'test', 'account' => 'acct_prova_test', 'types' => $tipi, 'fetched_at' => $scaduta ? time() - 3600 : time()]);

    return $http;
}

function configurazioni(array $tipi): array
{
    $configurazione = ['id' => 'pmc_prova', 'object' => 'payment_method_configuration', 'active' => true, 'is_default' => true];
    foreach ($tipi as $tipo) {
        $configurazione[$tipo] = ['available' => true];
    }

    return ['object' => 'list', 'url' => '/v1/payment_method_configurations', 'has_more' => false, 'data' => [$configurazione]];
}

const TIPI = ['card', 'apple_pay', 'google_pay', 'link', 'klarna', 'sepa_debit'];

/** I metodi di pagamento del sito di prova non devono mischiarsi con quelli della prova. */
function soloQuestiMetodi(): void
{
    sqlModify(PaymentMethod::$table, ['active' => 'false'], 'active', 'true');
}

function metodoStripe(): int
{
    return (int) (PaymentMethod::create([
        'code' => 'tst_'.uniqid(),
        'name' => 'Carta di credito',
        'provider' => 'stripe',
        'timing' => 'immediate',
        'applies_online' => 'true',
        'icons' => 'visa,master,google_pay,apple_pay,klarna',
        'fee_type' => 'none',
        'fee_value' => '0.00',
        'fee_percent' => '0.00',
        'available_for' => 'all',
        'active' => 'true',
        'position' => 1,
        'instructions' => '',
    ])->insert_id ?? 0);
}

function metodoBonifico(): int
{
    return (int) (PaymentMethod::create([
        'code' => 'tst_'.uniqid(),
        'name' => 'Bonifico',
        'provider' => 'bank_transfer',
        'timing' => 'deferred',
        'applies_online' => 'true',
        'fee_type' => 'none',
        'fee_value' => '0.00',
        'fee_percent' => '0.00',
        'available_for' => 'all',
        'active' => 'true',
        'position' => 2,
        'instructions' => 'Istruzioni di prova',
    ])->insert_id ?? 0);
}

function rifiuto(callable $fn): string
{
    try {
        $fn();
    } catch (UserError $errore) {
        return $errore->key();
    }

    return '';
}

/** Il checkout, senza spedire posta, sul metodo dato e con i dati dati. */
function compra(int $metodo, array $dati = []): array
{
    Mailer::useTransport(static fn (): bool => true);

    try {
        $cart = carrelloSenzaSpedizione();

        return Checkout::place($cart, $dati + [
            'email' => 'cliente@example.com',
            'payment_method_id' => $metodo,
            'fulfillment_type' => 'shipping',
            'billing' => ['country' => 'IT', 'province' => 'MI', 'city' => 'Milano', 'cap' => '20100', 'street' => 'Via Prova', 'number' => '1', 'name' => 'Mario', 'surname' => 'Rossi'],
        ]);
    } finally {
        Mailer::useTransport(null);
    }
}

/** `carrello()` riaccende le spedizioni: qui non servono, si rispengono. */
function carrelloSenzaSpedizione(): int
{
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    spegniFunzionalita(['shipping']);

    return $cart;
}

function providerMethod(array $esito): string
{
    return (string) Payment::findById((int) $esito['payment_id'])['provider_method'];
}

function anteprima(int $metodo): array
{
    $cart = carrelloSenzaSpedizione();

    return Checkout::preview($cart, ['payment_method_id' => $metodo]);
}

check('il metodo Stripe dà una scelta per la carta e per ogni tipo acceso, senza wallet', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    stripeConTipi(TIPI);
    $id = metodoStripe();
    $voci = array_values(array_filter(anteprima($id)['payment_methods']['options'], static fn (array $v): bool => $v['id'] === $id));
    PaymentProviders::reset();
    [$carta, $klarna, $sepa] = $voci + [null, null, null];

    return count($voci) === 3
        && array_column($voci, 'stripe_method_type') === ['card', 'klarna', 'sepa_debit']
        && array_column($voci, 'key') === ["{$id}", "{$id}:klarna", "{$id}:sepa_debit"]
        && array_unique(array_column($voci, 'id')) === [$id]
        && $carta['name'] === (string) PaymentMethod::findById($id)['name']
        && $carta['icons'] === ['visa', 'master']
        && $carta['payment_method_types'] === ['card']
        && $klarna['payment_method_types'] === ['klarna']
        && $sepa['name'] === 'Addebito SEPA';
}));

check('i metodi non Stripe restano una voce sola, con key, tipo vuoto e nessun tipo di intento', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    stripeConTipi(TIPI);
    $stripe = metodoStripe();
    $id = metodoBonifico();
    $voci = array_values(array_filter(anteprima($stripe)['payment_methods']['options'], static fn (array $v): bool => $v['id'] === $id));
    PaymentProviders::reset();

    return count($voci) === 1
        && $voci[0]['key'] === "{$id}"
        && $voci[0]['stripe_method_type'] === ''
        && $voci[0]['payment_method_types'] === [];
}));

check('selected resta l\'id del metodo', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    stripeConTipi(TIPI);
    $id = metodoStripe();
    $selected = anteprima($id)['payment_methods']['selected'];
    PaymentProviders::reset();

    return $selected === $id;
}));

check('place con un tipo non in elenco si rifiuta: spento (paypal) o wallet (apple_pay)', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    stripeConTipi(TIPI);
    $id = metodoStripe();
    $esiti = [];

    foreach (['paypal', 'apple_pay'] as $tipo) {
        $esiti[] = rifiuto(fn () => compra($id, ['stripe_method_type' => $tipo]));
    }

    PaymentProviders::reset();

    return $esiti === ['order.payment_method_unavailable', 'order.payment_method_unavailable'];
}));

check('place senza tipo su Stripe apre il pagamento con provider_method card', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    stripeConTipi(TIPI);
    $esito = compra(metodoStripe());
    PaymentProviders::reset();

    return providerMethod($esito) === 'card';
}));

check('place con klarna scrive klarna su provider_method', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    stripeConTipi(TIPI);
    $esito = compra(metodoStripe(), ['stripe_method_type' => 'klarna']);
    PaymentProviders::reset();

    return providerMethod($esito) === 'klarna';
}));

check('place su un metodo non Stripe ignora stripe_method_type', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    stripeConTipi(TIPI);
    $esito = compra(metodoBonifico(), ['stripe_method_type' => 'klarna']);
    PaymentProviders::reset();

    return providerMethod($esito) === '';
}));

PaymentProviders::reset();

check('con un metodo non Stripe scelto in place, Stripe collegato non viene letto', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    $http = stripeConTipi(TIPI, true);
    $esito = compra(metodoBonifico());

    return $esito['status'] === 'pending' && $http->requests === [];
}));

check('se tra i metodi attivi non c\'è Stripe, il preview non legge Stripe', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    $http = stripeConTipi(TIPI, true);
    $voci = anteprima(metodoBonifico())['payment_methods']['options'];

    return count($voci) === 1 && $http->requests === [];
}));

check('un carrello che non c\'è si rifiuta senza leggere Stripe, in preview e in place', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    $http = stripeConTipi(TIPI, true);
    $id = metodoStripe();

    return rifiuto(fn () => Checkout::preview(0, ['payment_method_id' => $id])) === 'cart.not_a_cart'
        && rifiuto(fn () => Checkout::place(0, ['payment_method_id' => $id])) === 'cart.not_a_cart'
        && $http->requests === [];
}));

check('con Stripe tra i metodi attivi il preview legge i tipi una volta, a cache scaduta', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    $http = stripeConTipi(TIPI, true);
    $http->queue(200, configurazioni(['card', 'klarna']));
    $id = metodoStripe();
    $tipi = array_column(array_filter(anteprima($id)['payment_methods']['options'], static fn (array $v): bool => $v['id'] === $id), 'stripe_method_type');

    return $tipi === ['card', 'klarna']
        && count($http->requests) === 1
        && $http->path(0) === '/v1/payment_method_configurations';
}));

check('con Stripe scelto in place i tipi si leggono una volta, a cache scaduta', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    $http = stripeConTipi(TIPI, true);
    $http->queue(200, configurazioni(['card', 'klarna']));
    $esito = compra(metodoStripe(), ['stripe_method_type' => 'klarna']);

    return providerMethod($esito) === 'klarna' && count($http->requests) === 1;
}));

check('un payment_method_id con il tipo attaccato non diventa l\'id in silenzio', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    stripeConTipi(TIPI);
    $id = metodoStripe();
    $cart = carrelloSenzaSpedizione();
    $selected = Checkout::preview($cart, ['payment_method_id' => "{$id}:klarna"])['payment_methods']['selected'];
    $rifiuto = rifiuto(fn () => Checkout::place($cart, [
        'email' => 'cliente@example.com',
        'payment_method_id' => "{$id}:klarna",
        'fulfillment_type' => 'shipping',
        'billing' => ['country' => 'IT', 'province' => 'MI', 'city' => 'Milano', 'cap' => '20100', 'street' => 'Via Prova', 'number' => '1', 'name' => 'Mario', 'surname' => 'Rossi'],
    ]));
    $resta = (string) Wonder\Plugin\Gestionale\Models\Sales\Order::findById($cart)['stage'] === 'cart';

    return $rifiuto === 'order.payment_method_unavailable' && $selected === 0 && $resta;
}));

check('il client dei test non esce dalla rete: la carta sola, e un errore chiaro per il resto', fn () => prova(static function (): bool {
    $senzaRete = StripeSenzaRete::install();
    StripeProvider::forget();
    cacheMetodi(null);
    $tipi = (new StripeProvider(chiavi()))->activeTypes();
    $errore = '';

    try {
        (new \Stripe\StripeClient(['api_key' => 'sk_test_prova']))->customers->create(['email' => 'cliente@example.com']);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        $errore = $e->getMessage();
    }

    return $tipi === ['card']
        && $senzaRete->requests[0] === 'GET /v1/payment_method_configurations'
        && str_contains($errore, 'Test senza rete');
}));

summary();
