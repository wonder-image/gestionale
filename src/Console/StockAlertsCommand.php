<?php

namespace Wonder\Plugin\Gestionale\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockEmail;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockNotifier;

/**
 * `php forge gestionale:stock-alerts` — cosa partirebbe adesso, e a chi.
 *
 * È solo un'anteprima: non manda email e non tocca gli avvisi. L'email la
 * manda l'attività `gestionale.stock_alerts` dello scheduler, che gira con il
 * sito avviato; `forge` non carica le funzioni del core, e `sendMail()` qui
 * non c'è.
 */
final class StockAlertsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('gestionale:stock-alerts')
            ->setDescription('Mostra l\'email degli avvisi di scorta minima che partirebbe adesso, senza mandarla');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = LowStockNotifier::run(true);

        if ($result['status'] === LowStockNotifier::DISABLED) {
            $output->writeln('Gli avvisi di scorta minima non sono sbloccati.');

            return Command::SUCCESS;
        }

        if ($result['resolved'] > 0 || $result['closed'] > 0) {
            $output->writeln("<comment>Al prossimo giro si chiudono {$result['resolved']} avvisi di prodotti tolti e {$result['closed']} di prodotti tornati sopra la soglia.</comment>");
        }

        if ($result['items'] === []) {
            $output->writeln('Nessun prodotto sotto scorta da segnalare.');

            return Command::SUCCESS;
        }

        if ($result['status'] === LowStockNotifier::NO_RECIPIENTS) {
            $output->writeln('<comment>Mancano i Destinatari degli avvisi nelle impostazioni: l\'email aspetta.</comment>');
        } else {
            $output->writeln('Oggetto: '.$result['subject']);
            $output->writeln('A: '.implode(', ', $result['to']));
        }

        foreach ($result['items'] as $item) {
            $name = $item['article'].($item['option'] !== '' ? ' — '.$item['option'] : '');
            $sku = $item['sku'] !== '' ? ' ('.$item['sku'].')' : '';

            $output->writeln(sprintf(
                '- %s%s: disponibili %s, scorta minima %s',
                $name,
                $sku,
                LowStockEmail::quantity($item['available']),
                LowStockEmail::quantity($item['threshold'])
            ));
        }

        $output->writeln('L\'email la manda l\'attività gestionale.stock_alerts dello scheduler: questo comando non spedisce niente.');

        return Command::SUCCESS;
    }
}
