<?php

namespace Wonder\Plugin\Gestionale\Support\Providers;

use Wonder\Plugin\Gestionale\Models\System\ProviderEvent;

/**
 * Registro degli eventi arrivati dai provider.
 *
 * `receive()` dice se l'evento va elaborato: `false` solo quando l'avevamo già
 * elaborato. Un evento ricevuto o fallito si riprende — il rinvio del provider
 * è proprio il nuovo tentativo.
 */
final class ProviderEvents
{
    /** Dopo quattro tentativi andati male l'evento resta a una persona. */
    public const MAX_ATTEMPTS = 4;

    /** Registra l'evento; `false` se l'avevamo già elaborato. */
    public static function receive(
        string $provider,
        string $eventId,
        string $type,
        array $payload,
        string $environment = 'live'
    ): bool {
        $existing = self::find($provider, $eventId, $environment);

        if ($existing !== null) {
            // Già elaborato: il rinvio non rifà il lavoro. Ricevuto o fallito:
            // il rinvio è il nuovo tentativo, sulla stessa riga, col contenuto nuovo.
            if ((string) ($existing['status'] ?? '') === 'processed') {
                return false;
            }

            sqlModify(ProviderEvent::$table, [
                'type' => trim($type),
                'payload' => self::encode($payload),
            ], 'id', (int) $existing['id']);

            return true;
        }

        $result = sqlInsert(ProviderEvent::$table, [
            'provider' => trim($provider),
            'environment' => self::environment($environment),
            'event_id' => trim($eventId),
            'type' => trim($type),
            'payload' => self::encode($payload),
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

    /**
     * Tentativo andato male: si conta, così si vede chi insiste a fallire.
     *
     * `$final` è per gli errori che riprovare non aggiusta (importo diverso,
     * ordine sbagliato): l'evento arriva subito al massimo dei tentativi.
     */
    public static function markFailed(
        string $provider,
        string $eventId,
        string $error,
        string $environment = 'live',
        bool $final = false
    ): bool {
        $event = self::find($provider, $eventId, $environment);

        if ($event === null) {
            return false;
        }

        return !empty(sqlModify(ProviderEvent::$table, [
            'status' => 'failed',
            'attempts' => $final ? self::MAX_ATTEMPTS : (int) ($event['attempts'] ?? 0) + 1,
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

    /** Il contenuto da salvare, senza i `client_secret`: nel registro non servono. */
    private static function encode(array $payload): string
    {
        $strip = static function (array $data) use (&$strip): array {
            unset($data['client_secret']);

            return array_map(static fn (mixed $value): mixed => is_array($value) ? $strip($value) : $value, $data);
        };

        return (string) json_encode($strip($payload), JSON_UNESCAPED_UNICODE);
    }
}
