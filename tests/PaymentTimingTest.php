<?php
/** php tests/PaymentTimingTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Gestionale\Support\Orders\PaymentTiming;

check('la carta impegna la merce per i minuti delle impostazioni', function () {
    return PaymentTiming::expiry(PaymentTiming::IMMEDIATE, 30, 7, '2026-09-30 10:00:00')
        === '2026-09-30 10:30:00';
});

check('il bonifico la impegna per i giorni delle impostazioni', function () {
    return PaymentTiming::expiry(PaymentTiming::DEFERRED, 30, 7, '2026-09-30 10:00:00')
        === '2026-10-07 10:00:00';
});

check('il contrassegno non scade mai', function () {
    return PaymentTiming::expiry(PaymentTiming::ON_DELIVERY, 30, 7, '2026-09-30 10:00:00') === null;
});

check('un modo di pagare che non conosciamo si comporta da immediato', function () {
    return PaymentTiming::expiry('chissà', 30, 7, '2026-09-30 10:00:00') === '2026-09-30 10:30:00';
});

check('minuti e giorni a zero non fanno una scadenza già passata', function () {
    // Zero nelle impostazioni vuol dire «non scade», non «scaduto un istante fa»:
    // con una scadenza nel passato la merce si libererebbe al primo giro.
    return PaymentTiming::expiry(PaymentTiming::IMMEDIATE, 0, 7, '2026-09-30 10:00:00') === null
        && PaymentTiming::expiry(PaymentTiming::DEFERRED, 30, 0, '2026-09-30 10:00:00') === null;
});

check('i tre modi di pagare sono quelli dichiarati', function () {
    return PaymentTiming::ALL === ['immediate', 'deferred', 'on_delivery'];
});

summary();
