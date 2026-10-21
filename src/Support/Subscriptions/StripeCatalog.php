<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use RuntimeException;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Providers\Payments\SubscriptionGateway;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Providers\ExternalReferences;

/**
 * Piani, prezzi, aliquote e coupon in Stripe (G10.9), creati alla prima
 * occorrenza e ritrovati con `ExternalReferences`.
 *
 * Un prezzo in Stripe non si cambia: un prezzo cambiato nel gestionale è
 * un'**impronta** diversa (importo, intervallo, mesi, valuta) e quindi un
 * prezzo nuovo, con un suo `object_type` (`price:2490-month-1-eur`). Il
 * vecchio resta agli abbonati che lo usano finché non cambiano piano. Lo
 * stesso per coupon e codici promozionali; il codice promozionale di prima si
 * spegne, perché in Stripe un codice attivo è unico.
 *
 * I metodi `*Params()` e `fingerprint()` sono puri: li provano i test senza
 * database.
 */
final class StripeCatalog
{
    public function __construct(private readonly SubscriptionGateway $gateway)
    {
    }

    /**
     * L'id del prezzo Stripe per quel prezzo del piano, creato se manca.
     *
     * Con il sovrapprezzo delle personalizzazioni (per unità) l'importo è un
     * altro, quindi un altro prezzo. `$slot` distingue le righe uguali dello
     * stesso abbonamento: Stripe non accetta lo stesso prezzo su due righe
     * (`slots()`).
     */
    public function priceFor(int $planPriceId, float $surcharge = 0.0, int $slot = 0): string
    {
        $price = PlanPrice::findById($planPriceId);

        if (!is_array($price) || (int) ($price['id'] ?? 0) <= 0) {
            throw new RuntimeException('Prezzo del piano sconosciuto: '.$planPriceId);
        }

        $env = $this->gateway->environment();
        $fingerprint = static::fingerprint($price, $surcharge, $slot);
        $object = 'price:'.$fingerprint;
        $saved = ExternalReferences::find('plan_price', $planPriceId, 'stripe', $object, $env);

        if ($saved !== null && trim((string) $saved['external_id']) !== '') {
            return (string) $saved['external_id'];
        }

        $product = $this->productFor((int) $price['plan_id']);
        $created = $this->gateway->createPrice(
            static::priceParams($price, $product, $surcharge),
            'plan-price-'.(string) $price['code'].'-'.$fingerprint.'-'.$env
        );
        ExternalReferences::save('plan_price', $planPriceId, 'stripe', $object, (string) $created['id'], $env);

        return (string) $created['id'];
    }

    /** L'id del prodotto Stripe del piano, creato se manca. */
    public function productFor(int $planId): string
    {
        $env = $this->gateway->environment();
        $saved = ExternalReferences::find('plan', $planId, 'stripe', 'product', $env);

        if ($saved !== null && trim((string) $saved['external_id']) !== '') {
            return (string) $saved['external_id'];
        }

        $plan = Plan::findById($planId);

        if (!is_array($plan) || (int) ($plan['id'] ?? 0) <= 0) {
            throw new RuntimeException('Piano sconosciuto: '.$planId);
        }

        $created = $this->gateway->createProduct(static::productParams($plan), 'plan-'.(string) $plan['code'].'-'.$env);
        ExternalReferences::save('plan', $planId, 'stripe', 'product', (string) $created['id'], $env);

        return (string) $created['id'];
    }

    /** L'aliquota Stripe, inclusa o esclusa, per quell'aliquota del gestionale. */
    public function taxRateFor(int $taxId, float $rate, bool $inclusive): string
    {
        $env = $this->gateway->environment();
        $object = 'tax_rate:'.($inclusive ? 'incl' : 'excl').':'.number_format($rate, 2, '.', '');
        $saved = ExternalReferences::find('tax', $taxId, 'stripe', $object, $env);

        if ($saved !== null && trim((string) $saved['external_id']) !== '') {
            return (string) $saved['external_id'];
        }

        $created = $this->gateway->createTaxRate(static::taxRateParams($rate, $inclusive), 'tax-'.$taxId.'-'.str_replace(':', '-', $object).'-'.$env);
        ExternalReferences::save('tax', $taxId, 'stripe', $object, (string) $created['id'], $env);

        return (string) $created['id'];
    }

    /**
     * L'id del codice promozionale Stripe di quel coupon, con il coupon Stripe
     * della stessa impronta. Se il coupon è cambiato, il codice di prima si
     * spegne.
     *
     * @param array<string, mixed> $coupon riga di `gst_coupons`
     */
    public function promotionCodeFor(array $coupon): string
    {
        $couponId = (int) ($coupon['id'] ?? 0);
        $env = $this->gateway->environment();
        $fingerprint = static::couponFingerprint($coupon);
        $object = 'promotion_code:'.$fingerprint;
        $saved = ExternalReferences::find('coupon', $couponId, 'stripe', $object, $env);

        if ($saved !== null && trim((string) $saved['external_id']) !== '') {
            return (string) $saved['external_id'];
        }

        $stripeCoupon = ExternalReferences::find('coupon', $couponId, 'stripe', 'coupon:'.$fingerprint, $env);
        $stripeCouponId = $stripeCoupon !== null ? trim((string) $stripeCoupon['external_id']) : '';

        if ($stripeCouponId === '') {
            $created = $this->gateway->createCoupon(static::couponParams($coupon), 'coupon-'.$couponId.'-'.$fingerprint.'-'.$env);
            $stripeCouponId = (string) $created['id'];
            ExternalReferences::save('coupon', $couponId, 'stripe', 'coupon:'.$fingerprint, $stripeCouponId, $env);
        }

        // In Stripe un codice attivo è unico: quello della versione di prima si spegne.
        foreach (ExternalReferences::all('coupon', $couponId) as $old) {
            if ((string) $old['provider'] === 'stripe' && (string) $old['environment'] === $env
                && str_starts_with((string) $old['object_type'], 'promotion_code:') && (string) $old['object_type'] !== $object
                && trim((string) $old['external_id']) !== '') {
                $this->gateway->updatePromotionCode((string) $old['external_id'], ['active' => false]);
                ExternalReferences::forget('coupon', $couponId, 'stripe', (string) $old['object_type'], $env);
            }
        }

        $created = $this->gateway->createPromotionCode(
            static::promotionCodeParams($coupon, $stripeCouponId),
            'promotion-code-'.$couponId.'-'.$fingerprint.'-'.$env
        );
        ExternalReferences::save('coupon', $couponId, 'stripe', $object, (string) $created['id'], $env);

        return (string) $created['id'];
    }

    /** Il cliente Stripe della scheda, lo stesso dei pagamenti degli ordini. */
    public function customerFor(int $contactId, string $email): string
    {
        $env = $this->gateway->environment();
        $saved = ExternalReferences::find('contact', $contactId, 'stripe', 'customer', $env);

        if ($saved !== null && trim((string) $saved['external_id']) !== '') {
            return (string) $saved['external_id'];
        }

        $contact = Contact::findById($contactId);
        $created = $this->gateway->createCustomer(array_filter([
            'email' => $email,
            'name' => is_array($contact) && $contact !== [] ? Contacts::displayName($contact) : '',
            'metadata' => ['contact_id' => (string) $contactId],
        ]), 'contact-'.$contactId.'-'.$env);
        ExternalReferences::save('contact', $contactId, 'stripe', 'customer', (string) $created['id'], $env);

        return (string) $created['id'];
    }

    /**
     * Il prezzo dell'impronta in centesimi: quello scontato se più basso, più
     * il sovrapprezzo delle personalizzazioni.
     *
     * @param array<string, mixed> $price
     */
    public static function cents(array $price, float $surcharge = 0.0): int
    {
        $amount = round((float) ($price['price'] ?? 0), 2);
        $sale = round((float) ($price['sale_price'] ?? 0), 2);

        return (int) round(($sale > 0.0 && $sale < $amount ? $sale : $amount) * 100) + (int) round(max(0.0, $surcharge) * 100);
    }

    /**
     * «2490-month-1-eur»: cambia, e il prezzo in Stripe è un altro. Il posto
     * di una riga ripetuta va in coda («-s1»); senza sovrapprezzo né posto è
     * l'impronta di prima, e i prezzi già creati si riusano.
     *
     * @param array<string, mixed> $price
     */
    public static function fingerprint(array $price, float $surcharge = 0.0, int $slot = 0): string
    {
        [$interval, $count] = static::recurring($price);

        return static::cents($price, $surcharge).'-'.$interval.'-'.$count.'-eur'.($slot > 0 ? '-s'.$slot : '');
    }

    /**
     * Per ogni riga, quante righe prima di lei hanno lo stesso prezzo del
     * piano e lo stesso sovrapprezzo: il posto da passare a `priceFor()`.
     *
     * @param list<array{plan_price_id: int, surcharge: float}> $rows
     * @return list<int>
     */
    public static function slots(array $rows): array
    {
        $seen = [];
        $slots = [];

        foreach ($rows as $row) {
            $key = (int) $row['plan_price_id'].'-'.(int) round(max(0.0, (float) $row['surcharge']) * 100);
            $slots[] = $seen[$key] ?? 0;
            $seen[$key] = ($seen[$key] ?? 0) + 1;
        }

        return $slots;
    }

    /** @param array<string, mixed> $price @return array{0: string, 1: int} */
    public static function recurring(array $price): array
    {
        $term = max(1, (int) ($price['term_months'] ?? 1));

        return (string) ($price['billing_interval'] ?? 'month') === 'year'
            ? ['year', max(1, intdiv($term, 12))]
            : ['month', $term];
    }

    /** @param array<string, mixed> $price @return array<string, mixed> */
    public static function priceParams(array $price, string $productId, float $surcharge = 0.0): array
    {
        [$interval, $count] = static::recurring($price);
        $metadata = ['plan_price_id' => (string) (int) $price['id'], 'plan_price_code' => (string) ($price['code'] ?? '')];

        if ($surcharge > 0.0) {
            $metadata['surcharge'] = number_format($surcharge, 2, '.', '');
        }

        return [
            'product' => $productId,
            'unit_amount' => static::cents($price, $surcharge),
            'currency' => 'eur',
            'recurring' => ['interval' => $interval, 'interval_count' => $count],
            'nickname' => trim((string) ($price['name'] ?? '')),
            'metadata' => $metadata,
        ];
    }

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    public static function productParams(array $plan): array
    {
        $description = trim(html_entity_decode(strip_tags((string) ($plan['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return array_filter([
            'name' => trim((string) ($plan['name'] ?? '')),
            'description' => $description !== '' ? $description : null,
            'metadata' => ['plan_id' => (string) (int) $plan['id'], 'plan_code' => (string) ($plan['code'] ?? '')],
        ], static fn ($value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function taxRateParams(float $rate, bool $inclusive): array
    {
        return [
            'display_name' => 'IVA',
            'percentage' => round($rate, 2),
            'inclusive' => $inclusive,
            'country' => 'IT',
            'jurisdiction' => 'IT',
        ];
    }

    /** «percent-20.00-repeating-3»: cambia, e il coupon in Stripe è un altro. @param array<string, mixed> $coupon */
    public static function couponFingerprint(array $coupon): string
    {
        $duration = (string) ($coupon['duration'] ?? 'once');

        return (string) ($coupon['discount_type'] ?? 'percent').'-'.number_format((float) ($coupon['discount_value'] ?? 0), 2, '.', '')
            .'-'.$duration.'-'.($duration === 'repeating' ? (int) ($coupon['duration_months'] ?? 0) : 0);
    }

    /** @param array<string, mixed> $coupon @return array<string, mixed> */
    public static function couponParams(array $coupon): array
    {
        $duration = in_array((string) ($coupon['duration'] ?? ''), ['once', 'repeating', 'forever'], true) ? (string) $coupon['duration'] : 'once';
        $value = (float) ($coupon['discount_value'] ?? 0);
        $params = (string) ($coupon['discount_type'] ?? '') === 'amount'
            ? ['amount_off' => (int) round($value * 100), 'currency' => 'eur']
            : ['percent_off' => round($value, 2)];

        $params += [
            'duration' => $duration,
            'name' => mb_substr((string) ($coupon['code'] ?? ''), 0, 40),
            'metadata' => ['coupon_id' => (string) (int) ($coupon['id'] ?? 0)],
        ];

        if ($duration === 'repeating') {
            $params['duration_in_months'] = max(1, (int) ($coupon['duration_months'] ?? 0));
        }

        return $params;
    }

    /** @param array<string, mixed> $coupon @return array<string, mixed> */
    public static function promotionCodeParams(array $coupon, string $stripeCouponId): array
    {
        $params = ['coupon' => $stripeCouponId, 'code' => (string) ($coupon['code'] ?? '')];
        $limit = (int) ($coupon['usage_limit'] ?? 0);
        $ends = trim((string) ($coupon['ends_at'] ?? ''));

        if ($limit > 0) {
            $params['max_redemptions'] = $limit;
        }

        if ($ends !== '' && !str_starts_with($ends, '0000') && strtotime($ends) !== false && strtotime($ends) > time()) {
            $params['expires_at'] = strtotime($ends);
        }

        return $params;
    }
}
