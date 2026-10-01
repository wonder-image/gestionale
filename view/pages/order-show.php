<?php

/**
 * La scheda dell'ordine, in sola lettura.
 *
 * Il disegno sta nella Resource. La guardia è qui, in un punto solo: un `id`
 * che non è un ordine vero — un carrello, un preventivo — riporta all'elenco.
 *
 * @var array $ITEM
 */

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\View\View;

$ordine = (array) ($ITEM ?? []);

if ((string) ($ordine['stage'] ?? '') !== 'order') {
    header('Location: '.OrderResource::listUrl());
    exit;
}

View::layout('backend.show');

echo ResourceFormLayoutRenderer::renderLayout(OrderResource::showLayoutSchema($ordine));

View::end();
