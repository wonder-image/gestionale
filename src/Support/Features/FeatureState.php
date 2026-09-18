<?php

namespace Wonder\Plugin\Gestionale\Support\Features;

/**
 * Stato effettivo di una funzionalità: sbloccata, con tutte le dipendenze
 * attive e il modulo richiesto abilitato. Classe pura: riceve catalogo, righe
 * e moduli, non legge il database.
 */
final class FeatureState
{
    /**
     * @param array<string, array<string, mixed>> $catalog
     * @param array<string, bool> $unlocked chiave → sbloccata
     * @param list<string> $modules slug dei moduli abilitati
     * @return array<string, bool>
     */
    public static function resolve(array $catalog, array $unlocked, array $modules): array
    {
        $state = [];

        foreach (array_keys($catalog) as $key) {
            $state[$key] = self::active($catalog, $unlocked, $modules, (string) $key, []);
        }

        return $state;
    }

    public static function isActive(array $catalog, array $unlocked, array $modules, string $key): bool
    {
        return isset($catalog[$key]) && self::active($catalog, $unlocked, $modules, $key, []);
    }

    /** @param list<string> $path protegge da cataloghi con cicli non validati */
    private static function active(array $catalog, array $unlocked, array $modules, string $key, array $path): bool
    {
        if (!isset($catalog[$key]) || in_array($key, $path, true)) {
            return false;
        }

        if (($unlocked[$key] ?? false) !== true) {
            return false;
        }

        $module = (string) ($catalog[$key]['module'] ?? '');

        if ($module !== '' && !in_array($module, $modules, true)) {
            return false;
        }

        $path[] = $key;

        foreach ($catalog[$key]['requires'] ?? [] as $required) {
            if (!self::active($catalog, $unlocked, $modules, (string) $required, $path)) {
                return false;
            }
        }

        return true;
    }
}
