<?php

namespace Wonder\Plugin\Gestionale\Resources\Contacts;

use Wonder\App\ResourceSchema\NavigationSchema;

/**
 * "Fornitori": lo stesso elenco visto dall'altra parte.
 *
 * Cambia il titolo, l'indirizzo, il filtro e la funzionalità che lo governa;
 * la scheda è quella dei clienti, perché la scheda è la stessa persona. Chi è
 * cliente e fornitore compare in tutti e due gli elenchi e si modifica una
 * volta sola.
 */
final class SupplierResource extends CustomerResource
{
    public static string $feature = 'purchasing';

    public static function hasSheet(): bool
    {
        return false;
    }

    public static function roleColumn(): string
    {
        return 'is_supplier';
    }

    public static function path(): string
    {
        return 'app/gestionale/fornitori';
    }

    public static function icon(): string
    {
        return 'bi-truck';
    }

    public static function titleLabel(): string
    {
        return 'Fornitori';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'fornitore',
            'plural_label' => 'fornitori',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()->order(20);
    }
}
