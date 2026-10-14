<?php

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Http\Csrf;
use Wonder\Plugin\Gestionale\Resources\Sales\DeliveryNoteResource;
use Wonder\View\View;

/*
 * Il DDT (G9 §4.2): la bozza con destinazione, trasporto e quantità; l'emesso
 * in sola lettura con PDF, invio e annullo. Il disegno è della Resource, con
 * i componenti del core (`DeliveryNoteResource::formLayoutFor()`). Posta alla
 * rotta `update` del core, che la Resource intercetta in
 * `mutateRequestValues()`.
 */

$values = (array) ($VALUES ?? []);
$draft = (string) ($values['status'] ?? 'draft') === 'draft';

View::layout('backend.form');

echo ResourceFormLayoutRenderer::render(DeliveryNoteResource::formLayoutFor($values), [
    'id' => 'wi-dn-form',
    'action' => (string) ($FORM_ACTION ?? ''),
    'attributes' => ['autocomplete' => 'off', 'data-wi-save-bar' => $draft],
    'footer' => Csrf::fieldFor('post'),
]);

View::end();
