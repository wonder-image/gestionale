<?php

use Wonder\App\Support\ApiRequest;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeShippingQuote;

// Il costo del metodo di spedizione scelto nel nuovo ordine da ufficio, dal
// listino, mentre la bozza si scrive. Accetta form o JSON (`lines` array).
// Senza metodo o senza articoli non è un errore: `ok` false e la nota in `message`.
ApiRequest::ok(OfficeShippingQuote::quote(ApiRequest::body()));
