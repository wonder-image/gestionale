<?php

// Caricato da app per ogni richiesta, prima della configurazione del sito
// (module.json → boot.files). Le regole controllano la funzionalità quando
// vengono chiamate, non qui.
\Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents::register();
// L'unione di due brand di customer-account sposta i documenti sul brand che resta.
\Wonder\Plugin\Gestionale\Support\Contacts\DocumentBrands::register();
