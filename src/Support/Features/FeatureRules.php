<?php

namespace Wonder\Plugin\Gestionale\Support\Features;

/**
 * Regole del pannello: da ciò che l'utente ha spuntato ricava lo stato finale
 * di tutte le funzionalità. Sbloccare tira dentro le dipendenze che mancano,
 * bloccare porta con sé le funzionalità che dipendono da quella. Classe pura:
 * niente database.
 */
final class FeatureRules
{
    /**
     * @param array<string, array<string, mixed>> $catalog
     * @param array<string, bool> $current stato nel database
     * @param array<string, bool> $requested stato spuntato nel form
     * @return array{state: array<string, bool>, unlocked: list<string>, locked: list<string>}
     */
    public static function apply(array $catalog, array $current, array $requested): array
    {
        $state = [];

        foreach (array_keys($catalog) as $key) {
            $state[$key] = ($requested[$key] ?? $current[$key] ?? false) === true;
        }

        // Prima gli sblocchi chiesti: portano su le dipendenze che mancano.
        foreach (array_keys($catalog) as $key) {
            if (($requested[$key] ?? false) === true && ($current[$key] ?? false) !== true) {
                foreach (self::dependencies($catalog, $key) as $dependency) {
                    $state[$dependency] = true;
                }
            }
        }

        // Poi i blocchi chiesti: portano giù chi dipende da loro.
        foreach (array_keys($catalog) as $key) {
            if (($requested[$key] ?? true) === false && ($current[$key] ?? false) === true) {
                foreach (self::dependents($catalog, $key) as $dependent) {
                    $state[$dependent] = false;
                }
            }
        }

        // Chiusura finale: nessuna funzionalità resta attiva senza le sue dipendenze.
        foreach (array_keys($catalog) as $key) {
            foreach (self::dependencies($catalog, $key) as $dependency) {
                if ($state[$key] === true && ($state[$dependency] ?? false) !== true) {
                    $state[$key] = false;
                }
            }
        }

        $unlocked = [];
        $locked = [];

        foreach ($state as $key => $enabled) {
            $was = ($current[$key] ?? false) === true;

            if ($enabled && !$was) {
                $unlocked[] = $key;
            } elseif (!$enabled && $was) {
                $locked[] = $key;
            }
        }

        return ['state' => $state, 'unlocked' => $unlocked, 'locked' => $locked];
    }

    /** Dipendenze diritte e indirette di una funzionalità. @return list<string> */
    public static function dependencies(array $catalog, string $key, array $path = []): array
    {
        if (in_array($key, $path, true)) {
            return [];
        }

        $path[] = $key;
        $dependencies = [];

        foreach ($catalog[$key]['requires'] ?? [] as $required) {
            $dependencies[] = (string) $required;
            $dependencies = array_merge($dependencies, self::dependencies($catalog, (string) $required, $path));
        }

        return array_values(array_unique($dependencies));
    }

    /** Funzionalità che dipendono da questa, anche a catena. @return list<string> */
    public static function dependents(array $catalog, string $key, array $path = []): array
    {
        if (in_array($key, $path, true)) {
            return [];
        }

        $path[] = $key;
        $dependents = [];

        foreach ($catalog as $candidate => $feature) {
            if (in_array($key, $feature['requires'] ?? [], true)) {
                $dependents[] = (string) $candidate;
                $dependents = array_merge($dependents, self::dependents($catalog, (string) $candidate, $path));
            }
        }

        return array_values(array_unique($dependents));
    }
}
