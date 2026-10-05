<?php

/**
 * La scheda di una spedizione.
 *
 * Si consulta; i cambi passano dai pulsanti, che postano alle azioni
 * dell'ordine. Il disegno sta nella Resource.
 *
 * @var array $ITEM
 */

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Plugin\Gestionale\Resources\Shipping\ShipmentResource;
use Wonder\View\View;

$spedizione = (array) ($ITEM ?? []);

View::layout('backend.show', ['TITLE' => 'Spedizione '.html_entity_decode((string) ($spedizione['code'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')]);

echo ResourceFormLayoutRenderer::renderLayout(ShipmentResource::showLayoutSchema($spedizione));

// Le finestre stanno fuori dal disegno: il layout non contiene campi.
echo ShipmentResource::modalsFor($spedizione);

View::end();
