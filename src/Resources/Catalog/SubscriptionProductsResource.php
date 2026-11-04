<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionModels;

/**
 * Voce di menu «Abbonamenti» in Catalogo, accesa con la funzionalità.
 * Gli abbonamenti in vendita: l'elenco dei prodotti filtrato sul tipo
 * «Abbonamento». Gli abbonamenti dei clienti stanno in Vendite.
 *
 * Una Resource a parte perché il menu fonde due voci della stessa cartella:
 * la voce porta all'elenco di ProductModelResource, che non cambia.
 */
final class SubscriptionProductsResource extends NavigationOnlyResource
{
    public static function path(): string
    {
        return 'app/gestionale/prodotti-abbonamento';
    }

    public static function icon(): string
    {
        return 'bi-arrow-repeat';
    }

    public static function titleLabel(): string
    {
        return 'Abbonamenti';
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('catalogo')
            ->title('Abbonamenti')
            ->order(39)
            ->file(ProductModelResource::typeListUrl(SubscriptionModels::TYPE))
            ->access(Gestionale::area('catalogo'))
            ->enabled(Gestionale::feature('subscriptions'));
    }
}
