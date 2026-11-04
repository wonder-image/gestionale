<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * Voce di menu «Multiprodotti» in Catalogo, accesa con la funzionalità.
 * I multiprodotti: l'elenco dei prodotti filtrato sul tipo «Multiprodotto».
 *
 * Una Resource a parte perché il menu fonde due voci della stessa cartella:
 * la voce porta all'elenco di ProductModelResource, che non cambia.
 */
final class BundleProductsResource extends NavigationOnlyResource
{
    public static function path(): string
    {
        return 'app/gestionale/multiprodotti';
    }

    public static function icon(): string
    {
        return 'bi-boxes';
    }

    public static function titleLabel(): string
    {
        return 'Multiprodotti';
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('catalogo')
            ->title('Multiprodotti')
            ->order(39)
            ->file(ProductModelResource::typeListUrl('bundle'))
            ->access(Gestionale::area('catalogo'))
            ->enabled(Gestionale::feature('bundles'));
    }
}
