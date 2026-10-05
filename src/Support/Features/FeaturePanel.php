<?php

namespace Wonder\Plugin\Gestionale\Support\Features;

use Wonder\App\Support\TableSync;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Models\System\FeatureLog;

/**
 * Dati e salvataggio del pannello "Funzionalità": una sola pagina con un
 * interruttore per funzionalità, raggruppate per area.
 */
final class FeaturePanel
{
    /**
     * Funzionalità per area, con stato, dipendenze e modulo richiesto.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function byArea(): array
    {
        $catalog = FeatureCatalog::all();
        $state = self::state();
        $modules = self::modules();
        $areas = [];

        foreach ($catalog as $key => $feature) {
            $areas[$feature['area']][] = [
                'key' => $key,
                'name' => $feature['name'],
                'description' => $feature['description'],
                'enabled' => $state[$key] ?? false,
                'requires' => array_map(
                    static fn (string $required): string => $catalog[$required]['name'] ?? $required,
                    $feature['requires']
                ),
                'module' => $feature['module'],
                // Un modulo non abilitato blocca l'interruttore: la riga si
                // vedrebbe sbloccata senza esserlo davvero.
                'created' => $feature['created'],
                'available' => $feature['module'] === '' || in_array($feature['module'], $modules, true),
            ];
        }

        ksort($areas);

        return $areas;
    }

    /** Stato salvato: chiave → sbloccata. @return array<string, bool> */
    public static function state(): array
    {
        $state = [];

        foreach (self::rows() as $row) {
            $state[(string) ($row['feature_key'] ?? '')] = ($row['enabled'] ?? 'false') === 'true';
        }

        return $state;
    }

    /**
     * Salva le spunte del form, applica le regole delle dipendenze e scrive lo
     * storico. Restituisce i nomi di ciò che è cambiato.
     *
     * @param array<string, bool> $requested
     * @return array{unlocked: list<string>, locked: list<string>}
     */
    public static function save(array $requested, int $userId): array
    {
        $catalog = FeatureCatalog::all();
        $current = self::state();
        $result = FeatureRules::apply($catalog, $current, $requested);
        $rows = array_column(self::rows(), null, 'feature_key');
        $changed = false;

        foreach ($result['state'] as $key => $enabled) {
            $row = $rows[$key] ?? null;
            $from = ($current[$key] ?? false) ? 'true' : 'false';
            $to = $enabled ? 'true' : 'false';

            if ($row === null || $from === $to) {
                continue;
            }

            sqlModify(Feature::$table, [
                'enabled' => $to,
                'changed_at' => date('Y-m-d H:i:s'),
                'changed_by' => $userId,
            ], 'id', (int) $row['id']);

            sqlInsert(FeatureLog::$table, [
                'feature_id' => (int) $row['id'],
                'from_value' => $from,
                'to_value' => $to,
                'source' => ($requested[$key] ?? null) === $enabled ? 'user' : 'system',
                'user_id' => $userId,
                'note' => ($requested[$key] ?? null) === $enabled ? '' : 'dipendenze',
            ]);

            $changed = true;
        }

        if ($changed) {
            // La tabella si sincronizza: il file di sync deve seguire subito.
            TableSync::autoExport();
            Gestionale::reset();
        }

        return [
            'unlocked' => self::names($catalog, $result['unlocked']),
            'locked' => self::names($catalog, $result['locked']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        $rows = Feature::find(['deleted' => 'false']);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return list<string> */
    private static function modules(): array
    {
        return array_values(array_map(
            static fn ($manifest): string => $manifest->slug(),
            \Wonder\App\Module\Registry::enabled()
        ));
    }

    /** @return list<string> */
    private static function names(array $catalog, array $keys): array
    {
        return array_values(array_map(
            static fn (string $key): string => $catalog[$key]['name'] ?? $key,
            $keys
        ));
    }
}
