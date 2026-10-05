<?php

namespace Wonder\Plugin\Gestionale\Support\Promotions;

/**
 * Il prezzo di campagna di **un** prodotto: tra le campagne che lo
 * riguardano sceglie quella che costa meno (§3 della spec di G6). Le campagne
 * non si cumulano: ne vince una sola, e a parità la più vecchia, così il
 * risultato non dipende dall'ordine in cui il database le restituisce.
 *
 * Classe pura: niente database, niente `date()`, niente eccezioni. Il
 * chiamante passa già solo le campagne in corso e che combaciano con il
 * prodotto. Uno sconto mai sotto zero: oltre il 100 % o oltre il prezzo il
 * prodotto è un omaggio, non un prezzo negativo.
 */
final class CampaignPrice
{
    /**
     * @param list<array{id: int, discount_type: string, discount_value: float|int|string, exclude_sale_products?: bool|int}> $campaigns
     * @return array{campaign_id: int, price: float, percent: float}|null
     */
    public static function best(array $campaigns, float $price, float $salePrice): ?array
    {
        if ($price <= 0.0) {
            return null;
        }

        $onSale = $salePrice > 0.0 && $salePrice < $price;
        $best = null;

        foreach ($campaigns as $campaign) {
            if (!empty($campaign['exclude_sale_products']) && $onSale) {
                continue;
            }

            $discounted = static::apply(
                (string) ($campaign['discount_type'] ?? ''),
                (float) ($campaign['discount_value'] ?? 0),
                $price
            );

            if ($discounted === null) {
                continue;
            }

            $id = (int) $campaign['id'];

            if ($best === null || $discounted < $best['price'] || ($discounted === $best['price'] && $id < $best['campaign_id'])) {
                $best = ['campaign_id' => $id, 'price' => $discounted];
            }
        }

        if ($best === null) {
            return null;
        }

        return $best + ['percent' => round(($price - $best['price']) / $price * 100, 2)];
    }

    /** Il prezzo scontato, o `null` se lo sconto non è uno sconto. */
    private static function apply(string $type, float $value, float $price): ?float
    {
        if ($value <= 0.0) {
            return null;
        }

        return match ($type) {
            'percent' => round($price * (1 - min($value, 100.0) / 100), 2),
            'amount' => round(max(0.0, $price - $value), 2),
            default => null,
        };
    }
}
