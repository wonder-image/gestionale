<?php

use Wonder\App\Support\ApiRequest;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeSearch;

// Il cliente del nuovo ordine da ufficio: `q` cerca, `id` dà i dati che la
// sua scheda propone (indirizzi, metodo e condizione di pagamento).
$id = ApiRequest::int('id');

if ($id > 0) {
    ApiRequest::json(['customer' => (object) OfficeOrders::customerDefaults($id)]);
}

ApiRequest::json(['customers' => OfficeSearch::customers(ApiRequest::string('q'))]);
