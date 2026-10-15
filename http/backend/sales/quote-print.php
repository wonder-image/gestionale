<?php

/*
 * Il preventivo in PDF com'è adesso, per stamparlo anche prima dell'invio.
 * Non si archivia: le revisioni inviate hanno il loro PDF. La rotta è della
 * QuoteResource, che non guarda la funzionalità: la si guarda qui.
 */

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\Sales\QuoteResource;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentData;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentPdfs;

$routeParameters = is_array($ROUTE_PARAMETERS ?? null) ? $ROUTE_PARAMETERS : [];
$id = (int) ($routeParameters['id'] ?? 0);
$order = $id > 0 && Gestionale::feature('quotes') ? Order::findById($id) : null;

if (!is_array($order) || !isset($order['id'])
    || (string) ($order['stage'] ?? '') !== 'quote'
    || (string) ($order['deleted'] ?? 'false') !== 'false') {
    http_response_code(404);
    exit('Preventivo non trovato');
}

$pdf = DocumentPdfs::render('quote', DocumentData::forOrder($id));
$name = preg_replace('/[^A-Za-z0-9_-]+/', '-', QuoteResource::number($order) ?: (string) $id);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="preventivo-'.$name.'.pdf"');
header('Content-Length: '.strlen($pdf));
header('Cache-Control: no-store');

echo $pdf;
exit;
