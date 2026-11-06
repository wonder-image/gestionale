<?php

/*
 * «Crea le righe dalla selezione» di un listino: il lavoro sta nella Resource.
 */

use Wonder\Plugin\Gestionale\Resources\Pricing\PriceListResource;

$routeParameters = is_array($ROUTE_PARAMETERS ?? null) ? $ROUTE_PARAMETERS : [];

PriceListResource::seedRows((int) ($routeParameters['id'] ?? 0));
