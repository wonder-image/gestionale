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

use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationLink;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentTerm;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentTermInstallment;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanLink;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItem;
use Wonder\Plugin\Gestionale\Resources\Subscriptions\OfficeSubscriptionResource;
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
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionItems;
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

/**
 * Un piano con un prezzo solo.
 *
 * @return array{plan: int, price: int}
 */
function servizio(string $nome, int $mesi, string $prezzo, int $prova = 0, int $vincolo = 0): array
{
    $plan = (int) (Plan::create(['code' => Code::make(Plan::class, Codes::PLAN), 'name' => $nome, 'slug' => 'srv-'.uniqid(), 'active' => 'true', 'visible_online' => 'false'])->insert_id ?? 0);
    $price = (int) (PlanPrice::create(['code' => Code::make(PlanPrice::class, Codes::PLAN_PRICE), 'plan_id' => $plan, 'name' => $mesi === 12 ? 'Annuale' : 'Ogni '.$mesi.' mesi', 'billing_interval' => $mesi === 12 ? 'year' : 'month', 'term_months' => $mesi, 'trial_days' => $prova, 'commitment_months' => $vincolo, 'price' => $prezzo, 'sale_price' => '0.00', 'active' => 'true'])->insert_id ?? 0);

    return ['plan' => $plan, 'price' => $price];
}

/**
 * Dominio annuale col nome obbligatorio, hosting annuale legato al dominio,
 * assistenza mensile.
 *
 * @return array{dominio: array{plan: int, price: int}, hosting: array{plan: int, price: int}, assistenza: array{plan: int, price: int}, nome: int}
 */
function servizi(): array
{
    $dominio = servizio('Dominio .it', 12, '12.20');
    $hosting = servizio('Hosting', 12, '61.00');
    $assistenza = servizio('Assistenza', 1, '10.00');
    $nome = (int) (Customization::create(['name' => 'Nome del dominio', 'label' => 'Nome del dominio', 'kind' => 'text', 'max_length' => 63, 'active' => 'true', 'for_products' => 'false', 'for_subscriptions' => 'true'])->insert_id ?? 0);
    CustomizationLink::create(['plan_id' => $dominio['plan'], 'customization_id' => $nome, 'is_required' => 'true', 'position' => 1]);
    PlanLink::create(['plan_id' => $hosting['plan'], 'parent_plan_id' => $dominio['plan']]);

    return ['dominio' => $dominio, 'hosting' => $hosting, 'assistenza' => $assistenza, 'nome' => $nome];
}

/** @return list<array<string, mixed>> */
function righe(int $abbonamento): array
{
    $rows = SubscriptionItem::find(['subscription_id' => $abbonamento, 'deleted' => 'false'], null, 'position', 'ASC');

    return is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'))) : [];
}

function totale(int $abbonamento): float
{
    return round((float) Subscription::findById($abbonamento)['total'], 2);
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

check('cambio del servizio dal prossimo rinnovo: il prezzo nuovo vale dal periodo dopo, fino all\'anniversario', fn () => prova(static function (): bool {
    // Il cambio parte da oggi: il periodo in corso deve contenere oggi.
    $inizio = (new DateTimeImmutable('first day of this month'))->setTime(0, 0);
    $rinnovo = $inizio->modify('+1 month');
    $anniversario = $inizio->modify('+1 year');
    $p = piano();
    $id = OfficeSubscriptions::create(['customer_id' => azienda(), 'plan_price_id' => $p['mensile'], 'starts_at' => $inizio->format('Y-m-d')]);
    paga($id);
    $esito = SubscriptionActionResource::run('change_item', $id, [
        'item_id' => (int) SubscriptionItems::main($id)['id'],
        'plan_price_id' => $p['annuale'],
        'billing' => 'next_period',
    ], 0);
    $adesso = round((float) Subscription::findById($id)['total'], 2);
    OfficeSubscriptions::daily($rinnovo);
    $riga = Subscription::findById($id);
    $servizio = SubscriptionItems::main($id);
    // L'annuale rinnova all'anniversario dell'abbonamento: dal rinnovo a lì, una quota.
    $quota = round(1000 * $rinnovo->diff($anniversario)->days / $inizio->diff($anniversario)->days, 2);

    return $esito['ok'] && $adesso === 100.0
        && (int) $servizio['plan_price_id'] === $p['annuale'] && round((float) $servizio['total'], 2) === 1000.0
        && substr((string) $riga['current_period_end'], 0, 10) === $anniversario->format('Y-m-d')
        && array_map(static fn (array $r): float => round((float) $r['amount'], 2), rate($id)) === [$quota];
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

check('dati di prova: due abbonamenti da ufficio con le rate, uno coi tre servizi, e la pulizia li toglie', fn () => prova(static function (): bool {
    OfficeSubscriptionsDemo::clear();
    PlansDemo::create();
    $creati = OfficeSubscriptionsDemo::create();
    $secondo = OfficeSubscriptionsDemo::create();
    $riga = Subscription::find(['code' => 'sub_demo-ufficio', 'deleted' => 'false'], 1);
    $servizi = Subscription::find(['code' => 'sub_demo-servizi', 'deleted' => 'false'], 1);
    $righe = is_array($servizi) && isset($servizi['id']) ? righe((int) $servizi['id']) : [];
    $tolti = OfficeSubscriptionsDemo::clear();

    // Senza i contatti di prova non crea niente e lo dice.
    if ($creati === 0) {
        return Wonder\Plugin\Gestionale\Console\Demo\DemoData::notes() !== [] && $secondo === 0 && $tolti === 0;
    }

    return $creati >= 4 && $secondo === 0
        && (string) $riga['channel'] === 'office'
        && count($righe) === 3
        && (int) $righe[1]['parent_item_id'] === (int) $righe[0]['id']
        && str_contains((string) $righe[0]['customization'], 'annaverdi.it')
        && $tolti === $creati;
}));

check('più servizi: dominio, hosting legato e assistenza su un abbonamento, rinnovo al mese, prima rata la somma', fn () => prova(static function (): bool {
    $s = servizi();
    $id = OfficeSubscriptions::create(['customer_id' => azienda(), 'starts_at' => '2026-03-01', 'items' => [
        ['plan_price_id' => $s['dominio']['price'], 'customization' => [$s['nome'] => 'esempio.it']],
        ['plan_price_id' => $s['hosting']['price'], 'parent' => 0],
        ['plan_price_id' => $s['assistenza']['price'], 'quantity' => 2],
    ]]);
    $righe = righe($id);
    $riga = Subscription::findById($id);
    $rate = rate($id);
    $somma = round(array_sum(array_map(static fn (array $r): float => (float) $r['total'], $righe)), 2);

    return count($righe) === 3
        && array_column($righe, 'status') === ['active', 'active', 'active']
        && (int) $righe[1]['parent_item_id'] === (int) $righe[0]['id'] && (int) $righe[0]['parent_item_id'] === 0
        && (int) $righe[2]['quantity'] === 2
        && str_contains((string) $righe[0]['customization'], 'esempio.it')
        && substr((string) $riga['current_period_end'], 0, 10) === '2026-04-01'
        && $somma === totale($id) && totale($id) > 0.0
        && count($rate) === 1 && round((float) $rate[0]['amount'], 2) === totale($id)
        && DocumentFiles::path(OfficeSubscriptions::proformaFile($id, '2026-03-01')) !== null;
}));

check('più servizi: senza il nome del dominio, hosting senza dominio o intervalli che non si incastrano non si crea', fn () => prova(static function (): bool {
    $s = servizi();
    $cliente = azienda();
    $senzaNome = rifiuto(static fn () => OfficeSubscriptions::create(['customer_id' => $cliente, 'items' => [['plan_price_id' => $s['dominio']['price']]]]));
    $senzaMadre = rifiuto(static fn () => OfficeSubscriptions::create(['customer_id' => $cliente, 'items' => [['plan_price_id' => $s['hosting']['price']]]]));
    $madreSbagliata = rifiuto(static fn () => OfficeSubscriptions::create(['customer_id' => $cliente, 'items' => [
        ['plan_price_id' => $s['assistenza']['price']],
        ['plan_price_id' => $s['hosting']['price'], 'parent' => 0],
    ]]));
    $vuoto = rifiuto(static fn () => OfficeSubscriptions::create(['customer_id' => $cliente, 'items' => []]));
    $bimestrale = servizio('Bimestrale', 2, '20.00');
    $trimestrale = servizio('Trimestrale', 3, '30.00');
    $intervalli = rifiuto(static fn () => OfficeSubscriptions::create(['customer_id' => $cliente, 'items' => [
        ['plan_price_id' => $bimestrale['price']], ['plan_price_id' => $trimestrale['price']],
    ]]));

    return $senzaNome === 'customization.required' && $senzaMadre === 'subscription.parent_required'
        && $madreSbagliata === 'subscription.parent_invalid' && $vuoto === 'subscription.items_required'
        && $intervalli === 'subscription.interval_mismatch'
        && !isset(((array) Subscription::find(['customer_id' => $cliente, 'deleted' => 'false'], 1))['id']);
}));

check('più servizi: al secondo mese si addebita solo l\'assistenza, al tredicesimo tutti e tre', fn () => prova(static function (): bool {
    $s = servizi();
    $id = OfficeSubscriptions::create(['customer_id' => azienda(), 'starts_at' => '2026-01-01', 'items' => [
        ['plan_price_id' => $s['dominio']['price'], 'customization' => [$s['nome'] => 'esempio.it']],
        ['plan_price_id' => $s['hosting']['price'], 'parent' => 0],
        ['plan_price_id' => $s['assistenza']['price']],
    ]]);
    $primo = totale($id);
    $assistenza = round((float) righe($id)[2]['total'], 2);
    $mesi = [];

    for ($mese = 2; $mese <= 13; $mese++) {
        paga($id);
        $giorno = (new DateTimeImmutable('2026-01-01'))->modify('+'.($mese - 1).' months');
        OfficeSubscriptions::daily($giorno);
        $mesi[$mese] = totale($id);
    }

    return $mesi[2] === $assistenza && $mesi[12] === $assistenza && $mesi[13] === $primo
        && substr((string) Subscription::findById($id)['current_period_start'], 0, 10) === '2027-01-01';
}));

check('più servizi: con la prova le righe sono già attive e nessuna rata fino al primo periodo', fn () => prova(static function (): bool {
    $conProva = servizio('Gestionale', 1, '30.00', 14);
    $altro = servizio('Backup', 1, '5.00');
    $id = OfficeSubscriptions::create(['customer_id' => azienda(), 'starts_at' => '2026-03-01', 'items' => [
        ['plan_price_id' => $conProva['price']], ['plan_price_id' => $altro['price']],
    ]]);
    $riga = Subscription::findById($id);
    $prima = [stato($id), count(rate($id)), array_column(righe($id), 'status')];
    OfficeSubscriptions::daily(new DateTimeImmutable('2026-03-15'));

    return $prima === ['trialing', 0, ['active', 'active']]
        && substr((string) $riga['trial_ends_at'], 0, 10) === '2026-03-15'
        && stato($id) === 'active' && count(rate($id)) === 1 && totale($id) === 35.0;
}));

check('più servizi: un secondo abbonamento allo stesso cliente supera il limite di uno', fn () => prova(static function (): bool {
    $p = piano();
    $cliente = azienda();
    OfficeSubscriptions::create(['customer_id' => $cliente, 'plan_price_id' => $p['mensile'], 'starts_at' => '2026-03-01']);

    return rifiuto(static fn () => OfficeSubscriptions::create(['customer_id' => $cliente, 'items' => [['plan_price_id' => $p['annuale']]]])) === 'subscription.limit_reached';
}));

check('dalla pagina: le righe del repeater diventano servizi, con la madre per numero di riga', fn () => prova(static function (): bool {
    $s = servizi();
    $righe = OfficeSubscriptionResource::itemsFrom([
        ['plan_price_id' => (string) $s['dominio']['price'], 'quantity' => '1', 'parent' => '', 'customization_'.$s['nome'] => 'esempio.it'],
        ['plan_price_id' => '', 'quantity' => '1'],
        ['plan_price_id' => (string) $s['hosting']['price'], 'quantity' => '0', 'parent' => '1', 'customization_'.$s['nome'] => 'non-suo.it'],
        ['plan_price_id' => (string) $s['assistenza']['price'], 'parent' => '2'],
    ]);

    return count($righe) === 3
        && $righe[0] === ['plan_price_id' => $s['dominio']['price'], 'quantity' => 1, 'customization' => [$s['nome'] => 'esempio.it'], 'parent' => -1]
        && $righe[1]['parent'] === 0 && $righe[1]['quantity'] === 1 && $righe[1]['customization'] === []
        && $righe[2]['parent'] === -2;
}));

check('scheda: le finestre dei servizi si aprono, con l\'aiuto accanto al titolo', fn () => prova(static function (): bool {
    $s = servizi();
    $id = OfficeSubscriptions::create(['customer_id' => azienda(), 'starts_at' => '2026-03-01', 'items' => [
        ['plan_price_id' => $s['dominio']['price'], 'customization' => [$s['nome'] => 'esempio.it']],
        ['plan_price_id' => $s['hosting']['price'], 'parent' => 0],
    ]]);
    $html = \Wonder\Plugin\Gestionale\Resources\Subscriptions\SubscriptionResource::itemModals(Subscription::findById($id));

    return str_contains($html, 'id="wi-servizio-add"')
        && substr_count($html, 'wi-servizio-change-') >= 2
        && str_contains($html, 'Si appoggia a')
        && str_contains($html, 'Gratis: niente fino al rinnovo');
}));

summary();
