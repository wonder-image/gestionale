<?php

namespace Wonder\Plugin\Gestionale\Scheduler;

use Wonder\App\Scheduler\AbstractTask;
use Wonder\App\Scheduler\Context;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OnlineSubscriptions;

/**
 * Una volta al giorno, la fine del vincolo degli abbonamenti online (G10.13):
 * la disdetta chiesta durante il vincolo passa a Stripe, oppure l'abbonamento
 * prende il prezzo di rinnovo.
 *
 * Nasce **spenta**, come le altre: si accende con gli abbonamenti online.
 */
final class SubscriptionCommitmentTask extends AbstractTask
{
    public function key(): string
    {
        return 'gestionale.subscription_commitments';
    }

    public function label(): string
    {
        return 'Gestionale: fine vincolo degli abbonamenti';
    }

    public function expression(): string
    {
        return '15 6 * * *';
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
        if (!Gestionale::feature('online_subscriptions')) {
            return ['cancelled' => 0, 'renewed' => 0];
        }

        return OnlineSubscriptions::commitments();
    }
}
