<?php
/** php tests/integrazione/SalesAgentsTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';
require __DIR__ . '/supporto/compra.php';

use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\App\Visibility\RowVisibility;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgent;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderActionResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderPaymentResource;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

// Utenti finti: alla visibilità bastano id e ruoli.
const UTENTE_A = 990001;
const UTENTE_B = 990002;

function utente(int $id, array $ruoli): object
{
    return (object) ['id' => $id, 'authority' => json_encode($ruoli)];
}

function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            accendiFunzionalita(['sales_agents']);
            SalesAgents::forget();
            $esito = $corpo(scenario());

            throw new Annulla();
        });
    } catch (Annulla) {
    } finally {
        Gestionale::reset();
        SalesAgents::forget();
    }

    return $esito;
}

function nuovo(string $model, array $valori): int
{
    return (int) ($model::create($valori)->insert_id ?? 0);
}

function persona(string $nome, int $agente = 0): int
{
    return nuovo(Contact::class, [
        'name' => $nome,
        'surname' => 'Prova',
        'email' => strtolower($nome).'.'.uniqid().'@example.com',
        'is_customer' => 'true',
        'active' => 'true',
        'agent_id' => $agente,
    ]);
}

/** Due commerciali, i loro clienti e un ordine a testa. */
function scenario(): array
{
    $a = nuovo(SalesAgent::class, ['user_id' => UTENTE_A, 'active' => 'true']);
    $b = nuovo(SalesAgent::class, ['user_id' => UTENTE_B, 'active' => 'true']);

    $s = ['a' => $a, 'b' => $b];
    $s['clienteA'] = persona('Anna', $a);
    $s['clienteB'] = persona('Bruno', $b);
    $s['aziendaA'] = nuovo(Company::class, ['business_name' => 'Azienda A '.uniqid(), 'is_customer' => 'true', 'agent_id' => $a]);
    $s['referente'] = persona('Carla');
    nuovo(ContactRelation::class, ['company_id' => $s['aziendaA'], 'person_id' => $s['referente']]);

    // Ordine di un cliente di A senza timbro: lo eredita dal cliente.
    $s['ordineA'] = ordineDiProva();
    Order::update(['customer_id' => $s['clienteA'], 'agent_id' => 0], $s['ordineA']);
    // Ordine timbrato da B per un cliente non suo.
    $s['ordineB'] = ordineDiProva();
    Order::update(['customer_id' => $s['clienteA'], 'agent_id' => $b], $s['ordineB']);
    $s['ordineAzienda'] = ordineDiProva();
    Order::update(['customer_id' => $s['referente'], 'company_id' => $s['aziendaA'], 'agent_id' => 0], $s['ordineAzienda']);

    return $s;
}

function vede(object $utente, string $model, int $id): bool
{
    return RowVisibility::for($utente)->visible($model::$table, $id);
}

check('le regole sono registrate dal file di boot', function () {
    return RowVisibility::hasRule(Contact::$table)
        && RowVisibility::hasRule(Company::$table)
        && RowVisibility::hasRule(Order::$table);
});

check('il commerciale vede i suoi clienti e i referenti delle sue aziende', fn () => prova(function (array $s): bool {
    $a = utente(UTENTE_A, ['sales_agent']);

    return vede($a, Contact::class, $s['clienteA'])
        && vede($a, Contact::class, $s['referente'])
        && vede($a, Company::class, $s['aziendaA'])
        && !vede($a, Contact::class, $s['clienteB']);
}));

check('le vendite: le sue, quelle dei suoi clienti e delle sue aziende', fn () => prova(function (array $s): bool {
    $a = utente(UTENTE_A, ['sales_agent']);
    $b = utente(UTENTE_B, ['sales_agent']);

    return vede($a, Order::class, $s['ordineA'])
        && vede($a, Order::class, $s['ordineAzienda'])
        // Il cliente è di A: vede anche la vendita timbrata da B.
        && vede($a, Order::class, $s['ordineB'])
        && vede($b, Order::class, $s['ordineB'])
        && !vede($b, Order::class, $s['ordineA']);
}));

check('pagamento e azioni: solo sugli ordini che il commerciale vede', fn () => prova(function (array $s): bool {
    $b = utente(UTENTE_B, ['sales_agent']);
    $nonTrovato = 'Ordine non trovato.';
    $pagamento = static fn (int $id): string => (string) RowVisibility::as($b, static fn () => OrderPaymentResource::run($id, [], UTENTE_B))['message'];
    $azione = static fn (int $id): string => (string) RowVisibility::as($b, static fn () => OrderActionResource::run('nessuna', $id, UTENTE_B))['message'];

    return $pagamento($s['ordineA']) === $nonTrovato
        && $azione($s['ordineA']) === $nonTrovato
        // Sul suo ordine passa il controllo e si ferma ai campi.
        && $pagamento($s['ordineB']) !== $nonTrovato
        && $azione($s['ordineB']) !== $nonTrovato;
}));

check('lo Scadenzario: le rate dei suoi ordini e dei suoi clienti', fn () => prova(function (array $s): bool {
    $a = utente(UTENTE_A, ['sales_agent']);
    $b = utente(UTENTE_B, ['sales_agent']);
    $rata = static fn (array $valori): int => nuovo(Payment::class, ['amount' => 10, 'status' => 'pending', ...$valori]);
    $diA = $rata(['order_id' => $s['ordineA']]);
    $diB = $rata(['order_id' => $s['ordineB']]);
    $delCliente = $rata(['customer_id' => $s['clienteA']]);
    $diNessuno = $rata([]);

    return $diA > 0 && vede($a, Payment::class, $diA) && !vede($b, Payment::class, $diA)
        && vede($b, Payment::class, $diB)
        && vede($a, Payment::class, $delCliente) && !vede($b, Payment::class, $delCliente)
        && !vede($a, Payment::class, $diNessuno);
}));

check('l\'ufficio vede tutto, anche con il ruolo di commerciale', fn () => prova(function (array $s): bool {
    $ufficio = utente(UTENTE_A, ['sales_agent', 'sales']);

    return vede($ufficio, Contact::class, $s['clienteB'])
        && vede($ufficio, Order::class, $s['ordineA']);
}));

check('un commerciale disattivato non vede più nulla', fn () => prova(function (array $s): bool {
    SalesAgent::update(['active' => 'false'], $s['a']);
    SalesAgents::forget();

    return !vede(utente(UTENTE_A, ['sales_agent']), Contact::class, $s['clienteA']);
}));

check('il timbro di un documento nuovo: azienda, poi persona, poi chi lo crea', fn () => prova(function (array $s): bool {
    $nuovo = persona('Dario');

    return SalesAgents::forDocument(['customer_id' => $s['referente'], 'company_id' => $s['aziendaA']]) === $s['a']
        && SalesAgents::forDocument(['customer_id' => $s['clienteB']]) === $s['b']
        && SalesAgents::forDocument(['customer_id' => $nuovo], utente(UTENTE_B, ['sales_agent'])) === $s['b']
        && SalesAgents::forDocument(['customer_id' => $nuovo], utente(9, ['sales'])) === 0;
}));

check('il cliente creato dal commerciale è suo, qualunque cosa mandi', fn () => prova(function (array $s): bool {
    $valori = RowVisibility::as(utente(UTENTE_B, ['sales_agent']), static fn () => CustomerResource::mutateRequestValues(
        ['name' => 'Elena', 'surname' => 'Prova', 'agent_id' => $s['a']],
        'store'
    ));

    return (int) ($valori['agent_id'] ?? 0) === $s['b'];
}));

check('l\'ufficio sceglie il commerciale del cliente', fn () => prova(function (array $s): bool {
    $valori = RowVisibility::as(utente(9, ['sales']), static fn () => CustomerResource::mutateRequestValues(
        ['name' => 'Elena', 'surname' => 'Prova', 'agent_id' => (string) $s['a']],
        'store'
    ));

    return (int) ($valori['agent_id'] ?? 0) === $s['a'];
}));

check('il filtro «Commerciale» c\'è solo per l\'ufficio', fn () => prova(function (array $s): bool {
    $filtri = static fn (object $u): array => (array) (RowVisibility::as(
        $u,
        static fn () => SalesAgents::filter(TableLayoutSchema::for(CustomerResource::class))
    )->get('custom_filters') ?? []);

    $ufficio = $filtri(utente(9, ['sales']));

    return count($ufficio) === 1
        && isset($ufficio[0]['array'][$s['a']], $ufficio[0]['array'][$s['b']])
        && $filtri(utente(UTENTE_A, ['sales_agent'])) === [];
}));

summary();
