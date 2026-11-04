<?php

/*
 * Il PDF delle etichette della pagina Catalogo › Etichette o di un carico
 * (#116). La sorgente la mette la rotta (`labels_source`), i permessi li
 * guarda la rotta, la funzionalità `Labels::respond()`. La pagina arriva in POST.
 */

use Wonder\Http\Csrf;
use Wonder\Plugin\Gestionale\Resources\Catalog\LabelsResource;
use Wonder\Plugin\Gestionale\Support\Labels\Labels;

$routeParameters = is_array($ROUTE_PARAMETERS ?? null) ? $ROUTE_PARAMETERS : [];
$routeMeta = is_array($ROUTE_META ?? null) ? $ROUTE_META : [];

$source = (string) ($routeMeta['labels_source'] ?? '');

if ($source === 'scope' && !Csrf::verify()) {
    http_response_code(419);
    exit(Csrf::failureMessage());
}

$response = Labels::respond(
    $source,
    (int) ($routeParameters['id'] ?? 0),
    $source === 'scope' ? LabelsResource::printQuery($_POST) : $_GET
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
