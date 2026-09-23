<?php

namespace Wonder\Plugin\Gestionale\Scheduler;

use Wonder\App\Scheduler\AbstractTask;
use Wonder\App\Scheduler\Context;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockNotifier;

/**
 * Gli avvisi di scorta minima, come attività dello scheduler del core.
 *
 * Ogni quarto d'ora raccoglie i prodotti scesi sotto la soglia e li manda in
 * un'email sola ai *Destinatari degli avvisi*. Nasce **spenta**: prima si
 * sblocca la funzionalità e si scrivono i destinatari, poi la si accende.
 *
 * Il salvataggio non manda mai email: apre l'avviso e basta. Così dieci
 * rettifiche di fila fanno un'email, e il magazzino non aspetta la posta.
 */
final class StockAlertsTask extends AbstractTask
{
    public function key(): string
    {
        return 'gestionale.stock_alerts';
    }

    public function label(): string
    {
        return 'Gestionale: avvisi di scorta minima';
    }

    public function expression(): string
    {
        return '*/15 * * * *';
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
        $result = LowStockNotifier::run();

        return [
            'status' => $result['status'],
            'items' => count($result['items']),
            'resolved' => $result['resolved'],
            'closed' => $result['closed'],
            'sent' => count($result['sent']),
            'failed' => count($result['failed']),
        ];
    }
}
