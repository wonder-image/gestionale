<?php

use Wonder\App\Support\ApiRequest;
use Wonder\Plugin\Gestionale\Support\Catalog\RelatedModelSearch;

// «Cerca un articolo» di *Articoli collegati*: `q` la ricerca, `exclude`
// l'articolo della scheda, che non si collega a sé stesso. Con `items=1`
// le voci degli elenchi del sito, varianti comprese.
if (ApiRequest::string('items') === '1') {
    ApiRequest::json(['models' => RelatedModelSearch::searchItems(ApiRequest::string('q'))]);
}

ApiRequest::json(['models' => RelatedModelSearch::search(ApiRequest::string('q'), ApiRequest::int('exclude'))]);
