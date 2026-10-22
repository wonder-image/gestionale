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
    // Aree permessi del gestionale (Wonder\App\Permission\Access), lette dalle
    // Resource con `access(Gestionale::area('<area>'))`. `admin` (Wonder Image)
    // è sempre incluso. Una regola è una lista di ruoli (tutte le azioni) o una
    // mappa per azione: view, create, edit, delete; `*` vale per le altre.
    // Per cambiarle nel sito: `permissions` in custom/config/modules/gestionale.php,
    // ogni area indicata sostituisce per intero quella di default.
    'permissions' => [
        'catalogo' => ['*' => ['administrator'], 'view' => ['administrator', 'sales', 'warehouse']],
        'contatti' => ['administrator', 'sales'],
        'vendite' => ['*' => ['administrator', 'sales'], 'view' => ['administrator', 'sales', 'warehouse']],
        'spedizioni' => ['administrator', 'sales', 'warehouse'],
        'promozioni' => ['administrator'],
        'magazzino' => ['*' => ['administrator', 'warehouse'], 'view' => ['administrator', 'warehouse', 'sales']],
        // Solo Wonder Image finché non le concede al commerciante.
        'impostazioni_spedizioni' => [],
        'impostazioni_pagamenti' => [],
        'fiscale' => [],
        'impostazioni' => [],
    ],
    // Riquadri della home del backend (Wonder\Backend\Contracts\HomeWidget).
    'backend' => [
        'home_widgets' => [
            \Wonder\Plugin\Gestionale\Backend\Widgets\SetupWidget::class,
            \Wonder\Plugin\Gestionale\Backend\Widgets\AttentionWidget::class,
            \Wonder\Plugin\Gestionale\Backend\Widgets\DuePaymentsWidget::class,
            \Wonder\Plugin\Gestionale\Backend\Widgets\LowStockWidget::class,
            \Wonder\Plugin\Gestionale\Backend\Widgets\ShipmentsToCheckWidget::class,
            \Wonder\Plugin\Gestionale\Backend\Widgets\ShippedNotDeliveredWidget::class,
            \Wonder\Plugin\Gestionale\Backend\Widgets\StockValueWidget::class,
            \Wonder\Plugin\Gestionale\Backend\Widgets\ExpiringBatchesWidget::class,
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
