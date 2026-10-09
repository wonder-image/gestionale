<?php

use Wonder\Plugin\Gestionale\Support\Payments\PaymentEvents;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;

// La firma è sul corpo grezzo: si legge così com'è, prima di ogni decodifica.
$raw = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
$provider = PaymentProviders::get('stripe');
$code = $provider === null ? 503 : PaymentEvents::handle($provider, $raw, $signature);

http_response_code($code);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['received' => $code === 200]);
