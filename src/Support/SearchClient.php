<?php

namespace Wonder\Plugin\Gestionale\Support;

use Wonder\Plugin\Gestionale\Gestionale;

/**
 * Il JS di ricerca condiviso dagli editor (documenti di magazzino, ordini
 * d'ufficio): `resources/js/search-client.js`, fuori da `paths.assets` perché
 * `publish:module --assets` non lo copi nel sito.
 *
 * In linea e non con `module_asset()`: non dipende da `vendor/` raggiungibile
 * dal web né da un publish. Ogni editor lo stampa: il guard
 * `window.wiSearchClient || …` del file lo rende innocuo se ripetuto.
 */
final class SearchClient
{
    public const FILE = 'resources/js/search-client.js';

    public static function source(): string
    {
        return (string) file_get_contents(Gestionale::root().'/'.self::FILE);
    }

    public static function script(): string
    {
        return '<script>'.self::source().'</script>';
    }
}
