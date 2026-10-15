<?php
/** php tests/integrazione/SubscriptionItemsTest.php */
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
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanLink;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItem;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItemChange;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentFiles;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OfficeSubscriptions;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionItems;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

function prova(callable $corpo): mixed
{
    $esito = null;
    DocumentFiles::useBase(sys_get_temp_dir().'/gst-srv-'.bin2hex(random_bytes(4)));

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

function azienda(): int
{
    return (int) (Contact::create([
        'type' => 'business', 'business_name' => 'Studio Servizi Srl', 'country' => 'IT', 'city' => 'Brescia',
        'street' => 'Via Milano', 'number' => '3', 'cap' => '25100', 'pi' => '01234567890',
        'email' => 'studio.srv.'.uniqid().'@example.com', 'is_customer' => 'true', 'active' => 'true',
    ])->insert_id ?? 0);
}

/** @return list<array<string, mixed>> */
function lista(mixed $rows): array
{
    return is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'))) : [];
}

/** @return list<array<string, mixed>> */
function rate(int $abbonamento, string $stato = 'pending'): array
{
    return lista(Payment::find(['subscription_id' => $abbonamento, 'status' => $stato, 'deleted' => 'false'], null, 'due_date', 'ASC'));
}

/** @return list<float> gli importi delle rate in attesa */
function importi(int $abbonamento): array
{
    return array_map(static fn (array $r): float => round((float) $r['amount'], 2), rate($abbonamento));
}

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

function riga(int $id): array
{
    $row = SubscriptionItem::findById($id);

    return is_array($row) ? $row : [];
}

/** @return list<array<string, mixed>> */
function storia(int $abbonamento): array
{
    return lista(SubscriptionItemChange::find(['subscription_id' => $abbonamento, 'deleted' => 'false'], null, 'id', 'ASC'));
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

function giorno(string $data): string
{
    return substr($data, 0, 10);
}

/** @return array{plan: int, price: int} */
function servizio(string $nome, int $mesi, string $prezzo, int $vincolo = 0): array
{
    $plan = (int) (Plan::create(['code' => Code::make(Plan::class, Codes::PLAN), 'name' => $nome, 'slug' => 'srv-'.uniqid(), 'active' => 'true', 'visible_online' => 'false'])->insert_id ?? 0);

    return ['plan' => $plan, 'price' => prezzo($plan, $mesi, $prezzo, $vincolo)];
}

function prezzo(int $plan, int $mesi, string $prezzo, int $vincolo = 0): int
{
    return (int) (PlanPrice::create(['code' => Code::make(PlanPrice::class, Codes::PLAN_PRICE), 'plan_id' => $plan, 'name' => ($mesi === 12 ? 'Annuale' : 'Mensile').' '.$prezzo, 'billing_interval' => $mesi === 12 ? 'year' : 'month', 'term_months' => $mesi, 'commitment_months' => $vincolo, 'price' => $prezzo, 'sale_price' => '0.00', 'active' => 'true'])->insert_id ?? 0);
}

/**
 * Assistenza mensile 30,00 e dominio annuale 12,00 (col nome) dal
 * 2026-01-15; l'hosting annuale 120,00 si appoggia al dominio. Pagato e
 * portato avanti, mese per mese, fino a `$fino`.
 *
 * @return array{id: int, assistenza: int, dominio: int, hosting: int, hostingPlan: int, assistenzaPlan: int, nome: int}
 */
function abbonamento(string $fino = '2026-01-15', int $vincolo = 0): array
{
    $assistenza = servizio('Assistenza', 1, '30.00', $vincolo);
    $dominio = servizio('Dominio .it', 12, '12.00');
    $hosting = servizio('Hosting', 12, '120.00');
    $nome = (int) (Customization::create(['name' => 'Nome del dominio', 'label' => 'Nome del dominio', 'kind' => 'text', 'max_length' => 63, 'active' => 'true', 'for_products' => 'false', 'for_subscriptions' => 'true'])->insert_id ?? 0);
    CustomizationLink::create(['plan_id' => $dominio['plan'], 'customization_id' => $nome, 'is_required' => 'true', 'position' => 1]);
    PlanLink::create(['plan_id' => $hosting['plan'], 'parent_plan_id' => $dominio['plan']]);

    $id = OfficeSubscriptions::create(['customer_id' => azienda(), 'starts_at' => '2026-01-15', 'items' => [
        ['plan_price_id' => $assistenza['price']],
        ['plan_price_id' => $dominio['price'], 'customization' => [$nome => 'esempio.it']],
    ]]);
    paga($id);
    avanza($id, $fino);

    return [
        'id' => $id, 'assistenza' => $assistenza['price'], 'dominio' => $dominio['price'], 'hosting' => $hosting['price'],
        'assistenzaPlan' => $assistenza['plan'], 'hostingPlan' => $hosting['plan'], 'nome' => $nome,
    ];
}

/** Il giro giornaliero il 15 di ogni mese fino a `$fino`, pagando ogni volta. */
function avanza(int $abbonamento, string $fino): void
{
    $giorno = new DateTimeImmutable('2026-02-15');
    $fine = new DateTimeImmutable($fino);

    while ($giorno <= $fine) {
        OfficeSubscriptions::daily($giorno);
        paga($abbonamento);
        $giorno = $giorno->modify('+1 month');
    }
}

function rigaDi(int $abbonamento, int $priceId): array
{
    foreach (lista(SubscriptionItem::find(['subscription_id' => $abbonamento, 'deleted' => 'false'], null, 'position', 'ASC')) as $row) {
        if ((int) $row['plan_price_id'] === $priceId) {
            return $row;
        }
    }

    return [];
}

check('aggiungo l\'hosting con la quota: riga attiva, una rata di 184/365 e il proforma', fn () => prova(static function (): bool {
    $a = abbonamento('2026-07-15');
    $prima = importi($a['id']);
    $hosting = SubscriptionItems::add($a['id'], ['plan_price_id' => $a['hosting'], 'parent_item_id' => (int) rigaDi($a['id'], $a['dominio'])['id']], 'prorate', 0, 'user', new DateTimeImmutable('2026-07-15'));

    return $prima === []
        && (string) riga($hosting)['status'] === 'active'
        && importi($a['id']) === [60.49]
        && DocumentFiles::path(OfficeSubscriptions::proformaFile($a['id'], '2026-07-15')) !== null;
}));

check('aggiungo l\'hosting senza addebito: nessuna rata; all\'anniversario è nel periodo', fn () => prova(static function (): bool {
    $a = abbonamento('2026-07-15');
    SubscriptionItems::add($a['id'], ['plan_price_id' => $a['hosting'], 'parent_item_id' => (int) rigaDi($a['id'], $a['dominio'])['id']], 'free', 0, 'user', new DateTimeImmutable('2026-07-15'));
    $subito = importi($a['id']);
    avanza($a['id'], '2026-12-15');
    OfficeSubscriptions::daily(new DateTimeImmutable('2027-01-15'));

    return $subito === [] && array_sum(importi($a['id'])) === 162.0;
}));

check('aggiungo l\'hosting dal prossimo rinnovo: parte al periodo dopo e paga fino all\'anniversario', fn () => prova(static function (): bool {
    $a = abbonamento('2026-07-15');
    $hosting = SubscriptionItems::add($a['id'], ['plan_price_id' => $a['hosting'], 'parent_item_id' => (int) rigaDi($a['id'], $a['dominio'])['id']], 'next_period', 0, 'user', new DateTimeImmutable('2026-07-20'));
    $prima = riga($hosting);
    $subito = importi($a['id']);
    OfficeSubscriptions::daily(new DateTimeImmutable('2026-08-15'));
    $importi = importi($a['id']);
    sort($importi);

    return (string) $prima['status'] === 'pending' && giorno((string) $prima['starts_at']) === '2026-08-15'
        && $subito === []
        && (string) riga($hosting)['status'] === 'active'
        && $importi === [30.0, 50.3];
}));

check('tolgo il dominio a fine periodo: anche l\'hosting finisce all\'anniversario e non si addebita', fn () => prova(static function (): bool {
    $a = abbonamento('2026-07-15');
    $hosting = SubscriptionItems::add($a['id'], ['plan_price_id' => $a['hosting'], 'parent_item_id' => (int) rigaDi($a['id'], $a['dominio'])['id']], 'free', 0, 'user', new DateTimeImmutable('2026-07-15'));
    $dominio = (int) rigaDi($a['id'], $a['dominio'])['id'];
    $esito = SubscriptionItems::remove($dominio, 'period_end', 0, 'user', new DateTimeImmutable('2026-07-20'));
    $durante = [riga($dominio), riga($hosting)];
    avanza($a['id'], '2026-12-15');
    OfficeSubscriptions::daily(new DateTimeImmutable('2027-01-15'));

    return giorno($esito['ends_at']) === '2027-01-15' && count($esito['removed']) === 2
        && array_column($durante, 'status') === ['ending', 'ending']
        && array_map(static fn (array $r): string => giorno((string) $r['ends_at']), $durante) === ['2027-01-15', '2027-01-15']
        && (string) riga($dominio)['status'] === 'ended' && (string) riga($hosting)['status'] === 'ended'
        && importi($a['id']) === [30.0];
}));

check('tolgo l\'assistenza col credito: finita subito, 16/31 della quota da usare', fn () => prova(static function (): bool {
    $a = abbonamento('2026-07-15');
    $assistenza = (int) rigaDi($a['id'], $a['assistenza'])['id'];
    SubscriptionItems::remove($assistenza, 'credit', 0, 'user', new DateTimeImmutable('2026-07-30'));
    $storia = storia($a['id']);
    $ultima = end($storia);

    return (string) riga($assistenza)['status'] === 'ended'
        && (string) $ultima['action'] === 'removed' && round((float) $ultima['credit_left'], 2) === 15.48;
}));

check('in vincolo: col credito non si toglie, a fine periodo finisce col vincolo', fn () => prova(static function (): bool {
    $a = abbonamento('2026-07-15', 12);
    $assistenza = (int) rigaDi($a['id'], $a['assistenza'])['id'];
    $credito = rifiuto(static fn () => SubscriptionItems::remove($assistenza, 'credit', 0, 'user', new DateTimeImmutable('2026-07-30')));
    $esito = SubscriptionItems::remove($assistenza, 'period_end', 0, 'user', new DateTimeImmutable('2026-07-30'));

    return $credito === 'subscription.item_committed'
        && giorno($esito['ends_at']) === '2027-01-15' && giorno((string) riga($assistenza)['ends_at']) === '2027-01-15';
}));

check('cambio l\'assistenza da 30 a 50 con la quota: credito e rata della differenza, storia «changed»', fn () => prova(static function (): bool {
    $a = abbonamento('2026-07-15');
    $nuovo = prezzo($a['assistenzaPlan'], 1, '50.00');
    $assistenza = (int) rigaDi($a['id'], $a['assistenza'])['id'];
    SubscriptionItems::change($assistenza, $nuovo, 'prorate', [], 0, 'user', new DateTimeImmutable('2026-07-30'));
    $storia = storia($a['id']);
    $ultima = end($storia);

    return (int) riga($assistenza)['plan_price_id'] === $nuovo
        && (string) $ultima['action'] === 'changed' && round((float) $ultima['credit_left'], 2) === 15.48
        && importi($a['id']) === [25.81];
}));

check('correggo il nome del dominio: storia «customized» col nome di prima', fn () => prova(static function (): bool {
    $a = abbonamento();
    $dominio = (int) rigaDi($a['id'], $a['dominio'])['id'];
    SubscriptionItems::customize($dominio, [$a['nome'] => 'nuovo.it']);
    $storia = storia($a['id']);
    $ultima = end($storia);

    return str_contains((string) riga($dominio)['customization'], 'nuovo.it')
        && (string) $ultima['action'] === 'customized' && str_contains((string) $ultima['note'], 'esempio.it');
}));

check('tolgo l\'ultimo servizio a fine periodo: al giro l\'abbonamento è chiuso', fn () => prova(static function (): bool {
    $assistenza = servizio('Assistenza', 1, '30.00');
    $id = OfficeSubscriptions::create(['customer_id' => azienda(), 'starts_at' => '2026-01-15', 'plan_price_id' => $assistenza['price']]);
    paga($id);
    SubscriptionItems::remove((int) rigaDi($id, $assistenza['price'])['id'], 'period_end', 0, 'user', new DateTimeImmutable('2026-01-20'));
    OfficeSubscriptions::daily(new DateTimeImmutable('2026-02-15'));

    return stato($id) === 'cancelled' && rate($id) === [];
}));

check('a un abbonamento non pagato non si aggiunge niente', fn () => prova(static function (): bool {
    $a = abbonamento();
    Subscription::update(['status' => 'unpaid'], $a['id']);

    return rifiuto(static fn () => SubscriptionItems::add($a['id'], ['plan_price_id' => $a['hosting']], 'free')) === 'subscription.not_live';
}));

summary();
