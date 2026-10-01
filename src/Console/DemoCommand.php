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

        self::boot();
        Demo::registerAll();
        $registry = DemoData::all();

        if ($registry === []) {
            $output->writeln('Nessun dato di prova da creare: li porteranno i prossimi sotto-progetti.');

            return Command::SUCCESS;
        }

        $fresh = (bool) $input->getOption('fresh');

        // Prima si toglie tutto, dall'ultimo registrato al primo: gli ordini
        // vanno via prima degli articoli che hanno venduto. Poi si crea, nell'ordine
        // di registrazione. Un giro per dato farebbe cancellare il catalogo sotto gli ordini.
        if ($fresh) {
            foreach (DemoData::inClearOrder() as $data) {
                $removed = (int) ($data['clear'])();
                $output->writeln("Cancellati {$removed} dati di «{$data['title']}».");
                self::writeNotes($output);
            }
        }

        foreach ($registry as $data) {
            $created = (int) ($data['create'])();
            $output->writeln("Creati {$created} dati di «{$data['title']}».");
            self::writeNotes($output);
        }

        return Command::SUCCESS;
    }

    /**
     * Gli ordini di prova passano dal carrello vero, che usa le funzioni del
     * sito (`sqlDelete()` e le altre): `forge` da solo non le carica, come
     * fanno i comandi `update`, `export` e `import`, che aprono il sito.
     */
    private static function boot(): void
    {
        $root = getcwd() ?: '.';
        $bootstrap = $root.'/vendor/wonder-image/app/wonder-image.php';

        if (function_exists('sqlDelete') || !file_exists($bootstrap)) {
            return;
        }

        $GLOBALS['ROOT'] = $root;

        require_once $bootstrap;
    }

    /** Le note lasciate dall'ultimo passaggio, rientrate sotto la sua riga. */
    private static function writeNotes(OutputInterface $output): void
    {
        foreach (DemoData::notes() as $note) {
            $output->writeln('  '.$note);
        }
    }
}
