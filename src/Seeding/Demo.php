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
     * fornitori di prova, e al primo giro devono già esserci. La vetrina
     * (l'articolo completo, quello in offerta e il multiprodotto) subito dopo
     * il catalogo, di cui usa marchio, tassonomie e attributi. Le spedizioni
     * e le campagne dopo il catalogo: le prime danno il peso agli articoli di
     * prova, le seconde usano le tassonomie. I piani di abbonamento non
     * dipendono da niente e stanno con le promozioni; l'abbonamento da ufficio
     * li segue, con i contatti. Gli ordini poi: vendono
     * gli articoli del catalogo. Le spedizioni degli ordini per ultime.
     *
     * @var list<class-string>
     */
    private const PROVIDERS = [
        ContactsDemo::class,
        CatalogDemo::class,
        ShowcaseDemo::class,
        ShippingDemo::class,
        PromotionsDemo::class,
        PlansDemo::class,
        OfficeSubscriptionsDemo::class,
        OrdersDemo::class,
        ShipmentsDemo::class,
        StockDocumentsDemo::class,
    ];

    public static function registerAll(): void
    {
        foreach (self::PROVIDERS as $provider) {
            $provider::register();
        }
    }
}
