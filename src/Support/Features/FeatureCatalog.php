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
     * @return array<string, array{key: string, name: string, description: string, area: string, requires: list<string>, module: string, release: string}>
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
                    'module' => trim((string) ($feature['module'] ?? '')),
                    'release' => trim((string) ($feature['release'] ?? '')),
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

    /** Dipendenze esistenti e senza cicli. */
    public static function assertValid(array $catalog): void
    {
        foreach ($catalog as $key => $feature) {
            foreach ($feature['requires'] as $required) {
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

        foreach ($catalog[$key]['requires'] ?? [] as $required) {
            self::assertNoCycle($catalog, $required, $path);
        }
    }
}
