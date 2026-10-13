<?php

/*
 * «Scarica i clienti» di un lotto (G3.20): il CSV per un richiamo. La rotta è
 * della BatchResource (`registerBackendRoutes()`), che non guarda la
 * funzionalità: la si guarda qui.
 */

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Stock\Batch;
use Wonder\Plugin\Gestionale\Support\Stock\BatchCustomers;

$routeParameters = is_array($ROUTE_PARAMETERS ?? null) ? $ROUTE_PARAMETERS : [];
$id = (int) ($routeParameters['id'] ?? 0);
$batch = $id > 0 && Gestionale::feature('batch_tracking') ? Batch::findById($id) : null;

if (!is_array($batch) || !isset($batch['id'])) {
    http_response_code(404);
    exit('Lotto non trovato');
}

$csv = BatchCustomers::csv(BatchCustomers::of($id));
// Il numero del lotto è testo del commerciante: nel nome del file solo lettere, cifre, - e _.
$name = preg_replace('/[^A-Za-z0-9_-]+/', '-', html_entity_decode((string) ($batch['number'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$file = 'clienti-lotto-'.trim((string) $name, '-').'.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$file.'"');
header('Content-Length: '.strlen($csv));
header('Cache-Control: no-store');

echo $csv;
exit;
