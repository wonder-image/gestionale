<?php
/** php tests/OnlineSubscriptionsEnabledTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OnlineSubscriptions;

// Lo stato si forza senza database: features() è memoizzato.
$forza = static function (array $stato): void {
    $proprieta = new ReflectionProperty(Gestionale::class, 'features');
    $proprieta->setValue(null, $stato);
};

$abbonamento = static fn (string $online = 'true'): array => [
    'id' => 1, 'type' => 'subscription', 'visible' => 'true', 'deleted' => 'false', 'visible_online' => $online,
];

check('la funzionalità «Abbonamenti online» non è più nel catalogo', function () {
    return !array_key_exists('online_subscriptions', FeatureCatalog::all());
});

check('abbonamenti e vendita online accesi, prodotto visibile online: si vende', function () use ($forza, $abbonamento) {
    $forza(['subscriptions' => true, 'online_sales' => true]);

    return OnlineSubscriptions::enabled() && OnlineSubscriptions::sellable($abbonamento());
});

check('prodotto non visibile online: non si vende', function () use ($forza, $abbonamento) {
    $forza(['subscriptions' => true, 'online_sales' => true]);

    return OnlineSubscriptions::sellable($abbonamento('false')) === false;
});

check('senza vendita online: niente abbonamenti sul sito', function () use ($forza, $abbonamento) {
    $forza(['subscriptions' => true, 'online_sales' => false]);

    return OnlineSubscriptions::enabled() === false && OnlineSubscriptions::sellable($abbonamento()) === false;
});

check('senza abbonamenti: niente abbonamenti sul sito', function () use ($forza, $abbonamento) {
    $forza(['subscriptions' => false, 'online_sales' => true]);

    return OnlineSubscriptions::enabled() === false && OnlineSubscriptions::sellable($abbonamento()) === false;
});

check('una vecchia chiave salvata `online_subscriptions` non conta', function () use ($forza, $abbonamento) {
    $forza(['subscriptions' => false, 'online_sales' => true, 'online_subscriptions' => true]);

    return OnlineSubscriptions::enabled() === false;
});

check('un prodotto che non è un abbonamento non si vende come tale', function () use ($forza, $abbonamento) {
    $forza(['subscriptions' => true, 'online_sales' => true]);

    return OnlineSubscriptions::sellable(['type' => 'simple'] + $abbonamento()) === false
        && OnlineSubscriptions::sellable(null) === false;
});

Gestionale::reset();
summary();
