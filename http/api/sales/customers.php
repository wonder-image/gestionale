<?php

use Wonder\App\Support\ApiRequest;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeCustomerPanel;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeSearch;

// Il cliente del nuovo ordine da ufficio: `q` cerca, `id` dà i dati che la
// sua scheda propone (indirizzi, metodo e condizione di pagamento) e i
// riquadri della colonna del cliente.
$id = ApiRequest::int('id');

if ($id > 0) {
    ApiRequest::json(OfficeCustomerPanel::payload($id) ?: ['customer' => (object) []]);
}

$customers = OfficeSearch::customers(ApiRequest::string('q'));

ApiRequest::json(['items' => $customers, 'customers' => $customers]);
