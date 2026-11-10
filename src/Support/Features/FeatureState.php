<?php

namespace Wonder\Plugin\Gestionale\Support\Features;

/**
 * Stato effettivo di una funzionalità: sbloccata, con tutte le dipendenze
 * attive (e almeno una delle `requires_any`) e il modulo richiesto abilitato. Classe pura: riceve catalogo, righe
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

    /**
     * Lo stato di una voce senza riga propria nasce da quelle che sostituisce
     * (`replaces`): sbloccata se almeno una lo era. Con la riga, vale la riga.
     *
     * @param array<string, array<string, mixed>> $catalog
     * @param array<string, bool> $unlocked chiave → sbloccata, comprese le chiavi tolte
     * @return array<string, bool>
     */
    public static function inherit(array $catalog, array $unlocked): array
    {
        foreach ($catalog as $key => $feature) {
            $replaces = (array) ($feature['replaces'] ?? []);

            if ($replaces === [] || array_key_exists($key, $unlocked)) {
                continue;
            }

            foreach ($replaces as $old) {
                if (($unlocked[$old] ?? false) === true) {
                    $unlocked[$key] = true;
                    break;
                }
            }
        }

        return $unlocked;
    }

    /**
     * Una voce con `default => true`, o con un `default_if` che risponde sì, e
     * senza riga propria conta come sbloccata. Con la riga, vale la riga: il
     * `default_if` si chiama solo senza.
     *
     * @param array<string, array<string, mixed>> $catalog
     * @param array<string, bool> $unlocked
     * @return array<string, bool>
     */
    public static function defaults(array $catalog, array $unlocked): array
    {
        foreach ($catalog as $key => $feature) {
            if (array_key_exists($key, $unlocked)) {
                continue;
            }

            $if = $feature['default_if'] ?? null;

            if (($feature['default'] ?? false) === true || (is_callable($if) && $if() === true)) {
                $unlocked[$key] = true;
            }
        }

        return $unlocked;
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

        $any = (array) ($catalog[$key]['requires_any'] ?? []);

        if ($any === []) {
            return true;
        }

        foreach ($any as $required) {
            if (self::active($catalog, $unlocked, $modules, (string) $required, $path)) {
                return true;
            }
        }

        return false;
    }
}
