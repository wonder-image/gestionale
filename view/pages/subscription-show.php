<?php

/**
 * La scheda dell'abbonamento, in sola lettura.
 *
 * Il disegno sta nella Resource: la pagina apre il layout del backend, stampa
 * i riquadri e chiude. Il titolo è il codice, non «Abbonamento».
 *
 * @var array $ITEM
 */

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Plugin\Gestionale\Resources\Subscriptions\SubscriptionResource;
use Wonder\View\View;

$abbonamento = (array) ($ITEM ?? []);
$codice = trim((string) ($abbonamento['code'] ?? ''));

View::layout('backend.show', $codice !== '' ? ['TITLE' => $codice] : []);

echo ResourceFormLayoutRenderer::renderLayout(SubscriptionResource::showLayoutSchema($abbonamento));
// Le finestre di conferma delle azioni della testata (Cambia piano, Disdici, Annulla, proforma).
echo SubscriptionResource::modals($abbonamento);

View::end();
