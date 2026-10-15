<?php
/**
 * php tests/integrazione/McpHttpTest.php
 *
 * Il server MCP vero, via HTTPS sul sito di prova servito da Herd. Serve un
 * utente con `administrator` e `api_internal_user` e la sua riga di
 * `api_users`: si sceglie con WI_TEST_MCP_USER (username, di serie `@mcp`).
 * Solo letture: le scritture sono provate, con l'annullamento, in
 * McpServerTest.
 */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

$url = rtrim(getenv('WI_TEST_URL') ?: 'https://ecommerce.test', '/').'/api/gestionale/mcp/';
$user = infoUser(getenv('WI_TEST_MCP_USER') ?: '@mcp', 'username');
$token = (string) ($user->api_internal_user->token ?? '');

/** @return array{0: int, 1: string, 2: string} stato, intestazioni, corpo */
$http = static function (string $method, ?string $body, ?string $bearer) use ($url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_filter([
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
            $bearer !== null ? 'Authorization: Bearer '.$bearer : null,
        ]),
    ] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []));
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    return [$status, substr($raw, 0, $size), substr($raw, $size)];
};
$rpc = static fn (string $method, array $params = [], bool $notifica = false): string => json_encode(
    ['jsonrpc' => '2.0', 'method' => $method] + ($notifica ? [] : ['id' => 1]) + ($params !== [] ? ['params' => $params] : [])
);

echo "Server MCP via HTTP ({$url})\n";

check('GET: 405 con Allow: POST', static function () use ($http): bool {
    [$status, $headers] = $http('GET', null, null);

    return $status === 405 && stripos($headers, 'Allow: POST') !== false;
});

check('senza token: 401 con resource_metadata e senza error', static function () use ($http, $rpc): bool {
    [$status, $headers] = $http('POST', $rpc('ping'), null);
    $www = preg_match('/^WWW-Authenticate:\s*(.*)$/mi', $headers, $m) ? $m[1] : '';

    return $status === 401
        && str_starts_with($www, 'Bearer ')
        && str_contains($www, 'resource_metadata="')
        && str_contains($www, '/.well-known/oauth-protected-resource/api/gestionale/mcp"')
        && !str_contains($www, 'error=');
});

check('token falso: 401', static fn (): bool => $http('POST', $rpc('ping'), 'abc.def.ghi')[0] === 401);

$wwwAuth = static fn (string $headers): string => preg_match('/^WWW-Authenticate:\s*(.*)$/mi', $headers, $m) ? trim($m[1]) : '';

// Anche il percorso JWT risponde 401 con resource_metadata e invalid_token.
check('JWT non valido: 401 con resource_metadata e invalid_token', static function () use ($http, $rpc, $wwwAuth): bool {
    [$status, $headers] = $http('POST', $rpc('tools/list'), 'eyJhbGciOiJIUzI1NiJ9.e30.x');
    $www = $wwwAuth($headers);

    return $status === 401 && str_contains($www, 'resource_metadata="') && str_contains($www, 'error="invalid_token"');
});

check('wio_at_ inesistente: 401 invalid_token', static function () use ($http, $rpc, $wwwAuth): bool {
    [$status, $headers] = $http('POST', $rpc('tools/list'), 'wio_at_'.str_repeat('0', 16).'.'.str_repeat('0', 64));
    $www = $wwwAuth($headers);

    return $status === 401 && str_contains($www, 'resource_metadata="') && str_contains($www, 'error="invalid_token"');
});

if ($token === '') {
    echo "  · manca l'utente MCP con il suo token: salto le prove autenticate\n";
    summary();
}

check('JSON rotto: 400 e -32700', static function () use ($http, $token): bool {
    [$status, , $body] = $http('POST', '{"jsonrpc":', $token);

    return $status === 400 && (json_decode($body, true)['error']['code'] ?? 0) === -32700;
});

check('initialize', static function () use ($http, $rpc, $token): bool {
    [$status, $headers, $body] = $http('POST', $rpc('initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']]), $token);
    $r = json_decode($body, true);

    return $status === 200 && stripos($headers, 'application/json') !== false
        && ($r['result']['serverInfo']['name'] ?? '') === 'wonder-gestionale';
});

check('notifica: 202 senza corpo', static function () use ($http, $rpc, $token): bool {
    [$status, , $body] = $http('POST', $rpc('notifications/initialized', [], true), $token);

    return $status === 202 && $body === '';
});

check('tools/list', static function () use ($http, $rpc, $token): bool {
    $tools = array_column(json_decode($http('POST', $rpc('tools/list'), $token)[2], true)['result']['tools'] ?? [], 'name');

    return in_array('product_get', $tools, true) && in_array('product_update', $tools, true);
});

check('products_search e product_get', static function () use ($http, $rpc, $token): bool {
    $found = json_decode($http('POST', $rpc('tools/call', ['name' => 'products_search', 'arguments' => ['query' => 'a']]), $token)[2], true);
    $list = json_decode($found['result']['content'][0]['text'] ?? '[]', true);

    if ($list === []) {
        return true;
    }

    $got = json_decode($http('POST', $rpc('tools/call', ['name' => 'product_get', 'arguments' => ['id' => $list[0]['id']]]), $token)[2], true);
    $product = json_decode($got['result']['content'][0]['text'] ?? 'null', true);

    return ($got['result']['isError'] ?? true) === false && ($product['id'] ?? 0) === $list[0]['id'];
});

\Wonder\OAuth\OAuth::resource('/api/gestionale/mcp', 'Gestionale');

/** @return array{0: string, 1: int, 2: string} client_id, grant_id, access token */
$oauthToken = static function () use ($user): array {
    $store = \Wonder\OAuth\OAuth::store();
    $client = (new \Wonder\OAuth\ClientRegistry($store))->register(['client_name' => 'test-integrazione', 'redirect_uris' => ['http://localhost/callback']], '127.0.0.1')['client_id'];
    $issuer = new \Wonder\OAuth\TokenIssuer($store);
    $grant = $issuer->grantFor((int) $user->id, $client, \Wonder\OAuth\ResourceRegistry::findByPath('/api/gestionale/mcp')['url']);

    return [$client, $grant, $issuer->issue($grant)['access_token']];
};
$oauthCleanup = static function (string $client, int $grant): void {
    $store = \Wonder\OAuth\OAuth::store();
    $store->revokeGrant($grant, date('Y-m-d H:i:s'));
    $store->deleteClient($client);
};

check('wio_at_ valido: tools/list risponde', static function () use ($http, $rpc, $oauthToken, $oauthCleanup): bool {
    [$client, $grant, $access] = $oauthToken();
    [$status, , $body] = $http('POST', $rpc('tools/list'), $access);
    $oauthCleanup($client, $grant);

    return $status === 200 && isset(json_decode($body, true)['result']['tools']);
});

check('wio_at_ revocato: 401 invalid_token', static function () use ($http, $rpc, $wwwAuth, $oauthToken, $oauthCleanup): bool {
    [$client, $grant, $access] = $oauthToken();
    \Wonder\OAuth\OAuth::store()->revokeGrant($grant, date('Y-m-d H:i:s'));
    [$status, $headers] = $http('POST', $rpc('tools/list'), $access);
    $oauthCleanup($client, $grant);

    return $status === 401 && str_contains($wwwAuth($headers), 'error="invalid_token"');
});

summary();
