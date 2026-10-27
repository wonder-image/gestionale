<?php

/*
 * «Scarica» le righe di un listino in xlsx o csv. La rotta è della
 * PriceListResource (`registerBackendRoutes()`), che non guarda la
 * funzionalità: la si guarda qui.
 */

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceList;
use Wonder\Plugin\Gestionale\Support\Export\Download;
use Wonder\Plugin\Gestionale\Support\Pricing\PriceListTransfer;

$routeParameters = is_array($ROUTE_PARAMETERS ?? null) ? $ROUTE_PARAMETERS : [];
$id = (int) ($routeParameters['id'] ?? 0);
$format = (string) ($routeParameters['format'] ?? '') === 'csv' ? 'csv' : 'xlsx';
$list = $id > 0 && Gestionale::feature('customer_price_lists') ? PriceList::findById($id) : null;

if (!is_array($list) || !isset($list['id'])) {
    http_response_code(404);
    exit('Listino non trovato');
}

$rows = PriceListTransfer::rows($id);
// Il nome è testo del commerciante: nel nome del file solo lettere, cifre, - e _.
$name = preg_replace('/[^A-Za-z0-9_-]+/', '-', html_entity_decode((string) ($list['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$file = 'listino-'.(trim((string) $name, '-') ?: $id).'.'.$format;

$format === 'csv'
    ? Download::send(PriceListTransfer::csv($rows), 'text/csv; charset=UTF-8', $file)
    : Download::send(PriceListTransfer::xlsx($rows), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $file);
exit;
