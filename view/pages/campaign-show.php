<?php

/**
 * La scheda della campagna, in sola lettura.
 *
 * Il disegno sta nella Resource: la pagina apre il layout del backend, stampa
 * i riquadri e chiude. Il titolo è il nome, non «Campagna».
 *
 * @var array $ITEM
 */

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Plugin\Gestionale\Resources\Promotions\DiscountCampaignResource;
use Wonder\View\View;

$campagna = (array) ($ITEM ?? []);
$nome = trim((string) ($campagna['name'] ?? ''));

View::layout('backend.show', $nome !== '' ? ['TITLE' => $nome] : []);

echo ResourceFormLayoutRenderer::renderLayout(DiscountCampaignResource::showLayoutSchema($campagna));

View::end();
