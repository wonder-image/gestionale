<?php
/** php tests/StripeCatalogTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';
require __DIR__ . '/supporto/FakeSubscriptionGateway.php';

use Wonder\Plugin\Gestionale\Support\Subscriptions\StripeCatalog;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionGateways;

$mensile = ['id' => 7, 'code' => 'ppr_abc1234', 'name' => 'Mensile', 'price' => '24.90', 'sale_price' => '0.00', 'billing_interval' => 'month', 'term_months' => 1];

check('il prezzo in centesimi: lo scontato se più basso', fn () =>
    StripeCatalog::cents($mensile) === 2490
    && StripeCatalog::cents(['sale_price' => '19.90'] + $mensile) === 1990
    && StripeCatalog::cents(['sale_price' => '30.00'] + $mensile) === 2490
);

check('l\'intervallo: mesi per il mensile, anni per l\'annuale', fn () =>
    StripeCatalog::recurring($mensile) === ['month', 1]
    && StripeCatalog::recurring(['term_months' => 3] + $mensile) === ['month', 3]
    && StripeCatalog::recurring(['billing_interval' => 'year', 'term_months' => 12] + $mensile) === ['year', 1]
    && StripeCatalog::recurring(['billing_interval' => 'year', 'term_months' => 24] + $mensile) === ['year', 2]
);

check('l\'impronta cambia con importo e intervallo, non con il nome', fn () =>
    StripeCatalog::fingerprint($mensile) === '2490-month-1-eur'
    && StripeCatalog::fingerprint(['name' => 'Altro nome'] + $mensile) === '2490-month-1-eur'
    && StripeCatalog::fingerprint(['price' => '29.90'] + $mensile) === '2990-month-1-eur'
    && StripeCatalog::fingerprint(['billing_interval' => 'year', 'term_months' => 12] + $mensile) === '2490-year-1-eur'
);

check('il prezzo Stripe è ricorrente, in euro, con il codice del prezzo nei metadati', function () use ($mensile) {
    $p = StripeCatalog::priceParams($mensile, 'prod_1');

    return $p['product'] === 'prod_1' && $p['unit_amount'] === 2490 && $p['currency'] === 'eur'
        && $p['recurring'] === ['interval' => 'month', 'interval_count' => 1]
        && $p['metadata'] === ['plan_price_id' => '7', 'plan_price_code' => 'ppr_abc1234'];
});

check('il prodotto prende nome e descrizione in testo semplice', function () {
    $p = StripeCatalog::productParams(['id' => 3, 'code' => 'pln_x', 'name' => 'Pro', 'description' => '<p>Per chi lavora &amp; cresce</p>']);
    $vuota = StripeCatalog::productParams(['id' => 3, 'code' => 'pln_x', 'name' => 'Pro', 'description' => '']);

    return $p['name'] === 'Pro' && $p['description'] === 'Per chi lavora & cresce' && !array_key_exists('description', $vuota);
});

check('l\'aliquota: italiana, inclusa o esclusa', fn () =>
    StripeCatalog::taxRateParams(22.0, true) === ['display_name' => 'IVA', 'percentage' => 22.0, 'inclusive' => true, 'country' => 'IT', 'jurisdiction' => 'IT']
    && StripeCatalog::taxRateParams(22.0, false)['inclusive'] === false
);

check('il coupon: percentuale o importo in centesimi, con la durata', function () {
    $percento = StripeCatalog::couponParams(['id' => 2, 'code' => 'ABB20', 'discount_type' => 'percent', 'discount_value' => '20.00', 'duration' => 'repeating', 'duration_months' => 3]);
    $importo = StripeCatalog::couponParams(['id' => 2, 'code' => 'ABB5', 'discount_type' => 'amount', 'discount_value' => '5.00', 'duration' => 'forever']);
    $strano = StripeCatalog::couponParams(['id' => 2, 'code' => 'X', 'discount_type' => 'percent', 'discount_value' => '10', 'duration' => 'mai']);

    return $percento['percent_off'] === 20.0 && $percento['duration'] === 'repeating' && $percento['duration_in_months'] === 3
        && $importo['amount_off'] === 500 && $importo['currency'] === 'eur' && $importo['duration'] === 'forever' && !isset($importo['duration_in_months'])
        && $strano['duration'] === 'once';
});

check('l\'impronta del coupon cambia con tipo, valore e durata', fn () =>
    StripeCatalog::couponFingerprint(['discount_type' => 'percent', 'discount_value' => '20', 'duration' => 'repeating', 'duration_months' => 3]) === 'percent-20.00-repeating-3'
    && StripeCatalog::couponFingerprint(['discount_type' => 'percent', 'discount_value' => '20', 'duration' => 'once', 'duration_months' => 3]) === 'percent-20.00-once-0'
);

check('il codice promozionale porta limite e scadenza, solo se ci sono', function () {
    $pieno = StripeCatalog::promotionCodeParams(['code' => 'ABB20', 'usage_limit' => 50, 'ends_at' => date('Y-m-d H:i:s', time() + 86400)], 'coup_1');
    $vuoto = StripeCatalog::promotionCodeParams(['code' => 'ABB20', 'usage_limit' => 0, 'ends_at' => '0000-00-00 00:00:00'], 'coup_1');
    $passato = StripeCatalog::promotionCodeParams(['code' => 'ABB20', 'ends_at' => '2020-01-01 23:59:59'], 'coup_1');

    return $pieno['coupon'] === 'coup_1' && $pieno['code'] === 'ABB20' && $pieno['max_redemptions'] === 50 && $pieno['expires_at'] > time()
        && $vuoto === ['coupon' => 'coup_1', 'code' => 'ABB20']
        && !isset($passato['expires_at']);
});

check('il gateway finto si mette e si toglie', function () {
    $finto = new FakeSubscriptionGateway('test');
    SubscriptionGateways::use($finto);
    $preso = SubscriptionGateways::get('live');
    SubscriptionGateways::use(null);

    return $preso === $finto;
});

check('il finto rispetta le chiavi di idempotenza, come Stripe', function () {
    $finto = new FakeSubscriptionGateway();
    $a = $finto->createPrice(['unit_amount' => 100], 'k1');
    $b = $finto->createPrice(['unit_amount' => 100], 'k1');
    $c = $finto->createPrice(['unit_amount' => 100], 'k2');

    return $a['id'] === $b['id'] && $a['id'] !== $c['id'] && $finto->count('price.create') === 3;
});

check('con il sovrapprezzo delle personalizzazioni: importo più alto, senza sovrapprezzo l\'impronta di prima', fn () =>
    StripeCatalog::cents($mensile, 2.0) === 2690
    && StripeCatalog::fingerprint($mensile, 0.0, 0) === '2490-month-1-eur'
    && StripeCatalog::fingerprint(['billing_interval' => 'year', 'term_months' => 12] + $mensile, 2.0) === '2690-year-1-eur'
    && StripeCatalog::fingerprint(['sale_price' => '19.90'] + $mensile, 1.5) === '2140-month-1-eur'
);

check('due righe uguali: posti 0 e 1, impronte diverse (Stripe non vuole lo stesso prezzo due volte)', function () use ($mensile) {
    $slots = StripeCatalog::slots([
        ['plan_price_id' => 7, 'surcharge' => 0.0],
        ['plan_price_id' => 7, 'surcharge' => 0.0],
        ['plan_price_id' => 7, 'surcharge' => 2.0],
        ['plan_price_id' => 8, 'surcharge' => 0.0],
        ['plan_price_id' => 7, 'surcharge' => 0.0],
    ]);

    return $slots === [0, 1, 0, 0, 2]
        && StripeCatalog::fingerprint($mensile, 0.0, 1) === '2490-month-1-eur-s1'
        && StripeCatalog::fingerprint($mensile, 0.0, 1) !== StripeCatalog::fingerprint($mensile);
});

check('il prezzo Stripe col sovrapprezzo: importo con il sovrapprezzo, che resta nei metadati', function () use ($mensile) {
    $p = StripeCatalog::priceParams($mensile, 'prod_1', 2.0);

    return $p['unit_amount'] === 2690
        && $p['metadata'] === ['plan_price_id' => '7', 'plan_price_code' => 'ppr_abc1234', 'surcharge' => '2.00'];
});

summary();
