<?php

use Wonder\App\Theme;
use Wonder\Backend\Support\ResourceFormLayoutRenderer;

/**
 * Il layout di una scheda come lo vedrebbe il browser: tutto l'HTML, titoli,
 * `DataItem` e tabelle comprese, per cercarci dentro.
 */
function layoutHtml(object $layout): string
{
    Theme::set('bootstrap');

    return ResourceFormLayoutRenderer::renderLayout($layout);
}
