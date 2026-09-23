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
        $base = str_replace('_', '-', TextSlug::make($name));
        $base = trim((string) preg_replace('/-+/', '-', $base), '-');

        if ($base === '') {
            $base = self::make($name);
        }

        if ($base === '') {
            return '';
        }

        $slug = $base;

        for ($n = 2; $n < 1000 && self::taken($modelClass, $column, $slug); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    private static function taken(string $modelClass, string $column, string $slug): bool
    {
        foreach (['false', 'true'] as $deleted) {
            try {
                $row = $modelClass::find([$column => $slug, 'deleted' => $deleted], 1);
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
