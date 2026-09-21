<?php

namespace Wonder\Plugin\Gestionale\Seeding;

/**
 * Registro dei dati di prova del modulo.
 *
 * Ogni sotto-progetto aggiunge qui la propria classe: è l'unico posto da
 * guardare per sapere cosa crea `php forge gestionale:demo`.
 */
final class Demo
{
    /** @var list<class-string> */
    private const PROVIDERS = [
        CatalogDemo::class,
    ];

    public static function registerAll(): void
    {
        foreach (self::PROVIDERS as $provider) {
            $provider::register();
        }
    }
}
