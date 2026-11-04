<?php

/**
 * La scheda dell'abbonamento, in sola lettura.
 *
 * Il disegno sta nella Resource: la pagina apre il layout del backend, stampa
 * i riquadri e chiude. Titolo e sottotitolo come nella scheda ordine.
 *
 * @var array $ITEM
 */

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Plugin\Gestionale\Resources\Subscriptions\SubscriptionResource;
use Wonder\View\View;

$abbonamento = (array) ($ITEM ?? []);
View::layout('backend.show', ['TITLE' => SubscriptionResource::pageTitle($abbonamento), 'SUBTITLE' => SubscriptionResource::pageSubtitle($abbonamento)]);

echo ResourceFormLayoutRenderer::renderLayout(SubscriptionResource::showLayoutSchema($abbonamento));
// Le finestre delle azioni della testata e dei servizi.
echo SubscriptionResource::modals($abbonamento);

View::end();
