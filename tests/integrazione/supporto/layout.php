<?php

use Wonder\App\Theme;
use Wonder\Backend\Support\ResourceFormLayoutRenderer;

// I filtri delle tabelle del core chiamano l'helper `select()` del backend, che da CLI non
// c'è (e il suo file ridichiara `check()` dell'harness): basta un segnaposto.
if (!function_exists('select')) {
    function select(...$argomenti): string
    {
        return '<select data-test-filtro></select>';
    }
}

/**
 * Il layout di una scheda come lo vedrebbe il browser: tutto l'HTML, titoli,
 * `DataItem` e tabelle comprese, per cercarci dentro.
 */
function layoutHtml(object $layout): string
{
    Theme::set('bootstrap');

    return ResourceFormLayoutRenderer::renderLayout($layout);
}

/**
 * Una cella di una tabella del core come la scrive l'API: il formatter della
 * colonna applicato alla riga.
 */
function cella(string $resource, string $colonna, array $riga): string
{
    foreach ($resource::tableSchema() as $c) {
        if ((string) $c->name === $colonna) {
            $formatter = $c->schema['formatter'] ?? null;

            return is_callable($formatter) ? (string) $formatter($riga) : (string) ($riga[$colonna] ?? '');
        }
    }

    throw new RuntimeException('Colonna '.$colonna.' assente in '.$resource);
}
