<?php

use Wonder\App\Support\ApiRequest;
use Wonder\Plugin\Gestionale\Support\Stock\StockVersionSearch;

// «Cerca o scansiona» dei documenti di magazzino: `code` è il lettore (SKU o
// EAN esatto), `q` la ricerca, `ids` le righe già nella pagina; `location` la
// sede del documento per la giacenza.
$location = ApiRequest::int('location');
$code = ApiRequest::string('code');
$ids = ApiRequest::string('ids');

if ($ids !== '') {
    // Cambiando la sede, la pagina rilegge la giacenza delle sue righe.
    ApiRequest::json(['versions' => array_values(StockVersionSearch::describe(explode(',', $ids), $location))]);
}

if ($code !== '') {
    ApiRequest::json(StockVersionSearch::scan($code, $location));
}

ApiRequest::json(['versions' => StockVersionSearch::search(ApiRequest::string('q'), $location)]);
