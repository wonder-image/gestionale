<?php
/** php tests/SalesAgentsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgent;
use Wonder\Plugin\Gestionale\Resources\SalesAgents\SalesAgentResource;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;

// Stato delle funzionalità e id dei commerciali forzati senza database.
$forza = static function (bool $acceso): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, ['sales_agents' => $acceso]);
};
$ids = static function (array $mappa): void {
    (new ReflectionProperty(SalesAgents::class, 'ids'))->setValue(null, $mappa);
};
$utente = static fn (int $id, array $ruoli): object => (object) ['id' => $id, 'authority' => json_encode($ruoli)];

check('la tabella dei commerciali ha le colonne della rete', function () {
    $colonne = array_map(static fn ($c) => (string) $c->name, SalesAgent::tableSchema());

    return SalesAgent::$table === 'gst_sales_agents'
        && array_diff(['user_id', 'contact_id', 'parent_id', 'commission_percent', 'max_discount_percent', 'active', 'note'], $colonne) === [];
});

check('la funzionalità esiste e la pagina dipende da lei', function () use ($forza) {
    $catalogo = require __DIR__.'/../config/features.php';
    $forza(false);
    $spenta = (bool) SalesAgentResource::navigationSchema()->get('enabled');
    $forza(true);
    $accesa = (bool) SalesAgentResource::navigationSchema()->get('enabled');

    return SalesAgentResource::$feature === 'sales_agents'
        && str_contains(json_encode($catalogo), 'sales_agents')
        && $spenta === false && $accesa === true;
});

check('il ruolo «Commerciale» esiste', function () {
    $permessi = require __DIR__.'/../config/permissions.php';

    return str_contains(print_r($permessi, true), 'sales_agent');
});

check('solo il commerciale puro è limitato', function () use ($forza, $utente) {
    $forza(true);

    return SalesAgents::restricted($utente(1, ['sales_agent'])) === true
        && SalesAgents::restricted($utente(2, ['sales_agent', 'sales'])) === false
        && SalesAgents::restricted($utente(3, ['administrator'])) === false
        && SalesAgents::restricted(null) === false;
});

check('a funzionalità spenta nessuno è limitato', function () use ($forza, $utente) {
    $forza(false);

    return SalesAgents::restricted($utente(1, ['sales_agent'])) === false
        && SalesAgents::documentsRule($utente(1, ['sales_agent'])) === null;
});

check('le regole: l\'ufficio vede tutto, il commerciale i suoi', function () use ($forza, $ids, $utente) {
    $forza(true);
    $ids([1 => 7]);
    $agente = $utente(1, ['sales_agent']);

    $documenti = (string) SalesAgents::documentsRule($agente);
    $contatti = (string) SalesAgents::contactsRule($agente);

    return SalesAgents::documentsRule($utente(2, ['sales'])) === null
        && str_contains($documenti, '`agent_id` = 7')
        && str_contains($documenti, '`customer_id` IN (SELECT `id` FROM `'.Wonder\Plugin\Gestionale\Models\Contacts\Contact::$table.'` WHERE `agent_id` = 7)')
        && str_contains($documenti, '`company_id` IN (SELECT `id` FROM')
        && str_contains($contatti, '`agent_id` = 7 OR `id` IN (SELECT `person_id`')
        && SalesAgents::companiesRule($agente) === '`agent_id` = 7';
});

check('un commerciale senza scheda attiva non vede nulla', function () use ($forza, $ids, $utente) {
    $forza(true);
    $ids([1 => 0]);
    $agente = $utente(1, ['sales_agent']);
    $nulla = Wonder\App\Visibility\RowVisibility::none();

    return SalesAgents::documentsRule($agente) === $nulla
        && SalesAgents::contactsRule($agente) === $nulla
        && SalesAgents::companiesRule($agente) === $nulla;
});

check('i riquadri con dati di tutti perdono il commerciale', function () {
    return SalesAgents::withoutAgents(['administrator', 'sales_agent', 'sales']) === ['administrator', 'sales'];
});

check('i Fornitori non sono per il commerciale', function () {
    $permessi = \Wonder\Plugin\Gestionale\Resources\Contacts\SupplierResource::permissionSchema()->toArray()['backend'] ?? [];

    return $permessi !== [] && !in_array('sales_agent', array_merge(...array_values($permessi)), true);
});

check('il commerciale di un nuovo documento è di chi lo crea se il cliente non ne ha', function () use ($forza, $ids, $utente) {
    $forza(true);
    $ids([1 => 7]);

    return SalesAgents::forDocument([], $utente(1, ['sales_agent'])) === 7
        && SalesAgents::forDocument([], $utente(2, ['sales'])) === 0;
});

$forza(false);
SalesAgents::forget();

summary();
