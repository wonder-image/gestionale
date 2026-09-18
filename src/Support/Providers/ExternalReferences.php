<?php

namespace Wonder\Plugin\Gestionale\Support\Providers;

use Wonder\Plugin\Gestionale\Models\System\ExternalReference;

/**
 * Legge e scrive gli id delle nostre entità nei sistemi esterni.
 *
 * `save()` e `fail()` sono separati apposta: quando una sincronizzazione va
 * male l'id già ottenuto non si tocca, altrimenti al tentativo dopo si
 * creerebbe un doppione dall'altra parte. L'errore resta scritto accanto, e il
 * primo `save()` riuscito lo cancella.
 */
final class ExternalReferences
{
    /** Il riferimento di quell'entità per quel provider, `null` se non c'è. */
    public static function find(
        string $entityType,
        int $entityId,
        string $provider,
        string $objectType,
        string $environment = 'live'
    ): ?array {
        $row = ExternalReference::find([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'provider' => $provider,
            'object_type' => $objectType,
            'environment' => self::environment($environment),
            'deleted' => 'false',
        ], 1);

        return is_array($row) && $row !== [] ? $row : null;
    }

    /** Tutti i riferimenti di un'entità, in ogni provider e ambiente. @return list<array> */
    public static function all(string $entityType, int $entityId): array
    {
        $rows = ExternalReference::find([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'deleted' => 'false',
        ]);

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        // Con una riga sola il Model restituisce la riga, non la lista.
        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /** Registra l'id esterno e segna la sincronizzazione riuscita. */
    public static function save(
        string $entityType,
        int $entityId,
        string $provider,
        string $objectType,
        string $externalId,
        string $environment = 'live'
    ): bool {
        $existing = self::find($entityType, $entityId, $provider, $objectType, $environment);

        $values = [
            'external_id' => $externalId,
            'synced_at' => date('Y-m-d H:i:s'),
            'sync_error' => '',
        ];

        if ($existing !== null) {
            return !empty(sqlModify(ExternalReference::$table, $values, 'id', (int) $existing['id'])->success);
        }

        return !empty(sqlInsert(ExternalReference::$table, array_merge($values, [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'provider' => $provider,
            'object_type' => $objectType,
            'environment' => self::environment($environment),
        ]))->success);
    }

    /** Segna il tentativo andato male, lasciando dov'è l'id già salvato. */
    public static function fail(
        string $entityType,
        int $entityId,
        string $provider,
        string $objectType,
        string $error,
        string $environment = 'live'
    ): bool {
        $existing = self::find($entityType, $entityId, $provider, $objectType, $environment);

        if ($existing !== null) {
            return !empty(sqlModify(
                ExternalReference::$table,
                ['sync_error' => $error],
                'id',
                (int) $existing['id']
            )->success);
        }

        return !empty(sqlInsert(ExternalReference::$table, [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'provider' => $provider,
            'object_type' => $objectType,
            'environment' => self::environment($environment),
            'external_id' => '',
            'sync_error' => $error,
        ])->success);
    }

    /** Dimentica il riferimento: l'entità non esiste più dall'altra parte. */
    public static function forget(
        string $entityType,
        int $entityId,
        string $provider,
        string $objectType,
        string $environment = 'live'
    ): bool {
        $existing = self::find($entityType, $entityId, $provider, $objectType, $environment);

        if ($existing === null) {
            return false;
        }

        return !empty(ExternalReference::delete((int) $existing['id'])->success ?? false);
    }

    private static function environment(string $environment): string
    {
        $environment = strtolower(trim($environment));

        return in_array($environment, ExternalReference::ENVIRONMENTS, true) ? $environment : 'live';
    }
}
