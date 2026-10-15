<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItem;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItemChange;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Sql\Transaction;

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
    /** Come si paga una riga aggiunta o cambiata. */
    public const ADD_BILLINGS = ['prorate', 'free', 'next_period'];

    /** Come si toglie una riga. */
    public const REMOVE_BILLINGS = ['period_end', 'credit'];

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

        $out = array_map(static fn (array $item): array => self::prepareLine($item, $billing, $pricesIncludeTax), $items);

        foreach ($out as $index => $line) {
            self::assertParent($out, $index);
        }

        PlanPriceRules::assertIntervals(array_column($out, 'term'));

        return $out;
    }

    /**
     * Una riga chiesta, controllata e preparata come in {@see prepare()}.
     *
     * @param array{plan_price_id?: int, quantity?: int, customization?: array<int|string, mixed>, parent?: int} $item
     * @param array<string, mixed> $billing
     * @return array<string, mixed>
     * @throws UserError
     */
    public static function prepareLine(array $item, array $billing, bool $pricesIncludeTax): array
    {
        [$price, $plan] = self::available((int) ($item['plan_price_id'] ?? 0));
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $resolved = Customizations::resolvePlan((int) $plan['id'], is_array($item['customization'] ?? null) ? $item['customization'] : []);
        [$taxId, $taxRate] = OnlineSubscriptions::taxFor((int) ($plan['tax_category_id'] ?? 0), $billing);
        $term = max(1, (int) ($price['term_months'] ?? 1));
        $line = ['price' => $price, 'quantity' => $quantity, 'surcharge' => $resolved['surcharge'], 'tax_rate' => $taxRate];
        $amounts = SubscriptionAmounts::ofItems([$line], null, $pricesIncludeTax, 0, $term);

        return [
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

    /**
     * Un prezzo attivo di un piano attivo.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     * @throws UserError `subscription.price_unavailable`
     */
    private static function available(int $priceId): array
    {
        $price = $priceId > 0 ? PlanPrice::findById($priceId) : null;
        $plan = is_array($price) && (int) ($price['plan_id'] ?? 0) > 0 ? Plan::findById((int) $price['plan_id']) : null;

        if (!is_array($price) || (string) ($price['active'] ?? 'false') !== 'true' || (string) ($price['deleted'] ?? 'false') !== 'false'
            || !is_array($plan) || (string) ($plan['active'] ?? 'false') !== 'true' || (string) ($plan['deleted'] ?? 'false') !== 'false') {
            throw UserError::make('subscription.price_unavailable');
        }

        return [$price, $plan];
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
     * La data d'ancora dell'abbonamento: la fine della prova, altrimenti
     * l'inizio. Tutte le righe contano i loro periodi da qui, anche quelle
     * aggiunte dopo: una riga nuova paga la quota fino alla sua prossima data
     * d'ancora e poi si rinnova con le altre.
     *
     * @param array<string, mixed> $subscription
     */
    public static function anchor(array $subscription): DateTimeImmutable
    {
        return self::date((string) (($subscription['trial_ends_at'] ?? '') ?: ($subscription['started_at'] ?? ''))) ?? new DateTimeImmutable('today');
    }

    /**
     * Le righe del periodo che parte in `$start`: le righe in corso (e le
     * `pending` che partono quel giorno) non ancora finite, la durata più
     * corta fra loro, la fine del periodo (la più vicina fra le fini dei
     * periodi delle righe) e, fra queste, quelle che si addebitano quel giorno
     * con l'inizio e la fine del loro periodo. Una riga che parte quel giorno
     * fuori dalla sua data d'ancora si addebita in quota: `row_start` è
     * l'inizio del suo periodo, da cui si conta la quota.
     *
     * @param array<string, mixed> $subscription
     * @return array{term: int, end: DateTimeImmutable, items: list<array<string, mixed>>, charged: list<array{item: array<string, mixed>, price: array<string, mixed>, start: DateTimeImmutable, end: DateTimeImmutable, row_start?: DateTimeImmutable}>}
     */
    public static function forPeriod(array $subscription, DateTimeImmutable $start): array
    {
        $start = $start->setTime(0, 0);
        $anchor = self::anchor($subscription);
        $items = [];
        $prices = [];

        foreach (self::forSubscription((int) ($subscription['id'] ?? 0), ['pending', 'active', 'ending']) as $item) {
            $begins = self::date((string) ($item['starts_at'] ?? ''));
            $ends = self::date((string) ($item['ends_at'] ?? ''));
            $price = PlanPrice::findById((int) $item['plan_price_id']);

            if (($begins !== null && $begins > $start) || ($ends !== null && $ends <= $start) || !is_array($price)) {
                continue;
            }

            $items[] = $item;
            $prices[(int) $item['id']] = $price;
        }

        $term = $items === [] ? 1 : min(array_map(static fn (array $item): int => max(1, (int) ($prices[(int) $item['id']]['term_months'] ?? 1)), $items));
        $end = null;
        $charged = [];

        foreach ($items as $item) {
            $price = $prices[(int) $item['id']];
            $itemTerm = max(1, (int) ($price['term_months'] ?? 1));
            [$rowStart, $rowEnd] = SubscriptionProration::rowPeriod($anchor, $itemTerm, $start);
            $end = $end === null || $rowEnd < $end ? $rowEnd : $end;
            $begins = self::date((string) ($item['starts_at'] ?? ''));

            if (SubscriptionProration::charges($anchor, $itemTerm, $start)) {
                $charged[] = ['item' => $item, 'price' => $price, 'start' => $start, 'end' => $rowEnd];
            } elseif ($begins !== null && $begins->format('Y-m-d') === $start->format('Y-m-d')) {
                // Parte oggi a metà del suo periodo (aggiunta «dal prossimo periodo»): paga la quota.
                $charged[] = ['item' => $item, 'price' => $price, 'start' => $start, 'end' => $rowEnd, 'row_start' => $rowStart];
            }
        }

        return ['term' => $term, 'end' => $end ?? SubscriptionProration::addMonths($start, $term), 'items' => $items, 'charged' => $charged];
    }

    /**
     * Una riga pronta per `SubscriptionAmounts::ofItems()`: il prezzo, la
     * quantità, il sovrapprezzo per pezzo (quello tenuto dalla riga) e
     * l'aliquota. Con `$quota` = [giorno d'effetto, inizio, fine] prezzo e
     * sovrapprezzo sono la parte che resta del periodo, a giorni.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $price
     * @param array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: DateTimeImmutable}|null $quota
     * @return array{price: array<string, mixed>, quantity: int, surcharge: float, tax_rate: float}
     */
    public static function amountLine(array $item, array $price, ?array $quota = null): array
    {
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $surcharge = round((float) ($item['customization_total'] ?? 0) / $quantity, 2);

        if ($quota !== null) {
            [$effective, $start, $end] = $quota;
            $part = static fn (mixed $amount): float => SubscriptionProration::quota((float) $amount, $effective, $start, $end);
            $price = ['price' => number_format($part($price['price'] ?? 0), 2, '.', ''), 'sale_price' => number_format($part($price['sale_price'] ?? 0), 2, '.', '')] + $price;
            $surcharge = $part($surcharge);
        }

        return ['price' => $price, 'quantity' => $quantity, 'surcharge' => $surcharge, 'tax_rate' => (float) ($item['tax_rate'] ?? 0)];
    }

    /**
     * Aggiunge una riga a un abbonamento in corso (§4.1). `$billing`:
     * `prorate` (riga attiva e rata della quota fino alla fine del suo
     * periodo), `free` (attiva, niente fino al suo rinnovo), `next_period`
     * (`pending`, parte all'apertura del periodo dopo). In prova la riga è
     * subito attiva e non si paga niente; a periodo già scaduto vale
     * `next_period`. `$item['parent_item_id']` è la riga madre, se il piano la
     * chiede.
     *
     * @param array{plan_price_id?: int, quantity?: int, customization?: array<int|string, mixed>, parent_item_id?: int} $item
     * @throws UserError
     */
    public static function add(int $subscriptionId, array $item, string $billing, int $userId = 0, string $source = 'user', ?DateTimeImmutable $at = null): int
    {
        if (!in_array($billing, self::ADD_BILLINGS, true)) {
            throw new InvalidArgumentException('Conguaglio non valido per un\'aggiunta: '.$billing);
        }

        $at = ($at ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $done = Transaction::run(static function () use ($subscriptionId, $item, $billing, $userId, $source, $at): array {
            $subscription = self::lockLive($subscriptionId);
            $line = self::prepareLine($item, $subscription, (string) ($subscription['prices_include_tax'] ?? 'true') === 'true');
            $live = self::forSubscription($subscriptionId, ['pending', ...SubscriptionItem::LIVE]);
            $parentId = (int) ($item['parent_item_id'] ?? 0);

            self::assertLiveParent($line['plan'], $parentId, $live);
            PlanPriceRules::assertIntervals([...array_map([self::class, 'termOf'], $live), $line['term']]);

            $trial = (string) $subscription['status'] === 'trialing';
            $periodEnd = self::date((string) ($subscription['current_period_end'] ?? ''));

            if (!$trial && $periodEnd !== null && $periodEnd <= $at) {
                $billing = 'next_period';
            }

            $later = !$trial && $billing === 'next_period';
            $startsAt = $later ? ($periodEnd ?? self::date((string) ($subscription['next_renewal_at'] ?? '')) ?? $at) : $at;
            $commitmentFrom = $trial ? self::anchor($subscription) : $startsAt;
            $commitment = (int) $line['commitment_months'] > 0 ? SubscriptionProration::addMonths($commitmentFrom, (int) $line['commitment_months']) : null;
            $position = array_reduce(self::forSubscription($subscriptionId), static fn (int $max, array $row): int => max($max, (int) $row['position']), 0) + 1;

            $created = SubscriptionItem::create([
                'subscription_id' => $subscriptionId,
                'plan_price_id' => (int) $line['price']['id'],
                'parent_item_id' => $parentId,
                'quantity' => (int) $line['quantity'],
                'status' => $later ? 'pending' : 'active',
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
                'position' => $position,
            ]);
            $id = (int) ($created->insert_id ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Riga dell\'abbonamento non scritta.');
            }

            self::raiseCommitment($subscription, $commitment);
            $row = SubscriptionItem::findById($id);
            $charged = OfficeSubscriptions::itemAdded($subscription, is_array($row) ? $row : ['id' => $id], $trial ? 'free' : $billing, $at);
            self::history($subscriptionId, $id, 'added', 0, (int) $line['price']['id'], $billing, $charged['amount'], 0.0, $startsAt, $userId, $source);

            return ['id' => $id, 'payments' => $charged['payments']];
        });

        if ($done['payments'] !== []) {
            OfficeSubscriptions::quotaProforma($subscriptionId, $done['id'], $at, $done['payments']);
        }

        return $done['id'];
    }

    /**
     * Toglie una riga e le sue figlie in corso, con la stessa scelta (§4.2):
     * `period_end` = `ending` fino alla fine del suo periodo (o del vincolo,
     * se dura di più; le figlie finiscono con la madre); `credit` = `ended`
     * subito, col credito della parte non goduta. In vincolo `credit` non si
     * può. Una riga `pending` si chiude subito, senza credito. Se non resta
     * una riga in corso è una disdetta (`period_end`) o un annullamento
     * (`credit`).
     *
     * @return array{ends_at: string, removed: list<int>}
     * @throws UserError `subscription.item_not_live`, `subscription.not_live`, `subscription.item_committed`
     */
    public static function remove(int $itemId, string $billing, int $userId = 0, string $source = 'user', ?DateTimeImmutable $at = null): array
    {
        if (!in_array($billing, self::REMOVE_BILLINGS, true)) {
            throw new InvalidArgumentException('Conguaglio non valido per una rimozione: '.$billing);
        }

        $at = ($at ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $subscriptionId = (int) self::liveItem($itemId)['subscription_id'];

        $done = Transaction::run(static function () use ($subscriptionId, $itemId, $billing, $userId, $source, $at): array {
            $subscription = self::lockLive($subscriptionId);
            $mother = self::liveItem($itemId);
            $rows = [$mother, ...array_values(array_filter(
                self::forSubscription($subscriptionId, ['pending', 'active']),
                static fn (array $row): bool => (int) $row['parent_item_id'] === $itemId
            ))];

            if ($billing === 'credit') {
                foreach ($rows as $row) {
                    $commitment = self::date((string) ($row['commitment_ends_at'] ?? ''));

                    if ($commitment !== null && $commitment > $at) {
                        throw UserError::make('subscription.item_committed', ['date' => $commitment->format('d/m/Y')]);
                    }
                }
            }

            $trial = (string) $subscription['status'] === 'trialing';
            $anchor = self::anchor($subscription);
            $motherEnd = null;
            $removed = [];

            foreach ($rows as $index => $row) {
                $term = self::termOf($row);
                [$rowStart, $rowEnd] = SubscriptionProration::rowPeriod($anchor, $term, $at);
                $credit = 0.0;

                if ((string) $row['status'] === 'pending' || $billing === 'credit') {
                    $status = 'ended';
                    $end = $at;
                    $credit = (string) $row['status'] === 'active' && !$trial ? SubscriptionProration::quota((float) $row['total'], $at, $rowStart, $rowEnd) : 0.0;
                } else {
                    $status = 'ending';
                    $end = $rowEnd;
                    $commitment = self::date((string) ($row['commitment_ends_at'] ?? ''));

                    if ($commitment !== null && $commitment > $at) {
                        // Il vincolo finisce dentro un periodo della riga: la riga va fino alla fine di quello.
                        $committedEnd = SubscriptionProration::rowPeriod($anchor, $term, $commitment->modify('-1 day'))[1];
                        $end = $committedEnd > $end ? $committedEnd : $end;
                    }

                    if ($index > 0 && $motherEnd !== null) {
                        $end = $motherEnd;
                    }
                }

                if ($index === 0 && $status === 'ending') {
                    $motherEnd = $end;
                }

                SubscriptionItem::update(['status' => $status, 'ends_at' => $end->format('Y-m-d H:i:s')], (int) $row['id']);
                self::history($subscriptionId, (int) $row['id'], 'removed', (int) $row['plan_price_id'], 0, $billing, $credit, $credit, $end, $userId, $source);
                $removed[] = (int) $row['id'];
            }

            return [
                'ends_at' => ($motherEnd ?? $at)->format('Y-m-d H:i:s'),
                'removed' => $removed,
                'last' => self::forSubscription($subscriptionId, ['pending', 'active']) === [],
                'auto_renew' => (string) ($subscription['auto_renew'] ?? 'true') === 'true',
            ];
        });

        if ($done['last']) {
            if ($billing === 'credit') {
                OfficeSubscriptions::terminate($subscriptionId, $userId, 'Tolto l\'ultimo servizio');
            } elseif ($done['auto_renew']) {
                OfficeSubscriptions::cancel($subscriptionId, $userId);
            }
        }

        return ['ends_at' => $done['ends_at'], 'removed' => $done['removed']];
    }

    /**
     * Cambia prezzo (o piano) a una riga, sulla stessa riga (§4.3): stessa
     * posizione, stessa madre e stesse figlie se i legami del piano nuovo le
     * ammettono. Le personalizzazioni restano quelle che il piano nuovo ha,
     * se non se ne passano di nuove. `prorate` = credito della parte non
     * goduta del prezzo vecchio e rata della quota del nuovo; `free` = il
     * nuovo vale da subito senza conti; `next_period` = il cambio vale dalla
     * fine del periodo della riga e lo applica il giro giornaliero. In prova
     * o su una riga `pending` il cambio vale subito, senza conti. Lo stesso
     * prezzo con altre personalizzazioni è una correzione ({@see customize()}).
     *
     * @param array<int|string, mixed> $customization
     * @throws UserError
     */
    public static function change(int $itemId, int $newPriceId, string $billing, array $customization = [], int $userId = 0, string $source = 'user', ?DateTimeImmutable $at = null): void
    {
        if (!in_array($billing, self::ADD_BILLINGS, true)) {
            throw new InvalidArgumentException('Conguaglio non valido per un cambio: '.$billing);
        }

        $at = ($at ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $subscriptionId = (int) self::liveItem($itemId)['subscription_id'];

        $done = Transaction::run(static function () use ($subscriptionId, $itemId, $newPriceId, $billing, $customization, $userId, $source, $at): array {
            $subscription = self::lockLive($subscriptionId);
            $item = self::liveItem($itemId);
            [$price, $plan] = self::available($newPriceId);

            if ($customization === []) {
                // Le personalizzazioni di prima che il piano nuovo ha ancora.
                $known = array_map(static fn (array $d): int => (int) $d['id'], Customizations::forPlan((int) $plan['id']));
                $customization = array_intersect_key(Customizations::valuesOf(Customizations::decode($item['customization'] ?? '')), array_flip($known));
            }

            $resolved = Customizations::resolvePlan((int) $plan['id'], $customization);
            $samePrice = (int) $item['plan_price_id'] === (int) $price['id'];

            if ($samePrice) {
                self::recustomize($subscription, $item, $price, $plan, $resolved, $userId, $source);

                return ['payments' => []];
            }

            self::assertLinks($item, $plan);
            $others = array_values(array_filter(
                self::forSubscription($subscriptionId, ['pending', ...SubscriptionItem::LIVE]),
                static fn (array $row): bool => (int) $row['id'] !== $itemId
            ));
            PlanPriceRules::assertIntervals([...array_map([self::class, 'termOf'], $others), max(1, (int) ($price['term_months'] ?? 1))]);

            $trial = (string) $subscription['status'] === 'trialing';
            $periodEnd = self::date((string) ($subscription['current_period_end'] ?? ''));
            $charging = !$trial && (string) $item['status'] === 'active';

            if ($charging && $periodEnd !== null && $periodEnd <= $at) {
                $billing = 'next_period';
            }

            $anchor = self::anchor($subscription);
            [$rowStart, $rowEnd] = SubscriptionProration::rowPeriod($anchor, self::termOf($item), $at);

            if ($charging && $billing === 'next_period') {
                self::scheduleChange($subscriptionId, $item, (int) $price['id'], Customizations::encode($resolved['fields']), $rowEnd, $userId, $source);

                return ['payments' => []];
            }

            $credit = $charging && $billing === 'prorate' ? SubscriptionProration::quota((float) $item['total'], $at, $rowStart, $rowEnd) : 0.0;
            $updated = self::applyPrice($subscription, $item, $price, $plan, $resolved, $at);
            $charged = OfficeSubscriptions::itemAdded($subscription, $updated, $charging ? $billing : 'free', $at);
            self::history($subscriptionId, $itemId, 'changed', (int) $item['plan_price_id'], (int) $price['id'], $billing, $charged['amount'], $credit, $at, $userId, $source);

            return ['payments' => $charged['payments']];
        });

        if ($done['payments'] !== []) {
            OfficeSubscriptions::quotaProforma($subscriptionId, $itemId, $at, $done['payments']);
        }
    }

    /**
     * Corregge le personalizzazioni di una riga: storia `customized` col
     * testo di prima nella nota. Il sovrapprezzo nuovo vale dal periodo dopo:
     * cambiano gli importi della riga, non le rate già fatte.
     *
     * @param array<int|string, mixed> $values
     * @throws UserError
     */
    public static function customize(int $itemId, array $values, int $userId = 0, string $source = 'user'): void
    {
        $subscriptionId = (int) self::liveItem($itemId)['subscription_id'];

        Transaction::run(static function () use ($subscriptionId, $itemId, $values, $userId, $source): void {
            $subscription = self::lockLive($subscriptionId);
            $item = self::liveItem($itemId);
            $price = PlanPrice::findById((int) $item['plan_price_id']);
            $plan = is_array($price) ? Plan::findById((int) ($price['plan_id'] ?? 0)) : null;

            if (!is_array($price) || !is_array($plan)) {
                throw UserError::make('subscription.price_unavailable');
            }

            self::recustomize($subscription, $item, $price, $plan, Customizations::resolvePlan((int) $plan['id'], $values), $userId, $source);
        });
    }

    /**
     * I cambi «dal prossimo periodo» arrivati al loro giorno: per ogni riga
     * attiva, se l'ultimo cambio è un `next_period` con `effective_at` passato
     * e la riga ha ancora il prezzo di prima, lo applica. Se il prezzo nuovo
     * non si addebita quel giorno (un'altra durata), la quota fino al suo
     * rinnovo diventa una rata. Lo chiama il giro giornaliero, prima di aprire
     * il periodo.
     *
     * @param array<string, mixed> $subscription
     * @return list<array{item: int, payments: list<int>, at: DateTimeImmutable}>
     */
    public static function applyScheduled(array $subscription, DateTimeImmutable $today): array
    {
        $done = [];
        $trial = (string) $subscription['status'] === 'trialing';
        $anchor = self::anchor($subscription);

        foreach (self::forSubscription((int) $subscription['id'], ['active']) as $item) {
            $last = self::lastChange((int) $item['id']);
            $effective = $last !== null ? self::date((string) ($last['effective_at'] ?? '')) : null;

            if ($last === null || (string) $last['billing'] !== 'next_period' || $effective === null || $effective > $today
                || (int) $last['old_plan_price_id'] !== (int) $item['plan_price_id']) {
                continue;
            }

            $price = PlanPrice::findById((int) $last['new_plan_price_id']);
            $plan = is_array($price) ? Plan::findById((int) ($price['plan_id'] ?? 0)) : null;

            if (!is_array($price) || (string) ($price['deleted'] ?? 'false') === 'true' || !is_array($plan)) {
                continue;
            }

            $fields = Customizations::decode((string) ($last['note'] ?? ''));
            $surcharge = number_format(array_sum(array_map(static fn (array $f): float => (float) $f['surcharge'], $fields)), 2, '.', '');
            $updated = self::applyPrice($subscription, $item, $price, $plan, ['fields' => $fields, 'surcharge' => $surcharge], $effective);
            $payments = [];

            if (!$trial && !SubscriptionProration::charges($anchor, max(1, (int) ($price['term_months'] ?? 1)), $effective)) {
                $payments = OfficeSubscriptions::itemAdded($subscription, $updated, 'prorate', $effective)['payments'];
            }

            $done[] = ['item' => (int) $item['id'], 'payments' => $payments, 'at' => $effective];
        }

        return $done;
    }

    /**
     * Alla fine del vincolo di una riga, il prezzo di rinnovo del suo prezzo
     * (`renewal_plan_price_id`) diventa un cambio «dal prossimo periodo» col
     * giorno d'effetto alla fine del vincolo.
     *
     * @param array<string, mixed> $subscription
     */
    public static function scheduleRenewals(array $subscription, DateTimeImmutable $today): void
    {
        foreach (self::forSubscription((int) $subscription['id'], ['active']) as $item) {
            $commitment = self::date((string) ($item['commitment_ends_at'] ?? ''));
            $price = $commitment !== null && $commitment <= $today ? PlanPrice::findById((int) $item['plan_price_id']) : null;
            $renewal = is_array($price) ? (int) ($price['renewal_plan_price_id'] ?? 0) : 0;
            $last = $renewal > 0 ? self::lastChange((int) $item['id']) : null;

            if ($renewal <= 0 || $renewal === (int) $item['plan_price_id']
                || ($last !== null && (string) $last['billing'] === 'next_period' && (int) $last['new_plan_price_id'] === $renewal && (int) $last['old_plan_price_id'] === (int) $item['plan_price_id'])) {
                continue;
            }

            self::scheduleChange((int) $subscription['id'], $item, $renewal, (string) ($item['customization'] ?? ''), $commitment, 0, 'system');
        }
    }

    /**
     * Nuove personalizzazioni sullo stesso prezzo: niente se sono le stesse.
     *
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $item
     * @param array<string, mixed> $price
     * @param array<string, mixed> $plan
     * @param array{fields: list<array<string, mixed>>, surcharge: string} $resolved
     */
    private static function recustomize(array $subscription, array $item, array $price, array $plan, array $resolved, int $userId, string $source): void
    {
        if (Customizations::signature($resolved['fields']) === Customizations::signature(Customizations::decode($item['customization'] ?? ''))) {
            return;
        }

        $before = implode(' · ', Customizations::lines($item));
        self::applyPrice($subscription, $item, $price, $plan, $resolved, null);
        self::history((int) $subscription['id'], (int) $item['id'], 'customized', (int) $price['id'], (int) $price['id'], '', 0.0, 0.0, new DateTimeImmutable('today'), $userId, $source, $before);
    }

    /**
     * Scrive sulla riga il prezzo e le personalizzazioni, con gli importi del
     * periodo pieno. Con `$effective` (un cambio di prezzo) il vincolo della
     * riga riparte da lì se il prezzo nuovo ne ha uno, senza accorciare quello
     * che c'era; e quello dell'abbonamento si allunga se serve.
     *
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $item
     * @param array<string, mixed> $price
     * @param array<string, mixed> $plan
     * @param array{fields: list<array<string, mixed>>, surcharge: string} $resolved
     * @return array<string, mixed> la riga aggiornata
     */
    private static function applyPrice(array $subscription, array $item, array $price, array $plan, array $resolved, ?DateTimeImmutable $effective): array
    {
        [$taxId, $taxRate] = OnlineSubscriptions::taxFor((int) ($plan['tax_category_id'] ?? 0), $subscription);
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $amounts = SubscriptionAmounts::ofItems(
            [['price' => $price, 'quantity' => $quantity, 'surcharge' => $resolved['surcharge'], 'tax_rate' => $taxRate]],
            null,
            (string) ($subscription['prices_include_tax'] ?? 'true') === 'true',
            0,
            max(1, (int) ($price['term_months'] ?? 1))
        );
        $values = [
            'plan_price_id' => (int) $price['id'],
            'customization' => Customizations::encode($resolved['fields']),
            'unit_price' => $amounts['lines'][0]['unit_price'],
            'customization_total' => $amounts['lines'][0]['customization_total'],
            'subtotal' => $amounts['subtotal'],
            'tax_total' => $amounts['tax_total'],
            'total' => $amounts['total'],
            'tax_id' => (int) $taxId,
            'tax_rate' => number_format((float) $taxRate, 2, '.', ''),
        ];

        if ($effective !== null) {
            $old = self::date((string) ($item['commitment_ends_at'] ?? ''));
            $months = max(0, (int) ($price['commitment_months'] ?? 0));
            $new = $months > 0 ? SubscriptionProration::addMonths($effective, $months) : null;
            $kept = $old !== null && $old > $effective ? $old : null;
            $commitment = $new !== null && ($kept === null || $new > $kept) ? $new : $kept;
            $values['commitment_ends_at'] = $commitment?->format('Y-m-d H:i:s') ?? '';
            self::raiseCommitment($subscription, $commitment);
        }

        SubscriptionItem::update($values, (int) $item['id']);

        return $values + $item;
    }

    /**
     * Un cambio «dal prossimo periodo»: solo nella storia, con le
     * personalizzazioni nella nota; lo applica {@see applyScheduled()}.
     *
     * @param array<string, mixed> $item
     */
    private static function scheduleChange(int $subscriptionId, array $item, int $newPriceId, string $customization, DateTimeImmutable $effective, int $userId, string $source): void
    {
        self::history($subscriptionId, (int) $item['id'], 'changed', (int) $item['plan_price_id'], $newPriceId, 'next_period', 0.0, 0.0, $effective, $userId, $source, $customization);
    }

    /** @return array<string, mixed>|null l'ultimo cambio di prezzo della riga */
    private static function lastChange(int $itemId): ?array
    {
        try {
            $found = SubscriptionItemChange::find('`item_id` = '.$itemId." AND `action` = 'changed' AND `deleted` = 'false'", null, 'id', 'DESC');
        } catch (Throwable) {
            return null;
        }

        $rows = !is_array($found) || $found === [] ? [] : (isset($found['id']) ? [$found] : array_values(array_filter($found, 'is_array')));
        usort($rows, static fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);

        return $rows[0] ?? null;
    }

    private static function history(int $subscriptionId, int $itemId, string $action, int $oldPriceId, int $newPriceId, string $billing, float|string $amount, float $credit, DateTimeImmutable $effective, int $userId, string $source, string $note = ''): void
    {
        SubscriptionItemChange::create([
            'subscription_id' => $subscriptionId,
            'item_id' => $itemId,
            'action' => $action,
            'old_plan_price_id' => $oldPriceId,
            'new_plan_price_id' => $newPriceId,
            'billing' => $billing,
            'amount' => number_format((float) $amount, 2, '.', ''),
            'credit_left' => number_format($credit, 2, '.', ''),
            'effective_at' => $effective->format('Y-m-d H:i:s'),
            'user_id' => $userId,
            'source' => $source,
            'changed_at' => date('Y-m-d H:i:s'),
            'note' => $note,
        ]);
    }

    /** Il vincolo dell'abbonamento è il più lontano fra quelli delle righe. @param array<string, mixed> $subscription */
    private static function raiseCommitment(array $subscription, ?DateTimeImmutable $commitment): void
    {
        $current = self::date((string) ($subscription['commitment_ends_at'] ?? ''));

        if ($commitment !== null && ($current === null || $commitment > $current)) {
            Subscription::update(['commitment_ends_at' => $commitment->format('Y-m-d H:i:s')], (int) $subscription['id']);
        }
    }

    /**
     * L'abbonamento, bloccato fino alla fine della transazione, se è in corso.
     *
     * @return array<string, mixed>
     * @throws UserError `subscription.not_live`
     */
    private static function lockLive(int $subscriptionId): array
    {
        $row = $subscriptionId > 0 ? Subscription::findForUpdate(['id' => $subscriptionId], 1) : null;

        if (!is_array($row) || $row === [] || (string) ($row['deleted'] ?? 'false') === 'true' || !in_array((string) ($row['status'] ?? ''), Subscription::LIVE, true)) {
            throw UserError::make('subscription.not_live');
        }

        if ((string) ($row['channel'] ?? '') !== 'office') {
            throw new RuntimeException('Le righe degli abbonamenti online si cambiano da OnlineSubscriptions.');
        }

        return $row;
    }

    /**
     * Una riga `pending` o `active`.
     *
     * @return array<string, mixed>
     * @throws UserError `subscription.item_not_live`
     */
    private static function liveItem(int $itemId): array
    {
        $item = $itemId > 0 ? SubscriptionItem::findById($itemId) : null;

        if (!is_array($item) || (int) ($item['id'] ?? 0) <= 0 || (string) ($item['deleted'] ?? 'false') === 'true'
            || !in_array((string) ($item['status'] ?? ''), ['pending', 'active'], true)) {
            throw UserError::make('subscription.item_not_live');
        }

        return $item;
    }

    /** La durata del prezzo della riga, in mesi. @param array<string, mixed> $item */
    private static function termOf(array $item): int
    {
        $price = PlanPrice::findById((int) ($item['plan_price_id'] ?? 0));

        return max(1, (int) (is_array($price) ? ($price['term_months'] ?? 1) : 1));
    }

    /**
     * La madre di una riga aggiunta: come in {@see assertParent()}, fra le
     * righe in corso dell'abbonamento.
     *
     * @param array<string, mixed> $plan
     * @param list<array<string, mixed>> $live
     * @throws UserError `subscription.parent_required`, `subscription.parent_invalid`
     */
    private static function assertLiveParent(array $plan, int $parentId, array $live): void
    {
        $allowed = PlanLinks::parentsOf((int) $plan['id']);
        $name = trim(html_entity_decode((string) ($plan['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($parentId <= 0) {
            if ($allowed !== []) {
                throw UserError::make('subscription.parent_required', ['name' => $name]);
            }

            return;
        }

        $mother = null;

        foreach ($live as $row) {
            if ((int) $row['id'] === $parentId && in_array((string) $row['status'], ['pending', 'active'], true)) {
                $mother = $row;
            }
        }

        $motherPrice = $mother !== null ? PlanPrice::findById((int) $mother['plan_price_id']) : null;

        if ($mother === null || (int) $mother['parent_item_id'] > 0 || !is_array($motherPrice) || !in_array((int) $motherPrice['plan_id'], $allowed, true)) {
            throw UserError::make('subscription.parent_invalid', ['name' => $name]);
        }
    }

    /**
     * I legami di una riga che cambia piano: la madre deve essere fra quelli
     * del piano nuovo (o nessuna, se non ne chiede) e le figlie in corso
     * devono poter stare sotto il piano nuovo.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $plan il piano nuovo
     * @throws UserError `subscription.link_broken`
     */
    private static function assertLinks(array $item, array $plan): void
    {
        $allowed = PlanLinks::parentsOf((int) $plan['id']);
        $name = trim(html_entity_decode((string) ($plan['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $planOf = static function (int $itemId): int {
            $row = $itemId > 0 ? SubscriptionItem::findById($itemId) : null;
            $price = is_array($row) ? PlanPrice::findById((int) $row['plan_price_id']) : null;

            return is_array($price) ? (int) $price['plan_id'] : 0;
        };
        $parentId = (int) ($item['parent_item_id'] ?? 0);

        if (($parentId > 0 && !in_array($planOf($parentId), $allowed, true)) || ($parentId <= 0 && $allowed !== [])) {
            throw UserError::make('subscription.link_broken', ['name' => $name]);
        }

        foreach (self::forSubscription((int) $item['subscription_id'], ['pending', ...SubscriptionItem::LIVE]) as $child) {
            if ((int) $child['parent_item_id'] !== (int) $item['id']) {
                continue;
            }

            $childPrice = PlanPrice::findById((int) $child['plan_price_id']);

            if ($allowed !== [] || !is_array($childPrice) || !in_array((int) $plan['id'], PlanLinks::parentsOf((int) $childPrice['plan_id']), true)) {
                throw UserError::make('subscription.link_broken', ['name' => $name]);
            }
        }
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
