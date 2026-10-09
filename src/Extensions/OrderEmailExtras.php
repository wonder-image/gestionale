<?php

namespace Wonder\Plugin\Gestionale\Extensions;

use Throwable;
use Wonder\App\Module\Registry;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;

/**
 * I dati che i moduli accesi aggiungono alle email dell'ordine, letti una
 * volta per richiesta.
 *
 * A differenza dei riquadri delle impostazioni, un modulo che si rompe qui si
 * salta: l'email al cliente conta più del dato in più.
 */
final class OrderEmailExtras
{
    /** @var list<class-string<ProvidesOrderEmailExtras>>|null */
    private static ?array $classes = null;

    /**
     * @param array<string, mixed> $order
     * @return array<string, string>
     */
    public static function for(string $key, array $order): array
    {
        $extras = [];

        foreach (self::classes() as $class) {
            try {
                $extras += array_filter($class::orderEmailExtras($key, $order), 'is_string');
            } catch (Throwable $error) {
                Errors::internal($error, 'orders.email_extras', ['module' => $class, 'key' => $key]);
            }
        }

        return $extras;
    }

    /**
     * @param iterable<string> $entrypoints
     * @return list<class-string<ProvidesOrderEmailExtras>>
     */
    public static function fromEntrypoints(iterable $entrypoints): array
    {
        $classes = [];

        foreach ($entrypoints as $entrypoint) {
            if (is_string($entrypoint) && is_subclass_of($entrypoint, ProvidesOrderEmailExtras::class)) {
                $classes[] = $entrypoint;
            }
        }

        return $classes;
    }

    /** Forza le classi; `null` torna a leggerle dai moduli. */
    public static function use(?array $classes): void
    {
        self::$classes = $classes === null ? null : array_values($classes);
    }

    /** @return list<class-string<ProvidesOrderEmailExtras>> */
    private static function classes(): array
    {
        if (self::$classes !== null) {
            return self::$classes;
        }

        try {
            $entrypoints = array_map(static fn ($manifest): string => $manifest->entrypoint(), Registry::enabled());
        } catch (Throwable) {
            $entrypoints = [];
        }

        return self::$classes = self::fromEntrypoints($entrypoints);
    }
}
