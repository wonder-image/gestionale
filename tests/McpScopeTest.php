<?php
/** php tests/McpScopeTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Mcp\Caller;
use Wonder\Plugin\Gestionale\Support\Mcp\Server;
use Wonder\Plugin\Gestionale\Support\Mcp\Tools;

(new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, ['mcp' => true]);

$ruoli = ['administrator', 'api_internal_user'];
$nomi = static fn (Caller $caller): array => array_column(Server::list($caller), 'name');
$chiama = static fn (Caller $caller, string $nome): array => Server::handle(
    ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $nome, 'arguments' => []]],
    $caller
);

check('ogni strumento ha un\'area fra catalogo, magazzino e vendite', function () {
    foreach (Tools::all() as $tool) {
        if (!preg_match('/^(catalog|stock|sales):(read|write)$/', Tools::scope($tool))) {
            return false;
        }
    }

    return true;
});

check('gli scope dichiarati sono quelli degli strumenti, con l\'etichetta', function () {
    $scopes = Tools::scopes();

    return array_keys($scopes) === ['catalog:read', 'catalog:write', 'sales:read', 'sales:write', 'stock:write']
        && !in_array('', $scopes, true)
        && !str_contains(implode('', $scopes), 'mcp.scope');
});

check('il token di api_users non ha limiti di scope', function () use ($ruoli, $nomi) {
    return count($nomi(new Caller(0, 1, $ruoli))) === count(Tools::all());
});

check('con catalog:read solo gli strumenti di lettura del catalogo', function () use ($ruoli, $nomi) {
    $nomi = $nomi(new Caller(0, null, $ruoli, '', 1, ['catalog:read']));

    foreach ($nomi as $nome) {
        if (Tools::scope(Tools::all()[$nome]) !== 'catalog:read') {
            return false;
        }
    }

    return in_array('products_search', $nomi, true) && !in_array('product_update', $nomi, true);
});

check('catalog:write vale anche come lettura', function () use ($ruoli, $nomi) {
    $nomi = $nomi(new Caller(0, null, $ruoli, '', 1, ['catalog:write']));

    return in_array('products_search', $nomi, true) && in_array('product_update', $nomi, true)
        && !in_array('orders_list', $nomi, true) && !in_array('stock_adjust', $nomi, true);
});

check('uno strumento fuori scope non si chiama: è come se non esistesse', function () use ($ruoli, $chiama) {
    $risposta = $chiama(new Caller(0, null, $ruoli, '', 1, ['catalog:read']), 'stock_adjust');

    return ($risposta['error']['message'] ?? '') === Server::text('unknown_tool', ['name' => 'stock_adjust']);
});

check('lo scope non allarga i ruoli dell\'utente', function () use ($nomi) {
    return $nomi(new Caller(0, null, [], '', 1, ['catalog:write', 'sales:write', 'stock:write'])) === [];
});

check('senza scope un token OAuth non apre niente', function () use ($ruoli, $nomi) {
    return $nomi(new Caller(0, null, $ruoli, '', 1, [])) === [];
});

summary();
