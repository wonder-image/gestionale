<?php

/*
 * «Scarica i codici» di un coupon in blocco (gestionale#113): il CSV dei codici
 * generati, con chi li ha usati. La rotta è della CouponResource
 * (`registerBackendRoutes()`), che non guarda la funzionalità: la si guarda qui.
 */

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Support\Promotions\CouponCodes;

$routeParameters = is_array($ROUTE_PARAMETERS ?? null) ? $ROUTE_PARAMETERS : [];
$id = (int) ($routeParameters['id'] ?? 0);
$coupon = $id > 0 && Gestionale::feature('coupons') ? Coupon::findById($id) : null;

if (!is_array($coupon) || !isset($coupon['id'])) {
    http_response_code(404);
    exit('Coupon non trovato');
}

$csv = CouponCodes::csv(CouponCodes::of($id));
// Il codice è testo del commerciante: nel nome del file solo lettere, cifre, - e _.
$name = preg_replace('/[^A-Za-z0-9_-]+/', '-', html_entity_decode((string) ($coupon['code'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$file = 'codici-coupon-'.trim((string) $name, '-').'.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$file.'"');
header('Content-Length: '.strlen($csv));
header('Cache-Control: no-store');

echo $csv;
exit;
