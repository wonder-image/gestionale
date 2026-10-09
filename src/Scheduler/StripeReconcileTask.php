<?php

namespace Wonder\Plugin\Gestionale\Scheduler;

use Wonder\App\Scheduler\AbstractTask;
use Wonder\App\Scheduler\Context;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
use Wonder\Plugin\Gestionale\Support\Payments\Reconcile;

/**
 * Una volta all'ora recupera da Stripe quello che il webhook ha perso:
 * pagamenti riusciti o annullati ed eventi rimasti indietro.
 *
 * Nasce **spenta**, come le altre: si accende insieme a `ExpiryTask` quando il
 * negozio comincia a incassare con la carta.
 */
final class StripeReconcileTask extends AbstractTask
{
    public function key(): string
    {
        return 'gestionale.stripe_reconcile';
    }

    public function label(): string
    {
        return 'Gestionale: riallineamento con Stripe';
    }

    public function expression(): string
    {
        return '0 * * * *';
    }

    public function enabled(): bool
    {
        return false;
    }

    public function timeout(): int
    {
        return 300;
    }

    public function run(Context $context): array
    {
        $provider = PaymentProviders::get('stripe');

        return $provider === null ? ['payments' => 0, 'events' => 0] : Reconcile::run($provider);
    }
}
