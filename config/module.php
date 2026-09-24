<?php

return [
    // Indirizzo base della guida commercianti su GitBook: ogni pagina aggiunge
    // il proprio slug (es. `<base>/funzionalita`). Lo spazio è "Guida
    // commercianti" del sito mappato in gitbook-docs.yaml, path `guida`.
    // Vuoto: il pulsante "Guida" non compare.
    'docs' => [
        'merchant_url' => 'https://wonder-image.gitbook.io/wonder-image-gestionale/user',
        // Guida sviluppatori: è lo spazio predefinito del sito, quindi senza
        // niente dopo il nome. Ci puntano le pagine che il commerciante non
        // deve toccare (aliquote, tipi fiscali, regole).
        'developer_url' => 'https://wonder-image.gitbook.io/wonder-image-gestionale',
    ],
    // Riquadri della home del backend (Wonder\Backend\Contracts\HomeWidget).
    'backend' => [
        'home_widgets' => [
            \Wonder\Plugin\Gestionale\Backend\Widgets\SetupWidget::class,
            \Wonder\Plugin\Gestionale\Backend\Widgets\AttentionWidget::class,
            \Wonder\Plugin\Gestionale\Backend\Widgets\LowStockWidget::class,
            \Wonder\Plugin\Gestionale\Backend\Widgets\ContactsWidget::class,
        ],
    ],
    // Classi del sito che estendono Extensions\GestionaleExtension (piano 4).
    'extensions' => [],
    // Funzionalità aggiunte dal sito e chiavi da sbloccare alla prima installazione.
    'features' => [
        'extra' => [],
        'unlock' => [],
    ],
];
