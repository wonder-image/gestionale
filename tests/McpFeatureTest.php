<?php
/** php tests/McpFeatureTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;
use Wonder\Plugin\Gestionale\Support\Mcp\Caller;
use Wonder\Plugin\Gestionale\Support\Mcp\Server;

// Lo stato si forza senza database: features() è memoizzato.
$forza = static function (array $stato): void {
    $proprieta = new ReflectionProperty(Gestionale::class, 'features');
    $proprieta->setValue(null, $stato);
};

$admin = new Caller(0, 0, ['administrator', 'api_internal_user'], '127.0.0.1');
$elenco = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

check('la funzionalità mcp è nel catalogo', function () {
    return isset(FeatureCatalog::all()['mcp']);
});

check('spenta: nessuno strumento elencato e nessuna chiamata', function () use ($forza, $admin, $elenco) {
    $forza(['mcp' => false]);

    $call = Server::handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'products_search', 'arguments' => ['query' => 'a']]], $admin);

    return Server::enabled() === false
        && Server::list($admin) === []
        && isset(Server::handle($elenco, $admin)['error'])
        && isset($call['error'])
        && !isset($call['result']);
});

check('spenta: nemmeno initialize risponde', function () use ($forza, $admin) {
    $forza(['mcp' => false]);

    return isset(Server::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], $admin)['error']);
});

check('accesa: initialize risponde', function () use ($forza, $admin) {
    $forza(['mcp' => true]);

    return Server::enabled() === true
        && isset(Server::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], $admin)['result']['serverInfo']);
});

check('accesa: gli strumenti sono elencati', function () use ($forza, $admin, $elenco) {
    $forza(['mcp' => true]);

    return isset(Server::handle($elenco, $admin)['result']['tools']);
});

check('spenta: l\'endpoint risponde 404 senza corpo', function () {
    // Processo a parte: l'handler imposta header e codice di risposta.
    $codice = '$p = new ReflectionProperty(' . var_export(Gestionale::class, true) . ", 'features'); \$p->setValue(null, ['mcp' => false]);"
        . "\$_SERVER['REQUEST_METHOD'] = 'POST'; ob_start(); include " . var_export(__DIR__ . '/../http/api/mcp.php', true) . ';'
        . "\$corpo = ob_get_clean(); echo http_response_code(), '|', \$corpo;";
    $uscita = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -r ' . escapeshellarg('require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . ';' . $codice));

    return trim((string) $uscita) === '404|';
});

summary();
