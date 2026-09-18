<?php

return [
    // Indirizzo base della guida commercianti su GitBook: ogni pagina aggiunge
    // il proprio slug (es. `<base>/funzionalita`). Lo spazio è "Guida
    // commercianti" del sito mappato in gitbook-docs.yaml, path `guida`.
    // Vuoto: il pulsante "Guida" non compare.
    'docs' => [
        'merchant_url' => '',
    ],
    // Riquadri della home del backend (Wonder\Backend\Contracts\HomeWidget).
    'backend' => [
        'home_widgets' => [],
    ],
    // Classi del sito che estendono Extensions\GestionaleExtension (piano 4).
    'extensions' => [],
    // Funzionalità aggiunte dal sito e chiavi da sbloccare alla prima installazione.
    'features' => [
        'extra' => [],
        'unlock' => [],
    ],
];
