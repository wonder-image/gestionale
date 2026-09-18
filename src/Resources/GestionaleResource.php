<?php

namespace Wonder\Plugin\Gestionale\Resources;

use Wonder\App\Resource;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * Resource base del gestionale. Ogni pagina dichiara la funzionalità che la
 * governa (`$feature`, vuoto = sempre attiva) e la pagina della guida
 * commercianti (`$docsPage`). Il controllo della funzionalità su menu, pagine e
 * API arriva con il piano 2, quando esiste lo stato delle funzionalità.
 */
abstract class GestionaleResource extends Resource
{
    public static string $feature = '';
    public static string $docsPage = '';

    public static function pageSchema(): PageSchema
    {
        return static::withDocs(PageSchema::for(static::class));
    }

    /** Aggiunge il pulsante "Guida" se la Resource dichiara una pagina. */
    protected static function withDocs(PageSchema $schema): PageSchema
    {
        $url = Gestionale::docsUrl(static::$docsPage);

        return $url === '' ? $schema : $schema->docs($url);
    }
}
