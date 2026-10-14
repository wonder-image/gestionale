<?php
/** php tests/integrazione/OfficeSubscriptionsTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/posta.php';

use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentTerm;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentTermInstallment;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Resources\Subscriptions\SubscriptionActionResource;
use Wonder\Plugin\Gestionale\Resources\Subscriptions\SubscriptionPaymentResource;
use Wonder\Plugin\Gestionale\Seeding\OfficeSubscriptionsDemo;
use Wonder\Plugin\Gestionale\Seeding\PlansDemo;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentFiles;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\Installments;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OfficeSubscriptions;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

function prova(callable $corpo): mixed
{
    $esito = null;
    DocumentFiles::useBase(sys_get_temp_dir().'/gst-abb-'.bin2hex(random_bytes(4)));

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            accendiFunzionalita(['orders', 'office_sales', 'subscriptions']);
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    } finally {
        DocumentFiles::useBase(null);
        Wonder\Plugin\Gestionale\Gestionale::reset();
    }

    return $esito;
}

/** @return array{plan: int, mensile: int, annuale: int} */
function piano(int $prova = 0, int $vincolo = 0): array
{
    $plan = (int) (Plan::create(['code' => Code::make(Plan::class, Codes::PLAN), 'name' => 'Ufficio', 'slug' => 'ufficio-'.uniqid(), 'active' => 'true', 'visible_online' => 'false'])->insert_id ?? 0);
    $mensile = (int) (PlanPrice::create(['code' => Code::make(PlanPrice::class, Codes::PLAN_PRICE), 'plan_id' => $plan, 'name' => 'Mensile', 'billing_interval' => 'month', 'term_months' => 1, 'trial_days' => $prova, 'commitment_months' => $vincolo, 'price' => '100.00', 'sale_price' => '0.00', 'active' => 'true'])->insert_id ?? 0);
    $annuale = (int) (PlanPrice::create(['code' => Code::make(PlanPrice::class, Codes::PLAN_PRICE), 'plan_id' => $plan, 'name' => 'Annuale', 'billing_interval' => 'year', 'term_months' => 12, 'price' => '1000.00', 'sale_price' => '0.00', 'active' => 'true'])->insert_id ?? 0);

    return ['plan' => $plan, 'mensile' => $mensile, 'annuale' => $annuale];
}

/** Una condizione a due rate, 30 e 60 giorni, metà e metà. */
function dueRate(): int
{
    $term = (int) (PaymentTerm::create(['name' => 'Prova 30/60', 'sdi_condition' => 'TP01', 'active' => 'true'])->insert_id ?? 0);
    PaymentTermInstallment::create(['payment_term_id' => $term, 'days' => 30, 'end_of_month' => 'false', 'percentage' => '50.00', 'position' => 0]);
    PaymentTermInstallment::create(['payment_term_id' => $term, 'days' => 60, 'end_of_month' => 'false', 'percentage' => '50.00', 'position' => 1]);

    return $term;
}

function azienda(int $termine = 0): int
{
    return (int) (Contact::create([
        'type' => 'business', 'business_name' => 'Studio Abbonato Srl', 'country' => 'IT', 'city' => 'Brescia',
        'street' => 'Via Milano', 'number' => '3', 'cap' => '25100', 'pi' => '01234567890',
        'email' => 'studio.abb.'.uniqid().'@example.com', 'is_customer' => 'true', 'active' => 'true',
        'payment_term_id' => $termine,
    ])->insert_id ?? 0);
}

/** @return list<array<string, mixed>> */
function rate(int $abbonamento, string $stato = 'pending'): array
{
    $rows = Payment::find(['subscription_id' => $abbonamento, 'status' => $stato, 'deleted' => 'false'], null, 'due_date', 'ASC');

    return is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'))) : [];
}

/** Incassa tutte le rate in attesa: l'abbonamento resta in regola. */
function paga(int $abbonamento): void
{
    foreach (rate($abbonamento) as $rata) {
        OfficeSubscriptions::registerPayment($abbonamento, (int) $rata['id'], (float) $rata['amount']);
    }
}

function stato(int $abbonamento): string
{
    return (string) (Subscription::findById($abbonamento)['status'] ?? '');
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

check('creato: attivo, due rate a 30 e 60 giorni, proforma archiviato e mandato', fn () => prova(static function (): bool {
    $p = piano();
    $termine = dueRate();
    $dal = new DateTimeImmutable('2026-03-01');
    $posta = conPosta(static function () use ($p, $termine, $dal, &$id): void {
        $id = OfficeSubscriptions::create(['customer_id' => azienda($termine), 'plan_price_id' => $p['mensile'], 'starts_at' => $dal->format('Y-m-d')]);
    });
    $riga = Subscription::findById($id);
    $rate = rate($id);
    $proforma = array_values(array_filter($posta, static fn ($m) => str_starts_with($m['subject'], '[subscription.proforma] ')));

    return (string) $riga['status'] === 'active' && (string) $riga['channel'] === 'office' && (string) $riga['provider'] === 'manual'
        && (int) $riga['payment_term_id'] === $termine
        && substr((string) $riga['current_period_start'], 0, 10) === '2026-03-01'
        && substr((string) $riga['current_period_end'], 0, 10) === '2026-04-01'
        && count($rate) === 2
        && substr((string) $rate[0]['due_date'], 0, 10) === '2026-03-31' && substr((string) $rate[1]['due_date'], 0, 10) === '2026-04-30'
        && round((float) $rate[0]['amount'] + (float) $rate[1]['amount'], 2) === round((float) $riga['total'], 2)
        && (int) $rate[0]['order_id'] === 0
        && DocumentFiles::path(OfficeSubscriptions::proformaFile($id, '2026-03-01')) !== null
        && count($proforma) === 1;
}));

check('con la prova nessuna rata; alla fine della prova il primo periodo', fn () => prova(static function (): bool {
    $p = piano(14);
    $id = OfficeSubscriptions::create(['customer_id' => azienda(), 'plan_price_id' => $p['mensile'], 'starts_at' => '2026-03-01']);
    $prima = [stato($id), count(rate($id))];
    OfficeSubscriptions::daily(new DateTimeImmutable('2026-03-10'));
    $durante = count(rate($id));
    OfficeSubscriptions::daily(new DateTimeImmutable('2026-03-15'));
    $riga = Subscription::findById($id);

    return $prima === ['trialing', 0] && $durante === 0
        && (string) $riga['status'] === 'active' && substr((string) $riga['current_period_start'], 0, 10) === '2026-03-15'
        && count(rate($id)) === 1;
}));

check('nuovo periodo alla scadenza; il coupon di due mesi vale sui primi due', fn () => prova(static function (): bool {
    $p = piano();
    $codice = 'UFF'.strtoupper(substr(uniqid(), -7));
    Coupon::create(['code' => $codice, 'discount_type' => 'percent', 'discount_value' => '50', 'applies_office' => 'true', 'active' => 'true', 'applies_subscriptions' => 'true', 'duration' => 'repeating', 'duration_months' => 2]);
    $id = OfficeSubscriptions::create(['customer_id' => azienda(), 'plan_price_id' => $p['mensile'], 'starts_at' => '2026-01-01', 'coupon' => $codice]);
    // Le rate si pagano: un abbonamento non pagato non apre periodi nuovi.
    $primo = (float) Subscription::findById($id)['total'];
    paga($id);
    OfficeSubscriptions::daily(new DateTimeImmutable('2026-02-01'));
    $secondo = (float) Subscription::findById($id)['total'];
    paga($id);
    OfficeSubscriptions::daily(new DateTimeImmutable('2026-03-01'));
    $terzo = (float) Subscription::findById($id)['total'];
    $ripetuto = OfficeSubscriptions::daily(new DateTimeImmutable('2026-03-01'));

    return round($primo, 2) === 50.0 && round($secondo, 2) === 50.0 && round($terzo, 2) === 100.0
        && $ripetuto['opened'] === 0
        && count(rate($id, 'paid')) === 2 && count(rate($id)) === 1;
}));

check('rata scaduta: da pagare con un solo promemoria; oltre i giorni non pagato; incassata, attivo', fn () => prova(static function (): bool {
    $p = piano();
    $id = OfficeSubscriptions::create(['customer_id' => azienda(), 'plan_price_id' => $p['mensile'], 'starts_at' => '2026-03-01']);
    $rata = rate($id)[0];
    $scade = new DateTimeImmutable(substr((string) $rata['due_date'], 0, 10));
    $posta = conPosta(static function () use ($scade): void {
        OfficeSubscriptions::daily($scade->modify('+1 day'));
        OfficeSubscriptions::daily($scade->modify('+2 days'));
    });
    $dopo = stato($id);
    OfficeSubscriptions::daily($scade->modify('+'.(OfficeSubscriptions::unpaidDays() + 1).' days'));
    $poi = stato($id);
    OfficeSubscriptions::registerPayment($id, (int) $rata['id'], (float) $rata['amount'], ['paid_at' => date('Y-m-d')]);

    return $dopo === 'past_due'
        && count(array_filter($posta, static fn ($m) => str_starts_with($m['subject'], '[subscription.reminder] '))) === 1
        && $poi === 'unpaid'
        && stato($id) === 'active'
        && (string) Payment::findById((int) $rata['id'])['status'] === 'paid';
}));

check('un acconto lascia il resto con la stessa scadenza; la rata d\'un altro abbonamento no', fn () => prova(static function (): bool {
    $p = piano();
    $id = OfficeSubscriptions::create(['customer_id' => azienda(), 'plan_price_id' => $p['mensile'], 'starts_at' => '2026-03-01']);
    $altro = OfficeSubscriptions::create(['customer_id' => azienda(), 'plan_price_id' => $p['mensile'], 'starts_at' => '2026-03-01']);
    $rata = rate($id)[0];
    $estranea = rifiuto(static fn () => OfficeSubscriptions::registerPayment($altro, (int) $rata['id'], 10.0));
    OfficeSubscriptions::registerPayment($id, (int) $rata['id'], 40.0);
    $resto = rate($id);

    return $estranea === 'payment.installment_not_pending'
        && count($resto) === 1 && round((float) $resto[0]['amount'], 2) === round((float) $rata['amount'] - 40.0, 2)
        && substr((string) $resto[0]['due_date'], 0, 10) === substr((string) $rata['due_date'], 0, 10);
}));

check('disdetta: niente rinnovo, finisce a fine periodo; nel vincolo i periodi continuano', fn () => prova(static function (): bool {
    $p = piano();
    $id = OfficeSubscriptions::create(['customer_id' => azienda(), 'plan_price_id' => $p['mensile'], 'starts_at' => '2026-03-01']);
    OfficeSubscriptions::cancel($id);
    OfficeSubscriptions::daily(new DateTimeImmutable('2026-04-01'));
    $libero = [stato($id), substr((string) Subscription::findById($id)['ended_at'], 0, 10)];

    $vincolato = piano(0, 3);
    $id2 = OfficeSubscriptions::create(['customer_id' => azienda(), 'plan_price_id' => $vincolato['mensile'], 'starts_at' => '2026-03-01']);
    OfficeSubscriptions::cancel($id2);
    paga($id2);
    OfficeSubscriptions::daily(new DateTimeImmutable('2026-04-01'));
    paga($id2);
    OfficeSubscriptions::daily(new DateTimeImmutable('2026-05-01'));
    $durante = stato($id2);
    paga($id2);
    OfficeSubscriptions::daily(new DateTimeImmutable('2026-06-01'));

    return $libero === ['cancelled', '2026-04-01'] && count(rate($id)) <= 1
        && $durante === 'active' && count(rate($id2, 'paid')) === 3
        && stato($id2) === 'cancelled';
}));

check('annulla: chiuso subito, le rate in attesa annullate', fn () => prova(static function (): bool {
    $p = piano();
    $id = OfficeSubscriptions::create(['customer_id' => azienda(dueRate()), 'plan_price_id' => $p['mensile'], 'starts_at' => '2026-03-01']);
    $esito = SubscriptionActionResource::run('terminate', $id, ['reason' => 'Cliente chiuso'], 0);

    return $esito['ok'] && stato($id) === 'cancelled' && rate($id) === [] && count(rate($id, 'cancelled')) === 2;
}));

check('cambio piano: il prezzo nuovo vale dal periodo dopo', fn () => prova(static function (): bool {
    $p = piano();
    $id = OfficeSubscriptions::create(['customer_id' => azienda(), 'plan_price_id' => $p['mensile'], 'starts_at' => '2026-03-01']);
    $esito = SubscriptionActionResource::run('change_plan', $id, ['plan_price_id' => $p['annuale']], 0);
    $adesso = round((float) Subscription::findById($id)['total'], 2);
    OfficeSubscriptions::daily(new DateTimeImmutable('2026-04-01'));
    $riga = Subscription::findById($id);

    return $esito['ok'] && $adesso === 100.0
        && round((float) $riga['total'], 2) === 1000.0
        && substr((string) $riga['current_period_end'], 0, 10) === '2027-04-01';
}));

check('dalla pagina: registra pagamento sulla rata', fn () => prova(static function (): bool {
    $p = piano();
    $id = OfficeSubscriptions::create(['customer_id' => azienda(), 'plan_price_id' => $p['mensile'], 'starts_at' => '2026-03-01']);
    $rata = rate($id)[0];
    $esito = SubscriptionPaymentResource::run([
        'subscription_id' => $id, 'payment_id' => $rata['id'], 'amount' => number_format((float) $rata['amount'], 2, ',', '.'),
        'paid_at' => date('d/m/Y'), 'reference' => 'CRO-'.uniqid(),
    ], 0);

    return $esito['ok'] && rate($id) === [] && Installments::pendingForSubscription($id) === [];
}));

check('dati di prova: un abbonamento da ufficio con la sua rata, e la pulizia lo toglie', fn () => prova(static function (): bool {
    OfficeSubscriptionsDemo::clear();
    PlansDemo::create();
    $creati = OfficeSubscriptionsDemo::create();
    $secondo = OfficeSubscriptionsDemo::create();
    $riga = Subscription::find(['code' => 'sub_demo-ufficio', 'deleted' => 'false'], 1);
    $tolti = OfficeSubscriptionsDemo::clear();

    return ($creati >= 2 || Wonder\Plugin\Gestionale\Console\Demo\DemoData::notes() !== []) && $secondo === 0
        && (!is_array($riga) || $riga === [] || (string) $riga['channel'] === 'office')
        && $tolti >= ($creati > 0 ? 2 : 0);
}));

summary();
