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
    /**
     * Le anagrafiche prima del catalogo: gli articoli di prova comprano dai
     * fornitori di prova, e al primo giro devono già esserci. Le campagne
     * dopo il catalogo, di cui usano le tassonomie. Gli ordini per ultimi:
     * vendono gli articoli del catalogo.
     *
     * @var list<class-string>
     */
    private const PROVIDERS = [
        ContactsDemo::class,
        CatalogDemo::class,
        PromotionsDemo::class,
        OrdersDemo::class,
    ];

    public static function registerAll(): void
    {
        foreach (self::PROVIDERS as $provider) {
            $provider::register();
        }
    }
}
