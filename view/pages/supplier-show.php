<?php

/**
 * La scheda del fornitore, in sola lettura.
 *
 * Il disegno sta nella Resource: la pagina apre il layout del backend, stampa
 * i riquadri e chiude. Un `id` che non è un fornitore riporta all'elenco.
 *
 * @var array $ITEM
 */

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Plugin\Gestionale\Resources\Contacts\SupplierResource;
use Wonder\View\View;

$fornitore = (array) ($ITEM ?? []);

if (($fornitore['is_supplier'] ?? 'false') !== 'true') {
    header('Location: /backend/'.SupplierResource::path().'/');
    exit;
}

View::layout('backend.show', ['TITLE' => SupplierResource::pageTitle($fornitore)]);

echo ResourceFormLayoutRenderer::renderLayout(SupplierResource::showLayoutSchema($fornitore));

View::end();
