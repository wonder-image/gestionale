<?php

use Wonder\App\Support\ApiRequest;
use Wonder\Plugin\Gestionale\Support\Contacts\QuickContacts;

// «Nuovo referente» / «Nuova azienda» dal form dei legami: crea la scheda (o
// trova quella che c'è già) e risponde con id ed etichetta da selezionare.
$result = QuickContacts::create(ApiRequest::body());

if (!$result['ok']) {
    ApiRequest::error($result['message'], 422);
}

unset($result['ok']);
ApiRequest::ok($result);
