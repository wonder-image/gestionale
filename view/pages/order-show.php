<?php

/**
 * La scheda dell'ordine.
 *
 * Si consulta, e si modificano solo le note: le altre mani passano dalle azioni.
 * Il disegno sta nella Resource. La guardia è qui, in un punto solo: un `id`
 * che non è un ordine vero — un carrello, un preventivo — riporta all'elenco.
 *
 * @var array $ITEM
 */

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderNoteResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\View\View;

$ordine = (array) ($ITEM ?? []);

if ((string) ($ordine['stage'] ?? '') !== 'order') {
    header('Location: '.OrderResource::listUrl());
    exit;
}

View::layout('backend.show', ['TITLE' => OrderResource::pageTitle($ordine)]);

echo ResourceFormLayoutRenderer::renderLayout(OrderResource::showLayoutSchema($ordine));

// Le finestre (azioni e note) stanno fuori dal disegno: il layout non contiene campi.
echo OrderResource::actionModalsFor($ordine);
echo OrderNoteResource::modal($ordine, StockAdjustmentResource::backUrlFrom($_GET['torna'] ?? ''));

View::end();
