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

// Il titolo della pagina è il nome dell'articolo, non «Scheda prodotto»: chi
// ha aperto la scheda sa già cos'è, vuole sapere di chi.
$nome = trim((string) (($ITEM ?? [])['name'] ?? ''));

View::layout('backend.show', $nome !== '' ? ['TITLE' => $nome] : []);

echo ResourceFormLayoutRenderer::renderLayout(
    ProductModelResource::showLayoutSchema((array) ($ITEM ?? []))
);

View::end();
