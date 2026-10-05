<?php

/**
 * La scheda del coupon, in sola lettura.
 *
 * Il disegno sta nella Resource: la pagina apre il layout del backend, stampa
 * i riquadri e chiude. Il titolo è il codice, non «Coupon».
 *
 * @var array $ITEM
 */

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Plugin\Gestionale\Resources\Promotions\CouponResource;
use Wonder\View\View;

$coupon = (array) ($ITEM ?? []);
$codice = trim((string) ($coupon['code'] ?? ''));

View::layout('backend.show', $codice !== '' ? ['TITLE' => $codice] : []);

echo ResourceFormLayoutRenderer::renderLayout(CouponResource::showLayoutSchema($coupon));

View::end();
