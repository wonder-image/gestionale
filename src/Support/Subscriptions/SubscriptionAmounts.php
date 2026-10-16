<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use Wonder\Plugin\Gestionale\Support\Pricing\LinePrice;
use Wonder\Plugin\Gestionale\Support\Pricing\OrderTotals;

/**
 * Gli importi di un periodo di un abbonamento (G10 §1.3), puri.
 *
 * Un periodo si calcola come un ordine con le righe dell'abbonamento (G10b;
 * una sola finché c'è un servizio solo): il prezzo del piano
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
     * Una riga sola: il prezzo del piano, quantità 1, senza sovrapprezzo.
     *
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
        $amounts = static::ofItems(
            [['price' => $planPrice, 'quantity' => 1, 'surcharge' => 0.0, 'tax_rate' => $taxRate]],
            $coupon,
            $pricesIncludeTax,
            $charge,
            (int) ($planPrice['term_months'] ?? 1)
        );

        return [
            'list_price' => $amounts['lines'][0]['list_price'],
            'unit_price' => $amounts['lines'][0]['unit_price'],
        ] + array_intersect_key($amounts, array_flip(['subtotal', 'discount_total', 'taxable_total', 'tax_total', 'total', 'coupon_applied']));
    }

    /**
     * Le righe di un abbonamento (G10b): ognuna passa da `LinePrice` con la
     * sua quantità, il suo sovrapprezzo e la sua aliquota; i totali e il
     * coupon (sul totale delle righe) da `OrderTotals`. `$termMonths` è la
     * durata più corta dell'abbonamento, per `couponApplies()`.
     *
     * @param list<array{price: array<string, mixed>, quantity: int, surcharge: float, tax_rate: float}> $lines
     * @param array<string, mixed>|null $coupon
     * @return array{subtotal: string, discount_total: string, taxable_total: string, tax_total: string, total: string, coupon_applied: bool, tax_summaries: list<array<string, mixed>>, lines: list<array{list_price: string, unit_price: string, customization_total: string, line_total: string, tax_rate: float}>}
     */
    public static function ofItems(array $lines, ?array $coupon, bool $pricesIncludeTax, int $charge, int $termMonths): array
    {
        $applied = $coupon !== null && static::couponApplies($coupon, $charge, $termMonths);
        $context = ['prices_include_tax' => $pricesIncludeTax];

        if ($applied) {
            $context['discount_type'] = (string) ($coupon['discount_type'] ?? 'none');
            $context['discount_value'] = (float) ($coupon['discount_value'] ?? 0);
        }

        $priced = [];
        $out = [];

        foreach ($lines as $line) {
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $surcharge = max(0.0, (float) ($line['surcharge'] ?? 0));
            $taxRate = (float) ($line['tax_rate'] ?? 0);
            $price = LinePrice::of([
                'quantity' => $quantity,
                'price' => $line['price']['price'] ?? 0,
                'sale_price' => $line['price']['sale_price'] ?? 0,
                'customization_surcharge' => $surcharge,
            ]);
            $priced[] = ['type' => 'product', 'tax_rate' => $taxRate, 'tax_nature' => ''] + $price;
            $out[] = [
                'list_price' => $price['list_price'],
                'unit_price' => $price['unit_price'],
                'customization_total' => number_format(round($surcharge * $quantity, 2), 2, '.', ''),
                'line_total' => $price['line_total'],
                'tax_rate' => $taxRate,
            ];
        }

        $totals = OrderTotals::of($priced, $context);

        return [
            'subtotal' => $totals['products_total'],
            'discount_total' => $totals['discount_total'],
            'taxable_total' => $totals['taxable_total'],
            'tax_total' => $totals['tax_total'],
            'total' => $totals['total'],
            'coupon_applied' => $applied && (float) $totals['discount_total'] > 0.0,
            'tax_summaries' => $totals['tax_summaries'],
            'lines' => $out,
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
