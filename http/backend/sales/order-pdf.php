<?php

/*
 * La conferma d'ordine in PDF (G9 §3.3) di un ordine da ufficio confermato.
 * Non si archivia: si fa adesso, con l'ordine com'è (dopo un *Annulla righe*
 * è già aggiornata). La rotta è della OfficeOrderResource.
 */

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentData;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentPdfs;

$routeParameters = is_array($ROUTE_PARAMETERS ?? null) ? $ROUTE_PARAMETERS : [];
$id = (int) ($routeParameters['id'] ?? 0);
$order = $id > 0 && Gestionale::feature('office_sales') ? Order::findById($id) : null;

if (!is_array($order) || !isset($order['id'])
    || (string) ($order['stage'] ?? '') !== 'order'
    || (string) ($order['channel'] ?? '') !== 'office'
    || in_array((string) ($order['status'] ?? ''), ['draft', 'cancelled'], true)) {
    http_response_code(404);
    exit('Ordine non trovato');
}

$pdf = DocumentPdfs::render('order_confirmation', DocumentData::forOrder($id));
$name = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($order['order_number'] ?? $id));

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="conferma-ordine-'.$name.'.pdf"');
header('Content-Length: '.strlen($pdf));
header('Cache-Control: no-store');

echo $pdf;
exit;
