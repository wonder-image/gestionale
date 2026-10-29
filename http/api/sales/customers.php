<?php

use Wonder\App\Support\ApiRequest;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeCustomerPanel;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeSearch;

// Il cliente del nuovo ordine da ufficio: `q` cerca, `id` dà i dati che la
// sua scheda propone (indirizzi, metodo e condizione di pagamento) e i
// riquadri della colonna del cliente.
$id = ApiRequest::int('id');

if ($id > 0) {
    ApiRequest::ok(OfficeCustomerPanel::payload($id) ?: ['customer' => (object) []]);
}

// `items` è la forma che legge la ricerca remota del core.
ApiRequest::ok(['items' => OfficeSearch::customers(ApiRequest::string('q'))]);
