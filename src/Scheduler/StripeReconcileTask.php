<?php

namespace Wonder\Plugin\Gestionale\Scheduler;

use Wonder\App\Scheduler\AbstractTask;
use Wonder\App\Scheduler\Context;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
use Wonder\Plugin\Gestionale\Support\Payments\Reconcile;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OnlineSubscriptions;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * Una volta all'ora recupera da Stripe quello che il webhook ha perso:
 * pagamenti riusciti o annullati, eventi rimasti indietro e, con gli
 * abbonamenti online, gli abbonamenti in attesa o da pagare.
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
        $done = $provider === null ? ['payments' => 0, 'events' => 0] : Reconcile::run($provider);

        // Gli abbonamenti online (G10): i `pending` e i `past_due` si rileggono da Stripe.
        if (Gestionale::feature('online_subscriptions')) {
            $done += OnlineSubscriptions::reconcile();
        }

        return $done;
    }
}
