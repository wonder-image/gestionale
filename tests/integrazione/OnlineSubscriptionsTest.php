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
    $plan = (int) (Plan::create(['code' => Code::make(Plan::class, Codes::PLAN), 'name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'active' => 'true', 'visible_online' => 'true'])->insert_id ?? 0);
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
    return OnlineSubscriptions::start($cliente, (string) $prezzo['code'], [], $coupon, ['success' => 'https://negozio.test/ok?abbonamento={SUBSCRIPTION}', 'cancel' => 'https://negozio.test/no']);
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
    $chiamata = $stripe->last('subscription.update');
    SubscriptionEvents::apply(notifica('customer.subscription.updated', abbonamentoStripe('sub_S4', $id, 'active', prezzoStripe($p['annuale']))));

    return $chiamata['proration_behavior'] === 'none' && $chiamata['items'][0]['id'] === 'si_'.$id
        && SubscriptionItems::priceIdOf($id) === (int) $p['annuale']['id']
        && round((float) Subscription::findById($id)['total'], 2) === 244.0
        && count(righe(SubscriptionItemChange::class, ['subscription_id' => $id])) === 1;
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

summary();
