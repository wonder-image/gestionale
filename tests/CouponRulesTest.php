<?php
/** php tests/CouponRulesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Promotions\CouponRules;

const ORA = '2026-10-05 12:00:00';

/** Un coupon del 20 % su tutto, che regge, con quello che serve in più o in meno. */
$coupon = static fn (array $in = []): array => $in + [
    'discount_type' => 'percent',
    'discount_value' => '20.00',
    'min_order_amount' => '0.00',
    'applies_to_all' => 'true',
    'exclude_discounted_products' => 'false',
    'first_order_only' => 'false',
    'usage_limit' => 0,
    'usage_limit_per_customer' => 0,
    'starts_at' => '2026-10-01 00:00:00',
    'ends_at' => '2026-10-31 23:59:59',
    'applies_online' => 'true',
    'applies_office' => 'false',
    'applies_pos' => 'false',
    'active' => 'true',
    'scope' => ['all' => true],
    'customers' => [],
    'used_total' => 0,
    'used_by_customer' => 0,
    'has_previous_orders' => false,
];

/** Una riga di prodotto del carrello. */
$riga = static fn (float $totale, array $in = []): array => $in + [
    'facts' => ['categories' => [], 'tags' => [], 'brand_id' => 0, 'model_id' => 1],
    'price_source' => 'base',
    'line_total' => $totale,
    'is_product' => true,
];

/** Un carrello online di un cliente registrato. */
$carrello = static fn (array $righe, array $in = []): array => $in + [
    'channel' => 'online',
    'customer_id' => 7,
    'email' => 'a@example.com',
    'has_manual_discount' => false,
    'lines' => $righe,
];

$motivo = static fn (array $esito): string => $esito['ok'] ? '' : (string) $esito['reason'];

check('percentuale: 20 su 100 fa 20,00 e le righe adatte sono quelle giuste', function () use ($coupon, $riga, $carrello) {
    $esito = CouponRules::check($coupon(), $carrello([$riga(60), $riga(40)]), ORA);

    return $esito['ok'] === true && $esito['amount'] === 20.0 && $esito['eligible'] === [0, 1] && $esito['eligible_total'] === 100.0;
});

check('importo: 30 su merce adatta di 20 fa 20,00', function () use ($coupon, $riga, $carrello) {
    $esito = CouponRules::check($coupon(['discount_type' => 'amount', 'discount_value' => '30.00']), $carrello([$riga(20)]), ORA);

    return $esito['ok'] === true && $esito['amount'] === 20.0;
});

check('percentuale oltre il 100 sconta tutta la merce adatta, e il 100 pure', function () use ($coupon, $riga, $carrello) {
    $oltre = CouponRules::check($coupon(['discount_value' => '150.00']), $carrello([$riga(80)]), ORA);
    $intera = CouponRules::check($coupon(['discount_value' => '100.00']), $carrello([$riga(80)]), ORA);

    return $oltre['amount'] === 80.0 && $intera['amount'] === 80.0;
});

check('il tipo store_credit non si usa nel carrello', fn () =>
    $motivo(CouponRules::check($coupon(['discount_type' => 'store_credit']), $carrello([$riga(50)]), ORA)) === 'inactive'
);

check('un coupon non attivo si rifiuta', fn () =>
    $motivo(CouponRules::check($coupon(['active' => 'false']), $carrello([$riga(50)]), ORA)) === 'inactive'
);

check('il canale non coperto si rifiuta', function () use ($coupon, $riga, $carrello, $motivo) {
    return $motivo(CouponRules::check($coupon(), $carrello([$riga(50)], ['channel' => 'pos']), ORA)) === 'channel'
        && $motivo(CouponRules::check($coupon(['applies_pos' => 'true']), $carrello([$riga(50)], ['channel' => 'pos']), ORA)) === '';
});

check('prima della partenza e dopo la fine, con i confini esatti', function () use ($coupon, $riga, $carrello, $motivo) {
    $c = $carrello([$riga(50)]);

    return $motivo(CouponRules::check($coupon(), $c, '2026-09-30 23:59:59')) === 'not_started'
        && $motivo(CouponRules::check($coupon(), $c, '2026-10-01 00:00:00')) === ''
        && $motivo(CouponRules::check($coupon(), $c, '2026-10-31 23:59:59')) === ''
        && $motivo(CouponRules::check($coupon(), $c, '2026-11-01 00:00:00')) === 'expired';
});

check('una fine mancante vuol dire senza fine', function () use ($coupon, $riga, $carrello, $motivo) {
    return $motivo(CouponRules::check($coupon(['ends_at' => null]), $carrello([$riga(50)]), '2030-01-01 00:00:00')) === ''
        && $motivo(CouponRules::check($coupon(['ends_at' => '0000-00-00 00:00:00', 'starts_at' => '0000-00-00 00:00:00']), $carrello([$riga(50)]), ORA)) === '';
});

check('uno sconto scritto a mano in testata blocca il coupon', fn () =>
    $motivo(CouponRules::check($coupon(), $carrello([$riga(50)], ['has_manual_discount' => true]), ORA)) === 'has_manual_discount'
);

check('un coupon riservato non è per gli altri, ospite compreso', function () use ($coupon, $riga, $carrello, $motivo) {
    $riservato = $coupon(['customers' => [3, 9]]);

    return $motivo(CouponRules::check($riservato, $carrello([$riga(50)], ['customer_id' => 7]), ORA)) === 'not_yours'
        && $motivo(CouponRules::check($riservato, $carrello([$riga(50)], ['customer_id' => 0]), ORA)) === 'not_yours'
        && $motivo(CouponRules::check($riservato, $carrello([$riga(50)], ['customer_id' => 9]), ORA)) === '';
});

check('il limite totale: esaurito a limite, libero un uso prima', function () use ($coupon, $riga, $carrello, $motivo) {
    $c = $carrello([$riga(50)]);

    return $motivo(CouponRules::check($coupon(['usage_limit' => 5, 'used_total' => 5]), $c, ORA)) === 'exhausted'
        && $motivo(CouponRules::check($coupon(['usage_limit' => 5, 'used_total' => 4]), $c, ORA)) === ''
        && $motivo(CouponRules::check($coupon(['usage_limit' => 0, 'used_total' => 999]), $c, ORA)) === '';
});

check('il limite per cliente: già usato a limite, libero un uso prima', function () use ($coupon, $riga, $carrello, $motivo) {
    $c = $carrello([$riga(50)]);

    return $motivo(CouponRules::check($coupon(['usage_limit_per_customer' => 1, 'used_by_customer' => 1]), $c, ORA)) === 'already_used'
        && $motivo(CouponRules::check($coupon(['usage_limit_per_customer' => 2, 'used_by_customer' => 1]), $c, ORA)) === '';
});

check('solo il primo ordine: chi ha già ordinato si rifiuta', function () use ($coupon, $riga, $carrello, $motivo) {
    $c = $carrello([$riga(50)]);

    return $motivo(CouponRules::check($coupon(['first_order_only' => 'true', 'has_previous_orders' => true]), $c, ORA)) === 'not_first_order'
        && $motivo(CouponRules::check($coupon(['first_order_only' => 'true', 'has_previous_orders' => false]), $c, ORA)) === ''
        && $motivo(CouponRules::check($coupon(['first_order_only' => 'false', 'has_previous_orders' => true]), $c, ORA)) === '';
});

check('nessuna riga adatta si rifiuta; righe non adatte non contano né per la base né per la spesa minima', function () use ($coupon, $riga, $carrello, $motivo) {
    $selezione = ['all' => false, 'categories' => [5], 'tags' => [], 'brands' => [], 'models' => [], 'excluded_models' => []];
    $dentro = $riga(30, ['facts' => ['categories' => [5], 'tags' => [], 'brand_id' => 0, 'model_id' => 1]]);
    $fuori = $riga(70, ['facts' => ['categories' => [9], 'tags' => [], 'brand_id' => 0, 'model_id' => 2]]);
    $nonProdotto = $riga(40, ['is_product' => false]);

    $nessuna = CouponRules::check($coupon(['scope' => $selezione]), $carrello([$fuori, $nonProdotto]), ORA);
    $una = CouponRules::check($coupon(['scope' => $selezione]), $carrello([$dentro, $fuori, $nonProdotto]), ORA);
    $minima = CouponRules::check($coupon(['scope' => $selezione, 'min_order_amount' => '50.00']), $carrello([$dentro, $fuori, $nonProdotto]), ORA);

    return $motivo($nessuna) === 'no_eligible_products'
        && $una['ok'] === true && $una['eligible'] === [0] && $una['eligible_total'] === 30.0 && $una['amount'] === 6.0
        && $motivo($minima) === 'min_order';
});

check('con exclude_discounted_products le righe non a prezzo base non sono adatte', function () use ($coupon, $riga, $carrello, $motivo) {
    $righe = [$riga(50, ['price_source' => 'campaign']), $riga(40, ['price_source' => 'sale']), $riga(10)];
    $esclude = CouponRules::check($coupon(['exclude_discounted_products' => 'true']), $carrello($righe), ORA);
    $include = CouponRules::check($coupon(['exclude_discounted_products' => 'false']), $carrello($righe), ORA);
    $soloScontate = CouponRules::check($coupon(['exclude_discounted_products' => 'true']), $carrello([$riga(50, ['price_source' => 'campaign'])]), ORA);

    return $esclude['eligible'] === [2] && $esclude['amount'] === 2.0
        && $include['eligible'] === [0, 1, 2] && $include['amount'] === 20.0
        && $motivo($soloScontate) === 'no_eligible_products';
});

check('la spesa minima: pari passa, un centesimo meno no', function () use ($coupon, $riga, $carrello, $motivo) {
    $c = $coupon(['min_order_amount' => '50.00']);

    return $motivo(CouponRules::check($c, $carrello([$riga(50.00)]), ORA)) === ''
        && $motivo(CouponRules::check($c, $carrello([$riga(49.99)]), ORA)) === 'min_order';
});

check('spedizione gratuita: sconto zero, ok anche senza righe adatte se la spesa minima regge', function () use ($coupon, $riga, $carrello, $motivo) {
    $selezione = ['all' => false, 'categories' => [5], 'tags' => [], 'brands' => [], 'models' => [], 'excluded_models' => []];
    $gratis = $coupon(['discount_type' => 'free_shipping', 'discount_value' => '0.00', 'scope' => $selezione, 'min_order_amount' => '50.00']);

    $ok = CouponRules::check($gratis, $carrello([$riga(60)]), ORA);
    $corta = CouponRules::check($gratis, $carrello([$riga(30)]), ORA);
    $senzaRighe = CouponRules::check($gratis, $carrello([]), ORA);

    return $ok['ok'] === true && $ok['amount'] === 0.0 && $ok['eligible_total'] === 60.0
        && $motivo($corta) === 'min_order'
        && $motivo($senzaRighe) === 'min_order';
});

check('l\'ordine dei controlli: vince il primo che fallisce', function () use ($coupon, $riga, $carrello, $motivo) {
    $tutto = $coupon([
        'active' => 'false', 'applies_online' => 'false', 'starts_at' => '2027-01-01 00:00:00',
        'customers' => [1], 'usage_limit' => 1, 'used_total' => 1, 'min_order_amount' => '999.00',
    ]);
    $c = $carrello([$riga(10)], ['has_manual_discount' => true]);

    $passi = [];

    foreach ([
        ['active', 'true'], ['applies_online', 'true'], ['starts_at', '2026-10-01 00:00:00'],
        ['has_manual_discount', null], ['customers', []], ['usage_limit', 0],
    ] as [$chiave, $valore]) {
        $passi[] = $motivo(CouponRules::check($tutto, $c, ORA));

        if ($chiave === 'has_manual_discount') {
            $c['has_manual_discount'] = false;
        } else {
            $tutto[$chiave] = $valore;
        }
    }

    $passi[] = $motivo(CouponRules::check($tutto, $c, ORA));

    return $passi === ['inactive', 'channel', 'not_started', 'has_manual_discount', 'not_yours', 'exhausted', 'min_order'];
});

check('un coupon che manca di campi non rompe niente', function () use ($riga, $carrello) {
    $esito = CouponRules::check([], $carrello([$riga(10)]), ORA);

    return $esito['ok'] === false && is_string($esito['reason']);
});

summary();
