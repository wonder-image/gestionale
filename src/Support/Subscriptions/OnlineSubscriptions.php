<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use DateTimeImmutable;
use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCustomer;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItem;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionStatusLog;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Models\Tax\TaxRule;
use Wonder\Plugin\Gestionale\Providers\Payments\SubscriptionGateway;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
use Wonder\Plugin\Gestionale\Support\Providers\ExternalReferences;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Plugin\Gestionale\Support\Tax\TaxResolver;
use Wonder\Sql\Transaction;

/**
 * Gli abbonamenti online (G10 §2): sottoscrizione con Stripe Checkout, cambio
 * piano, disdetta, portale di Stripe, riallineamento e fine del vincolo.
 *
 * La verità dello stato la decidono le notifiche (`SubscriptionEvents`): qui
 * si crea l'abbonamento `pending`, si chiede a Stripe e si registra quello che
 * il cliente ha chiesto. Tutte le chiamate passano da `SubscriptionGateways`.
 */
final class OnlineSubscriptions
{
    /** Dopo quanto un `pending` senza abbonamento Stripe è abbandonato: la Checkout Session dura 24 ore. */
    public const ABANDONED_HOURS = 24;

    /** Dopo quanto il riallineamento rilegge un `pending` da Stripe. */
    public const RECONCILE_AFTER_MINUTES = 60;

    /** Quanti servizi al più in un Checkout: Stripe non ne accetta di più in un abbonamento. */
    public const MAX_ITEMS = 20;

    /**
     * Apre la sottoscrizione: abbonamento `pending` con fatturazione e
     * importi, una riga `pending` per servizio, Checkout Session di Stripe.
     * Ridà l'URL della pagina di Stripe.
     *
     * Un codice solo (`'ppr_...'`) vale come un servizio con quantità 1: è la
     * forma di prima di G10b, che l'ecommerce usa finché non passa ai servizi.
     *
     * @param list<array{price_code: string, quantity?: int, customization?: array<int|string, mixed>, parent?: int}>|string $items `parent` = indice di un'altra riga, -1 se nessuna
     * @param array<string, mixed> $billing i campi `billing_` del form del sito
     * @param array{success: string, cancel: string} $urls `{SUBSCRIPTION}` diventa il codice dell'abbonamento; `{CHECKOUT_SESSION_ID}` resta a Stripe
     * @return array{subscription_id: int, code: string, url: string}
     */
    public static function start(int $customerId, array|string $items, array $billing, string $couponCode, array $urls): array
    {
        $gateway = SubscriptionGateways::get();

        if (!Gestionale::feature('online_subscriptions') || $gateway === null || !$gateway->connected()) {
            throw UserError::make('subscription.unavailable');
        }

        $contact = $customerId > 0 ? Contact::findById($customerId) : null;

        if (!is_array($contact) || (int) ($contact['id'] ?? 0) <= 0) {
            throw UserError::make('subscription.account_required');
        }

        $items = is_string($items) ? [['price_code' => $items]] : array_values(array_filter($items, 'is_array'));

        if (count($items) > self::MAX_ITEMS) {
            throw UserError::make('subscription.too_many_items', ['max' => (string) self::MAX_ITEMS]);
        }

        // Il sito vende dal codice `ppr_` di un piano visibile online; le righe vogliono l'id.
        $items = array_map(static fn (array $item): array => [
            'plan_price_id' => (int) self::sellablePrice((string) ($item['price_code'] ?? ''))['id'],
            'quantity' => (int) ($item['quantity'] ?? 1),
            'customization' => is_array($item['customization'] ?? null) ? $item['customization'] : [],
            'parent' => (int) ($item['parent'] ?? -1),
        ], $items);

        Subscriptions::assertRoom($customerId);

        $coupon = trim($couponCode) !== '' ? self::couponFor($couponCode, $customerId) : null;
        $billing = self::billingFor($billing, $contact);
        $includeTax = (string) (Setting::current()['catalog_prices_include_tax'] ?? 'true') === 'true';
        $lines = SubscriptionItems::prepare($items, $billing, $includeTax);
        $amounts = SubscriptionAmounts::ofItems($lines, $coupon, $includeTax, 0, min(array_column($lines, 'term')));
        $rates = array_values(array_unique(array_map(static fn (array $line): string => number_format((float) $line['tax_rate'], 2, '.', ''), $lines)));
        // L'aliquota dell'abbonamento è un riepilogo: quella delle righe se è una sola, altrimenti 0.
        [$taxId, $taxRate] = count($rates) === 1 ? [(int) $lines[0]['tax_id'], (float) $rates[0]] : [0, 0.0];
        // La prova è una per abbonamento (Stripe ne ha una): la più lunga dei piani che il cliente non ha già avuto.
        $trial = 0;

        foreach ($lines as $line) {
            if ((int) $line['trial_days'] > $trial && !self::hadPlan($customerId, (int) $line['plan']['id'])) {
                $trial = (int) $line['trial_days'];
            }
        }

        $now = new DateTimeImmutable();

        [$row, $itemIds] = Transaction::run(static function () use ($gateway, $billing, $customerId, $coupon, $includeTax, $amounts, $taxId, $taxRate, $lines, $now, $trial): array {
            // Il codice si dà qui: `create()` non lo genera, e serve a Stripe e al ritorno dal pagamento.
            $created = Subscription::create($billing + [
                'code' => Code::make(Subscription::class, Codes::SUBSCRIPTION),
                'customer_id' => $customerId,
                'channel' => 'online',
                'status' => 'pending',
                'provider' => 'stripe',
                'environment' => $gateway->environment(),
                'coupon_id' => $coupon !== null ? (int) $coupon['id'] : 0,
                'coupon_code' => $coupon !== null ? (string) $coupon['code'] : '',
                'prices_include_tax' => $includeTax ? 'true' : 'false',
                'subtotal' => $amounts['subtotal'],
                'discount_total' => $amounts['discount_total'],
                'tax_id' => $taxId,
                'tax_country' => 'IT',
                'tax_rate' => number_format($taxRate, 2, '.', ''),
                'tax_total' => $amounts['tax_total'],
                'total' => $amounts['total'],
                'currency' => 'EUR',
                'auto_renew' => 'true',
            ]);
            $id = (int) ($created->insert_id ?? 0);
            $row = $id > 0 ? Subscription::findById($id) : null;

            if (!is_array($row)) {
                throw new \RuntimeException('Abbonamento non scritto.');
            }

            // Le date vere le porta la notifica di Stripe; fino ad allora contano da adesso.
            $itemIds = SubscriptionItems::insert($id, $lines, 'pending', $now, $now->modify('+'.$trial.' days'), 'api');
            StatusLogger::record(SubscriptionStatusLog::class, $id, 'status', '', 'pending', 'api', null, 'Sottoscrizione dal sito');

            return [$row, $itemIds];
        });
        $id = (int) $row['id'];
        // Il ritorno da Stripe porta il codice dell'abbonamento, che nasce solo adesso.
        $urls = array_map(static fn ($url): string => str_replace('{SUBSCRIPTION}', rawurlencode((string) $row['code']), (string) $url), $urls);

        try {
            $session = self::checkoutSession($gateway, $row, $lines, $itemIds, $coupon, $trial, (string) ($contact['email'] ?? ''), $urls);
        } catch (Throwable $error) {
            Errors::report('stripe', 'subscription.checkout', $error, ['subscription' => $id]);
            SubscriptionStatus::move($id, 'cancelled', 'system', 'Stripe non ha aperto il pagamento');

            throw UserError::make('subscription.stripe_unavailable');
        }

        ExternalReferences::save('subscription', $id, 'stripe', 'checkout_session', (string) $session['id'], $gateway->environment());

        return ['subscription_id' => $id, 'code' => (string) $row['code'], 'url' => (string) ($session['url'] ?? '')];
    }

    /**
     * Cambio piano (G10.13) della riga principale: subito, con il nuovo
     * importo dal rinnovo dopo, senza conguagli; il coupon resta. Passa da
     * {@see SubscriptionItems::change()} con `free`.
     */
    public static function changePlan(int $subscriptionId, int $newPriceId, string $source = 'api', ?int $userId = null): void
    {
        $row = self::liveStripe($subscriptionId);
        $price = PlanPrice::findById($newPriceId);
        $plan = is_array($price) ? Plan::findById((int) $price['plan_id']) : null;

        if (!is_array($price) || (string) ($price['active'] ?? 'false') !== 'true' || (string) ($price['deleted'] ?? 'false') !== 'false'
            || !is_array($plan) || (string) ($plan['active'] ?? 'false') !== 'true') {
            throw UserError::make('subscription.price_unavailable');
        }

        $main = SubscriptionItems::main((int) $row['id']);

        if ($main === null || $newPriceId === (int) $main['plan_price_id']) {
            return;
        }

        SubscriptionItems::change((int) $main['id'], $newPriceId, 'free', [], (int) ($userId ?? 0), StatusLogger::normalizeSource($source));
    }

    /**
     * Disdetta (G10.13): il rinnovo si spegne e l'abbonamento resta fino a
     * fine periodo. Durante il vincolo la disdetta si registra e vale a fine
     * vincolo: la passa a Stripe `commitments()`.
     *
     * @return string la data di fine, `Y-m-d H:i:s`
     */
    public static function cancel(int $subscriptionId, string $source = 'api', ?int $userId = null): string
    {
        $row = self::liveStripe($subscriptionId);

        if ((string) $row['auto_renew'] !== 'true' || !self::empty($row['cancel_requested_at'] ?? '')) {
            throw UserError::make('subscription.already_cancelled');
        }

        $inCommitment = !self::empty($row['commitment_ends_at'] ?? '') && strtotime((string) $row['commitment_ends_at']) > time()
            && strtotime((string) $row['commitment_ends_at']) > strtotime((string) ($row['current_period_end'] ?? 'now'));

        if (!$inCommitment) {
            // Con uno schedule attaccato Stripe non accetta la disdetta: i cambi futuri cadono con l'abbonamento.
            self::releaseSchedule($row);
            self::gateway($row)->updateSubscription((string) $row['provider_reference'], ['cancel_at_period_end' => true]);
        }

        Subscription::update([
            'auto_renew' => 'false',
            'cancel_requested_at' => date('Y-m-d H:i:s'),
            'next_renewal_at' => '',
        ], $subscriptionId);
        StatusLogger::record(SubscriptionStatusLog::class, $subscriptionId, 'auto_renew', 'true', 'false', $source, $userId, 'Disdetta registrata');
        SubscriptionNotifier::send('cancel_requested', $subscriptionId);

        return SubscriptionEmail::endOf($row);
    }

    /**
     * Una riga aggiunta a un abbonamento online (§5): `prorate` la mette
     * subito su Stripe e fattura adesso la quota fino al rinnovo, `free` la
     * mette senza quota, `next_period` la lascia allo schedule, che la fa
     * partire al rinnovo. Va chiamata per ultima dentro la transazione della
     * riga: se Stripe rifiuta, la riga non resta.
     *
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $item
     * @throws UserError `subscription.stripe_failed`
     */
    public static function itemAdded(array $subscription, array $item, string $billing): void
    {
        self::onStripe($subscription, static function (SubscriptionGateway $gateway, StripeCatalog $catalog) use ($subscription, $item, $billing): void {
            $line = self::stripeLine($catalog, $item, self::takenPrices((int) $subscription['id'], (int) $item['id']), self::inclusive($subscription));
            SubscriptionItem::update(['provider_price' => $line['price']], (int) $item['id']);

            if ($billing !== 'next_period') {
                $created = $gateway->createSubscriptionItem($line + [
                    'subscription' => (string) $subscription['provider_reference'],
                    'metadata' => ['item_id' => (string) (int) $item['id']],
                    'proration_behavior' => $billing === 'prorate' ? 'always_invoice' : 'none',
                    'payment_behavior' => 'error_if_incomplete',
                ], 'subscription-item-'.(int) $item['id']);
                SubscriptionItem::update(['provider_reference' => (string) $created['id']], (int) $item['id']);
            }

            self::schedule($gateway, $catalog, $subscription);
        }, (int) $item['id']);
    }

    /**
     * Righe tolte da un abbonamento online, con le figlie: con `credit` le
     * righe già su Stripe si tolgono subito, col credito della parte non
     * goduta sulla prossima fattura; con `period_end` restano fino alla loro
     * fine, che decide lo schedule. Le righe sono quelle di prima del cambio.
     *
     * @param array<string, mixed> $subscription
     * @param list<array<string, mixed>> $rows
     * @throws UserError `subscription.stripe_failed`
     */
    public static function itemRemoved(array $subscription, array $rows, string $billing): void
    {
        self::onStripe($subscription, static function (SubscriptionGateway $gateway, StripeCatalog $catalog) use ($subscription, $rows, $billing): void {
            foreach ($rows as $row) {
                $reference = self::referenceOf($row);

                if ($billing === 'credit' && $reference !== '' && in_array((string) ($row['status'] ?? ''), SubscriptionItem::LIVE, true)) {
                    $gateway->deleteSubscriptionItem($reference, ['proration_behavior' => 'create_prorations']);
                }
            }

            self::schedule($gateway, $catalog, $subscription);
        }, 0, $rows);
    }

    /**
     * Una riga cambiata subito (prezzo o personalizzazioni): su Stripe cambia
     * il prezzo della sua voce, con la quota fatturata adesso se `prorate`.
     * Una riga che non è ancora su Stripe cambia solo nello schedule.
     *
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $item la riga già aggiornata
     * @throws UserError `subscription.stripe_failed`
     */
    public static function itemChanged(array $subscription, array $item, int $newPriceId, string $billing): void
    {
        self::onStripe($subscription, static function (SubscriptionGateway $gateway, StripeCatalog $catalog) use ($subscription, $item, $newPriceId, $billing): void {
            $line = self::stripeLine($catalog, ['plan_price_id' => $newPriceId] + $item, self::takenPrices((int) $subscription['id'], (int) $item['id']), self::inclusive($subscription));
            $reference = self::referenceOf($item);

            if ($reference !== '') {
                $params = $line + [
                    'metadata' => ['item_id' => (string) (int) $item['id']],
                    'proration_behavior' => $billing === 'prorate' ? 'always_invoice' : 'none',
                ];
                // Senza IVA la voce perde l'aliquota di prima.
                $params['tax_rates'] ??= [];

                if ($billing === 'prorate') {
                    $params['payment_behavior'] = 'error_if_incomplete';
                }

                $gateway->updateSubscriptionItem($reference, $params);
            }

            SubscriptionItem::update(['provider_price' => $line['price']], (int) $item['id']);
            self::schedule($gateway, $catalog, $subscription);
        });
    }

    /**
     * Rifà lo schedule di Stripe dalle righe: le righe `pending` partono al
     * loro inizio, le `ending` finiscono alla loro fine, i cambi «dal
     * prossimo periodo» valgono dal loro giorno. Senza niente in arrivo lo
     * schedule si stacca.
     *
     * @param array<string, mixed> $subscription
     * @throws UserError `subscription.stripe_failed`
     */
    public static function reschedule(array $subscription): void
    {
        self::onStripe($subscription, static function (SubscriptionGateway $gateway, StripeCatalog $catalog) use ($subscription): void {
            self::schedule($gateway, $catalog, $subscription);
        });
    }

    /**
     * Quanto costa adesso un servizio aggiunto con `prorate`: l'anteprima
     * della fattura di Stripe, con la quota fino al rinnovo.
     *
     * @param array{plan_price_id?: int, quantity?: int, customization?: array<int|string, mixed>} $item
     * @return array{amount: float, currency: string}
     * @throws UserError
     */
    public static function quote(int $subscriptionId, array $item): array
    {
        $row = self::liveStripe($subscriptionId);
        $line = SubscriptionItems::prepareLine($item, $row, self::inclusive($row));
        $preview = [];

        self::onStripe($row, static function (SubscriptionGateway $gateway, StripeCatalog $catalog) use ($row, $line, &$preview): void {
            $item = [
                'plan_price_id' => (int) $line['price']['id'],
                'quantity' => (int) $line['quantity'],
                'customization_total' => $line['customization_total'],
                'tax_id' => (int) $line['tax_id'],
                'tax_rate' => (float) $line['tax_rate'],
            ];
            $preview = $gateway->previewInvoice([
                'subscription' => (string) $row['provider_reference'],
                'subscription_details' => [
                    'items' => [self::stripeLine($catalog, $item, self::takenPrices((int) $row['id'], 0), self::inclusive($row))],
                    'proration_behavior' => 'always_invoice',
                ],
            ]);
        });

        return [
            'amount' => round((int) ($preview['amount_due'] ?? 0) / 100, 2),
            'currency' => strtolower((string) ($preview['currency'] ?? 'eur')) ?: 'eur',
        ];
    }

    /** Il portale di Stripe per la carta, con il ritorno all'area cliente. */
    public static function portalUrl(int $subscriptionId, string $returnUrl): string
    {
        $row = Subscription::findById($subscriptionId);

        if (!is_array($row) || (string) ($row['provider'] ?? '') !== 'stripe') {
            throw UserError::make('subscription.not_found');
        }

        $gateway = self::gateway($row);
        $customer = ExternalReferences::find('contact', (int) $row['customer_id'], 'stripe', 'customer', $gateway->environment());

        if ($customer === null || trim((string) $customer['external_id']) === '') {
            throw UserError::make('subscription.not_found');
        }

        return (string) ($gateway->createPortalSession(['customer' => (string) $customer['external_id'], 'return_url' => $returnUrl])['url'] ?? '');
    }

    /**
     * La rete sotto le notifiche: i `pending` da più di un'ora e i `past_due`
     * dell'ambiente si rileggono da Stripe; un `pending` senza abbonamento
     * Stripe oltre le 24 ore è abbandonato e si chiude.
     *
     * @return array{synced: int, abandoned: int}
     */
    public static function reconcile(?SubscriptionGateway $gateway = null, ?DateTimeImmutable $now = null): array
    {
        $gateway ??= SubscriptionGateways::get();
        $now ??= new DateTimeImmutable();
        $done = ['synced' => 0, 'abandoned' => 0];

        if ($gateway === null || !$gateway->connected()) {
            return $done;
        }

        $env = $gateway->environment();
        $rows = self::rows(Subscription::find(
            "`provider` = 'stripe' AND `environment` = '".$env."' AND `deleted` = 'false' AND (`status` = 'past_due' OR (`status` = 'pending' AND `creation` < '"
            .$now->modify('-'.self::RECONCILE_AFTER_MINUTES.' minutes')->format('Y-m-d H:i:s')."'))"
        ));

        foreach ($rows as $row) {
            try {
                $reference = trim((string) ($row['provider_reference'] ?? ''));

                if ($reference === '') {
                    $reference = self::fromSession($gateway, $row);
                }

                if ($reference !== '') {
                    $remote = $gateway->retrieveSubscription($reference);
                    SubscriptionEvents::synced($remote, $env, 'cron', (string) ($remote['status'] ?? '') === 'canceled');
                    $done['synced']++;
                } elseif ((string) $row['status'] === 'pending'
                    && strtotime((string) $row['creation']) < $now->modify('-'.self::ABANDONED_HOURS.' hours')->getTimestamp()) {
                    SubscriptionStatus::move((int) $row['id'], 'cancelled', 'cron', 'Pagamento non completato su Stripe');
                    $done['abandoned']++;
                }
            } catch (Throwable $error) {
                Errors::report('stripe', 'subscription.reconcile', $error, ['subscription' => (int) $row['id']]);
            }
        }

        return $done;
    }

    /**
     * Fine vincolo (G10.13), una volta al giorno: dall'ultimo periodo del
     * vincolo la disdetta chiesta passa a Stripe; senza disdetta, con un
     * prezzo di rinnovo, l'abbonamento passa a quel prezzo dal rinnovo dopo.
     * Ripetere la disdetta a Stripe non cambia niente, quindi un giorno saltato
     * si recupera al giro dopo.
     *
     * @return array{cancelled: int, renewed: int}
     */
    public static function commitments(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $done = ['cancelled' => 0, 'renewed' => 0];
        $rows = self::rows(Subscription::find(
            "`provider` = 'stripe' AND `deleted` = 'false' AND `status` IN ('".implode("','", Subscription::LIVE)."')"
            ." AND `commitment_ends_at` IS NOT NULL AND `commitment_ends_at` > '1000-01-01'"
        ));

        foreach ($rows as $row) {
            $end = strtotime((string) $row['commitment_ends_at']);
            $periodEnd = self::empty($row['current_period_end'] ?? '') ? 0 : strtotime((string) $row['current_period_end']);

            // Dall'ultimo periodo del vincolo in poi: se l'attività è rimasta
            // ferma qualche giorno, recupera al primo giro.
            if ($periodEnd <= 0 || $end > $periodEnd) {
                continue;
            }

            try {
                if (!self::empty($row['cancel_requested_at'] ?? '')) {
                    self::releaseSchedule($row);
                    self::gateway($row)->updateSubscription((string) $row['provider_reference'], ['cancel_at_period_end' => true]);
                    $done['cancelled']++;

                    continue;
                }

                $current = SubscriptionItems::priceIdOf($row);
                $price = PlanPrice::findById($current);
                $renewal = is_array($price) ? (int) ($price['renewal_plan_price_id'] ?? 0) : 0;

                if ($renewal > 0 && $renewal !== $current) {
                    self::changePlan((int) $row['id'], $renewal, 'cron');
                    $done['renewed']++;
                }
            } catch (Throwable $error) {
                Errors::report('stripe', 'subscription.commitment', $error, ['subscription' => (int) $row['id']]);
            }
        }

        return $done;
    }

    /**
     * Gli importi del periodo per l'abbonamento così com'è: le righe non
     * chiuse coi loro prezzi, quantità e sovrapprezzi, il coupon contato sugli
     * addebiti già pagati e sulla durata più corta.
     *
     * @param array<string, mixed> $row
     * @return array{subtotal: string, discount_total: string, tax_total: string, total: string}
     */
    public static function amountsFor(array $row): array
    {
        $lines = [];
        $terms = [];

        foreach (SubscriptionItems::forSubscription((int) ($row['id'] ?? 0), ['pending', 'active', 'ending']) as $item) {
            $price = PlanPrice::findById((int) $item['plan_price_id']);

            if (is_array($price) && (int) ($price['id'] ?? 0) > 0) {
                $lines[] = SubscriptionItems::amountLine($item, $price);
                $terms[] = max(1, (int) ($price['term_months'] ?? 1));
            }
        }

        $coupon = (int) ($row['coupon_id'] ?? 0) > 0 ? Coupon::findById((int) $row['coupon_id']) : null;
        $charges = (int) ($row['id'] ?? 0) > 0
            ? (int) sqlCount(Payment::$table, '`subscription_id` = '.(int) $row['id']." AND `type` = 'payment' AND `status` = 'paid' AND `deleted` = 'false'")
            : 0;
        $amounts = SubscriptionAmounts::ofItems(
            $lines,
            is_array($coupon) && $coupon !== [] ? $coupon : null,
            (string) ($row['prices_include_tax'] ?? 'true') === 'true',
            $charges,
            $terms === [] ? 1 : min($terms)
        );

        return [
            'subtotal' => $amounts['subtotal'],
            'discount_total' => $amounts['discount_total'],
            'tax_total' => $amounts['tax_total'],
            'total' => $amounts['total'],
        ];
    }

    /**
     * Il cliente ha già avuto quel piano? Conta un abbonamento partito davvero
     * (`started_at` scritto), non un `pending` abbandonato: la prova si usa una
     * volta per cliente e per piano (§4).
     */
    public static function hadPlan(int $customerId, int $planId): bool
    {
        $prices = Subscriptions::priceIds($planId);

        if ($customerId <= 0 || $prices === []) {
            return false;
        }

        return (int) sqlCount(
            Subscription::$table,
            '`customer_id` = '.$customerId
            .' AND `id` IN (SELECT `subscription_id` FROM `'.SubscriptionItem::$table.'` WHERE `plan_price_id` IN ('.implode(',', $prices).") AND `deleted` = 'false')"
            ." AND `started_at` IS NOT NULL AND `started_at` > '1000-01-01' AND `deleted` = 'false'"
        ) > 0;
    }

    /**
     * Il prezzo vendibile online dal suo codice: prezzo attivo, piano attivo e
     * visibile sul sito.
     *
     * @return array<string, mixed>
     */
    public static function sellablePrice(string $code): array
    {
        $price = trim($code) !== '' ? PlanPrice::find(['code' => trim($code), 'active' => 'true', 'deleted' => 'false'], 1) : null;
        $plan = is_array($price) && (int) ($price['id'] ?? 0) > 0 ? Plan::findById((int) $price['plan_id']) : null;

        if (!is_array($plan) || (string) ($plan['active'] ?? 'false') !== 'true' || (string) ($plan['visible_online'] ?? 'false') !== 'true'
            || (string) ($plan['deleted'] ?? 'false') !== 'false') {
            throw UserError::make('subscription.price_unavailable');
        }

        return $price;
    }

    /**
     * Il coupon scritto dal cliente (o dall'operatore, con `office`), se vale
     * per gli abbonamenti su quel canale, adesso e per lui.
     *
     * @return array<string, mixed>
     */
    public static function couponFor(string $code, int $customerId, ?string $now = null, string $channel = 'online'): array
    {
        $now ??= date('Y-m-d H:i:s');
        $coupon = Coupons::find($code) ?? throw UserError::make('coupon.unknown');

        if ((string) $coupon['active'] !== 'true') {
            throw UserError::make('coupon.inactive');
        }

        if ((string) ($coupon['applies_subscriptions'] ?? 'false') !== 'true' || !in_array((string) $coupon['discount_type'], ['percent', 'amount'], true)) {
            throw UserError::make('coupon.not_for_subscriptions');
        }

        $column = $channel === 'office' ? 'applies_office' : 'applies_online';

        if ((string) ($coupon[$column] ?? ($channel === 'office' ? 'false' : 'true')) !== 'true') {
            throw UserError::make('coupon.channel');
        }

        $starts = (string) ($coupon['starts_at'] ?? '');
        $ends = (string) ($coupon['ends_at'] ?? '');

        if (!self::empty($starts) && $starts > $now) {
            throw UserError::make('coupon.not_started');
        }

        if (!self::empty($ends) && $ends < $now) {
            throw UserError::make('coupon.expired');
        }

        $reserved = self::rows(CouponCustomer::find(['coupon_id' => (int) $coupon['id'], 'deleted' => 'false']));

        if ($reserved !== [] && !in_array($customerId, array_map(static fn (array $r): int => (int) $r['customer_id'], $reserved), true)) {
            throw UserError::make('coupon.not_yours');
        }

        return $coupon;
    }

    /**
     * La fatturazione da scrivere: i campi `billing_` del form, quelli della
     * scheda per ciò che manca.
     *
     * @param array<string, mixed> $billing
     * @param array<string, mixed> $contact
     * @return array<string, string>
     */
    public static function billingFor(array $billing, array $contact): array
    {
        $values = [];

        foreach (Subscription::billingAddress()->tableSchema() as $column) {
            $name = (string) $column->name;
            $own = substr($name, strlen('billing_'));
            $given = trim((string) ($billing[$name] ?? ''));
            $values[$name] = $given !== '' ? $given : trim((string) ($contact[$own] ?? ''));
        }

        $values['billing_country'] = strtoupper($values['billing_country'] ?? '') ?: 'IT';

        return $values;
    }

    /**
     * L'aliquota del piano per un cliente italiano (G10.4): privato o azienda
     * dalla partita IVA, regola del tipo fiscale, aliquota di ripiego.
     *
     * @param array<string, string> $billing
     * @return array{0: int, 1: float}
     */
    public static function taxFor(int $taxCategoryId, array $billing): array
    {
        $rules = self::rows(TaxRule::find(['deleted' => 'false']));
        $type = trim((string) ($billing['billing_pi'] ?? '')) !== '' ? 'business' : 'private';
        $taxId = TaxResolver::resolve($rules, 'IT', $type, $taxCategoryId, (int) (Setting::current()['fallback_tax_id'] ?? 0));
        $tax = $taxId > 0 ? Tax::findById($taxId) : null;

        return [$taxId, is_array($tax) ? (float) ($tax['rate'] ?? 0) : 0.0];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $price
     * @param array<string, mixed>|null $coupon
     * @param array{success: string, cancel: string} $urls
     * @return array<string, mixed>
     */
    /**
     * La Checkout Session: una `line_item` per riga. Il prezzo Stripe di ogni
     * riga è unico nell'abbonamento (sovrapprezzo e posto nell'impronta), e
     * resta sulla riga in `provider_price`: è così che la notifica ritrova la
     * riga di ogni `si_`, perché le `line_items` del Checkout non hanno metadati.
     *
     * @param list<array<string, mixed>> $lines le righe di {@see SubscriptionItems::prepare()}
     * @param list<int> $itemIds i loro id, nello stesso ordine
     */
    private static function checkoutSession(SubscriptionGateway $gateway, array $row, array $lines, array $itemIds, ?array $coupon, int $trial, string $email, array $urls): array
    {
        $catalog = new StripeCatalog($gateway);
        $inclusive = (string) $row['prices_include_tax'] === 'true';
        $slots = StripeCatalog::slots(array_map(static fn (array $line): array => ['plan_price_id' => (int) $line['price']['id'], 'surcharge' => (float) $line['surcharge']], $lines));
        $items = [];

        foreach (array_values($lines) as $index => $line) {
            $item = ['price' => $catalog->priceFor((int) $line['price']['id'], (float) $line['surcharge'], $slots[$index]), 'quantity' => (int) $line['quantity']];

            if ((int) $line['tax_id'] > 0 && (float) $line['tax_rate'] > 0.0) {
                $item['tax_rates'] = [$catalog->taxRateFor((int) $line['tax_id'], (float) $line['tax_rate'], $inclusive)];
            }

            SubscriptionItem::update(['provider_price' => $item['price']], $itemIds[$index]);
            $items[] = $item;
        }

        $metadata = ['subscription_id' => (string) (int) $row['id'], 'subscription_code' => (string) $row['code']];
        $params = [
            'mode' => 'subscription',
            'customer' => $catalog->customerFor((int) $row['customer_id'], $email),
            'client_reference_id' => (string) $row['code'],
            'line_items' => $items,
            // Carta, con Link (G10.10); SEPA dopo.
            'payment_method_types' => ['card', 'link'],
            // Righe con date proprie (aggiunte e cambi in corso d'anno): serve la fatturazione flessibile.
            'subscription_data' => ['billing_mode' => ['type' => 'flexible'], 'metadata' => $metadata],
            'metadata' => $metadata,
            'success_url' => (string) ($urls['success'] ?? ''),
            'cancel_url' => (string) ($urls['cancel'] ?? ''),
            'locale' => 'it',
        ];

        if ($trial > 0) {
            $params['subscription_data']['trial_period_days'] = $trial;
        }

        if ($coupon !== null) {
            $params['discounts'] = [['promotion_code' => $catalog->promotionCodeFor($coupon)]];
        }

        return $gateway->createCheckoutSession($params, 'subscription-checkout-'.(string) $row['code']);
    }

    /**
     * Le chiamate a Stripe per le righe: un guasto si segnala e diventa un
     * errore per chi ha chiesto, che annulla la transazione intorno.
     *
     * @param array<string, mixed> $subscription
     * @param callable(SubscriptionGateway, StripeCatalog): void $work
     * @param int $newItemId la riga appena aggiunta, che non va legata a voci già su Stripe
     * @param list<array<string, mixed>> $removed righe appena tolte, com'erano prima
     */
    private static function onStripe(array $subscription, callable $work, int $newItemId = 0, array $removed = []): void
    {
        $gateway = self::gateway($subscription);

        try {
            self::adopt($gateway, $subscription, $newItemId, $removed);
            $work($gateway, new StripeCatalog($gateway));
        } catch (UserError $error) {
            throw $error;
        } catch (Throwable $error) {
            Errors::report('stripe', 'subscription.item', $error, ['subscription' => (int) ($subscription['id'] ?? 0)]);

            throw UserError::make('subscription.stripe_failed');
        }
    }

    /**
     * Lega alle voci di Stripe le righe in vigore che non hanno ancora il
     * loro `si_` (abbonamenti di prima delle righe, notifica non arrivata):
     * dal `metadata.item_id`, poi una voce sola rimasta per una riga sola.
     * Scrive solo i riferimenti: prezzi e importi restano come sono.
     *
     * @param array<string, mixed> $subscription
     * @param list<array<string, mixed>> $removed righe appena tolte, com'erano prima
     */
    private static function adopt(SubscriptionGateway $gateway, array $subscription, int $newItemId, array $removed = []): void
    {
        $loose = [];
        $known = [];
        $live = SubscriptionItems::forSubscription((int) $subscription['id'], SubscriptionItem::LIVE);
        $gone = array_filter($removed, static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), SubscriptionItem::LIVE, true));

        foreach ([...$live, ...$gone] as $row) {
            $reference = trim((string) ($row['provider_reference'] ?? ''));

            if ($reference === '' && (int) $row['id'] !== $newItemId) {
                $loose[(int) $row['id']] = $row;
            } else {
                $known[$reference] = true;
            }
        }

        if ($loose === []) {
            return;
        }

        $remote = StripeSubscriptionData::subscription($gateway->retrieveSubscription((string) $subscription['provider_reference']))['items'];
        $left = [];

        foreach ($remote as $one) {
            if ($one['id'] === '' || isset($known[$one['id']])) {
                continue;
            }

            if ($one['item_id'] > 0 && isset($loose[$one['item_id']])) {
                self::bind($loose[$one['item_id']], $one);
                unset($loose[$one['item_id']]);
            } else {
                $left[] = $one;
            }
        }

        if (count($left) === 1 && count($loose) === 1) {
            self::bind(reset($loose), $left[0]);
        }
    }

    /** Il `si_` della riga com'è adesso, anche se l'ha appena legato {@see adopt()}. @param array<string, mixed> $row */
    private static function referenceOf(array $row): string
    {
        $fresh = SubscriptionItem::findById((int) ($row['id'] ?? 0));

        return trim((string) (is_array($fresh) ? ($fresh['provider_reference'] ?? '') : ($row['provider_reference'] ?? '')));
    }

    /** @param array<string, mixed> $row @param array{id: string, price: string} $one */
    private static function bind(array $row, array $one): void
    {
        $values = ['provider_reference' => $one['id']];

        if (trim((string) ($row['provider_price'] ?? '')) === '' && $one['price'] !== '') {
            $values['provider_price'] = $one['price'];
        }

        SubscriptionItem::update($values, (int) $row['id']);
    }

    /**
     * La voce Stripe di una riga: prezzo (col sovrapprezzo per unità e un
     * posto non preso da altre righe), quantità e aliquota.
     *
     * @param array<string, mixed> $item
     * @param list<string> $taken i prezzi Stripe delle altre righe
     * @return array{price: string, quantity: int, tax_rates?: list<string>}
     */
    private static function stripeLine(StripeCatalog $catalog, array $item, array $taken, bool $inclusive, ?float $surcharge = null): array
    {
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $surcharge ??= round((float) ($item['customization_total'] ?? 0) / $quantity, 2);
        $slot = 0;

        do {
            $price = $catalog->priceFor((int) $item['plan_price_id'], $surcharge, $slot++);
        } while (in_array($price, $taken, true) && $slot < self::MAX_ITEMS);

        $line = ['price' => $price, 'quantity' => $quantity];

        if ((int) ($item['tax_id'] ?? 0) > 0 && (float) ($item['tax_rate'] ?? 0) > 0.0) {
            $line['tax_rates'] = [$catalog->taxRateFor((int) $item['tax_id'], (float) $item['tax_rate'], $inclusive)];
        }

        return $line;
    }

    /** I prezzi Stripe delle righe aperte dell'abbonamento, tranne `$exceptItemId`. @return list<string> */
    private static function takenPrices(int $subscriptionId, int $exceptItemId): array
    {
        $taken = [];

        foreach (SubscriptionItems::forSubscription($subscriptionId, ['pending', ...SubscriptionItem::LIVE]) as $row) {
            $price = trim((string) ($row['provider_price'] ?? ''));

            if ((int) $row['id'] !== $exceptItemId && $price !== '') {
                $taken[] = $price;
            }
        }

        return $taken;
    }

    /** Se i prezzi dell'abbonamento comprendono l'IVA. @param array<string, mixed> $subscription */
    private static function inclusive(array $subscription): bool
    {
        return (string) ($subscription['prices_include_tax'] ?? 'true') !== 'false';
    }

    /**
     * Lo schedule dalle righe (vedi {@see reschedule()}). La prima fase è
     * quella in corso, con le voci che Stripe ha già; ogni giorno in arrivo
     * apre una fase con le righe in vigore da lì; l'ultima dura un periodo
     * della durata più corta, poi lo schedule si stacca. Al più 10 fasi:
     * quelle oltre si aggiungono a uno dei cambi dopo.
     *
     * @param array<string, mixed> $subscription
     */
    private static function schedule(SubscriptionGateway $gateway, StripeCatalog $catalog, array $subscription): void
    {
        $id = (int) $subscription['id'];
        $now = time();
        $rows = SubscriptionItems::forSubscription($id, ['pending', ...SubscriptionItem::LIVE]);
        $changes = [];
        $edges = [];

        foreach ($rows as $row) {
            $at = 0;

            if ((string) $row['status'] === 'pending') {
                $at = self::stamp($row['starts_at'] ?? '');
            } elseif ((string) $row['status'] === 'ending') {
                $at = self::stamp($row['ends_at'] ?? '');
            } elseif (($change = SubscriptionItems::scheduledChange($row)) !== null) {
                $changes[(int) $row['id']] = $change;
                $at = self::stamp($change['effective_at'] ?? '');
            }

            if ($at > $now) {
                $edges[] = $at;
            }
        }

        $edges = array_values(array_unique($edges));
        sort($edges);
        [$scheduleId, $schedule] = self::liveSchedule($gateway, $id);

        if ($edges === []) {
            if ($scheduleId !== '') {
                $gateway->releaseSubscriptionSchedule($scheduleId);
            }

            return;
        }

        if ($scheduleId === '') {
            $schedule = $gateway->createSubscriptionSchedule(['from_subscription' => (string) $subscription['provider_reference']], 'subscription-schedule-'.$id.'-'.$now);
            $scheduleId = (string) $schedule['id'];
            ExternalReferences::save('subscription', $id, 'stripe', 'subscription_schedule', $scheduleId, $gateway->environment());
        }

        $inclusive = self::inclusive($subscription);
        $phases = self::phaseZero($catalog, $schedule, $subscription, $rows, $inclusive, $edges[0]);
        $kept = array_intersect_key($phases[0], ['discounts' => true]);

        foreach ($edges as $index => $at) {
            $items = self::itemsAt($catalog, $rows, $changes, $at, $inclusive);

            if ($items === [] || count($phases) >= 10) {
                break;
            }

            $phase = $kept + ['items' => $items['items']];

            if (isset($edges[$index + 1]) && count($phases) < 9) {
                $phase['end_date'] = $edges[$index + 1];
            } else {
                [$interval, $count] = StripeCatalog::recurring($items['shortest']);
                $phase['duration'] = ['interval' => $interval, 'interval_count' => $count];
            }

            $phases[] = $phase;

            if (isset($phase['duration'])) {
                break;
            }
        }

        $gateway->updateSubscriptionSchedule($scheduleId, ['end_behavior' => 'release', 'proration_behavior' => 'none', 'phases' => $phases]);
    }

    /**
     * La fase in corso: il suo inizio e i suoi sconti, prova e aliquote
     * come Stripe li ha, le voci delle righe già su Stripe.
     *
     * @param array<string, mixed> $schedule
     * @param array<string, mixed> $subscription
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function phaseZero(StripeCatalog $catalog, array $schedule, array $subscription, array $rows, bool $inclusive, int $end): array
    {
        $phases = is_array($schedule['phases'] ?? null) ? array_values(array_filter($schedule['phases'], 'is_array')) : [];
        $start = (int) ($schedule['current_phase']['start_date'] ?? 0);
        $current = $phases[0] ?? [];

        foreach ($phases as $phase) {
            if ($start > 0 && (int) ($phase['start_date'] ?? 0) === $start) {
                $current = $phase;
            }
        }

        $start = $start > 0 ? $start : ((int) ($current['start_date'] ?? 0) ?: (self::stamp($subscription['current_period_start'] ?? '') ?: time()));
        $phase = ['start_date' => $start, 'end_date' => $end, 'items' => []];
        $discounts = self::discounts($current['discounts'] ?? []);

        if ($discounts !== []) {
            $phase['discounts'] = $discounts;
        }

        if ((int) ($current['trial_end'] ?? 0) > 0) {
            $phase['trial_end'] = (int) $current['trial_end'];
        }

        $taxRates = array_values(array_filter(array_map(static fn ($rate): string => is_array($rate) ? (string) ($rate['id'] ?? '') : (string) $rate, (array) ($current['default_tax_rates'] ?? []))));

        if ($taxRates !== []) {
            $phase['default_tax_rates'] = $taxRates;
        }

        if (is_string($current['collection_method'] ?? null) && $current['collection_method'] !== '') {
            $phase['collection_method'] = $current['collection_method'];
        }

        foreach ($rows as $row) {
            $price = trim((string) ($row['provider_price'] ?? ''));

            if (trim((string) ($row['provider_reference'] ?? '')) === '' || $price === '' || !in_array((string) $row['status'], SubscriptionItem::LIVE, true)) {
                continue;
            }

            $phase['items'][] = self::scheduleItem($catalog, $row, $price, $inclusive);
        }

        return [$phase];
    }

    /**
     * Le voci in vigore da `$at`, col prezzo nuovo per i cambi già partiti,
     * e il prezzo con la durata più corta.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $changes
     * @return array{items: list<array<string, mixed>>, shortest: array<string, mixed>}|array{}
     */
    private static function itemsAt(StripeCatalog $catalog, array $rows, array $changes, int $at, bool $inclusive): array
    {
        $items = [];
        $used = [];
        $shortest = null;
        $later = [];

        foreach ($rows as $row) {
            $status = (string) $row['status'];

            if (($status === 'pending' && self::stamp($row['starts_at'] ?? '') > $at)
                || ($status === 'ending' && self::stamp($row['ends_at'] ?? '') <= $at)) {
                continue;
            }

            $change = $changes[(int) $row['id']] ?? null;

            if ($change !== null && self::stamp($change['effective_at'] ?? '') <= $at) {
                // Il prezzo nuovo si sceglie dopo, fra quelli che restano liberi.
                $later[] = [$row, $change];

                continue;
            }

            $price = trim((string) ($row['provider_price'] ?? ''));
            $price = $price !== '' ? $price : self::stripeLine($catalog, $row, $used, $inclusive)['price'];
            $used[] = $price;
            $items[] = self::scheduleItem($catalog, $row, $price, $inclusive);
            $shortest = self::shorter($shortest, (int) $row['plan_price_id']);
        }

        foreach ($later as [$row, $change]) {
            $fields = Customizations::decode((string) ($change['note'] ?? ''));
            $surcharge = (float) array_sum(array_map(static fn (array $field): float => (float) $field['surcharge'], $fields));
            $changed = ['plan_price_id' => (int) $change['new_plan_price_id']] + $row;
            $price = self::stripeLine($catalog, $changed, $used, $inclusive, $surcharge)['price'];
            $used[] = $price;
            $items[] = self::scheduleItem($catalog, $changed, $price, $inclusive);
            $shortest = self::shorter($shortest, (int) $change['new_plan_price_id']);
        }

        return $items === [] || $shortest === null ? [] : ['items' => $items, 'shortest' => $shortest];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function scheduleItem(StripeCatalog $catalog, array $row, string $price, bool $inclusive): array
    {
        $item = ['price' => $price, 'quantity' => max(1, (int) ($row['quantity'] ?? 1)), 'metadata' => ['item_id' => (string) (int) $row['id']]];

        if ((int) ($row['tax_id'] ?? 0) > 0 && (float) ($row['tax_rate'] ?? 0) > 0.0) {
            $item['tax_rates'] = [$catalog->taxRateFor((int) $row['tax_id'], (float) $row['tax_rate'], $inclusive)];
        }

        return $item;
    }

    /** Il prezzo con la durata più corta fra `$current` e quello di `$priceId`. @param array<string, mixed>|null $current @return array<string, mixed>|null */
    private static function shorter(?array $current, int $priceId): ?array
    {
        $price = PlanPrice::findById($priceId);

        if (!is_array($price) || (int) ($price['id'] ?? 0) <= 0) {
            return $current;
        }

        return $current === null || (int) ($price['term_months'] ?? 1) < (int) ($current['term_months'] ?? 1) ? $price : $current;
    }

    /**
     * Gli sconti di una fase come si rimandano a Stripe: solo i campi
     * scritti di ogni sconto.
     *
     * @return list<array<string, string>>
     */
    private static function discounts(mixed $discounts): array
    {
        $kept = [];

        foreach (is_array($discounts) ? $discounts : [] as $discount) {
            $fields = [];

            foreach (['coupon', 'discount', 'promotion_code'] as $key) {
                $value = $discount[$key] ?? null;
                $value = is_array($value) ? ($value['id'] ?? null) : $value;

                if (is_string($value) && $value !== '') {
                    $fields[$key] = $value;
                }
            }

            if ($fields !== []) {
                $kept[] = $fields;
            }
        }

        return $kept;
    }

    /**
     * Lo schedule dell'abbonamento ancora attaccato: id e oggetto, o `['', []]`
     * se non c'è, è staccato o finito, o Stripe non lo ritrova.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function liveSchedule(SubscriptionGateway $gateway, int $subscriptionId): array
    {
        $reference = ExternalReferences::find('subscription', $subscriptionId, 'stripe', 'subscription_schedule', $gateway->environment());
        $id = $reference !== null ? trim((string) $reference['external_id']) : '';

        if ($id === '') {
            return ['', []];
        }

        try {
            $schedule = $gateway->retrieveSubscriptionSchedule($id);
        } catch (Throwable) {
            return ['', []];
        }

        return in_array((string) ($schedule['status'] ?? ''), ['released', 'canceled', 'completed'], true) ? ['', []] : [$id, $schedule];
    }

    /** Stacca lo schedule dell'abbonamento, se ce n'è uno attaccato. @param array<string, mixed> $row */
    private static function releaseSchedule(array $row): void
    {
        $gateway = self::gateway($row);
        [$id] = self::liveSchedule($gateway, (int) $row['id']);

        if ($id !== '') {
            $gateway->releaseSubscriptionSchedule($id);
        }
    }

    private static function stamp(mixed $date): int
    {
        return self::empty($date) ? 0 : (int) strtotime((string) $date);
    }

    /** L'abbonamento Stripe di una Checkout Session completata; vuoto se non c'è. @param array<string, mixed> $row */
    private static function fromSession(SubscriptionGateway $gateway, array $row): string
    {
        $session = ExternalReferences::find('subscription', (int) $row['id'], 'stripe', 'checkout_session', $gateway->environment());

        if ($session === null || trim((string) $session['external_id']) === '') {
            return '';
        }

        $remote = $gateway->retrieveCheckoutSession((string) $session['external_id']);
        $reference = is_array($remote['subscription'] ?? null) ? (string) ($remote['subscription']['id'] ?? '') : (string) ($remote['subscription'] ?? '');

        if ($reference !== '') {
            Subscription::update(['provider_reference' => $reference], (int) $row['id']);
        }

        return $reference;
    }

    /** @return array<string, mixed> */
    private static function liveStripe(int $subscriptionId): array
    {
        $row = Subscription::findById($subscriptionId);

        if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0 || (string) ($row['provider'] ?? '') !== 'stripe'
            || !in_array((string) ($row['status'] ?? ''), Subscription::LIVE, true) || trim((string) ($row['provider_reference'] ?? '')) === '') {
            throw UserError::make('subscription.not_live');
        }

        return $row;
    }

    /** Il gateway dell'ambiente dell'abbonamento. @param array<string, mixed> $row */
    private static function gateway(array $row): SubscriptionGateway
    {
        return SubscriptionGateways::get((string) ($row['environment'] ?? '')) ?? throw UserError::make('subscription.unavailable');
    }

    private static function empty(mixed $date): bool
    {
        $date = trim((string) $date);

        return $date === '' || str_starts_with($date, '0000');
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return isset($found['id']) ? [$found] : array_values(array_filter($found, 'is_array'));
    }
}
