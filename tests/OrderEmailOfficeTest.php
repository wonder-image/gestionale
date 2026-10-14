<?php
/** php tests/OrderEmailOfficeTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Orders\OrderEmail;

$preventivo = ['quote_number' => '2026-10-0007', 'quote_revision' => 2, 'quote_expires_at' => '2026-11-12 00:00:00', 'total' => '117.50'];

check('il preventivo: oggetto con numero e revisione, la validità nella frase', function () use ($preventivo) {
    $email = OrderEmail::compose('quote_sent', $preventivo, []);

    return $email['subject'] === 'Preventivo 2026-10-0007 rev. 2'
        && str_contains($email['body'], '12/11/2026')
        && str_contains($email['body'], 'allegato');
});

check('alla prima revisione il numero è da solo', fn () =>
    OrderEmail::compose('quote_sent', ['quote_revision' => 1] + $preventivo, [])['subject'] === 'Preventivo 2026-10-0007'
);

check('la conferma da ufficio dice il numero e le scadenze in allegato', function () {
    $email = OrderEmail::compose('office_confirmed', ['order_number' => '2026-10-0012', 'total' => '50.00'], []);

    return $email['subject'] === 'Ordine 2026-10-0012 confermato' && str_contains($email['body'], 'scadenze');
});

summary();
