<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Throwable;
use Wonder\Support\Text\Slug as TextSlug;

/**
 * Slug di una riga del catalogo.
 *
 * Quando il framework è avviato usa `create_link()`, che sa anche rendere unico
 * lo slug guardando la tabella. Fuori (test, comandi) ripiega su una versione
 * semplice: serve a non dover accendere mezzo sito per provare una regola.
 */
final class Slug
{
    public static function make(string $name, ?string $table = null, string $column = 'slug'): string
    {
        $name = trim($name);

        if ($name === '') {
            return '';
        }

        if (function_exists('create_link')) {
            return create_link($name, $table, $table === null ? null : $column);
        }

        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));

        return $slug;
    }

    /**
     * Uno slug libero nella tabella di quel Model, anche fuori dal sito
     * avviato: `accessori`, e se è già preso `accessori-2`, `accessori-3`.
     *
     * Parte dallo slug che il Model stesso scriverebbe, così quello salvato è
     * quello controllato. Conta anche le righe cancellate: l'indice unico
     * della colonna le vede ancora.
     *
     * @param class-string<\Wonder\App\Model> $modelClass
     */
    public static function unique(string $name, string $modelClass, string $column = 'slug'): string
    {
        $base = self::base($name);

        if ($base === '') {
            return '';
        }

        return self::firstFree($base, static fn (string $slug): bool => self::taken($modelClass, [$column => $slug]));
    }

    /**
     * Uno slug libero solo tra le righe di `$scope`: lo slug della variante
     * è unico nel suo modello, non nella tabella (`blu`, poi `blu-2`). Se il
     * nome è fatto solo di simboli parte da `variante`, così la riga resta
     * raggiungibile. Conta anche le righe cancellate, come `unique()`.
     *
     * @param class-string<\Wonder\App\Model> $modelClass
     * @param array<string, mixed> $scope
     */
    public static function uniqueWithin(string $name, string $modelClass, array $scope, string $column = 'slug'): string
    {
        if (trim($name) === '') {
            return '';
        }

        $base = self::base($name);

        if ($base === '') {
            $base = 'variante';
        }

        return self::firstFree($base, static fn (string $slug): bool => self::taken($modelClass, [$column => $slug] + $scope));
    }

    /** Lo slug che scriverebbe il Model: `Blu Notte` → `blu-notte`. */
    public static function base(string $name): string
    {
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $base = str_replace('_', '-', TextSlug::make($name));
        $base = trim((string) preg_replace('/-+/', '-', $base), '-');

        return $base !== '' ? $base : self::make($name);
    }

    /**
     * `base`, e se è preso `base-2`, `base-3`…
     *
     * @param callable(string): bool $taken
     */
    public static function firstFree(string $base, callable $taken): string
    {
        $slug = $base;

        for ($n = 2; $n < 1000 && $taken($slug); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    /** @param array<string, mixed> $where */
    private static function taken(string $modelClass, array $where): bool
    {
        foreach (['false', 'true'] as $deleted) {
            try {
                $row = $modelClass::find($where + ['deleted' => $deleted], 1);
            } catch (Throwable) {
                // Senza database non c'è niente con cui scontrarsi.
                return false;
            }

            if (is_array($row) && $row !== []) {
                return true;
            }
        }

        return false;
    }
}
