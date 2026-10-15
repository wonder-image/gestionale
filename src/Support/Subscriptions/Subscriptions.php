<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use DateTimeImmutable;
use Throwable;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanFeature;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItem;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * Letture degli abbonamenti che servono al resto del gestionale e al sito.
 *
 * `limit()` è il punto d'ingresso dei limiti del piano (G10.7, sommati su
 * tutte le righe con G10b): il sito lo chiama quando un cliente crea qualcosa
 * che il piano limita. Un piano più
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
     * Quanti abbonamenti in corso può avere un cliente (G10b.12): 0 è senza
     * limite; senza impostazioni vale 1.
     */
    public static function perCustomer(): int
    {
        try {
            $setting = Setting::current();
        } catch (Throwable) {
            return 1;
        }

        $value = is_array($setting) ? ($setting['subscriptions_per_customer'] ?? null) : null;

        return $value === null || $value === '' ? 1 : max(0, (int) $value);
    }

    /**
     * Rifiuta un abbonamento nuovo se il cliente è già al limite
     * (`subscription.limit_reached`, col codice del suo abbonamento più
     * recente). Lo chiamano l'avvio online e «Nuovo abbonamento» da ufficio.
     *
     * @throws UserError
     */
    public static function assertRoom(int $customerId): void
    {
        $limit = self::perCustomer();

        if ($limit === 0 || $customerId <= 0) {
            return;
        }

        $rows = self::rows(Subscription::find(
            '`customer_id` = '.$customerId." AND `status` IN ('".implode("','", [...Subscription::LIVE, 'pending'])."') AND `deleted` = 'false'",
            null,
            'id',
            'DESC'
        ));
        $now = new DateTimeImmutable();
        $counted = array_values(array_filter($rows, static fn (array $row): bool => self::countsFor([$row], $now) === 1));

        if (count($counted) >= $limit) {
            throw UserError::make('subscription.limit_reached', ['code' => (string) ($counted[0]['code'] ?? '')]);
        }
    }

    /**
     * Quanti di questi abbonamenti contano per il limite per cliente: gli stati
     * vivi e i `pending`, tranne i `pending` online creati da più di 24 ore
     * (un Checkout abbandonato non blocca il cliente).
     *
     * @param list<array<string, mixed>> $rows status, channel, creation
     */
    public static function countsFor(array $rows, DateTimeImmutable $now): int
    {
        $limit = $now->modify('-24 hours');
        $count = 0;

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');

            if (in_array($status, Subscription::LIVE, true)) {
                $count++;
            } elseif ($status === 'pending') {
                $created = trim((string) ($row['creation'] ?? ''));
                $stale = (string) ($row['channel'] ?? '') === 'online' && $created !== '' && !str_starts_with($created, '0000')
                    && new DateTimeImmutable($created) < $limit;
                $count += $stale ? 0 : 1;
            }
        }

        return $count;
    }

    /**
     * Il valore del limite `$key` per il cliente: i piani di **tutte** le righe
     * vive dei suoi abbonamenti vivi, messi insieme da `PlanFeatureMerge`
     * (numeri sommati per quantità, «illimitato» vince). `null` se nessuna
     * riga viva ha un piano che dichiara quel limite. Il valore è testo: lo
     * interpreta il sito (`5`, `illimitato`).
     */
    public static function limit(int $customerId, string $key): ?string
    {
        $key = trim($key);

        if ($customerId <= 0 || $key === '') {
            return null;
        }

        $items = self::rows(SubscriptionItem::find(
            "`status` IN ('".implode("','", SubscriptionItem::LIVE)."') AND `deleted` = 'false'"
                .' AND `subscription_id` IN (SELECT `id` FROM `'.Subscription::$table.'` WHERE `customer_id` = '.$customerId
                ." AND `status` IN ('".implode("','", Subscription::LIVE)."') AND `deleted` = 'false')"
        ));
        $values = [];
        $plans = [];

        foreach ($items as $item) {
            $priceId = (int) ($item['plan_price_id'] ?? 0);

            if (!array_key_exists($priceId, $plans)) {
                $price = $priceId > 0 ? PlanPrice::findById($priceId) : null;
                $planId = is_array($price) ? (int) ($price['plan_id'] ?? 0) : 0;
                $feature = $planId > 0 ? (self::rows(PlanFeature::find(['plan_id' => $planId, 'feature_key' => $key, 'deleted' => 'false'], 1))[0] ?? null) : null;
                $plans[$priceId] = $feature === null ? null : (string) ($feature['feature_value'] ?? '');
            }

            if ($plans[$priceId] !== null) {
                $values[] = [
                    'value' => $plans[$priceId],
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'at' => trim((string) ($item['starts_at'] ?? '')) !== '' && !str_starts_with((string) $item['starts_at'], '0000')
                        ? (string) $item['starts_at'] : (string) ($item['creation'] ?? ''),
                ];
            }
        }

        return PlanFeatureMerge::merge($values);
    }

    /**
     * Quanti abbonamenti vivi in tutto; con `$priceIds`, quante righe vive di
     * abbonamenti vivi su quei prezzi.
     *
     * @param list<int>|null $priceIds
     */
    public static function liveCount(?array $priceIds = null): int
    {
        $table = Subscription::$table;
        $where = "`status` IN ('".implode("','", Subscription::LIVE)."') AND `deleted` = 'false'";

        if ($priceIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $priceIds), static fn (int $id): bool => $id > 0));

            if ($ids === []) {
                return 0;
            }

            $table = SubscriptionItem::$table;
            $where = SubscriptionItems::liveWhere().' AND `plan_price_id` IN ('.implode(',', $ids).')';
        }

        try {
            return (int) sqlCount($table, $where);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * I prezzi, fra quelli dati, usati da almeno una riga d'abbonamento, anche chiusa:
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

        $rows = self::rows(SubscriptionItem::find(
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
