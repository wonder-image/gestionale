<?php

/**
 * La scheda dell'articolo, in sola lettura (P123).
 *
 * Il disegno sta nella Resource, non qui: la pagina apre il layout del
 * backend, stampa i riquadri e chiude. Così la scheda in lettura e la
 * modifica restano la stessa cosa vista da due parti.
 *
 * @var array $ITEM
 */

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\View\View;

View::layout('backend.show');

echo ResourceFormLayoutRenderer::renderLayout(
    ProductModelResource::showLayoutSchema((array) ($ITEM ?? []))
);

View::end();
