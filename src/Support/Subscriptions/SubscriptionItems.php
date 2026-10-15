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
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

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
     * Controlla le righe chieste per un abbonamento nuovo e le prepara da
     * scrivere. Per ogni riga: prezzo attivo di un piano attivo, quantità
     * almeno 1, personalizzazioni del piano (`Customizations::resolvePlan()`),
     * aliquota dalla categoria fiscale del piano; una riga di un piano che «si
     * lega a» altri vuole una madre fra quelli, e la madre non ne ha a sua
     * volta. Le durate seguono la regola degli intervalli. Importi del periodo
     * pieno, senza coupon.
     *
     * @param list<array{plan_price_id?: int, quantity?: int, customization?: array<int|string, mixed>, parent?: int}> $items `parent` = indice di un'altra riga, -1 se nessuna
     * @param array<string, mixed> $billing i dati di fatturazione (per l'aliquota)
     * @return list<array{price: array<string, mixed>, plan: array<string, mixed>, quantity: int, customization: string, surcharge: string, unit_price: string, customization_total: string, subtotal: string, tax_total: string, total: string, tax_id: int, tax_rate: float, parent: int, term: int, trial_days: int, commitment_months: int}>
     * @throws UserError
     */
    public static function prepare(array $items, array $billing, bool $pricesIncludeTax): array
    {
        $items = array_values(array_filter($items, 'is_array'));

        if ($items === []) {
            throw UserError::make('subscription.items_required');
        }

        $out = [];

        foreach ($items as $item) {
            $price = PlanPrice::findById((int) ($item['plan_price_id'] ?? 0));
            $plan = is_array($price) && (int) ($price['plan_id'] ?? 0) > 0 ? Plan::findById((int) $price['plan_id']) : null;

            if (!is_array($price) || (string) ($price['active'] ?? 'false') !== 'true' || (string) ($price['deleted'] ?? 'false') !== 'false'
                || !is_array($plan) || (string) ($plan['active'] ?? 'false') !== 'true' || (string) ($plan['deleted'] ?? 'false') !== 'false') {
                throw UserError::make('subscription.price_unavailable');
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $resolved = Customizations::resolvePlan((int) $plan['id'], is_array($item['customization'] ?? null) ? $item['customization'] : []);
            [$taxId, $taxRate] = OnlineSubscriptions::taxFor((int) ($plan['tax_category_id'] ?? 0), $billing);
            $term = max(1, (int) ($price['term_months'] ?? 1));
            $line = ['price' => $price, 'quantity' => $quantity, 'surcharge' => $resolved['surcharge'], 'tax_rate' => $taxRate];
            $amounts = SubscriptionAmounts::ofItems([$line], null, $pricesIncludeTax, 0, $term);

            $out[] = [
                'price' => $price,
                'plan' => $plan,
                'quantity' => $quantity,
                'customization' => Customizations::encode($resolved['fields']),
                'surcharge' => $resolved['surcharge'],
                'unit_price' => $amounts['lines'][0]['unit_price'],
                'customization_total' => $amounts['lines'][0]['customization_total'],
                'subtotal' => $amounts['subtotal'],
                'tax_total' => $amounts['tax_total'],
                'total' => $amounts['total'],
                'tax_id' => (int) $taxId,
                'tax_rate' => (float) $taxRate,
                'parent' => (int) ($item['parent'] ?? -1),
                'term' => $term,
                'trial_days' => max(0, (int) ($price['trial_days'] ?? 0)),
                'commitment_months' => max(0, (int) ($price['commitment_months'] ?? 0)),
            ];
        }

        foreach ($out as $index => $line) {
            self::assertParent($out, $index);
        }

        PlanPriceRules::assertIntervals(array_column($out, 'term'));

        return $out;
    }

    /**
     * Scrive le righe preparate da {@see prepare()} per l'abbonamento appena
     * creato, nello stato `$status`, con la madre risolta sull'id e una voce
     * `added` per riga nella storia. Va chiamata dentro la transazione che crea
     * l'abbonamento.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<int> gli id delle righe, nell'ordine di `$lines`
     */
    public static function insert(int $subscriptionId, array $lines, string $status, DateTimeImmutable $startsAt, DateTimeImmutable $firstPeriod, string $source, int $userId = 0): array
    {
        $ids = [];
        $now = date('Y-m-d H:i:s');

        foreach (array_values($lines) as $index => $line) {
            $commitment = (int) $line['commitment_months'] > 0 ? SubscriptionProration::addMonths($firstPeriod, (int) $line['commitment_months']) : null;
            $created = SubscriptionItem::create([
                'subscription_id' => $subscriptionId,
                'plan_price_id' => (int) $line['price']['id'],
                'parent_item_id' => 0,
                'quantity' => (int) $line['quantity'],
                'status' => $status,
                'customization' => (string) $line['customization'],
                'unit_price' => $line['unit_price'],
                'customization_total' => $line['customization_total'],
                'subtotal' => $line['subtotal'],
                'tax_total' => $line['tax_total'],
                'total' => $line['total'],
                'tax_id' => (int) $line['tax_id'],
                'tax_rate' => number_format((float) $line['tax_rate'], 2, '.', ''),
                'starts_at' => $startsAt->format('Y-m-d H:i:s'),
                'commitment_ends_at' => $commitment?->format('Y-m-d H:i:s') ?? '',
                'position' => $index + 1,
            ]);
            $id = (int) ($created->insert_id ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Riga dell\'abbonamento non scritta.');
            }

            $ids[$index] = $id;
            SubscriptionItemChange::create([
                'subscription_id' => $subscriptionId,
                'item_id' => $id,
                'action' => 'added',
                'old_plan_price_id' => 0,
                'new_plan_price_id' => (int) $line['price']['id'],
                'billing' => '',
                'amount' => '0.00',
                'credit_left' => '0.00',
                'effective_at' => $startsAt->format('Y-m-d H:i:s'),
                'user_id' => $userId,
                'source' => $source,
                'changed_at' => $now,
            ]);
        }

        foreach (array_values($lines) as $index => $line) {
            if ((int) $line['parent'] >= 0) {
                SubscriptionItem::update(['parent_item_id' => $ids[(int) $line['parent']]], $ids[$index]);
            }
        }

        return array_values($ids);
    }

    /**
     * Da quando conta il periodo di una riga: la data di rinnovo
     * dell'abbonamento (la fine della prova, altrimenti l'inizio) o, se la riga
     * è partita dopo, il suo inizio.
     *
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $item
     */
    public static function anchor(array $subscription, array $item): DateTimeImmutable
    {
        $anchor = self::date((string) (($subscription['trial_ends_at'] ?? '') ?: ($subscription['started_at'] ?? ''))) ?? new DateTimeImmutable('today');
        $start = self::date((string) ($item['starts_at'] ?? ''));

        return $start !== null && $start > $anchor ? $start : $anchor;
    }

    /**
     * Le righe del periodo che parte in `$start`: le righe in corso (e le
     * `pending` che partono) non ancora finite, la durata più corta fra loro
     * (il periodo dell'abbonamento) e, fra queste, quelle che si addebitano
     * quel giorno con l'inizio e la fine del loro periodo.
     *
     * @param array<string, mixed> $subscription
     * @return array{term: int, items: list<array<string, mixed>>, charged: list<array{item: array<string, mixed>, price: array<string, mixed>, start: DateTimeImmutable, end: DateTimeImmutable}>}
     */
    public static function forPeriod(array $subscription, DateTimeImmutable $start): array
    {
        $start = $start->setTime(0, 0);
        $items = [];
        $prices = [];

        foreach (self::forSubscription((int) ($subscription['id'] ?? 0), ['pending', 'active', 'ending']) as $item) {
            $ends = self::date((string) ($item['ends_at'] ?? ''));
            $price = PlanPrice::findById((int) $item['plan_price_id']);

            if (($ends !== null && $ends <= $start) || !is_array($price)) {
                continue;
            }

            $items[] = $item;
            $prices[(int) $item['id']] = $price;
        }

        $term = $items === [] ? 1 : min(array_map(static fn (array $item): int => max(1, (int) ($prices[(int) $item['id']]['term_months'] ?? 1)), $items));
        $charged = [];

        foreach ($items as $item) {
            $price = $prices[(int) $item['id']];
            $itemTerm = max(1, (int) ($price['term_months'] ?? 1));
            $anchor = self::anchor($subscription, $item);

            if (SubscriptionProration::charges($anchor, $itemTerm, $start)) {
                $charged[] = ['item' => $item, 'price' => $price, 'start' => $start, 'end' => SubscriptionProration::addMonths($start, $itemTerm)];
            }
        }

        return ['term' => $term, 'items' => $items, 'charged' => $charged];
    }

    /**
     * La madre della riga `$index`: obbligatoria se il piano «si lega a»
     * altri piani e di uno di quelli, senza a sua volta una madre; vietata se
     * il piano non si lega a niente.
     *
     * @param list<array<string, mixed>> $lines
     * @throws UserError `subscription.parent_required`, `subscription.parent_invalid`
     */
    private static function assertParent(array $lines, int $index): void
    {
        $line = $lines[$index];
        $allowed = PlanLinks::parentsOf((int) $line['plan']['id']);
        $parent = (int) $line['parent'];
        $name = trim(html_entity_decode((string) ($line['plan']['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($parent < 0) {
            if ($allowed !== []) {
                throw UserError::make('subscription.parent_required', ['name' => $name]);
            }

            return;
        }

        $mother = $lines[$parent] ?? null;

        if ($parent === $index || $mother === null || !in_array((int) $mother['plan']['id'], $allowed, true) || (int) $mother['parent'] >= 0) {
            throw UserError::make('subscription.parent_invalid', ['name' => $name]);
        }
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        if (self::empty($value)) {
            return null;
        }

        try {
            return (new DateTimeImmutable(substr(trim($value), 0, 10)))->setTime(0, 0);
        } catch (Throwable) {
            return null;
        }
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
