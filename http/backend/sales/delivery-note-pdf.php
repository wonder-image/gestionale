<?php

/*
 * Il PDF di un DDT emesso o annullato (G9 §4.2), dall'archivio non pubblico.
 * La rotta è della DeliveryNoteResource, che non guarda la funzionalità: la
 * si guarda qui.
 */

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\DeliveryNote;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentFiles;

$routeParameters = is_array($ROUTE_PARAMETERS ?? null) ? $ROUTE_PARAMETERS : [];
$id = (int) ($routeParameters['id'] ?? 0);
$note = $id > 0 && Gestionale::feature('delivery_notes') ? DeliveryNote::findById($id) : null;
$path = is_array($note) && isset($note['pdf_file']) ? DocumentFiles::path((string) $note['pdf_file']) : null;

if ($path === null) {
    http_response_code(404);
    exit('PDF non trovato');
}

$name = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($note['number'] ?? $id));

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="ddt-'.$name.'.pdf"');
header('Content-Length: '.(string) filesize($path));
header('Cache-Control: no-store');

readfile($path);
exit;
