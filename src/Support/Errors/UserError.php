<?php

namespace Wonder\Plugin\Gestionale\Support\Errors;

use RuntimeException;
use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * Errore da far leggere a chi sta usando il gestionale: "non c'è abbastanza
 * merce", "questo ordine è già chiuso".
 *
 * Non finisce in nessun log: non è un guasto, è una risposta. Il testo sta nei
 * file di lingua del modulo sotto `errors`, così si traduce e si riscrive
 * senza toccare il codice.
 */
final class UserError extends RuntimeException
{
    private string $key = '';

    /** @param array<string, string|int|float> $replacements */
    public static function make(string $key, array $replacements = [], ?Throwable $previous = null): self
    {
        $error = new self(self::translate($key, $replacements), 0, $previous);
        $error->key = $key;

        return $error;
    }

    /** La chiave di lingua, utile a chi deve reagire a un errore preciso. */
    public function key(): string
    {
        return $this->key;
    }

    /** @param array<string, string|int|float> $replacements */
    private static function translate(string $key, array $replacements): string
    {
        $text = self::fromFramework($key) ?? self::fromModuleFile($key) ?? $key;

        foreach ($replacements as $name => $value) {
            $text = str_replace('{{'.$name.'}}', (string) $value, $text);
        }

        return $text;
    }

    private static function fromFramework(string $key): ?string
    {
        if (!function_exists('__t')) {
            return null;
        }

        try {
            $text = __t('gestionale.errors.'.$key);
        } catch (Throwable) {
            return null;
        }

        return is_string($text) && trim($text) !== '' ? $text : null;
    }

    /** Ripiego quando il framework non è avviato (test, comandi). */
    private static function fromModuleFile(string $key): ?string
    {
        $file = Gestionale::root().'/lang/it/gestionale.json';

        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);
        $node = is_array($data) ? ($data['gestionale']['errors'] ?? null) : null;

        foreach (explode('.', $key) as $step) {
            if (!is_array($node) || !isset($node[$step])) {
                return null;
            }

            $node = $node[$step];
        }

        return is_string($node) ? $node : null;
    }
}
