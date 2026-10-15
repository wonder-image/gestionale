<?php

namespace Wonder\Plugin\Gestionale\Scheduler;

use Wonder\App\Scheduler\AbstractTask;
use Wonder\App\Scheduler\Context;
use Wonder\Plugin\Gestionale\Support\Catalog\Exports\ProductFeeds;

/**
 * I feed a indirizzo fisso per Google Merchant Center e Meta, come attività
 * dello scheduler del core.
 *
 * Passa ogni ora e riscrive solo i feed sbloccati nel pannello Funzionalità
 * e scaduti secondo la frequenza scelta lì; un feed bloccato lo cancella.
 * Nasce **accesa**, a differenza delle altre: senza feed sbloccati non fa
 * niente, e così l'interruttore che conta resta uno, quello del pannello.
 */
final class ProductFeedsTask extends AbstractTask
{
    public function key(): string
    {
        return 'gestionale.product_feeds';
    }

    public function label(): string
    {
        return 'Gestionale: feed dei prodotti per Google e Meta';
    }

    public function expression(): string
    {
        return '7 * * * *';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function timeout(): int
    {
        return 600;
    }

    public function run(Context $context): array
    {
        $result = ProductFeeds::refresh();

        return [
            'written' => implode(', ', $result['written']),
            'removed' => implode(', ', $result['removed']),
            'skipped' => implode(', ', $result['skipped']),
        ];
    }
}
