<?php

use Wonder\App\Support\ApiRequest;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeCustomerPanel;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeSearch;

// Il cliente del nuovo ordine da ufficio. `q` cerca: con `mixed=1` persone e
// aziende con la chiave composta (`contact:12`, `company:7`), senza solo
// persone con l'id numerico (abbonamenti). `key` (o `id`, una persona) dà i
// dati che la scheda propone e i riquadri della colonna del cliente; per
// un'azienda `referent` è la persona scelta come referente.
$key = ApiRequest::string('key');
[$contactId, $companyId] = $key !== '' ? OfficeSearch::parseCustomerKey($key) : [ApiRequest::int('id'), 0];

if ($companyId > 0) {
    $contactId = ApiRequest::int('referent');
}

if ($contactId > 0 || $companyId > 0) {
    ApiRequest::ok(OfficeCustomerPanel::payload($contactId, $companyId) ?: ['customer' => (object) []]);
}

// `items` è la forma che legge la ricerca remota del core.
$q = ApiRequest::string('q');
ApiRequest::ok(['items' => ApiRequest::int('mixed') === 1 ? OfficeSearch::customerChoices($q) : OfficeSearch::customers($q)]);
