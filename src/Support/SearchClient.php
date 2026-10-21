<?php

namespace Wonder\Plugin\Gestionale\Support;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Themes\Support\PageAssets;

/**
 * Il JS di ricerca condiviso dagli editor (documenti di magazzino, ordini
 * d'ufficio): `resources/assets/js/search-client.js`, stampato una volta per
 * pagina.
 *
 * In linea e non con `module_asset()`: se l'URL non si risolve, gli editor
 * resterebbero senza ricerca senza che nessuno se ne accorga.
 */
final class SearchClient
{
    public const FILE = 'js/search-client.js';

    public static function source(): string
    {
        return (string) file_get_contents(Gestionale::assetPath(self::FILE));
    }

    public static function script(): string
    {
        return PageAssets::once('gestionale.search-client', static fn (): string => '<script>'.self::source().'</script>');
    }
}
