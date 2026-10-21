<?php
/** php tests/PaymentEventsTypesTest.php — C-CHK-I5, gli eventi registrati sul webhook */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Payments\PaymentEvents;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionEvents;
use Wonder\Plugin\Stripe\Connect;

$tipi = PaymentEvents::types();

check('types(): gli intenti, il rimborso e gli eventi degli abbonamenti', fn () =>
    array_diff([
        'payment_intent.succeeded', 'payment_intent.processing', 'payment_intent.payment_failed',
        'payment_intent.canceled', 'charge.refunded',
    ], $tipi) === []
    && array_diff(SubscriptionEvents::TYPES, $tipi) === []
    && count($tipi) === 11
);

check('types(): nessun doppione', fn () => count($tipi) === count(array_unique($tipi)));

// Il match di apply() usa le stesse stringhe: un tipo nuovo va aggiunto in entrambi.
check('types(): ogni arm del match di apply() è registrato', function () use ($tipi) {
    $sorgente = (string) file_get_contents(dirname(__DIR__).'/src/Support/Payments/PaymentEvents.php');
    preg_match_all("/^\\s*'([a-z_]+\\.[a-z_.]+)' => OnlinePayments::/m", $sorgente, $arm);

    return count($arm[1]) === 5 && array_diff($arm[1], $tipi) === [];
});

// Il file delle rotte non si include: registra rotte e la risorsa OAuth.
check('route.api.php registra i tipi con Connect::addEvents', fn () =>
    str_contains(
        (string) file_get_contents(dirname(__DIR__).'/config/routes/route.api.php'),
        '\\Wonder\\Plugin\\Stripe\\Connect::addEvents(\\Wonder\\Plugin\\Gestionale\\Support\\Payments\\PaymentEvents::types());'
    )
);

check('Connect::events() dopo addEvents: core più modulo', function () use ($tipi) {
    Connect::addEvents($tipi);
    $eventi = Connect::events();

    return array_diff($tipi, $eventi) === [] && array_diff(Connect::EVENTS, $eventi) === [];
});

summary();
