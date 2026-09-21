<?php

namespace Wonder\Plugin\Gestionale\Extensions;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;

/**
 * Esegue gli hook dichiarati dal sito.
 *
 * Due modi di chiamarli: `run()` per avvisare (nessuno risponde) e `filter()`
 * per far passare un valore da un'estensione all'altra, dove la prima prepara
 * e la seconda ritocca.
 *
 * Una classe che non esiste o non estende la base viene saltata senza far
 * rumore: la configurazione del sito non deve poter rompere il gestionale.
 */
final class Extensions
{
    /** @var list<string>|null estensioni forzate (test) */
    private static ?array $classes = null;

    /** Avvisa tutte le estensioni. Nessun valore di ritorno. */
    public static function run(string $hook, mixed ...$arguments): mixed
    {
        foreach (self::instances() as $extension) {
            if (!method_exists($extension, $hook)) {
                continue;
            }

            try {
                $extension->{$hook}(...$arguments);
            } catch (Throwable $throwable) {
                // Un'estensione del sito non deve fermare il gestionale.
                Errors::internal($throwable, 'extension.'.$hook, ['class' => $extension::class]);
            }
        }

        return null;
    }

    /**
     * Fa passare un valore da un'estensione all'altra: ognuna riceve quello
     * che ha lasciato la precedente.
     */
    public static function filter(string $hook, mixed $value, mixed ...$arguments): mixed
    {
        foreach (self::instances() as $extension) {
            if (!method_exists($extension, $hook)) {
                continue;
            }

            try {
                $value = $extension->{$hook}(...[...$arguments, $value]);
            } catch (Throwable $throwable) {
                Errors::internal($throwable, 'extension.'.$hook, ['class' => $extension::class]);
            }
        }

        return $value;
    }

    /** Forza l'elenco delle estensioni; `null` torna a leggerlo dalla configurazione. */
    public static function use(?array $classes): void
    {
        self::$classes = $classes === null
            ? null
            : array_values(array_map('strval', $classes));
    }

    /** @return list<GestionaleExtension> */
    private static function instances(): array
    {
        $classes = self::$classes ?? array_map('strval', (array) Gestionale::config('extensions', []));
        $instances = [];

        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, GestionaleExtension::class)) {
                continue;
            }

            $instances[] = new $class();
        }

        return $instances;
    }
}
