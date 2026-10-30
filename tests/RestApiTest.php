<?php
/** php tests/RestApiTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Support\Api\Rest;
use Wonder\Plugin\Gestionale\Support\Mcp\Caller;
use Wonder\Plugin\Gestionale\Support\Mcp\McpError;
use Wonder\Plugin\Gestionale\Support\Mcp\Server;
use Wonder\Plugin\Gestionale\Support\Mcp\Tools;

(new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, ['mcp' => true]);

$admin = new Caller(0, 1, ['administrator', 'api_internal_user']);

check('ogni rotta punta a uno strumento che esiste', function () {
    foreach (Rest::routes() as $route) {
        if (!isset(Tools::all()[$route['tool']])) {
            return false;
        }
    }

    return isset(Tools::all()['products_search']);
});

check('le GET leggono la query con i nomi italiani e i tipi', function () {
    [$tool, $args] = Rest::call('orders.index', [], ['stato' => 'paid', 'limit' => '10', 'q' => '123'], ['ignorato' => 1]);

    return $tool === 'orders_list' && $args === ['status' => 'paid', 'limit' => 10, 'query' => '123'];
});

check('attivo=false diventa un booleano', function () {
    [, $args] = Rest::call('products.index', [], ['attivo' => 'false', 'categoria' => '4', 'offset' => '20'], []);

    return $args === ['active' => false, 'category_id' => 4, 'offset' => 20];
});

check('con q l\'elenco dei prodotti diventa la ricerca', function () {
    return Rest::call('products.index', [], ['q' => 'maglia', 'offset' => '20'], [])
        === ['products_search', ['query' => 'maglia']];
});

check('l\'id del percorso vince su quello del corpo, con il nome dello strumento', function () {
    [$tool, $args] = Rest::call('products.stock', ['id' => '12'], [], ['product_id' => 99, 'quantity' => 3]);
    [$azione, $ordine] = Rest::call('orders.action', ['id' => '7'], ['order_id' => '1'], ['action' => 'ship']);

    return $tool === 'stock_adjust' && $args === ['product_id' => 12, 'quantity' => 3]
        && $azione === 'order_action' && $ordine === ['action' => 'ship', 'order_id' => 7];
});

check('una rotta sconosciuta non ha strumento', function () {
    return Rest::call('nessuna', [], [], []) === ['', []];
});

check('gli stati HTTP degli esiti', function () {
    return Rest::status('products.store', null) === 201
        && Rest::status('products.show', null) === 200
        && Rest::status('products.show', 'forbidden') === 403
        && Rest::status('products.show', 'not_found') === 404
        && Rest::status('products.update', 'missing') === 422
        && Rest::status('products.update', 'invalid') === 422
        && Rest::status('products.update', 'internal') === 500;
});

check('uno strumento fuori scope o sconosciuto è forbidden', function () {
    $token = new Caller(0, null, ['administrator', 'api_internal_user'], '', 1, ['catalog:read']);

    return Server::invoke('customers_list', [], $token)['kind'] === 'forbidden'
        && Server::invoke('nessuno', [], $token)['kind'] === 'forbidden';
});

check('i campi obbligatori assenti sono missing', function () use ($admin) {
    $esito = Server::invoke('customer_get', [], $admin);

    return $esito['kind'] === 'missing' && $esito['outcome'] === null && str_contains((string) $esito['failure'], 'id');
});

check('un errore *_not_found è riconosciuto', function () {
    return McpError::make('customer_not_found', 0, ['id' => 1])->notFound()
        && !McpError::make('missing_fields', 0, ['fields' => 'id'])->notFound();
});

check('gli strumenti dei clienti leggono soltanto, con contacts:read', function () {
    foreach (['customers_list' => 'list', 'customer_get' => 'view'] as $nome => $azione) {
        $tool = Tools::all()[$nome];

        if ($tool['write'] || Tools::scope($tool) !== 'contacts:read' || $tool['permission'] !== [CustomerResource::class, $azione]) {
            return false;
        }
    }

    return true;
});

summary();
