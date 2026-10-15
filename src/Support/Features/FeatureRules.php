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
                self::pull($catalog, $state, $key, []);
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

        // Chiusura finale: nessuna funzionalità resta attiva senza le sue
        // dipendenze o senza almeno una delle `requires_any`. Si ripete finché
        // qualcosa si spegne: una alternativa spenta può portarsi dietro una catena.
        do {
            $changed = false;

            foreach (array_keys($catalog) as $key) {
                if ($state[$key] === true && !self::satisfied($catalog, $state, $key)) {
                    $state[$key] = false;
                    $changed = true;
                }
            }
        } while ($changed);

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

    /**
     * Accende le dipendenze di una funzionalità, a catena. Per le `requires_any`
     * va bene quella già accesa; se non ce n'è nessuna si accende la prima
     * della lista.
     *
     * @param array<string, bool> $state
     * @param list<string> $path
     */
    private static function pull(array $catalog, array &$state, string $key, array $path): void
    {
        if (in_array($key, $path, true)) {
            return;
        }

        $path[] = $key;

        foreach ($catalog[$key]['requires'] ?? [] as $required) {
            $state[(string) $required] = true;
            self::pull($catalog, $state, (string) $required, $path);
        }

        $any = array_map('strval', (array) ($catalog[$key]['requires_any'] ?? []));

        if ($any === []) {
            return;
        }

        $chosen = null;

        foreach ($any as $candidate) {
            if (($state[$candidate] ?? false) === true) {
                $chosen = $candidate;
                break;
            }
        }

        $chosen ??= $any[0];
        $state[$chosen] = true;
        self::pull($catalog, $state, $chosen, $path);
    }

    /** Le dipendenze dirette accese e, se ce ne sono, almeno una `requires_any`. */
    private static function satisfied(array $catalog, array $state, string $key): bool
    {
        foreach ($catalog[$key]['requires'] ?? [] as $required) {
            if (($state[(string) $required] ?? false) !== true) {
                return false;
            }
        }

        $any = (array) ($catalog[$key]['requires_any'] ?? []);

        if ($any === []) {
            return true;
        }

        foreach ($any as $required) {
            if (($state[(string) $required] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dipendenze obbligatorie, dirette e indirette, di una funzionalità. Le
     * `requires_any` restano fuori: nessuna delle alternative è obbligatoria.
     *
     * @return list<string>
     */
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

    /**
     * Funzionalità che dipendono per forza da questa, anche a catena. Chi la
     * ha fra le `requires_any` si spegne solo se non resta accesa un'altra
     * alternativa: lo decide la chiusura finale di `apply()`.
     *
     * @return list<string>
     */
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
