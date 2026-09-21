<?php

namespace Wonder\Plugin\Gestionale\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wonder\Plugin\Gestionale\Support\Catalog\ImageQueue;

/**
 * `php forge gestionale:images` — genera le misure delle foto caricate.
 *
 * Il salvataggio di una scheda scrive solo l'originale, così il commerciante
 * non aspetta; le misure le fa questo comando, quando gli pare. Lavora a
 * blocchi (`--limit`, predefinito 20): richiamandolo ogni minuto non si
 * accavalla con sé stesso, e finché resta qualcosa lo dice.
 *
 * Lo stesso lavoro lo fa da sé l'attività `gestionale.images` dello scheduler,
 * per chi non vuole pensarci.
 */
final class ImagesCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('gestionale:images')
            ->setDescription('Genera le misure delle immagini del catalogo rimaste in attesa')
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Quante immagini lavorare in questo giro',
                (string) ImageQueue::BATCH
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));

        if (ImageQueue::count() === 0) {
            $output->writeln('Nessuna immagine in attesa.');

            return Command::SUCCESS;
        }

        $result = ImageQueue::work($limit);

        if (($result['blocked'] ?? '') !== '') {
            $output->writeln('<error>'.$result['blocked'].'</error>');
            $output->writeln('Le immagini restano in attesa: nessun tentativo è andato perso.');

            return Command::FAILURE;
        }

        $output->writeln("Pronte: {$result['done']}.");

        if ($result['failed'] > 0) {
            $output->writeln("<comment>Non riuscite: {$result['failed']}.</comment>");
        }

        $output->writeln($result['left'] > 0
            ? "Restano {$result['left']} immagini: rilancia il comando."
            : 'La coda è vuota.');

        return Command::SUCCESS;
    }
}
