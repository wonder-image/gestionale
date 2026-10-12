<?php

/**
 * La scheda di un movimento di magazzino.
 *
 * Si legge e basta: un movimento non si modifica. Il disegno sta nella Resource.
 *
 * @var array $ITEM
 */

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;
use Wonder\View\View;

$movimento = (array) ($ITEM ?? []);

View::layout('backend.show', ['TITLE' => 'Movimento '.html_entity_decode((string) ($movimento['code'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')]);

echo ResourceFormLayoutRenderer::renderLayout(StockMovementResource::showLayoutSchema($movimento));

View::end();
