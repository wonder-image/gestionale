<?php

/*
 * «Genera codici» di un coupon (gestionale#113): il lavoro sta nella Resource.
 */

use Wonder\Plugin\Gestionale\Resources\Promotions\CouponResource;

$routeParameters = is_array($ROUTE_PARAMETERS ?? null) ? $ROUTE_PARAMETERS : [];

CouponResource::generateCodes((int) ($routeParameters['id'] ?? 0));
