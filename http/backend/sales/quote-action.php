<?php

/*
 * Un'azione del menu ⋯ nell'elenco dei preventivi (Trasforma in ordine,
 * Invia al cliente, Elimina): la rotta POST è della QuoteResource.
 */

use Wonder\Plugin\Gestionale\Resources\Sales\QuoteResource;

$routeParameters = is_array($ROUTE_PARAMETERS ?? null) ? $ROUTE_PARAMETERS : [];

QuoteResource::runAction((int) ($routeParameters['id'] ?? 0), (string) ($routeParameters['intent'] ?? ''));
