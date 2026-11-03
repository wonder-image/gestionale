<?php

/*
 * Il PDF delle etichette di un modello, di un prodotto o di un carico (#116).
 * La sorgente la mette la rotta (`labels_source`), i permessi li guarda la
 * rotta, la funzionalità `Labels::respond()`.
 */

use Wonder\Plugin\Gestionale\Support\Labels\Labels;

$routeParameters = is_array($ROUTE_PARAMETERS ?? null) ? $ROUTE_PARAMETERS : [];
$routeMeta = is_array($ROUTE_META ?? null) ? $ROUTE_META : [];

$response = Labels::respond(
    (string) ($routeMeta['labels_source'] ?? ''),
    (int) ($routeParameters['id'] ?? 0),
    $_GET
);

http_response_code($response['status']);

if ($response['status'] !== 200) {
    exit($response['body']);
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="'.$response['filename'].'"');
header('Content-Length: '.strlen($response['body']));
header('Cache-Control: no-store');

echo $response['body'];
exit;
