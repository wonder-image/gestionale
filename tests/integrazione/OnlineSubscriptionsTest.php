<?php
/** php tests/integrazione/OnlineSubscriptionsTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/FakePaymentProvider.php';
require __DIR__.'/supporto/posta.php';
require __DIR__.'/../supporto/FakeSubscriptionGateway.php';

use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItem;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItemChange;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionStatusLog;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentEvent;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentEvents;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
use Wonder\Plugin\Gestionale\Support\Providers\ExternalReferences;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OnlineSubscriptions;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionEvents;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionGateways;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionItems;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$provider = new FakePaymentProvider();
PaymentProviders::register($provider);

/** La prova dentro una transazione annullata, con Stripe finto e le funzionalità accese. */
function prova(callable $corpo): mixed
{
    $esito = null;
    $stripe = new FakeSubscriptionGateway('test');
    SubscriptionGateways::use($stripe);

    try {
        Transaction::run(static function () use ($corpo, $stripe, &$esito): void {
            accendiFunzionalita(['orders', 'online_sales', 'subscriptions', 'online_subscriptions']);
            $esito = $corpo($stripe);

            throw new Annulla();
        });
    } catch (Annulla) {
    } finally {
        SubscriptionGateways::use(null);
        Wonder\Plugin\Gestionale\Gestionale::reset();
    }

    return $esito;
}

/** Un piano visibile con un mensile (14 giorni di prova) e un annuale (vincolo 12 mesi). @return array{plan: int, mensile: array, annuale: array} */
function pianoOnline(): array
{
    // I codici come li dà la pagina dei piani: il negozio vende un prezzo dal suo `ppr_`.
    $plan = (int) (Plan::create(['code' => Code::make(Plan::class, Codes::PLAN), 'tax_category_id' => categoriaFiscaleDiProva(), 'name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'active' => 'true', 'visible_online' => 'true'])->insert_id ?? 0);
    $mensile = (int) (PlanPrice::create(['code' => Code::make(PlanPrice::class, Codes::PLAN_PRICE), 'plan_id' => $plan, 'name' => 'Mensile', 'billing_interval' => 'month', 'term_months' => 1, 'trial_days' => 14, 'price' => '24.40', 'sale_price' => '0.00', 'active' => 'true', 'position' => 0])->insert_id ?? 0);
    $annuale = (int) (PlanPrice::create(['code' => Code::make(PlanPrice::class, Codes::PLAN_PRICE), 'plan_id' => $plan, 'name' => 'Annuale', 'billing_interval' => 'year', 'term_months' => 12, 'commitment_months' => 12, 'price' => '244.00', 'sale_price' => '0.00', 'renewal_plan_price_id' => $mensile, 'active' => 'true', 'position' => 1])->insert_id ?? 0);

    return ['plan' => $plan, 'mensile' => PlanPrice::findById($mensile), 'annuale' => PlanPrice::findById($annuale)];
}

function cliente(): int
{
    return (int) (Contact::create([
        'type' => 'private', 'name' => 'Lucia', 'surname' => 'Online', 'country' => 'IT', 'city' => 'Torino',
        'street' => 'Via Po', 'number' => '1', 'cap' => '10100',
        'email' => 'lucia.abb.'.uniqid().'@example.com', 'is_customer' => 'true', 'active' => 'true',
    ])->insert_id ?? 0);
}

function inizia(int $cliente, array $prezzo, string $coupon = ''): array
{
    return iniziaServizi($cliente, [['price_code' => (string) $prezzo['code']]], $coupon);
}

/** @param list<array{price_code: string, quantity?: int, customization?: array, parent?: int}> $servizi */
function iniziaServizi(int $cliente, array $servizi, string $coupon = ''): array
{
    return OnlineSubscriptions::start($cliente, $servizi, [], $coupon, ['success' => 'https://negozio.test/ok?abbonamento={SUBSCRIPTION}', 'cancel' => 'https://negozio.test/no']);
}

/** Un servizio venduto online, con un prezzo solo. @return array<string, mixed> il prezzo */
function servizioOnline(string $nome, int $mesi, string $prezzo, int $prova = 0): array
{
    $plan = (int) (Plan::create(['code' => Code::make(Plan::class, Codes::PLAN), 'tax_category_id' => categoriaFiscaleDiProva(), 'name' => $nome, 'slug' => 'srv-'.uniqid(), 'active' => 'true', 'visible_online' => 'true'])->insert_id ?? 0);
    $price = (int) (PlanPrice::create(['code' => Code::make(PlanPrice::class, Codes::PLAN_PRICE), 'plan_id' => $plan, 'name' => $mesi === 12 ? 'Annuale' : 'Mensile', 'billing_interval' => $mesi === 12 ? 'year' : 'month', 'term_months' => $mesi, 'trial_days' => $prova, 'price' => $prezzo, 'sale_price' => '0.00', 'active' => 'true', 'position' => 0])->insert_id ?? 0);

    return PlanPrice::findById($price);
}

function rifiuto(callable $f): ?string
{
    try {
        $f();
    } catch (UserError $e) {
        return $e->key();
    }

    return null;
}

/** Una notifica di Stripe con quell'oggetto. */
function notifica(string $tipo, array $oggetto): PaymentEvent
{
    $id = 'evt_'.uniqid();

    return new PaymentEvent($id, $tipo, 'test', (string) ($oggetto['id'] ?? ''), payload: ['id' => $id, 'type' => $tipo, 'data' => ['object' => $oggetto]]);
}

/** Un abbonamento Stripe nella forma «basil». */
function abbonamentoStripe(string $id, int $locale, string $stato, string $prezzo, array $sopra = []): array
{
    $inizio = time();

    return array_replace_recursive([
        'id' => $id, 'object' => 'subscription', 'status' => $stato, 'customer' => 'cus_x', 'start_date' => $inizio,
        'trial_end' => $stato === 'trialing' ? $inizio + 14 * 86400 : null, 'cancel_at_period_end' => false,
        'metadata' => ['subscription_id' => (string) $locale],
        'items' => ['data' => [['id' => 'si_'.$locale, 'current_period_start' => $inizio, 'current_period_end' => $inizio + 30 * 86400, 'price' => ['id' => $prezzo]]]],
    ], $sopra);
}

function fattura(string $id, string $abbonamento, int $locale, int $centesimi): array
{
    return [
        'id' => $id, 'object' => 'invoice', 'amount_paid' => $centesimi, 'currency' => 'eur', 'status_transitions' => ['paid_at' => time()],
        'parent' => ['subscription_details' => ['subscription' => $abbonamento, 'metadata' => ['subscription_id' => (string) $locale]]],
    ];
}

function righe(string $model, array $dove): array
{
    $rows = $model::find($dove + ['deleted' => 'false']);

    return is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'))) : [];
}

/** L'id del prezzo Stripe che il catalogo ha creato per quel prezzo del piano. */
function prezzoStripe(array $prezzo): string
{
    foreach (ExternalReferences::all('plan_price', (int) $prezzo['id']) as $ref) {
        return (string) $ref['external_id'];
    }

    return '';
}

check('la sottoscrizione crea l\'abbonamento in attesa e la pagina di Stripe con prova, IVA e metadati', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    $p = pianoOnline();
    $avvio = inizia(cliente(), $p['mensile']);
    $riga = Subscription::findById($avvio['subscription_id']);
    $sessione = $stripe->last('checkout.session.create');

    return str_starts_with($avvio['url'], 'https://checkout.stripe.test/')
        && (string) $riga['status'] === 'pending' && (string) $riga['channel'] === 'online' && (string) $riga['environment'] === 'test'
        && (string) $riga['billing_city'] === 'Torino' && round((float) $riga['total'], 2) === 24.4
        && $sessione['mode'] === 'subscription' && $sessione['subscription_data']['trial_period_days'] === 14
        && $sessione['subscription_data']['metadata']['subscription_id'] === (string) $avvio['subscription_id']
        && $sessione['client_reference_id'] === $avvio['code']
        && $sessione['success_url'] === 'https://negozio.test/ok?abbonamento='.$avvio['code']
        && $sessione['payment_method_types'] === ['card', 'link']
        && count($sessione['line_items'][0]['tax_rates'] ?? []) === ((float) $riga['tax_rate'] > 0 ? 1 : 0)
        && ExternalReferences::find('subscription', $avvio['subscription_id'], 'stripe', 'checkout_session', 'test') !== null;
}));

check('prodotto e prezzo Stripe si creano una volta; un prezzo cambiato è un prezzo nuovo', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    $p = pianoOnline();
    inizia(cliente(), $p['mensile']);
    inizia(cliente(), $p['mensile']);
    $prima = [$stripe->count('product.create'), count(array_unique(array_map(static fn ($c) => $c[1]['_key'], array_filter($stripe->calls, static fn ($c) => $c[0] === 'price.create'))))];
    PlanPrice::update(['price' => '29.90'], (int) $p['mensile']['id']);
    inizia(cliente(), $p['mensile']);
    $chiavi = array_unique(array_map(static fn ($c) => $c[1]['_key'], array_filter($stripe->calls, static fn ($c) => $c[0] === 'price.create')));

    return $prima === [1, 1] && count($chiavi) === 2;
}));

check('rifiuti: senza account, già abbonato, piano nascosto', fn () => prova(static function (): bool {
    $p = pianoOnline();
    $persona = cliente();
    $senza = rifiuto(static fn () => inizia(0, $p['mensile']));
    $avvio = inizia($persona, $p['mensile']);
    Subscription::update(['status' => 'active'], $avvio['subscription_id']);
    $doppio = rifiuto(static fn () => inizia($persona, $p['annuale']));
    Plan::update(['visible_online' => 'false'], $p['plan']);
    $nascosto = rifiuto(static fn () => inizia(cliente(), $p['mensile']));

    return $senza === 'subscription.account_required' && $doppio === 'subscription.limit_reached' && $nascosto === 'subscription.price_unavailable';
}));

check('la prova si usa una volta per piano: un pending abbandonato non conta', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    $p = pianoOnline();
    $persona = cliente();
    $abbandonato = inizia($persona, $p['mensile']);
    Subscription::update(['status' => 'cancelled'], $abbandonato['subscription_id']);
    inizia($persona, $p['mensile']);
    $ancora = $stripe->last('checkout.session.create')['subscription_data']['trial_period_days'] ?? 0;
    $ultimo = righe(Subscription::class, ['customer_id' => $persona]);
    Subscription::update(['status' => 'cancelled', 'started_at' => date('Y-m-d H:i:s')], (int) end($ultimo)['id']);
    inizia($persona, $p['mensile']);
    $poi = $stripe->last('checkout.session.create')['subscription_data']['trial_period_days'] ?? 0;

    return $ancora === 14 && $poi === 0;
}));

check('coupon: senza «vale per gli abbonamenti» no; con, sconta e passa a Stripe il codice', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    $p = pianoOnline();
    $codice = 'ABB'.strtoupper(substr(uniqid(), -7));
    $id = (int) (Coupon::create(['code' => $codice, 'discount_type' => 'percent', 'discount_value' => '50', 'applies_online' => 'true', 'active' => 'true', 'applies_subscriptions' => 'false'])->insert_id ?? 0);
    $no = rifiuto(static fn () => inizia(cliente(), $p['mensile'], $codice));
    Coupon::update(['applies_subscriptions' => 'true', 'duration' => 'repeating', 'duration_months' => 3], $id);
    $avvio = inizia(cliente(), $p['mensile'], strtolower($codice));
    $riga = Subscription::findById($avvio['subscription_id']);
    $sessione = $stripe->last('checkout.session.create');

    return $no === 'coupon.not_for_subscriptions'
        && (string) $riga['coupon_code'] === $codice && round((float) $riga['total'], 2) === 12.2
        && str_starts_with((string) ($sessione['discounts'][0]['promotion_code'] ?? ''), 'promo_')
        && $stripe->last('coupon.create')['duration_in_months'] === 3;
}));

check('dalla notifica: collegato, in prova, una sola email di conferma e una sola riga di storia', fn () => prova(static function (): bool {
    $p = pianoOnline();
    $avvio = inizia(cliente(), $p['mensile']);
    $id = $avvio['subscription_id'];
    $prezzo = prezzoStripe($p['mensile']);
    $posta = conPosta(static function () use ($id, $avvio, $prezzo): void {
        SubscriptionEvents::apply(notifica('checkout.session.completed', ['id' => 'cs_1', 'mode' => 'subscription', 'subscription' => 'sub_S1', 'client_reference_id' => $avvio['code'], 'metadata' => ['subscription_id' => (string) $id]]));
        SubscriptionEvents::apply(notifica('customer.subscription.created', abbonamentoStripe('sub_S1', $id, 'trialing', $prezzo)));
        SubscriptionEvents::apply(notifica('customer.subscription.updated', abbonamentoStripe('sub_S1', $id, 'trialing', $prezzo)));
    });
    $riga = Subscription::findById($id);
    $storia = righe(SubscriptionStatusLog::class, ['subscription_id' => $id, 'to_value' => 'trialing']);

    return (string) $riga['provider_reference'] === 'sub_S1' && (string) $riga['status'] === 'trialing'
        && trim((string) $riga['started_at']) !== '' && trim((string) $riga['next_renewal_at']) !== ''
        && count(array_filter($posta, static fn ($m) => str_starts_with($m['subject'], '[subscription.confirmed] '))) === 1
        && count($storia) === 1;
}));

check('fatture: la prova a zero non è un pagamento; il primo addebito sì, una volta sola, e attiva', fn () => prova(static function (): bool {
    $p = pianoOnline();
    $avvio = inizia(cliente(), $p['mensile']);
    $id = $avvio['subscription_id'];
    SubscriptionEvents::apply(notifica('customer.subscription.created', abbonamentoStripe('sub_S2', $id, 'trialing', prezzoStripe($p['mensile']))));
    SubscriptionEvents::apply(notifica('invoice.paid', fattura('in_zero', 'sub_S2', $id, 0)));
    $dopoProva = count(righe(Payment::class, ['subscription_id' => $id]));
    SubscriptionEvents::apply(notifica('invoice.paid', fattura('in_1', 'sub_S2', $id, 2440)));
    SubscriptionEvents::apply(notifica('invoice.paid', fattura('in_1', 'sub_S2', $id, 2440)));
    $pagamenti = righe(Payment::class, ['subscription_id' => $id]);

    return $dopoProva === 0 && count($pagamenti) === 1
        && (string) $pagamenti[0]['status'] === 'paid' && round((float) $pagamenti[0]['amount'], 2) === 24.4
        && (string) $pagamenti[0]['provider_reference'] === 'in_1' && (int) $pagamenti[0]['order_id'] === 0
        && (string) Subscription::findById($id)['status'] === 'active';
}));

check('rinnovo non riuscito: da pagare, con l\'email; chiuso per mancato pagamento: non pagato', fn () => prova(static function (): bool {
    $p = pianoOnline();
    $avvio = inizia(cliente(), $p['mensile']);
    $id = $avvio['subscription_id'];
    $prezzo = prezzoStripe($p['mensile']);
    SubscriptionEvents::apply(notifica('customer.subscription.created', abbonamentoStripe('sub_S3', $id, 'active', $prezzo)));
    $posta = conPosta(static fn () => SubscriptionEvents::apply(notifica('invoice.payment_failed', fattura('in_f', 'sub_S3', $id, 0))));
    $dopo = (string) Subscription::findById($id)['status'];
    SubscriptionEvents::apply(notifica('customer.subscription.deleted', abbonamentoStripe('sub_S3', $id, 'canceled', $prezzo, ['cancellation_details' => ['reason' => 'payment_failed']])));
    $fine = Subscription::findById($id);

    return $dopo === 'past_due'
        && count(array_filter($posta, static fn ($m) => str_starts_with($m['subject'], '[subscription.payment_failed] '))) === 1
        && (string) $fine['status'] === 'unpaid' && (string) $fine['auto_renew'] === 'false' && trim((string) $fine['next_renewal_at'], ' 0:-') === '';
}));

check('l\'intento di una fattura senza ordine non finisce in «Da controllare»', fn () => prova(static function () use ($provider): bool {
    $evento = new PaymentEvent('evt_'.uniqid(), 'payment_intent.succeeded', 'test', 'pi_fattura_'.uniqid(), 2440, 'eur', 0);
    $posta = conPosta(static fn () => PaymentEvents::process($provider, $evento));

    return avvisiPagamento($posta) === [];
}));

check('cambio piano: Stripe senza conguagli, una riga sola anche quando torna la notifica', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    $p = pianoOnline();
    $avvio = inizia(cliente(), $p['mensile']);
    $id = $avvio['subscription_id'];
    SubscriptionEvents::apply(notifica('customer.subscription.created', abbonamentoStripe('sub_S4', $id, 'active', prezzoStripe($p['mensile']))));
    $stripe->objects['sub_S4'] = abbonamentoStripe('sub_S4', $id, 'active', prezzoStripe($p['mensile']));
    OnlineSubscriptions::changePlan($id, (int) $p['annuale']['id'], 'api');
    $chiamata = $stripe->last('subscription_item.update');
    SubscriptionEvents::apply(notifica('customer.subscription.updated', abbonamentoStripe('sub_S4', $id, 'active', prezzoStripe($p['annuale']))));

    return $chiamata['id'] === 'si_'.$id && $chiamata['proration_behavior'] === 'none'
        && str_starts_with((string) $chiamata['price'], 'price_') && $chiamata['price'] !== prezzoStripe($p['mensile'])
        && $stripe->count('subscription.update') === 0
        && SubscriptionItems::priceIdOf($id) === (int) $p['annuale']['id']
        && round((float) Subscription::findById($id)['total'], 2) === 244.0
        && count(righe(SubscriptionItemChange::class, ['subscription_id' => $id, 'action' => 'changed'])) === 1;
}));

check('disdetta fuori dal vincolo: a fine periodo in Stripe, con l\'email; la notifica non riaccende il rinnovo', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    $p = pianoOnline();
    $avvio = inizia(cliente(), $p['mensile']);
    $id = $avvio['subscription_id'];
    $prezzo = prezzoStripe($p['mensile']);
    SubscriptionEvents::apply(notifica('customer.subscription.created', abbonamentoStripe('sub_S5', $id, 'active', $prezzo)));
    $posta = conPosta(static fn () => OnlineSubscriptions::cancel($id));
    $chiamata = $stripe->last('subscription.update');
    SubscriptionEvents::apply(notifica('customer.subscription.updated', abbonamentoStripe('sub_S5', $id, 'active', $prezzo, ['cancel_at_period_end' => true])));

    return $chiamata === ['id' => 'sub_S5', 'cancel_at_period_end' => true]
        && count(array_filter($posta, static fn ($m) => str_starts_with($m['subject'], '[subscription.cancel_requested] '))) === 1
        && (string) Subscription::findById($id)['auto_renew'] === 'false'
        && rifiuto(static fn () => OnlineSubscriptions::cancel($id)) === 'subscription.already_cancelled';
}));

check('disdetta nel vincolo: niente a Stripe finché l\'ultimo periodo non arriva', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    $p = pianoOnline();
    $avvio = inizia(cliente(), $p['annuale']);
    $id = $avvio['subscription_id'];
    $prezzo = prezzoStripe($p['annuale']);
    SubscriptionEvents::apply(notifica('customer.subscription.created', abbonamentoStripe('sub_S6', $id, 'active', $prezzo)));
    $vincolo = (string) Subscription::findById($id)['commitment_ends_at'];
    $prima = $stripe->count('subscription.update');
    OnlineSubscriptions::cancel($id);
    $dopoDisdetta = $stripe->count('subscription.update');
    SubscriptionEvents::apply(notifica('customer.subscription.updated', abbonamentoStripe('sub_S6', $id, 'active', $prezzo)));
    $rinnovo = (string) Subscription::findById($id)['auto_renew'];
    // L'ultimo periodo del vincolo: finisce quando finisce il vincolo.
    Subscription::update(['current_period_end' => $vincolo], $id);
    $esito = OnlineSubscriptions::commitments(new DateTimeImmutable());

    return trim($vincolo) !== '' && $dopoDisdetta === $prima && $rinnovo === 'false'
        && $esito['cancelled'] === 1 && $stripe->last('subscription.update') === ['id' => 'sub_S6', 'cancel_at_period_end' => true];
}));

check('a fine vincolo senza disdetta l\'abbonamento passa al prezzo di rinnovo', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    $p = pianoOnline();
    $avvio = inizia(cliente(), $p['annuale']);
    $id = $avvio['subscription_id'];
    SubscriptionEvents::apply(notifica('customer.subscription.created', abbonamentoStripe('sub_S7', $id, 'active', prezzoStripe($p['annuale']))));
    $stripe->objects['sub_S7'] = abbonamentoStripe('sub_S7', $id, 'active', prezzoStripe($p['annuale']));
    Subscription::update(['current_period_end' => (string) Subscription::findById($id)['commitment_ends_at']], $id);
    $esito = OnlineSubscriptions::commitments(new DateTimeImmutable());

    return $esito['renewed'] === 1 && SubscriptionItems::priceIdOf($id) === (int) $p['mensile']['id'];
}));

check('fine vincolo recuperata: tre giorni dopo la disdetta passa comunque a Stripe', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    $p = pianoOnline();
    $avvio = inizia(cliente(), $p['annuale']);
    $id = $avvio['subscription_id'];
    SubscriptionEvents::apply(notifica('customer.subscription.created', abbonamentoStripe('sub_S9', $id, 'active', prezzoStripe($p['annuale']))));
    OnlineSubscriptions::cancel($id);
    Subscription::update([
        'commitment_ends_at' => date('Y-m-d H:i:s', time() - 3 * 86400),
        'current_period_end' => date('Y-m-d H:i:s', time() + 20 * 86400),
    ], $id);
    $esito = OnlineSubscriptions::commitments(new DateTimeImmutable());

    return $esito['cancelled'] === 1 && $stripe->last('subscription.update') === ['id' => 'sub_S9', 'cancel_at_period_end' => true];
}));

check('il secondo tentativo fallito della stessa fattura non rimanda l\'email', fn () => prova(static function (): bool {
    $p = pianoOnline();
    $avvio = inizia(cliente(), $p['mensile']);
    $id = $avvio['subscription_id'];
    SubscriptionEvents::apply(notifica('customer.subscription.created', abbonamentoStripe('sub_S10', $id, 'active', prezzoStripe($p['mensile']))));
    $posta = conPosta(static function () use ($id): void {
        SubscriptionEvents::apply(notifica('invoice.payment_failed', fattura('in_g', 'sub_S10', $id, 0)));
        SubscriptionEvents::apply(notifica('invoice.payment_failed', fattura('in_g', 'sub_S10', $id, 0)));
    });

    return count(array_filter($posta, static fn ($m) => str_starts_with($m['subject'], '[subscription.payment_failed] '))) === 1;
}));

check('riallineamento: il pending pagato si collega, l\'abbandonato oltre le 24 ore si chiude', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    $p = pianoOnline();
    $pagato = inizia(cliente(), $p['mensile']);
    $abbandonato = inizia(cliente(), $p['mensile']);
    $vecchio = date('Y-m-d H:i:s', time() - 2 * 3600);
    $vecchissimo = date('Y-m-d H:i:s', time() - 30 * 3600);
    sqlModify(Subscription::$table, ['creation' => $vecchio], 'id', $pagato['subscription_id']);
    sqlModify(Subscription::$table, ['creation' => $vecchissimo], 'id', $abbandonato['subscription_id']);
    $sessione = (string) ExternalReferences::find('subscription', $pagato['subscription_id'], 'stripe', 'checkout_session', 'test')['external_id'];
    $stripe->objects[$sessione]['subscription'] = 'sub_R1';
    $stripe->objects['sub_R1'] = abbonamentoStripe('sub_R1', $pagato['subscription_id'], 'active', prezzoStripe($p['mensile']));
    $esito = OnlineSubscriptions::reconcile($stripe);

    return $esito['synced'] >= 1 && $esito['abandoned'] >= 1
        && (string) Subscription::findById($pagato['subscription_id'])['status'] === 'active'
        && (string) Subscription::findById($pagato['subscription_id'])['provider_reference'] === 'sub_R1'
        && (string) Subscription::findById($abbandonato['subscription_id'])['status'] === 'cancelled';
}));

check('limiti: un abbonamento online attivo dà i limiti del suo piano', fn () => prova(static function (): bool {
    $p = pianoOnline();
    Wonder\Plugin\Gestionale\Models\Subscriptions\PlanFeature::create(['plan_id' => $p['plan'], 'feature_key' => 'max_progetti', 'feature_value' => '20', 'position' => 0]);
    $persona = cliente();
    $avvio = inizia($persona, $p['mensile']);
    $prima = Wonder\Plugin\Gestionale\Support\Subscriptions\Subscriptions::limit($persona, 'max_progetti');
    SubscriptionEvents::apply(notifica('customer.subscription.created', abbonamentoStripe('sub_S8', $avvio['subscription_id'], 'active', prezzoStripe($p['mensile']))));

    return $prima === null && Wonder\Plugin\Gestionale\Support\Subscriptions\Subscriptions::limit($persona, 'max_progetti') === '20';
}));

check('più servizi: una line_item per riga con la sua quantità, billing_mode flexible, righe pending col loro prezzo Stripe', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    $dominio = servizioOnline('Dominio', 12, '12.20');
    $hosting = servizioOnline('Hosting', 12, '61.00', 7);
    $assistenza = servizioOnline('Assistenza', 1, '10.00', 30);
    $avvio = iniziaServizi(cliente(), [
        ['price_code' => (string) $dominio['code']],
        ['price_code' => (string) $hosting['code'], 'quantity' => 2],
        ['price_code' => (string) $assistenza['code'], 'quantity' => 3],
    ]);
    $sessione = $stripe->last('checkout.session.create');
    $voci = $sessione['line_items'];
    $righe = SubscriptionItems::forSubscription($avvio['subscription_id']);
    $abbonamento = Subscription::findById($avvio['subscription_id']);

    return count($voci) === 3 && array_column($voci, 'quantity') === [1, 2, 3]
        && ($sessione['subscription_data']['billing_mode'] ?? null) === ['type' => 'flexible']
        && $sessione['subscription_data']['trial_period_days'] === 30
        && count($righe) === 3 && array_unique(array_column($righe, 'status')) === ['pending']
        && array_map('intval', array_column($righe, 'quantity')) === [1, 2, 3]
        && array_column($righe, 'provider_price') === array_column($voci, 'price')
        && count(array_unique(array_column($voci, 'price'))) === 3
        && round((float) $abbonamento['total'], 2) === round(array_sum(array_map(static fn (array $r): float => (float) $r['total'], $righe)), 2)
        && (string) $abbonamento['status'] === 'pending';
}));

check('due hosting uguali: due prezzi Stripe diversi nello stesso Checkout', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    $hosting = servizioOnline('Hosting', 12, '61.00');
    iniziaServizi(cliente(), [['price_code' => (string) $hosting['code']], ['price_code' => (string) $hosting['code']]]);
    $voci = $stripe->last('checkout.session.create')['line_items'];

    return count($voci) === 2 && $voci[0]['price'] !== $voci[1]['price'];
}));

check('più di 20 servizi: rifiutato prima di scrivere e di chiamare Stripe', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    $assistenza = servizioOnline('Assistenza', 1, '10.00');
    $persona = cliente();
    $errore = rifiuto(static fn () => iniziaServizi($persona, array_fill(0, 21, ['price_code' => (string) $assistenza['code']])));

    return $errore === 'subscription.too_many_items' && $stripe->count('checkout.session.create') === 0
        && righe(Subscription::class, ['customer_id' => $persona]) === [];
}));

/** Un abbonamento Stripe con questi item: [si_, prezzo Stripe, quantità, item_id nei metadati]. */
function conItem(string $id, int $locale, string $stato, array $item): array
{
    $oggetto = abbonamentoStripe($id, $locale, $stato, '');
    $inizio = time();
    $oggetto['items']['data'] = array_map(static fn (array $uno): array => [
        'id' => $uno[0], 'price' => ['id' => $uno[1]], 'quantity' => $uno[2] ?? 1,
        'metadata' => isset($uno[3]) ? ['item_id' => (string) $uno[3]] : [],
        'current_period_start' => $inizio, 'current_period_end' => $inizio + 30 * 86400,
    ], $item);

    return $oggetto;
}

/** Tre servizi venduti online e partiti su Stripe: ridà [id, righe, prezzi Stripe]. */
function treServiziAttivi(string $sub): array
{
    $dominio = servizioOnline('Dominio', 12, '12.20');
    $hosting = servizioOnline('Hosting', 12, '61.00');
    $assistenza = servizioOnline('Assistenza', 1, '10.00');
    PlanPrice::update(['commitment_months' => 12], (int) $hosting['id']);
    $avvio = iniziaServizi(cliente(), [
        ['price_code' => (string) $dominio['code']],
        ['price_code' => (string) $hosting['code'], 'quantity' => 2],
        ['price_code' => (string) $assistenza['code'], 'quantity' => 3],
    ]);
    $id = $avvio['subscription_id'];
    $prezzi = array_column(SubscriptionItems::forSubscription($id), 'provider_price');
    // Le righe del Checkout arrivano senza metadati: si riconoscono dal prezzo Stripe.
    SubscriptionEvents::apply(notifica('customer.subscription.created', conItem($sub, $id, 'incomplete', [['si_a', $prezzi[0]], ['si_b', $prezzi[1], 2], ['si_c', $prezzi[2], 3]])));

    return [$id, SubscriptionItems::forSubscription($id), $prezzi];
}

check('più servizi su Stripe: gli item trovano le righe dal prezzo, poi partono insieme col vincolo di ognuna', fn () => prova(static function (): bool {
    [$id, $prima, $prezzi] = treServiziAttivi('sub_M1');
    SubscriptionEvents::apply(notifica('customer.subscription.updated', conItem('sub_M1', $id, 'active', [['si_a', $prezzi[0]], ['si_b', $prezzi[1], 2], ['si_c', $prezzi[2], 3]])));
    $dopo = SubscriptionItems::forSubscription($id);
    $abbonamento = Subscription::findById($id);
    $vincoli = array_map(static fn (array $r): bool => trim((string) $r['commitment_ends_at']) !== '' && !str_starts_with((string) $r['commitment_ends_at'], '0000'), $dopo);

    return array_column($prima, 'provider_reference') === ['si_a', 'si_b', 'si_c'] && array_unique(array_column($prima, 'status')) === ['pending']
        && array_unique(array_column($dopo, 'status')) === ['active'] && count($dopo) === 3
        && array_unique(array_column($dopo, 'starts_at')) === [(string) $abbonamento['started_at']]
        && $vincoli === [false, true, false]
        && (string) $abbonamento['commitment_ends_at'] === (string) $dopo[1]['commitment_ends_at']
        && round((float) $abbonamento['total'], 2) === round(array_sum(array_map(static fn (array $r): float => (float) $r['total'], $dopo)), 2)
        && count(righe(SubscriptionItemChange::class, ['subscription_id' => $id, 'action' => 'changed'])) === 0;
}));

check('più servizi su Stripe: un item tolto chiude la riga, uno con item_id e un altro prezzo la cambia, uno nuovo la crea', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    [$id, $righe, $prezzi] = treServiziAttivi('sub_M2');
    SubscriptionEvents::apply(notifica('customer.subscription.updated', conItem('sub_M2', $id, 'active', [['si_a', $prezzi[0]], ['si_b', $prezzi[1], 2], ['si_c', $prezzi[2], 3]])));
    $catalogo = new Wonder\Plugin\Gestionale\Support\Subscriptions\StripeCatalog($stripe);
    $premium = servizioOnline('Assistenza premium', 1, '25.00');
    $backup = servizioOnline('Backup', 1, '5.00');
    // Dal portale: tolto il dominio, l'assistenza passa a premium (item_id nei metadati), il backup è nuovo.
    SubscriptionEvents::apply(notifica('customer.subscription.updated', conItem('sub_M2', $id, 'active', [
        ['si_b', $prezzi[1], 2],
        ['si_c', $catalogo->priceFor((int) $premium['id']), 3, (int) $righe[2]['id']],
        ['si_d', $catalogo->priceFor((int) $backup['id']), 4],
    ])));
    $dopo = SubscriptionItems::forSubscription($id);
    $vive = SubscriptionItems::forSubscription($id, ['active']);
    $abbonamento = Subscription::findById($id);
    $storia = static fn (string $azione): array => righe(SubscriptionItemChange::class, ['subscription_id' => $id, 'action' => $azione]);
    $assistenza = SubscriptionItem::findById((int) $righe[2]['id']);
    $nuova = end($dopo);

    // Una seconda notifica uguale non cambia niente.
    SubscriptionEvents::apply(notifica('customer.subscription.updated', conItem('sub_M2', $id, 'active', [
        ['si_b', $prezzi[1], 2],
        ['si_c', $catalogo->priceFor((int) $premium['id']), 3, (int) $righe[2]['id']],
        ['si_d', $catalogo->priceFor((int) $backup['id']), 4],
    ])));

    return count($dopo) === 4 && (string) $dopo[0]['status'] === 'ended' && trim((string) $dopo[0]['ends_at']) !== ''
        && count($vive) === 3
        && (int) $assistenza['plan_price_id'] === (int) $premium['id'] && (int) $assistenza['quantity'] === 3 && round((float) $assistenza['subtotal'], 2) === 75.0
        && (int) $nuova['plan_price_id'] === (int) $backup['id'] && (int) $nuova['quantity'] === 4 && (string) $nuova['provider_reference'] === 'si_d'
        && count($storia('removed')) === 1 && count($storia('changed')) === 1 && count($storia('added')) === 4
        && round((float) $abbonamento['total'], 2) === round(array_sum(array_map(static fn (array $r): float => (float) $r['total'], $vive)), 2)
        && count(SubscriptionItems::forSubscription($id)) === 4 && count($storia('changed')) === 1 && count($storia('removed')) === 1;
}));

/** treServiziAttivi partiti davvero, con l'abbonamento anche nel finto Stripe. */
function treServiziPartiti(FakeSubscriptionGateway $stripe, string $sub): array
{
    [$id, $righe, $prezzi] = treServiziAttivi($sub);
    $oggetto = conItem($sub, $id, 'active', [['si_a', $prezzi[0], 1, (int) $righe[0]['id']], ['si_b', $prezzi[1], 2, (int) $righe[1]['id']], ['si_c', $prezzi[2], 3, (int) $righe[2]['id']]]);
    SubscriptionEvents::apply(notifica('customer.subscription.updated', $oggetto));
    $stripe->objects[$sub] = $oggetto;

    return [$id, SubscriptionItems::forSubscription($id), $prezzi];
}

check('online, un servizio in più col conguaglio: un item su Stripe fatturato subito, con l\'id della riga', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    [$id] = treServiziPartiti($stripe, 'sub_I1');
    $backup = servizioOnline('Backup', 1, '5.00');
    $nuova = SubscriptionItems::add($id, ['plan_price_id' => (int) $backup['id'], 'quantity' => 2], 'prorate', 0, 'api');
    $chiamata = $stripe->last('subscription_item.create');
    $riga = SubscriptionItem::findById($nuova);

    return $chiamata['subscription'] === 'sub_I1' && $chiamata['proration_behavior'] === 'always_invoice'
        && $chiamata['payment_behavior'] === 'error_if_incomplete' && (int) $chiamata['quantity'] === 2
        && $chiamata['metadata']['item_id'] === (string) $nuova
        && str_starts_with((string) $riga['provider_reference'], 'si_fake_') && (string) $riga['provider_price'] === $chiamata['price']
        && (string) $riga['status'] === 'active';
}));

check('online, un servizio tolto col credito: item cancellato su Stripe col conguaglio', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    [$id, $righe] = treServiziPartiti($stripe, 'sub_I2');
    SubscriptionItems::remove((int) $righe[2]['id'], 'credit', 0, 'api');
    $chiamata = $stripe->last('subscription_item.delete');

    return $chiamata === ['id' => 'si_c', 'proration_behavior' => 'create_prorations']
        && (string) SubscriptionItem::findById((int) $righe[2]['id'])['status'] === 'ended';
}));

check('online, due cambi al prossimo periodo: uno schedule solo, aggiornato a ogni cambio', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    [$id, $righe] = treServiziPartiti($stripe, 'sub_I3');
    $premium = servizioOnline('Assistenza premium', 1, '25.00');
    $backup = servizioOnline('Backup', 1, '5.00');
    SubscriptionItems::change((int) $righe[2]['id'], (int) $premium['id'], 'next_period', [], 0, 'api');
    SubscriptionItems::add($id, ['plan_price_id' => (int) $backup['id']], 'next_period', 0, 'api');
    $ultimo = $stripe->last('subscription_schedule.update');
    $fasi = $ultimo['phases'] ?? [];

    return $stripe->count('subscription_schedule.create') === 1 && $stripe->count('subscription_schedule.update') >= 2
        && $ultimo['end_behavior'] === 'release' && count($fasi) >= 2 && count($fasi) <= 10
        && $stripe->count('subscription_item.create') === 0 && $stripe->count('subscription_item.update') === 0
        && (string) SubscriptionItem::findById((int) $righe[2]['id'])['plan_price_id'] === (string) $righe[2]['plan_price_id'];
}));

check('online, il preventivo del conguaglio viene dall\'anteprima di Stripe', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    [$id] = treServiziPartiti($stripe, 'sub_I4');
    $backup = servizioOnline('Backup', 1, '5.00');
    $stripe->previewAmountDue = 437;
    $preventivo = OnlineSubscriptions::quote($id, ['plan_price_id' => (int) $backup['id']]);
    $richiesta = $stripe->last('invoice.preview');

    return round((float) $preventivo['amount'], 2) === 4.37 && $richiesta['subscription'] === 'sub_I4'
        && ($richiesta['subscription_details']['proration_behavior'] ?? $richiesta['proration_behavior'] ?? null) === 'always_invoice'
        && count(SubscriptionItems::forSubscription($id)) === 3;
}));

check('online, Stripe rifiuta il servizio in più: niente riga e niente storia', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    [$id] = treServiziPartiti($stripe, 'sub_I5');
    $backup = servizioOnline('Backup', 1, '5.00');
    $prima = count(righe(SubscriptionItemChange::class, ['subscription_id' => $id]));
    $stripe->failNext = 'subscription_item.create';
    $errore = rifiuto(static fn () => SubscriptionItems::add($id, ['plan_price_id' => (int) $backup['id']], 'prorate', 0, 'api'));

    return $errore === 'subscription.stripe_failed' && count(SubscriptionItems::forSubscription($id)) === 3
        && count(righe(SubscriptionItemChange::class, ['subscription_id' => $id])) === $prima;
}));

check('online, l\'ultimo servizio non si toglie col credito: è una disdetta', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    $p = pianoOnline();
    $avvio = inizia(cliente(), $p['mensile']);
    $id = $avvio['subscription_id'];
    SubscriptionEvents::apply(notifica('customer.subscription.created', abbonamentoStripe('sub_I6', $id, 'active', prezzoStripe($p['mensile']))));
    $riga = SubscriptionItems::main($id);
    $errore = rifiuto(static fn () => SubscriptionItems::remove((int) $riga['id'], 'credit', 0, 'api'));

    return $errore === 'subscription.last_item_online' && $stripe->count('subscription_item.delete') === 0;
}));

check('limite: un abbonamento attivo blocca, un pending online di 30 ore no', fn () => prova(static function (): bool {
    $p = pianoOnline();
    $attivo = cliente();
    $avvio = inizia($attivo, $p['mensile']);
    SubscriptionEvents::apply(notifica('customer.subscription.created', abbonamentoStripe('sub_L1', $avvio['subscription_id'], 'active', prezzoStripe($p['mensile']))));
    $bloccato = rifiuto(static fn () => inizia($attivo, $p['annuale']));

    $lento = cliente();
    $vecchio = inizia($lento, $p['mensile']);
    sqlModify(Subscription::$table, ['creation' => date('Y-m-d H:i:s', time() - 30 * 3600)], 'id', $vecchio['subscription_id']);
    $nuovo = inizia($lento, $p['mensile']);

    return $bloccato === 'subscription.limit_reached' && $nuovo['subscription_id'] !== $vecchio['subscription_id'];
}));

check('il riepilogo del negozio: righe e primo addebito senza scrivere niente; intervalli e prezzi nascosti rifiutati', fn () => prova(static function (FakeSubscriptionGateway $stripe): bool {
    $mensile = servizioOnline('Hosting', 1, '12.20');
    $annuale = servizioOnline('Dominio', 12, '24.40');
    $contatto = Contact::findById(cliente());
    $righeScritte = static fn (): int => (int) SubscriptionItem::query()->mysqli->query('SELECT COUNT(*) FROM `'.SubscriptionItem::$table.'`')->fetch_row()[0];
    $prima = $righeScritte();
    $riepilogo = OnlineSubscriptions::preview($contatto, [['price_code' => $mensile['code'], 'quantity' => 2], ['price_code' => $annuale['code']]], [], '');
    $righe = OnlineSubscriptions::lines([['price_code' => $mensile['code']]], $riepilogo['billing']);

    $bimestre = servizioOnline('Backup', 2, '5.00');
    $trimestre = servizioOnline('Assistenza', 3, '9.00');
    $intervalli = rifiuto(static fn () => OnlineSubscriptions::lines([['price_code' => $bimestre['code']], ['price_code' => $trimestre['code']]], []));
    $nascosto = servizioOnline('Interno', 1, '1.00');
    sqlModify(Plan::$table, ['visible_online' => 'false'], 'id', (int) $nascosto['plan_id']);
    $troppi = rifiuto(static fn () => OnlineSubscriptions::lines(array_fill(0, OnlineSubscriptions::MAX_ITEMS + 1, ['price_code' => $mensile['code']]), []));

    return count($riepilogo['lines']) === 2 && $riepilogo['lines'][0]['quantity'] === 2 && $riepilogo['billing']['billing_country'] === 'IT'
        && abs((float) $riepilogo['amounts']['total'] - 48.80) < 0.001 && $riepilogo['coupon'] === null
        && count($righe) === 1 && $righeScritte() === $prima && $stripe->count('checkout.session.create') === 0
        && $intervalli === 'subscription.interval_mismatch'
        && rifiuto(static fn () => OnlineSubscriptions::lines([['price_code' => $nascosto['code']]], [])) === 'subscription.price_unavailable'
        && $troppi === 'subscription.too_many_items';
}));

summary();
