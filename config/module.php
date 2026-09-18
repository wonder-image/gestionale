<?php

return [
    // Indirizzo base della guida commercianti: ogni pagina aggiunge il proprio slug.
    'docs' => [
        'merchant_url' => 'https://guide.wonderimage.it/gestionale',
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
