<?php
/** php tests/WidgetsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\AttentionWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\SetupWidget;

check('i due riquadri sono riquadri della home', fn () =>
    is_subclass_of(SetupWidget::class, HomeWidget::class)
    && is_subclass_of(AttentionWidget::class, HomeWidget::class)
);

check('i Primi passi sono roba di chi installa', fn () =>
    (new SetupWidget)->title() === 'Primi passi'
    && (new SetupWidget)->authorities() === ['admin']
);

check('"Da controllare" lo vedono tutti e due', fn () =>
    (new AttentionWidget)->title() === 'Da controllare'
    && (new AttentionWidget)->authorities() === ['admin', 'administrator']
);

check('i Primi passi vengono prima', fn () =>
    (new SetupWidget)->order() < (new AttentionWidget)->order()
);

check('senza passi aperti il riquadro non disegna niente', fn () =>
    SetupWidget::markup([]) === ''
);

check('con dei passi aperti si vede il titolo e il link', function () {
    $html = SetupWidget::markup([[
        'key' => 'society',
        'title' => 'Completa i dati della società',
        'description' => 'Servono ragione sociale e partita IVA.',
        'url' => '/backend/app/config/society/',
    ]]);

    return str_contains($html, 'Completa i dati della società')
        && str_contains($html, '/backend/app/config/society/')
        && str_contains($html, 'Primi passi');
});

check('senza errori aperti il riquadro lo dice, senza allarmare', function () {
    $html = AttentionWidget::markup([]);

    return str_contains($html, 'Non c\'è niente da controllare');
});

check('gli errori aperti si vedono con servizio e volte', function () {
    $html = AttentionWidget::markup([[
        'id' => 3,
        'service' => 'fatture-in-cloud',
        'action' => 'invoice.send',
        'message' => 'Timeout',
        'occurrences' => 4,
        'last_seen_at' => '2026-09-21 10:00:00',
    ]]);

    return str_contains($html, 'fatture-in-cloud')
        && str_contains($html, 'invoice.send')
        && str_contains($html, '4');
});

check('i testi degli errori non possono iniettare markup', function () {
    $html = AttentionWidget::markup([[
        'id' => 1,
        'service' => '<script>alert(1)</script>',
        'action' => 'x',
        'message' => 'y',
        'occurrences' => 1,
        'last_seen_at' => '',
    ]]);

    return !str_contains($html, '<script>');
});

summary();
