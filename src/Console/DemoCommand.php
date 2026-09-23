<?php

namespace Wonder\Plugin\Gestionale\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wonder\App\Environment;
use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Seeding\Demo;

/**
 * `php forge gestionale:demo` — riempie il gestionale di dati per provarlo.
 *
 * Si rifiuta di partire fuori dal locale: sono dati finti, e in produzione
 * finirebbero davanti ai clienti. `--fresh` cancella quelli di prima prima di
 * rifarli: solo le righe col segno dei dati di prova nel codice (vedi
 * `DemoCode`) e quelle con i vecchi nomi `Prova …`. Una riga di prova che
 * qualcosa di vero usa ancora resta al suo posto, e il comando lo dice.
 */
final class DemoCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('gestionale:demo')
            ->setDescription('Crea i dati di prova del gestionale (solo in locale)')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Cancella i dati di prova già creati');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!Environment::isLocal()) {
            $output->writeln('<error>I dati di prova si creano solo in locale.</error>');

            return Command::FAILURE;
        }

        Demo::registerAll();
        $registry = DemoData::all();

        if ($registry === []) {
            $output->writeln('Nessun dato di prova da creare: li porteranno i prossimi sotto-progetti.');

            return Command::SUCCESS;
        }

        $fresh = (bool) $input->getOption('fresh');

        foreach ($registry as $key => $data) {
            if ($fresh) {
                $removed = (int) ($data['clear'])();
                $output->writeln("Cancellati {$removed} dati di «{$data['title']}».");
                self::writeNotes($output);
            }

            $created = (int) ($data['create'])();
            $output->writeln("Creati {$created} dati di «{$data['title']}».");
            self::writeNotes($output);
        }

        return Command::SUCCESS;
    }

    /** Le note lasciate dall'ultimo passaggio, rientrate sotto la sua riga. */
    private static function writeNotes(OutputInterface $output): void
    {
        foreach (DemoData::notes() as $note) {
            $output->writeln('  '.$note);
        }
    }
}
