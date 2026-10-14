<?php
/** php tests/SubscriptionPagesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Resources\Promotions\CouponResource;
use Wonder\Plugin\Gestionale\Resources\Subscriptions\PlanResource;
use Wonder\Plugin\Gestionale\Resources\Subscriptions\SubscriptionResource;
use Wonder\Plugin\Gestionale\Support\Features\FeatureGuards;

$conFunzionalita = static function (array $accese, callable $fai): mixed {
    $cache = new ReflectionProperty(Gestionale::class, 'features');
    $prima = $cache->getValue();
    $cache->setValue(null, $accese);

    try {
        return $fai();
    } finally {
        $cache->setValue(null, $prima);
    }
};

check('Piani e Abbonamenti stanno dietro «Abbonamenti», con la loro guida', fn () =>
    PlanResource::$feature === 'subscriptions'
    && SubscriptionResource::$feature === 'subscriptions'
    && PlanResource::$docsPage === 'vendite/vendite-abbonamenti'
    && SubscriptionResource::$docsPage === 'vendite/vendite-abbonamenti'
);

check('ogni stato dell\'abbonamento ha la sua etichetta', fn () =>
    array_keys(SubscriptionResource::statusLabels()) === Subscription::STATUSES
    && array_keys(SubscriptionResource::channelLabels()) === Subscription::CHANNELS
);

check('le righe vuote dei prezzi e dei limiti non si scrivono', function () {
    $prezzi = PlanResource::prepareRepeaterRows('prices', [
        ['name' => 'Mensile', 'billing_interval' => 'month', 'term_months' => '', 'price' => '19,00', 'sale_price' => ''],
        ['name' => '', 'price' => ''],
    ]);
    $limiti = PlanResource::prepareRepeaterRows('features', [
        ['feature_key' => ' max_progetti ', 'feature_value' => ' 5 '],
        ['feature_key' => '', 'feature_value' => '3'],
    ]);

    return count($prezzi) === 1 && $prezzi[0]['term_months'] === 1 && $prezzi[0]['price'] === '19.00'
        && $prezzi[0]['sale_price'] === '0.00' && $prezzi[0]['active'] === 'true'
        && count($limiti) === 1 && $limiti[0]['feature_key'] === 'max_progetti' && $limiti[0]['feature_value'] === '5';
});

check('un intervallo inventato diventa il mese; l\'annuale senza mesi ne prende 12', function () {
    $righe = PlanResource::prepareRepeaterRows('prices', [
        ['name' => 'A', 'billing_interval' => 'week', 'price' => '1'],
        ['name' => 'B', 'billing_interval' => 'year', 'price' => '1'],
    ]);

    return $righe[0]['billing_interval'] === 'month' && $righe[0]['term_months'] === 1 && $righe[1]['term_months'] === 12;
});

check('il prezzo in breve mostra lo scontato quando vale', fn () =>
    PlanResource::priceLabel(['name' => 'Mensile', 'price' => '19.00', 'sale_price' => '0.00']) === 'Mensile 19,00 €'
    && PlanResource::priceLabel(['name' => 'Annuale', 'price' => '190.00', 'sale_price' => '150.00']) === 'Annuale 150,00 €'
    && PlanResource::priceLabel(['name' => 'Strano', 'price' => '10.00', 'sale_price' => '12.00']) === 'Strano 10,00 €'
);

check('la durata del coupon detta a parole', fn () =>
    CouponResource::durationLabel(['applies_subscriptions' => 'false', 'duration' => 'forever']) === 'No'
    && CouponResource::durationLabel(['applies_subscriptions' => 'true', 'duration' => 'once']) === 'Sì, sul primo addebito'
    && CouponResource::durationLabel(['applies_subscriptions' => 'true', 'duration' => 'repeating', 'duration_months' => 1]) === 'Sì, per 1 mese'
    && CouponResource::durationLabel(['applies_subscriptions' => 'true', 'duration' => 'repeating', 'duration_months' => 3]) === 'Sì, per 3 mesi'
    && CouponResource::durationLabel(['applies_subscriptions' => 'true', 'duration' => 'forever']) === 'Sì, per sempre'
);

check('con «Abbonamenti» spenta il coupon tiene la durata di prima', fn () => $conFunzionalita(['coupons' => true], static function (): bool {
    $vecchio = ['applies_subscriptions' => 'true', 'duration' => 'repeating', 'duration_months' => '3'];
    $nuovo = CouponResource::subscriptionValues(['code' => 'X'], $vecchio);
    $creato = CouponResource::subscriptionValues(['code' => 'Y'], null);

    return $nuovo['applies_subscriptions'] === 'true' && $nuovo['duration'] === 'repeating' && (string) $nuovo['duration_months'] === '3'
        && $creato['applies_subscriptions'] === 'false' && $creato['duration'] === 'once' && $creato['duration_months'] === 0;
}));

check('accesa: interruttore spento azzera la durata, i mesi valgono solo per «Per N mesi»', fn () => $conFunzionalita(['coupons' => true, 'subscriptions' => true], static function (): bool {
    $spento = CouponResource::subscriptionValues(['duration' => 'forever', 'duration_months' => '4'], null);
    $sempre = CouponResource::subscriptionValues(['applies_subscriptions' => 'true', 'duration' => 'forever', 'duration_months' => '4'], null);
    $mesi = CouponResource::subscriptionValues(['applies_subscriptions' => 'true', 'duration' => 'repeating', 'duration_months' => '4'], null);
    $strano = CouponResource::subscriptionValues(['applies_subscriptions' => 'true', 'duration' => 'mai'], null);

    return $spento['applies_subscriptions'] === 'false' && $spento['duration'] === 'once' && $spento['duration_months'] === 0
        && $sempre['duration'] === 'forever' && $sempre['duration_months'] === 0
        && $mesi['duration'] === 'repeating' && $mesi['duration_months'] === 4
        && $strano['duration'] === 'once';
}));

check('i campi degli abbonamenti nel coupon ci sono solo con la funzionalità', function () use ($conFunzionalita) {
    $chiavi = static fn (): array => array_map(static fn ($field): string => (string) $field->name, CouponResource::formSchema());
    $spenta = $conFunzionalita(['coupons' => true], $chiavi);
    $accesa = $conFunzionalita(['coupons' => true, 'subscriptions' => true], $chiavi);

    return !in_array('duration', $spenta, true) && in_array('duration', $accesa, true)
        && in_array('applies_subscriptions', $accesa, true) && in_array('duration_months', $accesa, true);
});

check('il messaggio della guardia conta gli abbonamenti in corso', fn () =>
    FeatureGuards::subscriptionsLabel(1) === 'c\'è 1 abbonamento in corso'
    && FeatureGuards::subscriptionsLabel(4) === 'ci sono 4 abbonamenti in corso'
);

check('spegnere altre funzionalità non tocca gli abbonamenti', function () {
    FeatureGuards::assertAllowed(['subscriptions' => false, 'coupons' => true], ['subscriptions' => false, 'coupons' => false]);

    return true;
});

check('un prezzo nuovo del repeater nasce col suo codice, uno vecchio tiene il suo', function () {
    $nuovo = PlanResource::prepareRepeaterRelationRow('prices', ['name' => 'Mensile'], ['name' => 'Mensile']);
    $vecchio = PlanResource::prepareRepeaterRelationRow('prices', ['name' => 'Annuale'], ['id' => 7], ['id' => 7, 'code' => 'ppr_abc1234']);
    $limite = PlanResource::prepareRepeaterRelationRow('features', ['feature_key' => 'max'], ['feature_key' => 'max']);

    return str_starts_with((string) $nuovo['code'], 'ppr_') && strlen((string) $nuovo['code']) > 4
        && $vecchio['code'] === 'ppr_abc1234'
        && !array_key_exists('code', $limite);
});

summary();
