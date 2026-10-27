<?php

namespace Wonder\Plugin\Gestionale\Support\Tax;

/**
 * Spedizione e commissione prendono l'aliquota della merce (art. 12 DPR 633/72).
 *
 * Una sola aliquota fra le merci: la spesa la prende tutta. Più aliquote: la
 * spesa si divide in proporzione all'imponibile di ognuna, e l'ultima quota
 * prende il resto perché la somma faccia esattamente la spesa. Con imponibile
 * zero (merce tutta regalata) la spesa va all'aliquota più alta.
 *
 * Classe pura: le righe di merce arrivano già prezzate e con lo sconto sul
 * totale ripartito (`OrderTotals`).
 */
final class ChargeSplit
{
    /**
     * Le aliquote della merce, una per `tax_id`, dalla più pesante.
     *
     * @param list<array<string, mixed>> $goods righe con `tax_id`, `tax_rate`, `tax_nature`, `line_total`, `order_discount_amount`
     * @return list<array{tax_id: int, rate: float, nature: string, base: float}>
     */
    public static function groups(array $goods, bool $pricesIncludeTax): array
    {
        $groups = [];

        foreach ($goods as $line) {
            $taxId = (int) ($line['tax_id'] ?? 0);

            if ($taxId <= 0) {
                continue;
            }

            $rate = round((float) ($line['tax_rate'] ?? 0), 2);
            $paid = round((float) ($line['line_total'] ?? 0) - (float) ($line['order_discount_amount'] ?? 0), 2);
            // L'imponibile: con i prezzi IVA inclusa si scorpora.
            $base = $pricesIncludeTax ? $paid / (1 + $rate / 100) : $paid;

            $groups[$taxId] ??= ['tax_id' => $taxId, 'rate' => $rate, 'nature' => (string) ($line['tax_nature'] ?? ''), 'base' => 0.0];
            $groups[$taxId]['base'] += max(0.0, $base);
        }

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => [$b['base'], $a['tax_id']] <=> [$a['base'], $b['tax_id']]);

        return $groups;
    }

    /**
     * La spesa divisa sulle aliquote; vuota senza merce.
     *
     * @param list<array{tax_id: int, rate: float, nature: string, base: float}> $groups da `groups()`
     * @return list<array{tax_id: int, rate: float, nature: string, amount: float}>
     */
    public static function shares(float $amount, array $groups): array
    {
        if ($groups === []) {
            return [];
        }

        $amount = round($amount, 2);
        $total = array_sum(array_column($groups, 'base'));

        if ($total <= 0.0 || $amount === 0.0) {
            $top = $groups[0];

            foreach ($groups as $group) {
                if ($group['rate'] > $top['rate']) {
                    $top = $group;
                }
            }

            return [self::share($top, $amount)];
        }

        $shares = [];
        $assigned = 0.0;
        $last = count($groups) - 1;

        foreach ($groups as $index => $group) {
            $share = $index === $last
                ? round($amount - $assigned, 2)
                : round($amount * $group['base'] / $total, 2);
            $assigned = round($assigned + $share, 2);

            if ($share !== 0.0 || $index === $last) {
                $shares[] = self::share($group, $share);
            }
        }

        return $shares;
    }

    /**
     * @param array{tax_id: int, rate: float, nature: string, base: float} $group
     * @return array{tax_id: int, rate: float, nature: string, amount: float}
     */
    private static function share(array $group, float $amount): array
    {
        return ['tax_id' => $group['tax_id'], 'rate' => $group['rate'], 'nature' => $group['nature'], 'amount' => $amount];
    }
}
