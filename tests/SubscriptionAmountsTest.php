<?php
/** php tests/SubscriptionAmountsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionAmounts;

$mensile = ['price' => '24.40', 'sale_price' => '', 'term_months' => 1];
$annuale = ['price' => '244.00', 'sale_price' => '', 'term_months' => 12];
$coupon = static fn (array $sopra = []): array => $sopra + [
    'applies_subscriptions' => 'true', 'discount_type' => 'percent', 'discount_value' => '50.00',
    'duration' => 'once', 'duration_months' => 0,
];

check('IVA inclusa: 24,40 sono 20,00 più 4,40 di imposta', function () use ($mensile) {
    $a = SubscriptionAmounts::of($mensile, null, 22.0, true);

    return $a['subtotal'] === '24.40' && $a['discount_total'] === '0.00'
        && $a['taxable_total'] === '20.00' && $a['tax_total'] === '4.40' && $a['total'] === '24.40'
        && $a['coupon_applied'] === false;
});

check('IVA esclusa: 20,00 più il 22% fanno 24,40', function () {
    $a = SubscriptionAmounts::of(['price' => '20.00', 'term_months' => 1], null, 22.0, false);

    return $a['subtotal'] === '20.00' && $a['taxable_total'] === '20.00' && $a['tax_total'] === '4.40' && $a['total'] === '24.40';
});

check('il prezzo scontato vince sul prezzo, se più basso', function () use ($mensile) {
    $a = SubscriptionAmounts::of(['sale_price' => '12.20'] + $mensile, null, 22.0, true);

    return $a['list_price'] === '24.40' && $a['unit_price'] === '12.20' && $a['total'] === '12.20';
});

check('un coupon del 50% una volta: sul primo addebito sì, sul secondo no', function () use ($mensile, $coupon) {
    $primo = SubscriptionAmounts::of($mensile, $coupon(), 22.0, true, 0);
    $secondo = SubscriptionAmounts::of($mensile, $coupon(), 22.0, true, 1);

    return $primo['discount_total'] === '12.20' && $primo['total'] === '12.20' && $primo['tax_total'] === '2.20'
        && $primo['coupon_applied'] === true
        && $secondo['discount_total'] === '0.00' && $secondo['total'] === '24.40' && $secondo['coupon_applied'] === false;
});

check('un coupon in importo non va sotto zero', function () use ($mensile, $coupon) {
    $a = SubscriptionAmounts::of($mensile, $coupon(['discount_type' => 'amount', 'discount_value' => '100']), 22.0, true);

    return $a['discount_total'] === '24.40' && $a['total'] === '0.00';
});

check('per tre mesi: mensile sui primi tre addebiti, annuale solo sul primo', fn () =>
    SubscriptionAmounts::couponApplies($coupon(['duration' => 'repeating', 'duration_months' => 3]), 0, 1)
    && SubscriptionAmounts::couponApplies($coupon(['duration' => 'repeating', 'duration_months' => 3]), 2, 1)
    && !SubscriptionAmounts::couponApplies($coupon(['duration' => 'repeating', 'duration_months' => 3]), 3, 1)
    && SubscriptionAmounts::couponApplies($coupon(['duration' => 'repeating', 'duration_months' => 3]), 0, 12)
    && !SubscriptionAmounts::couponApplies($coupon(['duration' => 'repeating', 'duration_months' => 3]), 1, 12)
    && !SubscriptionAmounts::couponApplies($coupon(['duration' => 'repeating', 'duration_months' => 0]), 0, 1)
);

check('per sempre vale a ogni addebito; una volta solo al primo', fn () =>
    SubscriptionAmounts::couponApplies($coupon(['duration' => 'forever']), 40, 1)
    && SubscriptionAmounts::couponApplies($coupon(), 0, 12)
    && !SubscriptionAmounts::couponApplies($coupon(), 1, 12)
);

check('senza «vale per gli abbonamenti», o di spedizione gratuita, non vale', fn () =>
    !SubscriptionAmounts::couponApplies($coupon(['applies_subscriptions' => 'false']), 0, 1)
    && !SubscriptionAmounts::couponApplies($coupon(['discount_type' => 'free_shipping']), 0, 1)
    && !SubscriptionAmounts::couponApplies($coupon(), -1, 1)
);

check('il coupon non applicato non sconta neanche l\'annuale', function () use ($annuale, $coupon) {
    $a = SubscriptionAmounts::of($annuale, $coupon(['applies_subscriptions' => 'false']), 22.0, true);

    return $a['total'] === '244.00' && $a['taxable_total'] === '200.00';
});

summary();
