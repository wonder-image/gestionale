<?php

use Wonder\Plugin\Gestionale\Support\Mcp\Auth;
use Wonder\Plugin\Gestionale\Support\Mcp\McpError;
use Wonder\Plugin\Gestionale\Support\Mcp\Server;

// Server MCP (Streamable HTTP): solo POST, una risposta JSON per messaggio,
// niente stream SSE e niente sessioni. Il token è quello di `api_users`.
header('Cache-Control: no-store');

$respond = static function (int $status, ?array $body): void {
    http_response_code($status);

    if ($body !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo Server::json($body);
    }
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    $respond(405, null);

    return;
}

try {
    $caller = Auth::fromRequest($_SERVER);
} catch (McpError $error) {
    if ($error->getCode() === 401) {
        header('WWW-Authenticate: Bearer realm="mcp"');
    }

    $respond($error->getCode() ?: 401, Server::error(null, Server::INVALID_REQUEST, $error->getMessage()));

    return;
}

$message = json_decode((string) file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    $respond(400, Server::error(null, Server::PARSE_ERROR, Server::text('parse')));

    return;
}

$response = Server::handle($message, $caller);

// Le notifiche non hanno risposta: 202 senza corpo.
$respond($response === null ? 202 : 200, $response);
