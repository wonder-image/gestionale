<?php
declare(strict_types=1);

use Wonder\Plugin\Gestionale\Providers\Payments\PaymentEvent;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentProvider;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentStart;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;

/**
 * Stripe finto per i test del gestionale e dell'ecommerce: nessuna rete,
 * stati ed eventi decisi dal test.
 */
final class FakePaymentProvider implements PaymentProvider
{
    public string $environment = 'test';
    public bool $connected = true;

    /** @var array<string, PaymentState> */
    public array $states = [];

    public ?PaymentEvent $nextEvent = null;

    /** @var array<string, PaymentEvent> */
    public array $replays = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var list<array{order: array, payment: array}> */
    public array $started = [];

    private int $counter = 0;

    public function code(): string
    {
        return 'stripe';
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function connected(): bool
    {
        return $this->connected;
    }

    public function start(array $order, array $payment): PaymentStart
    {
        $this->started[] = ['order' => $order, 'payment' => $payment];
        $reference = 'pi_finto_'.(++$this->counter);

        return new PaymentStart($reference, $reference.'_secret_prova', $this->environment);
    }

    public function status(string $reference): PaymentState
    {
        return $this->states[$reference] ?? new PaymentState(PaymentState::OTHER);
    }

    public function event(string $raw, string $signature): ?PaymentEvent
    {
        return $signature === 'valida' ? $this->nextEvent : null;
    }

    public function replay(array $payload, string $environment): ?PaymentEvent
    {
        return $this->replays[(string) ($payload['id'] ?? '')] ?? null;
    }

    public function cancel(string $reference): void
    {
        $this->cancelled[] = $reference;
    }
}
