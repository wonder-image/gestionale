<?php

namespace Wonder\Plugin\Gestionale\Support\Status;

use RuntimeException;
use Wonder\Plugin\Gestionale\Models\System\StatusLog;

/**
 * Scrive una riga nel log degli stati di un documento.
 *
 * Chi cambia uno stato passa di qui invece di scrivere a mano nella tabella:
 * così `source` resta uno dei valori ammessi e la riga ha sempre le stesse
 * colonne, qualunque sia il documento.
 */
final class StatusLogger
{
    /** Chi ha fatto il cambio (4.1). */
    public const SOURCES = ['user', 'system', 'cron', 'webhook', 'api'];

    public static function record(
        string $logClass,
        int $entityId,
        string $field,
        string $from,
        string $to,
        string $source = 'user',
        ?int $userId = null,
        string $message = '',
        ?array $response = null
    ): bool {
        if (!is_subclass_of($logClass, StatusLog::class)) {
            throw new RuntimeException("{$logClass} deve estendere ".StatusLog::class);
        }

        $result = sqlInsert($logClass::$table, [
            $logClass::entityColumn() => $entityId,
            'field' => $field,
            'from_value' => $from,
            'to_value' => $to,
            'source' => self::normalizeSource($source),
            'user_id' => $userId ?? 0,
            'message' => $message,
            'response' => $response === null ? '' : (string) json_encode($response, JSON_UNESCAPED_UNICODE),
        ]);

        return !empty($result->success);
    }

    /** Un'origine che non conosciamo non è colpa di chi legge il log: diventa `system`. */
    public static function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));

        return in_array($source, self::SOURCES, true) ? $source : 'system';
    }
}
