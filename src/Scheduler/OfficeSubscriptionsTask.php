<?php

namespace Wonder\Plugin\Gestionale\Scheduler;

use Wonder\App\Scheduler\AbstractTask;
use Wonder\App\Scheduler\Context;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OfficeSubscriptions;

/**
 * Una volta al giorno, gli abbonamenti da ufficio (G10 §3): fine della prova,
 * nuovo periodo con le rate e il proforma, fine dei disdetti, rate scadute
 * (promemoria e, oltre i giorni dell'impostazione, «non pagato»).
 *
 * Nasce **spenta**, come le altre: si accende con gli abbonamenti da ufficio.
 */
final class OfficeSubscriptionsTask extends AbstractTask
{
    public function key(): string
    {
        return 'gestionale.office_subscriptions';
    }

    public function label(): string
    {
        return 'Gestionale: periodi e scadenze degli abbonamenti da ufficio';
    }

    public function expression(): string
    {
        return '20 6 * * *';
    }

    public function enabled(): bool
    {
        return false;
    }

    public function timeout(): int
    {
        return 600;
    }

    public function run(Context $context): array
    {
        if (!Gestionale::feature('subscriptions')) {
            return ['opened' => 0, 'ended' => 0, 'overdue' => 0, 'unpaid' => 0];
        }

        return OfficeSubscriptions::daily();
    }
}
