<?php

use Wonder\App\Support\ApiRequest;
use Wonder\Http\Csrf;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeShippingQuote;

// Il costo del metodo di spedizione scelto nel nuovo ordine da ufficio, dal
// listino, mentre la bozza si scrive.
if (!Csrf::verify()) {
    ApiRequest::error('Sessione scaduta: ricarica la pagina.', 419);
}

ApiRequest::json(OfficeShippingQuote::quote($_POST));
