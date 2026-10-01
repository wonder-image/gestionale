<?php

/**
 * La scheda del cliente, in sola lettura.
 *
 * Il disegno sta nella Resource: la pagina apre il layout del backend, stampa
 * i riquadri e chiude. Un `id` che non è un cliente — un fornitore, una scheda
 * cancellata — riporta all'elenco.
 *
 * @var array $ITEM
 */

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Plugin\Gestionale\Resources\Contacts\ContactAddressResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\View\View;

$cliente = (array) ($ITEM ?? []);

if (($cliente['is_customer'] ?? 'false') !== 'true') {
    header('Location: /backend/'.CustomerResource::path().'/');
    exit;
}

View::layout('backend.show', ['TITLE' => CustomerResource::pageTitle($cliente)]);

echo ResourceFormLayoutRenderer::renderLayout(CustomerResource::showLayoutSchema($cliente));

// Le finestre degli indirizzi stanno fuori dal disegno: hanno ciascuna la sua form.
echo ContactAddressResource::modals((int) $cliente['id']);

View::end();
