<?php

/*
 * Il proforma del periodo in corso di un abbonamento da ufficio (G10 §3),
 * dall'archivio non pubblico; se manca (archivio pulito, altro ambiente) si
 * ristampa. La rotta è della SubscriptionResource (`registerBackendRoutes()`),
 * che non guarda la funzionalità: la si guarda qui.
 */

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentFiles;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OfficeSubscriptions;

$routeParameters = is_array($ROUTE_PARAMETERS ?? null) ? $ROUTE_PARAMETERS : [];
$id = (int) ($routeParameters['id'] ?? 0);
$row = $id > 0 && Gestionale::feature('subscriptions') ? Subscription::findById($id) : null;
$start = is_array($row) ? trim((string) ($row['current_period_start'] ?? '')) : '';
$path = null;

if (is_array($row) && (string) ($row['channel'] ?? '') === 'office' && $start !== '' && !str_starts_with($start, '0000')) {
    $path = DocumentFiles::path(OfficeSubscriptions::proformaFile($id, $start))
        ?? DocumentFiles::path(OfficeSubscriptions::proforma($row, $start, (string) $row['current_period_end']));
}

if ($path === null) {
    http_response_code(404);
    exit('PDF non trovato');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="'.OfficeSubscriptions::proformaName($row).'"');
header('Content-Length: '.(string) filesize($path));
header('Cache-Control: no-store');

readfile($path);
exit;
