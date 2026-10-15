<?php

/*
 * Un'azione del menu ⋯ nell'elenco delle bozze d'ordine (Conferma ordine,
 * Elimina): la rotta POST è della OfficeOrderResource.
 */

use Wonder\Plugin\Gestionale\Resources\Sales\OfficeOrderResource;

$routeParameters = is_array($ROUTE_PARAMETERS ?? null) ? $ROUTE_PARAMETERS : [];

OfficeOrderResource::runAction((int) ($routeParameters['id'] ?? 0), (string) ($routeParameters['intent'] ?? ''));
