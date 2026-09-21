<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

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
}
