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

$number = html_entity_decode(trim((string) ($values['number'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');

// L'emesso ha il numero nel titolo; la bozza non ce l'ha ancora.
View::layout('backend.form', $number !== '' ? ['TITLE' => 'DDT '.$number] : []);

echo ResourceFormLayoutRenderer::render(DeliveryNoteResource::formLayoutFor($values), [
    'id' => 'wi-dn-form',
    'action' => (string) ($FORM_ACTION ?? ''),
    'attributes' => ['autocomplete' => 'off', 'data-wi-save-bar' => $draft],
    'footer' => Csrf::fieldFor('post'),
]);

View::end();
