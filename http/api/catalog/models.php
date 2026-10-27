<?php

use Wonder\App\Support\ApiRequest;
use Wonder\Plugin\Gestionale\Support\Catalog\RelatedModelSearch;

// «Cerca un articolo» di *Articoli collegati*: `q` la ricerca, `exclude`
// l'articolo della scheda, che non si collega a sé stesso.
ApiRequest::json(['models' => RelatedModelSearch::search(ApiRequest::string('q'), ApiRequest::int('exclude'))]);
