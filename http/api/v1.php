<?php

use Wonder\App\Support\ApiRequest;
use Wonder\Plugin\Gestionale\Support\Api\Rest;
use Wonder\Plugin\Gestionale\Support\Mcp\Auth;
use Wonder\Plugin\Gestionale\Support\Mcp\McpError;
use Wonder\Plugin\Gestionale\Support\Mcp\Server;

// API REST del gestionale: ogni rotta chiama uno strumento MCP (`Rest::routes()`).
// Niente sessione: vale il token di `api_users` o un access token OAuth (`wio_at_`).
header('Cache-Control: no-store');

try {
    $caller = Auth::fromRequest($_SERVER);
} catch (McpError $error) {
    $status = $error->getCode() ?: 401;

    if ($status === 401) {
        header('WWW-Authenticate: '.Auth::challenge(Auth::bearer($_SERVER) !== ''));
    }

    ApiRequest::error($error->getMessage(), $status);
}

$route = (string) ((is_array($ROUTE_META ?? null) ? $ROUTE_META : [])['rest'] ?? '');
$parameters = is_array($ROUTE_PARAMETERS ?? null) ? $ROUTE_PARAMETERS : [];
$get = (Rest::routes()[$route]['method'] ?? 'GET') === 'GET';

[$tool, $arguments] = Rest::call($route, $parameters, $get ? $_GET : [], $get ? [] : ApiRequest::body());
$call = Server::invoke($tool, $arguments, $caller);

if ($call['failure'] !== null) {
    // Per REST uno strumento non permesso è un 403, non uno sconosciuto.
    ApiRequest::error($call['kind'] === 'forbidden' ? Server::text('forbidden') : $call['failure'], Rest::status($route, $call['kind']));
}

$result = $call['outcome']['result'] ?? [];
ApiRequest::ok(is_array($result) || is_string($result) ? $result : [], Rest::status($route, null));
