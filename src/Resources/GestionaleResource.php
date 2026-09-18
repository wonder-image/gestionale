<?php

namespace Wonder\Plugin\Gestionale\Resources;

use Wonder\App\Resource;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
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

    /** Vero se la pagina non dipende da nessuna funzionalità o se quella dichiarata è attiva. */
    public static function featureActive(): bool
    {
        return static::$feature === '' || Gestionale::feature(static::$feature);
    }

    public static function pageSchema(): PageSchema
    {
        return static::withFeature(static::withDocs(PageSchema::for(static::class)));
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()->enabled(static::featureActive());
    }

    public static function apiSchema(): ApiSchema
    {
        $schema = parent::apiSchema();

        return static::featureActive() ? $schema : $schema->enabled(false);
    }

    /** Con la funzionalità bloccata la Resource non ha nessuna pagina. */
    protected static function withFeature(PageSchema $schema): PageSchema
    {
        return static::featureActive() ? $schema : $schema->only([]);
    }

    /** Aggiunge il pulsante "Guida" se la Resource dichiara una pagina. */
    protected static function withDocs(PageSchema $schema): PageSchema
    {
        $url = Gestionale::docsUrl(static::$docsPage);

        return $url === '' ? $schema : $schema->docs($url);
    }
}
