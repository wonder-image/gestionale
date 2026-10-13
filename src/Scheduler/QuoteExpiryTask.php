<?php

namespace Wonder\Plugin\Gestionale\Scheduler;

use Wonder\App\Scheduler\AbstractTask;
use Wonder\App\Scheduler\Context;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Sales\Quotes;

/**
 * I preventivi inviati oltre la validità diventano scaduti (G9 §3.1), una
 * volta al giorno al mattino presto.
 *
 * Nasce **spenta**, come le altre: la si accende dalla pagina delle attività.
 * Senza «Preventivi» non fa niente.
 */
final class QuoteExpiryTask extends AbstractTask
{
    public function key(): string
    {
        return 'gestionale.quote_expiry';
    }

    public function label(): string
    {
        return 'Gestionale: preventivi scaduti';
    }

    public function expression(): string
    {
        return '10 5 * * *';
    }

    public function enabled(): bool
    {
        return false;
    }

    public function timeout(): int
    {
        return 120;
    }

    public function run(Context $context): array
    {
        if (!Gestionale::feature('quotes')) {
            return ['expired' => 0];
        }

        return ['expired' => Quotes::expireDue()];
    }
}
