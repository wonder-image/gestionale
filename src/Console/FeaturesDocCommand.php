<?php

namespace Wonder\Plugin\Gestionale\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;

/**
 * `php forge gestionale:features-doc` — riscrive la tabella delle funzionalità
 * nella guida commercianti.
 *
 * La tabella nasce da `config/features.php`, così non può raccontare una cosa
 * diversa dal pannello. Il resto della pagina resta scritto a mano: il comando
 * tocca solo quello che sta tra i due marcatori.
 */
final class FeaturesDocCommand extends Command
{
    private const START = '<!-- funzionalita:inizio -->';
    private const END = '<!-- funzionalita:fine -->';
    private const PAGE = '/docs/user/funzionalita.md';

    protected function configure(): void
    {
        $this
            ->setName('gestionale:features-doc')
            ->setDescription('Riscrive la tabella delle funzionalità nella guida commercianti');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = Gestionale::root().self::PAGE;

        if (!is_file($file)) {
            $output->writeln('<error>Pagina della guida non trovata: '.$file.'</error>');

            return Command::FAILURE;
        }

        $page = (string) file_get_contents($file);

        if (!str_contains($page, self::START) || !str_contains($page, self::END)) {
            $output->writeln('<error>Marcatori mancanti nella pagina: '.self::START.' e '.self::END.'</error>');

            return Command::FAILURE;
        }

        $before = substr($page, 0, strpos($page, self::START) + strlen(self::START));
        $after = substr($page, (int) strpos($page, self::END));

        file_put_contents($file, $before."\n\n".self::table()."\n".$after);
        $output->writeln('Tabella delle funzionalità aggiornata.');

        return Command::SUCCESS;
    }

    /** La tabella in Markdown, una riga per funzionalità. */
    public static function table(): string
    {
        $rows = ['| Funzionalità | Area | A cosa serve | Serve prima |', '|---|---|---|---|'];

        foreach (FeatureCatalog::all() as $feature) {
            $requires = array_map(
                static fn (string $key): string => FeatureCatalog::all()[$key]['name'] ?? $key,
                (array) ($feature['requires'] ?? [])
            );

            $rows[] = '| '.$feature['name']
                .' | '.$feature['area']
                .' | '.$feature['description']
                .' | '.($requires === [] ? '—' : implode(', ', $requires)).' |';
        }

        return implode("\n", $rows);
    }
}
