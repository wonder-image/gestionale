<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanFeature;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;

/**
 * Letture degli abbonamenti che servono al resto del gestionale e al sito.
 *
 * `limit()` è il punto d'ingresso dei limiti del piano (G10.7): il sito lo
 * chiama quando un cliente crea qualcosa che il piano limita. Un piano più
 * basso non toglie niente: il controllo vale solo alla creazione.
 */
final class Subscriptions
{
    /**
     * L'abbonamento vivo del cliente (`trialing`, `active`, `past_due`), il più
     * recente se ce n'è più d'uno; `null` senza.
     *
     * @return array<string, mixed>|null
     */
    public static function live(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }

        $rows = self::rows(Subscription::find(
            '`customer_id` = '.$customerId." AND `status` IN ('".implode("','", Subscription::LIVE)."') AND `deleted` = 'false'",
            null,
            'id',
            'DESC'
        ));

        return $rows[0] ?? null;
    }

    /**
     * Il valore del limite `$key` dal piano dell'abbonamento vivo del cliente;
     * `null` se il cliente non ha un abbonamento vivo o il piano non dichiara
     * quel limite. Il valore è il testo scritto nel piano: lo interpreta il
     * sito (`5`, `illimitato`).
     */
    public static function limit(int $customerId, string $key): ?string
    {
        $subscription = self::live($customerId);
        $key = trim($key);

        if ($subscription === null || $key === '') {
            return null;
        }

        $price = PlanPrice::findById((int) ($subscription['plan_price_id'] ?? 0));
        $planId = is_array($price) ? (int) ($price['plan_id'] ?? 0) : 0;

        if ($planId <= 0) {
            return null;
        }

        $feature = self::rows(PlanFeature::find(['plan_id' => $planId, 'feature_key' => $key, 'deleted' => 'false'], 1))[0] ?? null;

        return $feature === null ? null : (string) ($feature['feature_value'] ?? '');
    }

    /** Quanti abbonamenti vivi in tutto, o su quei prezzi. @param list<int>|null $priceIds */
    public static function liveCount(?array $priceIds = null): int
    {
        $where = "`status` IN ('".implode("','", Subscription::LIVE)."') AND `deleted` = 'false'";

        if ($priceIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $priceIds), static fn (int $id): bool => $id > 0));

            if ($ids === []) {
                return 0;
            }

            $where .= ' AND `plan_price_id` IN ('.implode(',', $ids).')';
        }

        try {
            return (int) sqlCount(Subscription::$table, $where);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * I prezzi, fra quelli dati, usati da almeno un abbonamento, anche chiuso:
     * un abbonamento chiuso è storia, e il suo prezzo deve restare leggibile.
     *
     * @param list<int> $priceIds
     * @return list<int>
     */
    public static function usedPrices(array $priceIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $priceIds), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        $rows = self::rows(Subscription::find(
            '`plan_price_id` IN ('.implode(',', $ids).") AND `deleted` = 'false'",
            null,
            null,
            null,
            'DISTINCT plan_price_id'
        ));

        return array_values(array_map(static fn (array $row): int => (int) $row['plan_price_id'], $rows));
    }

    /** @return list<int> gli id dei prezzi di un piano, anche spenti */
    public static function priceIds(int $planId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            self::rows(PlanPrice::find(['plan_id' => $planId, 'deleted' => 'false']))
        );
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return isset($found['id']) || isset($found['plan_price_id']) ? [$found] : array_values(array_filter($found, 'is_array'));
    }
}
