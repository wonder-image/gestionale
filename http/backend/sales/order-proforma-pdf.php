<?php

/*
 * Il proforma in PDF di un ordine, online o da ufficio, anche in bozza, non
 * annullato. Non si archivia: si fa adesso, con l'ordine com'è. La rotta è
 * della OrderResource; l'area cliente del sito lo dà solo a ordine confermato.
 */

use Wonder\Plugin\Gestionale\Support\Documents\OrderProforma;

$routeParameters = is_array($ROUTE_PARAMETERS ?? null) ? $ROUTE_PARAMETERS : [];
$order = OrderProforma::order((int) ($routeParameters['id'] ?? 0), true);

if ($order === null) {
    http_response_code(404);
    exit('Ordine non trovato');
}

OrderProforma::send($order);
