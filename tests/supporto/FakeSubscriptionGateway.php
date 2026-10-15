<?php

use Wonder\Plugin\Gestionale\Providers\Payments\SubscriptionGateway;

/**
 * Stripe finto per i test degli abbonamenti: ricorda le chiamate, rende
 * oggetti con id progressivi e rispetta le chiavi di idempotenza (la stessa
 * chiave ridà lo stesso oggetto, come Stripe).
 */
final class FakeSubscriptionGateway implements SubscriptionGateway
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> */
    public array $objects = [];

    /** @var array<string, array<string, mixed>> */
    private array $byKey = [];

    private int $next = 0;

    /** L'`amount_due` delle anteprime, in centesimi: lo sceglie il test. */
    public int $previewAmountDue = 0;

    public function __construct(public string $env = 'test', public bool $isConnected = true)
    {
    }

    public function environment(): string
    {
        return $this->env;
    }

    public function connected(): bool
    {
        return $this->isConnected;
    }

    public function createProduct(array $params, string $idempotencyKey): array
    {
        return $this->make('prod', 'product', $params, $idempotencyKey);
    }

    public function createPrice(array $params, string $idempotencyKey): array
    {
        return $this->make('price', 'price', $params, $idempotencyKey);
    }

    public function createTaxRate(array $params, string $idempotencyKey): array
    {
        return $this->make('txr', 'tax_rate', $params, $idempotencyKey);
    }

    public function createCoupon(array $params, string $idempotencyKey): array
    {
        return $this->make('coup', 'coupon', $params, $idempotencyKey);
    }

    public function createPromotionCode(array $params, string $idempotencyKey): array
    {
        return $this->make('promo', 'promotion_code', $params + ['active' => true], $idempotencyKey);
    }

    public function updatePromotionCode(string $id, array $params): array
    {
        $this->calls[] = ['promotion_code.update', ['id' => $id] + $params];

        return $this->objects[$id] = array_replace($this->objects[$id] ?? ['id' => $id], $params);
    }

    public function createCustomer(array $params, string $idempotencyKey): array
    {
        return $this->make('cus', 'customer', $params, $idempotencyKey);
    }

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        $session = $this->make('cs_test', 'checkout.session', $params + ['status' => 'open'], $idempotencyKey);

        return $this->objects[$session['id']] = $session + ['url' => 'https://checkout.stripe.test/'.$session['id']];
    }

    public function retrieveCheckoutSession(string $id): array
    {
        $this->calls[] = ['checkout.session.retrieve', ['id' => $id]];

        return $this->objects[$id] ?? throw new RuntimeException('Sessione sconosciuta: '.$id);
    }

    public function retrieveSubscription(string $id): array
    {
        $this->calls[] = ['subscription.retrieve', ['id' => $id]];

        return $this->objects[$id] ?? throw new RuntimeException('Abbonamento sconosciuto: '.$id);
    }

    public function updateSubscription(string $id, array $params): array
    {
        $this->calls[] = ['subscription.update', ['id' => $id] + $params];

        return $this->objects[$id] = array_replace($this->objects[$id] ?? ['id' => $id, 'object' => 'subscription'], $params);
    }

    public function createPortalSession(array $params): array
    {
        $this->calls[] = ['billing_portal.session', $params];

        return ['id' => 'bps_'.(++$this->next), 'url' => 'https://billing.stripe.test/p/'.$this->next] + $params;
    }

    public function createSubscriptionItem(array $params, string $idempotencyKey): array
    {
        return $this->make('si_fake', 'subscription_item', $params, $idempotencyKey);
    }

    public function updateSubscriptionItem(string $id, array $params): array
    {
        $this->calls[] = ['subscription_item.update', ['id' => $id] + $params];

        return $this->objects[$id] = array_replace($this->objects[$id] ?? ['id' => $id, 'object' => 'subscription_item'], $params);
    }

    public function deleteSubscriptionItem(string $id, array $params): array
    {
        $this->calls[] = ['subscription_item.delete', ['id' => $id] + $params];
        unset($this->objects[$id]);

        return ['id' => $id, 'object' => 'subscription_item', 'deleted' => true];
    }

    public function createSubscriptionSchedule(array $params, string $idempotencyKey): array
    {
        return $this->make('sub_sched_fake', 'subscription_schedule', $params, $idempotencyKey);
    }

    public function updateSubscriptionSchedule(string $id, array $params): array
    {
        $this->calls[] = ['subscription_schedule.update', ['id' => $id] + $params];

        return $this->objects[$id] = array_replace($this->objects[$id] ?? ['id' => $id, 'object' => 'subscription_schedule'], $params);
    }

    public function retrieveSubscriptionSchedule(string $id): array
    {
        $this->calls[] = ['subscription_schedule.retrieve', ['id' => $id]];

        return $this->objects[$id] ?? throw new RuntimeException('Schedule sconosciuto: '.$id);
    }

    public function previewInvoice(array $params): array
    {
        $this->calls[] = ['invoice.preview', $params];

        return ['object' => 'invoice', 'amount_due' => $this->previewAmountDue, 'lines' => ['data' => []]];
    }

    /** Quante chiamate di quel tipo. */
    public function count(string $type): int
    {
        return count(array_filter($this->calls, static fn (array $call): bool => $call[0] === $type));
    }

    /** @return array<string, mixed>|null l'ultima chiamata di quel tipo */
    public function last(string $type): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call[0] === $type) {
                return $call[1];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function make(string $prefix, string $object, array $params, string $key): array
    {
        $this->calls[] = [$object.'.create', $params + ['_key' => $key]];

        if ($key !== '' && isset($this->byKey[$key])) {
            return $this->byKey[$key];
        }

        $made = ['id' => $prefix.'_'.(++$this->next), 'object' => $object] + $params;
        $this->objects[$made['id']] = $made;

        return $key !== '' ? $this->byKey[$key] = $made : $made;
    }
}
