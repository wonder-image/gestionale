<?php

namespace Wonder\Plugin\Gestionale\Support\Errors;

use InvalidArgumentException;
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
 *
 * Estende `InvalidArgumentException` apposta: è il tipo che il controller del
 * backend intercetta quando una Resource rifiuta un salvataggio in
 * `mutateRequestValues()`. Lì diventa `$ALERT`, il salvataggio non parte e il
 * messaggio torna sul form. Con un `RuntimeException` qualunque, invece,
 * l'utente vedrebbe una pagina di errore 500.
 */
final class UserError extends InvalidArgumentException
{
    private string $key = '';

    /** @param array<string, string|int|float> $replacements */
    public static function make(string $key, array $replacements = [], ?Throwable $previous = null): self
    {
        $error = new self(self::translate($key, $replacements), 0, $previous);
        $error->key = $key;

        return $error;
    }

    /**
     * Lo stesso rifiuto, ma per l'endpoint che cancella una riga.
     *
     * Il core ha **due porte** con due gusti diversi: il controller del form
     * intercetta `InvalidArgumentException` e la trasforma in avviso rosso;
     * `api/backend/delete` intercetta invece `RuntimeException` e risponde
     * 422 con il messaggio. Un `UserError` lì dentro non verrebbe visto e
     * diventerebbe una pagina 500.
     *
     * Il testo resta uno solo, nei file di lingua.
     *
     * @param array<string, string|int|float> $replacements
     */
    public static function refusal(string $key, array $replacements = []): RuntimeException
    {
        return new RuntimeException(self::translate($key, $replacements));
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
