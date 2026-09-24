<?php
/** php tests/WidgetsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\AttentionWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\ContactsWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\LowStockWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\SetupWidget;
use Wonder\Plugin\Gestionale\Gestionale;

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

check('il riquadro delle anagrafiche è un riquadro della home', fn () =>
    is_subclass_of(ContactsWidget::class, HomeWidget::class)
    && (new ContactsWidget)->title() === 'Anagrafiche'
    && (new ContactsWidget)->authorities() === ['admin', 'administrator']
);

check('le anagrafiche vengono dopo le cose da controllare', fn () =>
    (new ContactsWidget)->order() > (new AttentionWidget)->order()
);

check('con gli acquisti bloccati la parola fornitore non compare', function () {
    $markup = ContactsWidget::markup(3, null);

    return str_contains($markup, 'Clienti')
        && !str_contains(strtolower($markup), 'fornitor');
});

check('con gli acquisti sbloccati ci sono tutti e due i numeri', function () {
    $markup = ContactsWidget::markup(3, 2);

    return str_contains($markup, '3 clienti')
        && str_contains($markup, '2 fornitori');
});

check('una rubrica vuota lo dice, e il singolare è singolare', fn () =>
    str_contains(ContactsWidget::markup(0, null), 'Nessuno ancora')
    && str_contains(ContactsWidget::markup(1, null), '1 cliente')
);

// Una riga come la dà `LowStockReport::items()`.
$sottoScorta = static fn (string $article, string $option, float $available, float $threshold, int $id = 1): array => [
    'product_id' => $id,
    'article' => $article,
    'option' => $option,
    'sku' => 'SKU-'.$id,
    'threshold' => $threshold,
    'available' => $available,
];

check('il riquadro Sotto scorta sta fra Da controllare e Anagrafiche', fn () =>
    is_subclass_of(LowStockWidget::class, HomeWidget::class)
    && (new LowStockWidget)->title() === 'Sotto scorta'
    && (new LowStockWidget)->authorities() === ['admin', 'administrator']
    && (new LowStockWidget)->order() > (new AttentionWidget)->order()
    && (new LowStockWidget)->order() < (new ContactsWidget)->order()
);

check('il modulo lo registra subito dopo Da controllare', function () {
    $widgets = (require __DIR__.'/../config/module.php')['backend']['home_widgets'];
    $posto = array_search(LowStockWidget::class, $widgets, true);

    return $posto !== false && $posto === array_search(AttentionWidget::class, $widgets, true) + 1;
});

check('con gli avvisi bloccati il riquadro non disegna niente', function () {
    // `render()` deve fermarsi prima di qualunque lettura: qui non c'è database.
    $stato = new ReflectionProperty(Gestionale::class, 'features');
    $prima = $stato->getValue();
    $stato->setValue(null, ['low_stock_alerts' => false]);

    try {
        return (new LowStockWidget)->render() === '';
    } finally {
        $stato->setValue(null, $prima);
    }
});

check('senza prodotti sotto scorta lo dice, senza allarmare', function () {
    $html = LowStockWidget::markup([], true);

    return str_contains($html, 'Nessun prodotto sotto la scorta minima')
        && !str_contains($html, 'giacenze?sotto=1');
});

check('ogni riga dice cosa riordinare, e il pulsante apre le giacenze filtrate', function () use ($sottoScorta) {
    $html = LowStockWidget::markup([$sottoScorta('Maglia', 'Rossa, M', 1.25, 5.0)], true);

    return str_contains($html, 'Maglia — Rossa, M')
        && str_contains($html, 'SKU-1')
        && str_contains($html, 'Disponibili 1,25')
        && str_contains($html, 'scorta minima 5')
        && str_contains($html, 'giacenze?sotto=1');
});

check('un articolo senza varianti non si porta dietro il trattino', function () use ($sottoScorta) {
    $html = LowStockWidget::markup([$sottoScorta('Borraccia', '', 0.0, 3.0)], true);

    return str_contains($html, 'Borraccia') && !str_contains($html, 'Borraccia —');
});

check('oltre le dieci righe il resto si conta, al singolare e al plurale', function () use ($sottoScorta) {
    $righe = static function (int $quante) use ($sottoScorta): array {
        $items = [];

        for ($i = 1; $i <= $quante; $i++) {
            $items[] = $sottoScorta('Articolo '.$i, '', 0.0, 1.0, $i);
        }

        return $items;
    };
    $dodici = LowStockWidget::markup($righe(12), true);
    $undici = LowStockWidget::markup($righe(11), true);
    $dieci = LowStockWidget::markup($righe(10), true);

    return substr_count($dodici, '<li') === LowStockWidget::LIMIT
        && str_contains($dodici, 'e altri 2')
        && str_contains($undici, 'e un altro')
        && !str_contains($dieci, 'e altri') && !str_contains($dieci, 'e un altro');
});

check('senza destinatari il riquadro avvisa che l\'email non parte, anche vuoto', function () use ($sottoScorta) {
    $con = LowStockWidget::markup([$sottoScorta('Maglia', 'M', 0.0, 2.0)], true);
    $senza = LowStockWidget::markup([$sottoScorta('Maglia', 'M', 0.0, 2.0)], false);
    $vuoto = LowStockWidget::markup([], false);

    return !str_contains($con, 'impostazioni-negozio')
        && str_contains($senza, 'Nessuno riceve l\'email')
        && str_contains($senza, '/backend/app/gestionale/impostazioni-negozio')
        && str_contains($vuoto, 'Nessuno riceve l\'email');
});

check('i nomi dei prodotti non possono iniettare markup', function () use ($sottoScorta) {
    $html = LowStockWidget::markup([$sottoScorta('<script>alert(1)</script>', '<b>x</b>', 0.0, 1.0)], true);

    return !str_contains($html, '<script>')
        && !str_contains($html, '<b>x</b>')
        && str_contains($html, '&lt;script&gt;');
});

// Una riga come la dà `NegativeStock::items()`.
$negativa = static fn (int $id, float $quantity, int $locations = 1, string $option = 'Rossa, M'): array => [
    'product_id' => $id,
    'article' => 'Maglia',
    'option' => $option,
    'sku' => 'MAG-'.$id,
    'quantity' => $quantity,
    'locations' => $locations,
];

check('una giacenza sotto zero si vede, con il pulsante per rettificarla', function () use ($negativa) {
    $html = AttentionWidget::markup([], [$negativa(7, -3.0)]);

    return str_contains($html, 'Maglia — Rossa, M')
        && str_contains($html, 'MAG-7')
        && str_contains($html, 'Giacenza -3')
        && str_contains($html, 'rettifica?versione=7&amp;torna=%2Fbackend%2F')
        && str_contains($html, 'Rettifica')
        && !str_contains($html, 'Non c\'è niente da controllare');
});

check('le sedi si nominano solo quando sono più di una', function () use ($negativa) {
    $una = AttentionWidget::markup([], [$negativa(7, -3.0)]);
    $due = AttentionWidget::markup([], [$negativa(7, -4.25, 2)]);

    return !str_contains($una, ' sedi')
        && str_contains($due, 'Giacenza -4,25 in 2 sedi');
});

check('giacenze negative ed errori stanno nello stesso riquadro, prima le giacenze', function () use ($negativa) {
    $html = AttentionWidget::markup([[
        'id' => 3,
        'service' => 'fatture-in-cloud',
        'action' => 'invoice.send',
        'message' => 'Timeout',
        'occurrences' => 1,
        'last_seen_at' => '',
    ]], [$negativa(7, -1.0)]);

    return str_contains($html, 'fatture-in-cloud')
        && str_contains($html, 'MAG-7')
        && strpos($html, 'MAG-7') < strpos($html, 'fatture-in-cloud');
});

check('oltre le dieci giacenze negative il resto si conta', function () use ($negativa) {
    $righe = [];

    for ($i = 1; $i <= 12; $i++) {
        $righe[] = $negativa($i, -1.0);
    }

    $html = AttentionWidget::markup([], $righe);

    return substr_count($html, 'Rettifica</a>') === AttentionWidget::LIMIT
        && str_contains($html, 'e altre 2 giacenze sotto zero');
});

check('i nomi delle versioni non possono iniettare markup', function () use ($negativa) {
    $html = AttentionWidget::markup([], [$negativa(7, -1.0, 1, '<img src=x onerror=alert(1)>')]);

    return !str_contains($html, '<img') && str_contains($html, '&lt;img');
});

summary();
