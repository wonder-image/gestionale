<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use DateTimeImmutable;
use RuntimeException;
use Throwable;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItem;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItemChange;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;

/**
 * Le righe di un abbonamento (G10b): letture, la riga che nasce con
 * l'abbonamento, il cambio di prezzo e lo stato che segue quello
 * dell'abbonamento.
 *
 * La **riga principale** è la prima non chiusa (per `position`), altrimenti
 * l'ultima: finché un abbonamento ha un servizio solo, il suo prezzo è quello.
 */
final class SubscriptionItems
{
    /**
     * Le righe dell'abbonamento, ordinate per `position`; con `$statuses` solo
     * quelle in quegli stati.
     *
     * @param list<string>|null $statuses
     * @return list<array<string, mixed>>
     */
    public static function forSubscription(int $subscriptionId, ?array $statuses = null): array
    {
        if ($subscriptionId <= 0) {
            return [];
        }

        $where = '`subscription_id` = '.$subscriptionId." AND `deleted` = 'false'";

        if ($statuses !== null) {
            $statuses = array_values(array_intersect($statuses, SubscriptionItem::STATUSES));

            if ($statuses === []) {
                return [];
            }

            $where .= " AND `status` IN ('".implode("','", $statuses)."')";
        }

        try {
            $found = SubscriptionItem::find($where, null, 'position', 'ASC');
        } catch (Throwable) {
            return [];
        }

        $rows = !is_array($found) || $found === [] ? [] : (isset($found['id']) ? [$found] : array_values(array_filter($found, 'is_array')));
        // A parità di posizione vale l'ordine di creazione.
        usort($rows, static fn (array $a, array $b): int => [(int) $a['position'], (int) $a['id']] <=> [(int) $b['position'], (int) $b['id']]);

        return $rows;
    }

    /**
     * La riga principale: la prima non chiusa, altrimenti l'ultima; `null` se
     * l'abbonamento non ha righe.
     *
     * @return array<string, mixed>|null
     */
    public static function main(int $subscriptionId): ?array
    {
        $rows = self::forSubscription($subscriptionId);

        foreach ($rows as $row) {
            if ((string) $row['status'] !== 'ended') {
                return $row;
            }
        }

        return $rows === [] ? null : $rows[array_key_last($rows)];
    }

    /** Il prezzo della riga principale; 0 senza righe. @param int|array<string, mixed> $subscription */
    public static function priceIdOf(int|array $subscription): int
    {
        $id = is_array($subscription) ? (int) ($subscription['id'] ?? 0) : $subscription;

        return (int) (self::main($id)['plan_price_id'] ?? 0);
    }

    /**
     * «Piano - Prezzo», più le personalizzazioni se ci sono: «Dominio - Annuale ·
     * Nome del dominio: esempio.it». Testo semplice: chi stampa lo escapa.
     *
     * @param array<string, mixed> $item
     */
    public static function label(array $item): string
    {
        $price = (int) ($item['plan_price_id'] ?? 0) > 0 ? PlanPrice::findById((int) $item['plan_price_id']) : null;
        $plan = is_array($price) && (int) ($price['plan_id'] ?? 0) > 0 ? Plan::findById((int) $price['plan_id']) : null;
        $decode = static fn (mixed $v): string => trim(html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $label = trim((is_array($plan) ? $decode($plan['name'] ?? '').' - ' : '').(is_array($price) ? $decode($price['name'] ?? '') : ''), ' -');

        return implode(' · ', array_values(array_filter([$label, ...Customizations::lines($item)], static fn (string $part): bool => $part !== '')));
    }

    /**
     * La riga che nasce con l'abbonamento: importi del periodo pieno senza
     * coupon, `pending` se l'abbonamento aspetta il pagamento, altrimenti
     * `active`. Va chiamata dentro la transazione che crea l'abbonamento.
     *
     * @param array<string, mixed> $subscription la riga appena scritta (id, status, tax_id, tax_rate, prices_include_tax)
     * @param array<string, mixed> $price
     */
    public static function open(array $subscription, array $price, ?DateTimeImmutable $startsAt = null, ?DateTimeImmutable $commitmentEndsAt = null): int
    {
        $amounts = SubscriptionAmounts::of($price, null, (float) ($subscription['tax_rate'] ?? 0), (string) ($subscription['prices_include_tax'] ?? 'true') === 'true', 0);
        $created = SubscriptionItem::create([
            'subscription_id' => (int) $subscription['id'],
            'plan_price_id' => (int) $price['id'],
            'parent_item_id' => 0,
            'quantity' => 1,
            'status' => (string) ($subscription['status'] ?? '') === 'pending' ? 'pending' : 'active',
            'customization' => '',
            'unit_price' => $amounts['unit_price'],
            'customization_total' => '0.00',
            'subtotal' => $amounts['subtotal'],
            'tax_total' => $amounts['tax_total'],
            'total' => $amounts['total'],
            'tax_id' => (int) ($subscription['tax_id'] ?? 0),
            'tax_rate' => number_format((float) ($subscription['tax_rate'] ?? 0), 2, '.', ''),
            'starts_at' => $startsAt?->format('Y-m-d H:i:s') ?? '',
            'commitment_ends_at' => $commitmentEndsAt?->format('Y-m-d H:i:s') ?? '',
            'position' => 1,
        ]);
        $id = (int) ($created->insert_id ?? 0);

        if ($id <= 0) {
            throw new RuntimeException('Riga dell\'abbonamento non scritta.');
        }

        return $id;
    }

    /**
     * Cambia il prezzo della riga principale e lo scrive nella storia
     * (`changed`, dal periodo dopo). Gli importi della riga seguono il prezzo
     * nuovo. Ridà `false` se il prezzo è già quello.
     *
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $price il prezzo nuovo
     */
    public static function changePrice(array $subscription, array $price, string $source, int $userId = 0): bool
    {
        $item = self::main((int) $subscription['id']);
        $old = (int) ($item['plan_price_id'] ?? 0);

        if ($item === null || $old === (int) $price['id']) {
            return false;
        }

        $amounts = SubscriptionAmounts::of($price, null, (float) ($item['tax_rate'] ?? 0), (string) ($subscription['prices_include_tax'] ?? 'true') === 'true', 0);
        $now = date('Y-m-d H:i:s');

        SubscriptionItemChange::create([
            'subscription_id' => (int) $subscription['id'],
            'item_id' => (int) $item['id'],
            'action' => 'changed',
            'old_plan_price_id' => $old,
            'new_plan_price_id' => (int) $price['id'],
            'billing' => 'next_period',
            'amount' => '0.00',
            'credit_left' => '0.00',
            'effective_at' => self::empty($subscription['current_period_end'] ?? '') ? $now : (string) $subscription['current_period_end'],
            'user_id' => $userId,
            'source' => $source,
            'changed_at' => $now,
        ]);
        SubscriptionItem::update([
            'plan_price_id' => (int) $price['id'],
            'unit_price' => $amounts['unit_price'],
            'subtotal' => $amounts['subtotal'],
            'tax_total' => $amounts['tax_total'],
            'total' => $amounts['total'],
        ], (int) $item['id']);

        return true;
    }

    /**
     * Le righe seguono lo stato dell'abbonamento: quando parte (da `pending`
     * a uno stato vivo) le `pending` diventano `active`; quando è annullato
     * le righe non chiuse diventano `ended`. Lo chiama `SubscriptionStatus`.
     */
    public static function follow(int $subscriptionId, string $from, string $to): void
    {
        if ($from === 'pending' && in_array($to, Subscription::LIVE, true)) {
            foreach (self::forSubscription($subscriptionId, ['pending']) as $item) {
                SubscriptionItem::update(['status' => 'active'], (int) $item['id']);
            }

            return;
        }

        if ($to === 'cancelled') {
            $now = date('Y-m-d H:i:s');

            foreach (self::forSubscription($subscriptionId, ['pending', 'active', 'ending']) as $item) {
                SubscriptionItem::update(['status' => 'ended', 'ends_at' => self::empty($item['ends_at'] ?? '') ? $now : (string) $item['ends_at']], (int) $item['id']);
            }
        }
    }

    /**
     * La condizione SQL delle righe vive (`SubscriptionItem::LIVE`) di
     * abbonamenti vivi (`Subscription::LIVE`).
     */
    public static function liveWhere(): string
    {
        return "`status` IN ('".implode("','", SubscriptionItem::LIVE)."') AND `deleted` = 'false'"
            .' AND `subscription_id` IN (SELECT `id` FROM `'.Subscription::$table."` WHERE `status` IN ('".implode("','", Subscription::LIVE)."') AND `deleted` = 'false')";
    }

    private static function empty(mixed $value): bool
    {
        $value = trim((string) $value);

        return $value === '' || str_starts_with($value, '0000');
    }
}
