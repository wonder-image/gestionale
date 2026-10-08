<?php

namespace Wonder\Plugin\Gestionale\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wonder\Plugin\Gestionale\Support\Catalog\VariantSlugs;

/**
 * `php forge gestionale:variant-slugs` — rifà gli slug delle varianti dai loro
 * valori: `/prodotto/maglietta/blu/` al posto di `/prodotto/maglietta-51167/`.
 *
 * Lanciato una seconda volta non cambia nulla. `--dry-run` elenca i cambi
 * senza scriverli.
 */
final class VariantSlugsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('gestionale:variant-slugs')
            ->setDescription('Rifà gli slug delle varianti dai loro valori: blu, rosso, blu-2')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Elenca i cambi senza scriverli');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $changed = 0;

        foreach (VariantSlugs::modelIds() as $modelId) {
            $plan = VariantSlugs::plan($modelId);

            foreach ($plan as $variantId => $slug) {
                $output->writeln("Modello {$modelId}, variante {$variantId}: «{$slug}»");
            }

            $changed += $dryRun ? count($plan) : VariantSlugs::apply($plan);
        }

        $output->writeln($dryRun
            ? "<comment>Da cambiare: {$changed} varianti. Non ho scritto niente.</comment>"
            : "<info>Slug cambiati: {$changed}.</info>");

        return Command::SUCCESS;
    }
}
