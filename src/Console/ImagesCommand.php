<?php

namespace Wonder\Plugin\Gestionale\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `php forge gestionale:images` — deprecato: lancia `php forge media:resize`.
 *
 * Le misure delle foto le genera la coda del core, la stessa di ogni altra
 * immagine del sito. Il comando resta perché qualche sito lo chiama da un
 * cron: continua a svuotare la coda, che ora è quella del core, e lo dice.
 * `--limit` si accetta ancora e non conta più: il comando del core lavora
 * finché la coda non è vuota.
 *
 * @deprecated usa `php forge media:resize` (`--failed` per riprovare i falliti).
 */
final class ImagesCommand extends Command
{
    /** Il comando del core che fa il lavoro. */
    public const CORE_COMMAND = 'media:resize';

    protected function configure(): void
    {
        $this
            ->setName('gestionale:images')
            ->setDescription('Deprecato: genera le misure delle immagini in coda con media:resize del core')
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Non conta più: la coda del core si svuota tutta',
                '20'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>gestionale:images è deprecato: usa php forge '.self::CORE_COMMAND.'.</comment>');

        $application = $this->getApplication();

        if ($application === null || !$application->has(self::CORE_COMMAND)) {
            $output->writeln('<error>Il comando '.self::CORE_COMMAND.' del core non c\'è: aggiorna wonder-image/app.</error>');

            return Command::FAILURE;
        }

        return $application->find(self::CORE_COMMAND)->run(new ArrayInput([]), $output);
    }
}
