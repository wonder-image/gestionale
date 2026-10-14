<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use Wonder\Plugin\Gestionale\Support\Pricing\LinePrice;
use Wonder\Plugin\Gestionale\Support\Pricing\OrderTotals;

/**
 * Gli importi di un periodo di un abbonamento (G10 §1.3), puri.
 *
 * Un periodo si calcola come un ordine con una riga sola: il prezzo del piano
 * (scontato, se il prezzo scontato è più basso) passa da `LinePrice`, il coupon
 * è lo sconto sul totale di `OrderTotals`, l'imposta segue IVA inclusa o
 * esclusa come il listino base (G10.4). Così gli arrotondamenti sono gli
 * stessi degli ordini.
 *
 * Il coupon vale solo se `couponApplies()` dice che vale in quell'addebito:
 * chi chiama passa il numero dell'addebito, da zero.
 */
final class SubscriptionAmounts
{
    /**
     * @param array<string, mixed> $planPrice una riga di `gst_plan_prices`
     * @param array<string, mixed>|null $coupon una riga di `gst_coupons`, o niente
     * @return array{list_price: string, unit_price: string, subtotal: string, discount_total: string, taxable_total: string, tax_total: string, total: string, coupon_applied: bool}
     */
    public static function of(
        array $planPrice,
        ?array $coupon,
        float $taxRate,
        bool $pricesIncludeTax,
        int $charge = 0
    ): array {
        $line = LinePrice::of([
            'quantity' => 1,
            'price' => $planPrice['price'] ?? 0,
            'sale_price' => $planPrice['sale_price'] ?? 0,
        ]);

        $applied = $coupon !== null && static::couponApplies($coupon, $charge, (int) ($planPrice['term_months'] ?? 1));
        $context = ['prices_include_tax' => $pricesIncludeTax];

        if ($applied) {
            $context['discount_type'] = (string) ($coupon['discount_type'] ?? 'none');
            $context['discount_value'] = (float) ($coupon['discount_value'] ?? 0);
        }

        $totals = OrderTotals::of([[
            'type' => 'product',
            'tax_rate' => $taxRate,
            'tax_nature' => '',
        ] + $line], $context);

        return [
            'list_price' => $line['list_price'],
            'unit_price' => $line['unit_price'],
            'subtotal' => $totals['products_total'],
            'discount_total' => $totals['discount_total'],
            'taxable_total' => $totals['taxable_total'],
            'tax_total' => $totals['tax_total'],
            'total' => $totals['total'],
            'coupon_applied' => $applied && (float) $totals['discount_total'] > 0.0,
        ];
    }

    /**
     * Il coupon vale nell'addebito numero `$charge` (da zero)?
     *
     * - `once`: solo il primo addebito;
     * - `repeating`: finché i mesi già coperti dagli addebiti precedenti
     *   (`$charge × $termMonths`) sono meno di `duration_months`. Un annuale con
     *   un coupon di tre mesi lo prende solo sul primo addebito, come fa Stripe;
     * - `forever`: sempre.
     *
     * Vale solo con `applies_subscriptions` e uno sconto in percentuale o in
     * importo: la spedizione gratuita non ha senso su un abbonamento.
     *
     * @param array<string, mixed> $coupon
     */
    public static function couponApplies(array $coupon, int $charge, int $termMonths): bool
    {
        if ((string) ($coupon['applies_subscriptions'] ?? 'false') !== 'true'
            || !in_array((string) ($coupon['discount_type'] ?? ''), ['percent', 'amount'], true)
            || $charge < 0) {
            return false;
        }

        return match ((string) ($coupon['duration'] ?? 'once')) {
            'forever' => true,
            'repeating' => $charge * max(1, $termMonths) < (int) ($coupon['duration_months'] ?? 0),
            default => $charge === 0,
        };
    }
}
