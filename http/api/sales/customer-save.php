<?php

use Wonder\App\Support\ApiRequest;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeCustomerPanel;

// Le finestre del cliente nel nuovo ordine da ufficio: Contatti, Fatturazione
// e indirizzi si salvano sulla sua scheda (persona o, con `company_id`,
// azienda). Risponde con l'esito e i riquadri ridisegnati.
$contactId = ApiRequest::int('contact_id');
$companyId = ApiRequest::int('company_id');
$result = OfficeCustomerPanel::save(ApiRequest::string('action'), $contactId, ApiRequest::body(), $companyId);

if (!$result['ok']) {
    ApiRequest::error($result['message'], 422);
}

unset($result['ok']);
ApiRequest::ok(array_merge($result, OfficeCustomerPanel::payload($contactId, $companyId)));
