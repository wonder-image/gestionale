<?php

/**
 * La scheda di una persona, in sola lettura: cliente o referente.
 *
 * Il disegno sta nella Resource, che per un referente toglie le parti del
 * cliente (ordini, carrello, statistiche, listino…): la pagina apre il layout
 * del backend, stampa i riquadri e chiude. Una scheda che non c'è o è
 * cancellata riporta all'elenco.
 *
 * @var array $ITEM
 */

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Plugin\Gestionale\Resources\Contacts\ContactAddressResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\View\View;

$cliente = (array) ($ITEM ?? []);

if ((int) ($cliente['id'] ?? 0) <= 0 || ($cliente['deleted'] ?? 'false') === 'true') {
    header('Location: /backend/'.CustomerResource::path().'/');
    exit;
}

View::layout('backend.show', ['TITLE' => CustomerResource::pageTitle($cliente)]);

echo ResourceFormLayoutRenderer::renderLayout(CustomerResource::showLayoutSchema($cliente));

// Le finestre degli indirizzi stanno fuori dal disegno: hanno ciascuna la sua form.
echo ContactAddressResource::modals((int) $cliente['id']);

View::end();
