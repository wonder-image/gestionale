<?php

namespace Wonder\Plugin\Gestionale\Support\Mcp;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
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

    /** Funzionalità `mcp`: spenta, il server non espone niente. */
    public static function enabled(): bool
    {
        return Gestionale::feature('mcp');
    }

    /** @return array<string, mixed>|null */
    public static function handle(mixed $message, Caller $caller): ?array
    {
        if (!self::enabled()) {
            return self::error(null, self::METHOD_NOT_FOUND, self::text('disabled'));
        }

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
                'instructions' => 'Gestionale del negozio: prodotti (versioni vendibili), prezzi, giacenze, categorie, attributi, personalizzazioni, stato degli ordini, preventivi e clienti (in sola lettura). Gli id dei prodotti sono quelli delle versioni; cerca con products_search prima di modificare. Non inventare mai i valori obbligatori (nomi, tipi, clienti, righe, quantità, prezzi): se l\'utente non li ha detti chiediglieli, e se uno strumento risponde con un elenco di campi mancanti chiedi all\'utente proprio quelli.',
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

        foreach (self::enabled() ? Tools::all() : [] as $name => $tool) {
            if (self::opens($caller, $tool)) {
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

    /** Servono sia i ruoli dell'utente sia, per un token OAuth, lo scope approvato. */
    private static function opens(Caller $caller, array $tool): bool
    {
        return $caller->allows(Tools::roles($tool['permission'])) && $caller->grants(Tools::scope($tool));
    }

    /** @return array<string, mixed> */
    private static function call(mixed $id, array $params, Caller $caller): array
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';

        // Uno strumento non permesso è come uno che non esiste.
        if (self::tool($name, $caller) === null) {
            return self::error($id, self::INVALID_PARAMS, self::text('unknown_tool', ['name' => $name]));
        }

        $arguments = $params['arguments'] ?? [];

        if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return self::error($id, self::INVALID_PARAMS, self::text('object', ['field' => 'arguments']));
        }

        $call = self::invoke($name, $arguments, $caller);

        return self::result($id, $call['failure'] === null
            ? ['content' => [['type' => 'text', 'text' => self::json($call['outcome']['result'] ?? null)]], 'isError' => false]
            : ['content' => [['type' => 'text', 'text' => $call['failure']]], 'isError' => true]);
    }

    /**
     * Esegue uno strumento per chi lo può aprire: la usano il server MCP e le
     * API REST. `kind` dice perché non è andata: `forbidden` (strumento che
     * non c'è o non è permesso), `missing` (campi obbligatori), `not_found`,
     * `invalid` (un rifiuto dello strumento) o `internal`.
     *
     * @return array{outcome: ?array, failure: ?string, kind: ?string}
     */
    public static function invoke(string $name, array $arguments, Caller $caller): array
    {
        $tool = self::tool($name, $caller);

        if ($tool === null) {
            return ['outcome' => null, 'failure' => self::text('unknown_tool', ['name' => $name]), 'kind' => 'forbidden'];
        }

        $outcome = null;
        $failure = null;
        $kind = null;
        $missing = self::missing($tool['schema'], $arguments);

        try {
            if ($missing !== []) {
                // I valori obbligatori li dà l'utente: si dicono tutti quelli
                // che mancano, così il client li chiede invece di inventarli.
                $failure = self::text('missing_fields', ['fields' => implode(', ', $missing)]);
                $kind = 'missing';
            } else {
                $outcome = ($tool['run'])($arguments, $caller);
            }
        } catch (McpError $error) {
            $failure = $error->getMessage();
            $kind = $error->notFound() ? 'not_found' : 'invalid';
        } catch (UserError $error) {
            $failure = $error->getMessage();
            $kind = 'invalid';
        } catch (Throwable $error) {
            error_log('[mcp] '.$name.': '.$error->getMessage());
            $failure = self::text('internal');
            $kind = 'internal';
        }

        // Il registro si scrive fuori dalla transazione dello strumento, così
        // resta anche quando lo strumento fallisce e torna indietro.
        if ($tool['write']) {
            self::log($name, $arguments, $caller, $outcome, $failure);
        }

        return ['outcome' => $failure === null ? $outcome : null, 'failure' => $failure, 'kind' => $kind];
    }

    /** Lo strumento, se esiste e il chiamante lo può aprire. @return array<string, mixed>|null */
    private static function tool(string $name, Caller $caller): ?array
    {
        $tool = $name !== '' ? (Tools::all()[$name] ?? null) : null;

        return $tool !== null && self::opens($caller, $tool) ? $tool : null;
    }

    /**
     * I campi `required` dello schema che non sono arrivati, o sono arrivati
     * vuoti.
     *
     * @return list<string>
     */
    public static function missing(array $schema, array $arguments): array
    {
        $missing = [];

        foreach ((array) ($schema['required'] ?? []) as $key) {
            $value = $arguments[$key] ?? null;

            if ($value === null || $value === [] || (is_string($value) && trim($value) === '')) {
                $missing[] = (string) $key;
            }
        }

        return $missing;
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
