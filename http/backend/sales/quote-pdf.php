<?php

/*
 * Il PDF di una revisione inviata del preventivo (G9 §3.2), dall'archivio non
 * pubblico. La rotta è della QuoteResource (`registerBackendRoutes()`), che
 * non guarda la funzionalità: la si guarda qui.
 */

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\OrderQuoteRevision;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentFiles;
use Wonder\Http\Exceptions\NotFoundHttpException;

$routeParameters = is_array($ROUTE_PARAMETERS ?? null) ? $ROUTE_PARAMETERS : [];
$id = (int) ($routeParameters['id'] ?? 0);
$revision = (int) ($routeParameters['revision'] ?? 0);
$row = $id > 0 && $revision > 0 && Gestionale::feature('quotes')
    ? OrderQuoteRevision::find(['order_id' => $id, 'revision' => $revision, 'deleted' => 'false'], 1)
    : null;
$path = is_array($row) && isset($row['pdf_file']) ? DocumentFiles::path((string) $row['pdf_file']) : null;

if ($path === null) {
    throw new NotFoundHttpException();
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="preventivo-'.$id.'-rev'.$revision.'.pdf"');
header('Content-Length: '.(string) filesize($path));
header('Cache-Control: no-store');

readfile($path);
exit;
