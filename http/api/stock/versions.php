<?php

use Wonder\App\Support\ApiRequest;
use Wonder\Plugin\Gestionale\Support\Stock\StockVersionSearch;

// «Cerca o scansiona» dei documenti di magazzino: `code` è il lettore (SKU o
// EAN esatto), `q` la ricerca, `ids` le righe già nella pagina; `location` la
// sede del documento per la giacenza; `scope` e `scope_id` le versioni da
// contare in un inventario.
$location = ApiRequest::int('location');
$code = ApiRequest::string('code');
$ids = ApiRequest::string('ids');
$scope = ApiRequest::string('scope');

if ($scope !== '') {
    // *Aggiungi versioni* dell'inventario: tutte, una categoria o un marchio.
    ApiRequest::ok(StockVersionSearch::scope($scope, ApiRequest::int('scope_id'), $location));
}

if ($ids !== '') {
    // Cambiando la sede, la pagina rilegge la giacenza delle sue righe.
    ApiRequest::ok(['versions' => array_values(StockVersionSearch::describe(explode(',', $ids), $location))]);
}

if ($code !== '') {
    ApiRequest::ok(StockVersionSearch::scan($code, $location));
}

ApiRequest::ok(['versions' => StockVersionSearch::search(ApiRequest::string('q'), $location)]);
