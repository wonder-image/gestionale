<?php

namespace Wonder\Plugin\Gestionale\Support\Mcp;

use Throwable;
use Wonder\Plugin\Gestionale\Models\System\McpLog;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * Il server MCP (Streamable HTTP, solo POST, senza sessioni né stream):
 * riceve un messaggio JSON-RPC e restituisce la risposta, o `null` per le
 * notifiche. L'autenticazione è già fatta: arriva il `Caller`.
 */
final class Server
{
    public const PROTOCOL = '2025-03-26';
    public const NAME = 'wonder-gestionale';
    public const VERSION = '1.0.0';

    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    /** @return array<string, mixed>|null */
    public static function handle(mixed $message, Caller $caller): ?array
    {
        if (!is_array($message) || array_is_list($message) || ($message['jsonrpc'] ?? null) !== '2.0' || !is_string($message['method'] ?? null)) {
            // I batch non sono più nel protocollo dal 2025-06-18: si rifiutano.
            return self::error(null, self::INVALID_REQUEST, self::text('invalid_request'));
        }

        // Senza `id` è una notifica: non si risponde.
        if (!array_key_exists('id', $message)) {
            return null;
        }

        $id = $message['id'];
        $params = $message['params'] ?? [];

        if (!is_array($params)) {
            return self::error($id, self::INVALID_PARAMS, self::text('object', ['field' => 'params']));
        }

        return match ($message['method']) {
            'initialize' => self::result($id, [
                'protocolVersion' => is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : self::PROTOCOL,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => self::NAME, 'version' => self::VERSION],
                'instructions' => 'Gestionale del negozio: prodotti (versioni vendibili), prezzi, giacenze, categorie e stato degli ordini. Gli id dei prodotti sono quelli delle versioni; cerca con products_search prima di modificare.',
            ]),
            'ping' => self::result($id, (object) []),
            'tools/list' => self::result($id, ['tools' => self::list($caller)]),
            'tools/call' => self::call($id, $params, $caller),
            default => self::error($id, self::METHOD_NOT_FOUND, self::text('method', ['method' => $message['method']])),
        };
    }

    /** @return list<array<string, mixed>> */
    public static function list(Caller $caller): array
    {
        $out = [];

        foreach (Tools::all() as $name => $tool) {
            if ($caller->allows(Tools::roles($tool['permission']))) {
                $out[] = [
                    'name' => $name,
                    'description' => $tool['description'],
                    'inputSchema' => $tool['schema'],
                    'annotations' => ['readOnlyHint' => !$tool['write'], 'destructiveHint' => false],
                ];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function call(mixed $id, array $params, Caller $caller): array
    {
        $name = $params['name'] ?? null;
        $tools = Tools::all();
        $tool = is_string($name) ? ($tools[$name] ?? null) : null;

        // Uno strumento non permesso è come uno che non esiste.
        if ($tool === null || !$caller->allows(Tools::roles($tool['permission']))) {
            return self::error($id, self::INVALID_PARAMS, self::text('unknown_tool', ['name' => is_string($name) ? $name : '']));
        }

        $arguments = $params['arguments'] ?? [];

        if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return self::error($id, self::INVALID_PARAMS, self::text('object', ['field' => 'arguments']));
        }

        $outcome = null;
        $failure = null;

        try {
            $outcome = ($tool['run'])($arguments, $caller);
        } catch (McpError $error) {
            $failure = $error->getMessage();
        } catch (UserError $error) {
            $failure = $error->getMessage();
        } catch (Throwable $error) {
            error_log('[mcp] '.$name.': '.$error->getMessage());
            $failure = self::text('internal');
        }

        // Il registro si scrive fuori dalla transazione dello strumento, così
        // resta anche quando lo strumento fallisce e torna indietro.
        if ($tool['write']) {
            self::log((string) $name, $arguments, $caller, $outcome, $failure);
        }

        return self::result($id, $failure === null
            ? ['content' => [['type' => 'text', 'text' => self::json($outcome['result'] ?? null)]], 'isError' => false]
            : ['content' => [['type' => 'text', 'text' => $failure]], 'isError' => true]);
    }

    private static function log(string $tool, array $arguments, Caller $caller, ?array $outcome, ?string $failure): void
    {
        try {
            $row = [
                'tool' => $tool,
                'user_id' => $caller->userId,
                'api_user_id' => $caller->apiUserId,
                'subject_id' => (int) ($outcome['subject'] ?? $arguments['id'] ?? $arguments['product_id'] ?? $arguments['order_id'] ?? 0),
                'arguments' => self::json($arguments),
                'state_before' => array_key_exists('before', $outcome ?? []) ? self::json($outcome['before']) : '',
                'state_after' => array_key_exists('after', $outcome ?? []) ? self::json($outcome['after']) : '',
                'success' => $failure === null ? 'true' : 'false',
                'error' => (string) $failure,
                'ip' => $caller->ip,
            ];

            // La colonna esiste solo dopo la migrazione: la si scrive solo per i token OAuth.
            if ($caller->oauthGrantId !== null) {
                $row['oauth_grant_id'] = $caller->oauthGrantId;
            }

            McpLog::create($row);
        } catch (Throwable $error) {
            error_log('[mcp] registro non scritto: '.$error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    public static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** @param array<string, string|int|float> $replacements */
    public static function text(string $key, array $replacements = []): string
    {
        return McpError::make($key, 0, $replacements)->getMessage();
    }

    public static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
