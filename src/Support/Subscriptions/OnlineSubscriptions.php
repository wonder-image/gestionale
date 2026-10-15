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
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
use Wonder\Plugin\Gestionale\Support\Providers\ExternalReferences;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Plugin\Gestionale\Support\Tax\TaxResolver;

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

    /**
     * Apre la sottoscrizione: abbonamento `pending` con fatturazione e
     * importi, Checkout Session di Stripe. Ridà l'URL della pagina di Stripe.
     *
     * @param array<string, mixed> $billing i campi `billing_` del form del sito
     * @param array{success: string, cancel: string} $urls `{SUBSCRIPTION}` diventa il codice dell'abbonamento; `{CHECKOUT_SESSION_ID}` resta a Stripe
     * @return array{subscription_id: int, code: string, url: string}
     */
    public static function start(int $customerId, string $planPriceCode, array $billing, string $couponCode, array $urls): array
    {
        $gateway = SubscriptionGateways::get();

        if (!Gestionale::feature('online_subscriptions') || $gateway === null || !$gateway->connected()) {
            throw UserError::make('subscription.unavailable');
        }

        $contact = $customerId > 0 ? Contact::findById($customerId) : null;

        if (!is_array($contact) || (int) ($contact['id'] ?? 0) <= 0) {
            throw UserError::make('subscription.account_required');
        }

        $price = self::sellablePrice($planPriceCode);
        $plan = Plan::findById((int) $price['plan_id']);

        if (Subscriptions::live($customerId) !== null) {
            throw UserError::make('subscription.already_active');
        }

        $coupon = trim($couponCode) !== '' ? self::couponFor($couponCode, $customerId) : null;
        $billing = self::billingFor($billing, $contact);
        [$taxId, $taxRate] = self::taxFor((int) ($plan['tax_category_id'] ?? 0), $billing);
        $includeTax = (string) (Setting::current()['catalog_prices_include_tax'] ?? 'true') === 'true';
        $amounts = SubscriptionAmounts::of($price, $coupon, $taxRate, $includeTax, 0);
        $trial = (int) ($price['trial_days'] ?? 0) > 0 && !self::hadPlan($customerId, (int) $plan['id']) ? (int) $price['trial_days'] : 0;

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

        SubscriptionItems::open($row, $price);

        StatusLogger::record(SubscriptionStatusLog::class, $id, 'status', '', 'pending', 'api', null, 'Sottoscrizione dal sito');
        // Il ritorno da Stripe porta il codice dell'abbonamento, che nasce solo adesso.
        $urls = array_map(static fn ($url): string => str_replace('{SUBSCRIPTION}', rawurlencode((string) $row['code']), (string) $url), $urls);

        try {
            $session = self::checkoutSession($gateway, $row, $price, $coupon, $trial, (string) ($contact['email'] ?? ''), $urls);
        } catch (Throwable $error) {
            Errors::report('stripe', 'subscription.checkout', $error, ['subscription' => $id]);
            SubscriptionStatus::move($id, 'cancelled', 'system', 'Stripe non ha aperto il pagamento');

            throw UserError::make('subscription.stripe_unavailable');
        }

        ExternalReferences::save('subscription', $id, 'stripe', 'checkout_session', (string) $session['id'], $gateway->environment());

        return ['subscription_id' => $id, 'code' => (string) $row['code'], 'url' => (string) ($session['url'] ?? '')];
    }

    /**
     * Cambio piano (G10.13): subito, con il nuovo importo dal rinnovo dopo,
     * senza conguagli; il coupon resta.
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

        if ($newPriceId === SubscriptionItems::priceIdOf($row)) {
            return;
        }

        $gateway = self::gateway($row);
        $catalog = new StripeCatalog($gateway);
        $remote = $gateway->retrieveSubscription((string) $row['provider_reference']);
        $itemId = (string) ($remote['items']['data'][0]['id'] ?? '');
        $item = ['price' => $catalog->priceFor($newPriceId)];

        if ($itemId !== '') {
            $item['id'] = $itemId;
        }

        if ((int) $row['tax_id'] > 0 && (float) $row['tax_rate'] > 0.0) {
            $item['tax_rates'] = [$catalog->taxRateFor((int) $row['tax_id'], (float) $row['tax_rate'], (string) $row['prices_include_tax'] === 'true')];
        }

        $gateway->updateSubscription((string) $row['provider_reference'], ['items' => [$item], 'proration_behavior' => 'none']);

        SubscriptionItems::changePrice($row, $price, StatusLogger::normalizeSource($source), (int) ($userId ?? 0));
        Subscription::update(self::amountsFor($row, $newPriceId), $subscriptionId);
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
     * Gli importi del periodo per l'abbonamento così com'è (prezzo della riga
     * principale, o `$priceId`; coupon, aliquota): per il prossimo addebito,
     * contando quelli già pagati.
     *
     * @param array<string, mixed> $row
     * @return array{subtotal: string, discount_total: string, tax_total: string, total: string}
     */
    public static function amountsFor(array $row, ?int $priceId = null): array
    {
        $price = PlanPrice::findById($priceId ?? SubscriptionItems::priceIdOf($row));
        $coupon = (int) ($row['coupon_id'] ?? 0) > 0 ? Coupon::findById((int) $row['coupon_id']) : null;
        $charges = (int) ($row['id'] ?? 0) > 0
            ? (int) sqlCount(Payment::$table, '`subscription_id` = '.(int) $row['id']." AND `type` = 'payment' AND `status` = 'paid' AND `deleted` = 'false'")
            : 0;
        $amounts = SubscriptionAmounts::of(
            is_array($price) ? $price : [],
            is_array($coupon) && $coupon !== [] ? $coupon : null,
            (float) ($row['tax_rate'] ?? 0),
            (string) ($row['prices_include_tax'] ?? 'true') === 'true',
            $charges
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
    private static function checkoutSession(SubscriptionGateway $gateway, array $row, array $price, ?array $coupon, int $trial, string $email, array $urls): array
    {
        $catalog = new StripeCatalog($gateway);
        $item = ['price' => $catalog->priceFor((int) $price['id']), 'quantity' => 1];

        if ((int) $row['tax_id'] > 0 && (float) $row['tax_rate'] > 0.0) {
            $item['tax_rates'] = [$catalog->taxRateFor((int) $row['tax_id'], (float) $row['tax_rate'], (string) $row['prices_include_tax'] === 'true')];
        }

        $metadata = ['subscription_id' => (string) (int) $row['id'], 'subscription_code' => (string) $row['code']];
        $params = [
            'mode' => 'subscription',
            'customer' => $catalog->customerFor((int) $row['customer_id'], $email),
            'client_reference_id' => (string) $row['code'],
            'line_items' => [$item],
            // Carta, con Link (G10.10); SEPA dopo.
            'payment_method_types' => ['card', 'link'],
            'subscription_data' => ['metadata' => $metadata],
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
