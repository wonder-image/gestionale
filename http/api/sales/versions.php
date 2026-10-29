<?php

use Wonder\App\Support\ApiRequest;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeSearch;

// «Cerca o scansiona» del nuovo ordine da ufficio: come quella dei documenti
// di magazzino, con il prezzo di listino e il disponibile della sede.
$location = ApiRequest::int('location');
$code = ApiRequest::string('code');
$ids = ApiRequest::string('ids');

if ($ids !== '') {
    ApiRequest::ok(['versions' => OfficeSearch::describe(explode(',', $ids), $location)]);
}

if ($code !== '') {
    ApiRequest::ok(OfficeSearch::scan($code, $location));
}

ApiRequest::ok(['versions' => OfficeSearch::versions(ApiRequest::string('q'), $location)]);
