<?php

namespace Wonder\Plugin\Gestionale\Resources\System;

use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * Voce di menu della pagina "Funzionalità". La pagina è una sola, con un
 * interruttore per funzionalità: non è un elenco CRUD, quindi qui ci sono solo
 * la navigazione e le route verso l'handler del modulo.
 */
final class FeatureResource extends NavigationOnlyResource
{
    public static function path(): string
    {
        return 'app/gestionale/funzionalita';
    }

    public static function icon(): string
    {
        return 'bi-toggles';
    }

    public static function titleLabel(): string
    {
        return 'Funzionalità';
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('set-up', 'Set Up', 'bi-gear', 1020, ['admin'])
            ->title('Funzionalità')
            ->order(20)
            ->authority(['admin']);
    }

    /** Nessuna pagina CRUD: l'elenco automatico cercherebbe un model che non c'è. */
    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()->only([]);
    }

    /** La pagina risponde sullo stesso indirizzo in lettura e in salvataggio. */
    public static function registerBackendRoutes(string $rootApp, string $slug): void
    {
        $handler = Gestionale::handlerPath('backend/features.php');

        Route::get('/', $handler)->name('page')->permit(['admin']);
        Route::post('/', $handler)->name('save')->permit(['admin']);
    }
}
