<?php

/**
 * La scheda dell'azienda, in sola lettura: il disegno sta nella Resource.
 * Gli indirizzi si cambiano dalla modifica, quindi niente finestre qui.
 *
 * @var array $ITEM
 */

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyResource;
use Wonder\View\View;

$azienda = (array) ($ITEM ?? []);

if (($azienda['id'] ?? 0) <= 0) {
    header('Location: /backend/'.CompanyResource::path().'/');
    exit;
}

View::layout('backend.show', ['TITLE' => CompanyResource::pageTitle($azienda)]);

echo ResourceFormLayoutRenderer::renderLayout(CompanyResource::showLayoutSchema($azienda));

View::end();
