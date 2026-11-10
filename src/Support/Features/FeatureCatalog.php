<?php

namespace Wonder\Plugin\Gestionale\Support\Features;

use RuntimeException;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * Catalogo delle funzionalità: sta nel codice (`config/features.php` del
 * pacchetto più `features.extra` del sito), il database conserva solo lo stato.
 */
final class FeatureCatalog
{
    /**
     * @param array<string, array<string, mixed>> $package
     * @param array<string, array<string, mixed>> $extra voci aggiunte dal sito
     * `requires` vuole tutte le funzionalità della lista, `requires_any`
     * almeno una (la Personalizzazione va con Ordini o con Abbonamenti).
     * `replaces` elenca le chiavi tolte di cui la voce prende il posto.
     * `default => true` fa nascere la voce sbloccata quando non ha ancora la riga;
     * `default_if` (un callable senza argomenti) solo se risponde sì, ad esempio
     * perché i dati che governa ci sono già.
     *
     * @return array<string, array{key: string, name: string, description: string, area: string, requires: list<string>, requires_any: list<string>, replaces: list<string>, module: string, release: string, created: bool, default: bool, default_if: callable|null}>
     */
    public static function fromArray(array $package, array $extra = []): array
    {
        $catalog = [];

        foreach ([$package, $extra] as $index => $source) {
            foreach ($source as $key => $feature) {
                $key = is_string($key) ? trim($key) : '';

                if ($key === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                    throw new RuntimeException('Funzionalità con chiave non valida: '.var_export($key, true));
                }

                if ($index === 1 && isset($catalog[$key])) {
                    throw new RuntimeException('Il sito non può sovrascrivere la funzionalità '.$key);
                }

                $catalog[$key] = [
                    'key' => $key,
                    'name' => trim((string) ($feature['name'] ?? $key)),
                    'description' => trim((string) ($feature['description'] ?? '')),
                    'area' => trim((string) ($feature['area'] ?? 'Altro')),
                    'requires' => array_values(array_filter(
                        array_map('strval', (array) ($feature['requires'] ?? [])),
                        static fn (string $value): bool => trim($value) !== ''
                    )),
                    'requires_any' => array_values(array_filter(
                        array_map(static fn ($value): string => trim((string) $value), (array) ($feature['requires_any'] ?? [])),
                        static fn (string $value): bool => $value !== ''
                    )),
                    'replaces' => array_values(array_filter(
                        array_map(static fn ($value): string => trim((string) $value), (array) ($feature['replaces'] ?? [])),
                        static fn (string $value): bool => $value !== ''
                    )),
                    'module' => trim((string) ($feature['module'] ?? '')),
                    'release' => trim((string) ($feature['release'] ?? '')),
                    // Costruita, salvo `created => false`.
                    'created' => ($feature['created'] ?? true) !== false,
                    'default' => ($feature['default'] ?? false) === true,
                    'default_if' => is_callable($feature['default_if'] ?? null) ? $feature['default_if'] : null,
                ];
            }
        }

        self::assertValid($catalog);

        return $catalog;
    }

    /** Catalogo del pacchetto più le voci del sito. */
    public static function all(): array
    {
        return self::fromArray(
            (array) require Gestionale::root().'/config/features.php',
            (array) Gestionale::config('features.extra', [])
        );
    }

    /** Dipendenze esistenti e senza cicli; una chiave sostituita non è più nel catalogo e ha un solo erede. */
    public static function assertValid(array $catalog): void
    {
        $heirs = [];

        foreach ($catalog as $key => $feature) {
            foreach ((array) ($feature['replaces'] ?? []) as $old) {
                if (isset($catalog[$old])) {
                    throw new RuntimeException("La funzionalità {$key} sostituisce {$old}, che è ancora nel catalogo");
                }

                if (isset($heirs[$old])) {
                    throw new RuntimeException("La funzionalità {$old} è sostituita sia da {$heirs[$old]} sia da {$key}");
                }

                $heirs[$old] = $key;
            }
        }

        foreach ($catalog as $key => $feature) {
            foreach (self::edges($feature) as $required) {
                if (!isset($catalog[$required])) {
                    throw new RuntimeException("La funzionalità {$key} dipende da {$required}, che non esiste");
                }
            }
        }

        foreach (array_keys($catalog) as $key) {
            self::assertNoCycle($catalog, $key, []);
        }
    }

    /** @param list<string> $path */
    private static function assertNoCycle(array $catalog, string $key, array $path): void
    {
        if (in_array($key, $path, true)) {
            throw new RuntimeException('Dipendenza circolare tra le funzionalità: '.implode(' → ', [...$path, $key]));
        }

        $path[] = $key;

        foreach (self::edges($catalog[$key] ?? []) as $required) {
            self::assertNoCycle($catalog, $required, $path);
        }
    }

    /**
     * I nomi di ciò che serve prima, per il pannello e la documentazione: le
     * obbligatorie una per una, le alternative unite («Ordini o Abbonamenti»).
     *
     * @return list<string>
     */
    public static function requiredNames(array $catalog, array $feature): array
    {
        $name = static fn (string $key): string => (string) ($catalog[$key]['name'] ?? $key);
        $names = array_map($name, array_map('strval', (array) ($feature['requires'] ?? [])));
        $any = array_map('strval', (array) ($feature['requires_any'] ?? []));

        if ($any !== []) {
            $names[] = implode(' o ', array_map($name, $any));
        }

        return array_values($names);
    }

    /** Tutte le funzionalità da cui dipende, obbligatorie o alternative. @return list<string> */
    private static function edges(array $feature): array
    {
        return array_values(array_unique([...($feature['requires'] ?? []), ...($feature['requires_any'] ?? [])]));
    }
}
