<?php
/** php tests/CommandsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Console\DemoCommand;
use Wonder\Plugin\Gestionale\Console\FeaturesDocCommand;

check('i comandi del modulo sono comandi di forge', fn () =>
    is_subclass_of(DemoCommand::class, Command::class)
    && is_subclass_of(FeaturesDocCommand::class, Command::class)
);

check('i nomi sono quelli dichiarati nel manifest', function () {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__).'/module.json'), true);
    $dichiarati = (array) ($manifest['console']['commands'] ?? []);

    return in_array(DemoCommand::class, $dichiarati, true)
        && in_array(FeaturesDocCommand::class, $dichiarati, true)
        && (new DemoCommand)->getName() === 'gestionale:demo'
        && (new FeaturesDocCommand)->getName() === 'gestionale:features-doc';
});

check('in G1 non ci sono ancora dati di prova da creare', fn () => DemoData::all() === []);

check('i dati di prova si dichiarano con chiave, titolo e due funzioni', function () {
    DemoData::register('prova', 'Righe di prova', static fn (): int => 3, static fn (): int => 3);
    $registro = DemoData::all();
    DemoData::reset();

    return array_keys($registro) === ['prova']
        && ($registro['prova']['title'] ?? '') === 'Righe di prova'
        && is_callable($registro['prova']['create'] ?? null)
        && is_callable($registro['prova']['clear'] ?? null);
});

check('la tabella della guida ha una riga per funzionalità', function () {
    $tabella = FeaturesDocCommand::table();
    $righe = array_values(array_filter(
        explode("\n", $tabella),
        static fn (string $riga): bool => str_starts_with(trim($riga), '|')
    ));

    // Intestazione, separatore e una riga per funzionalità.
    return count($righe) === 2 + count(\Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog::all())
        && str_contains($tabella, 'Ordini')
        && str_contains($tabella, 'Area');
});

check('la tabella dice cosa serve prima', function () {
    $tabella = FeaturesDocCommand::table();

    foreach (explode("\n", $tabella) as $riga) {
        if (str_contains($riga, '| Resi ')) {
            return str_contains($riga, 'Ordini');
        }
    }

    return false;
});

check('fuori dal locale i dati di prova non si creano', function () {
    $comando = new DemoCommand();
    $output = new BufferedOutput();
    $esito = $comando->run(new ArrayInput([]), $output);

    // Senza un sito avviato l'ambiente non è locale: il comando si ferma,
    // che è la risposta giusta anche in produzione.
    return $esito === Command::FAILURE && str_contains($output->fetch(), 'solo in locale');
});

summary();
