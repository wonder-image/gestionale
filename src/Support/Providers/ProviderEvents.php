<?php

namespace Wonder\Plugin\Gestionale\Support\Providers;

use Wonder\Plugin\Gestionale\Models\System\ProviderEvent;

/**
 * Registro degli eventi arrivati dai provider.
 *
 * `receive()` dice se l'evento è nuovo: quando torna `false` l'evento era già
 * arrivato e va ignorato, senza rifare il lavoro. È l'unico modo per
 * sopravvivere ai rinvii dei webhook.
 */
final class ProviderEvents
{
    /** Registra l'evento; `false` se l'avevamo già. */
    public static function receive(
        string $provider,
        string $eventId,
        string $type,
        array $payload,
        string $environment = 'live'
    ): bool {
        if (self::find($provider, $eventId, $environment) !== null) {
            return false;
        }

        $result = sqlInsert(ProviderEvent::$table, [
            'provider' => trim($provider),
            'environment' => self::environment($environment),
            'event_id' => trim($eventId),
            'type' => trim($type),
            'payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status' => 'received',
            'attempts' => 0,
        ]);

        return !empty($result->success);
    }

    /** Lavoro finito: l'evento non si ripete. */
    public static function markProcessed(string $provider, string $eventId, string $environment = 'live'): bool
    {
        $event = self::find($provider, $eventId, $environment);

        if ($event === null) {
            return false;
        }

        return !empty(sqlModify(ProviderEvent::$table, [
            'status' => 'processed',
            'processed_at' => date('Y-m-d H:i:s'),
            'error' => '',
        ], 'id', (int) $event['id'])->success);
    }

    /** Tentativo andato male: si conta, così si vede chi insiste a fallire. */
    public static function markFailed(
        string $provider,
        string $eventId,
        string $error,
        string $environment = 'live'
    ): bool {
        $event = self::find($provider, $eventId, $environment);

        if ($event === null) {
            return false;
        }

        return !empty(sqlModify(ProviderEvent::$table, [
            'status' => 'failed',
            'attempts' => (int) ($event['attempts'] ?? 0) + 1,
            'error' => $error,
        ], 'id', (int) $event['id'])->success);
    }

    /** L'evento registrato, `null` se non c'è. */
    public static function find(string $provider, string $eventId, string $environment = 'live'): ?array
    {
        $row = ProviderEvent::find([
            'provider' => trim($provider),
            'event_id' => trim($eventId),
            'environment' => self::environment($environment),
            'deleted' => 'false',
        ], 1);

        return is_array($row) && $row !== [] ? $row : null;
    }

    private static function environment(string $environment): string
    {
        $environment = strtolower(trim($environment));

        return in_array($environment, ProviderEvent::ENVIRONMENTS, true) ? $environment : 'live';
    }
}
