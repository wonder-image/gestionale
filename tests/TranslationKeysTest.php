<?php
/** php tests/TranslationKeysTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

// Il core mette ogni file di lingua sotto una chiave uguale al suo nome:
// `lang/it/gestionale.json` diventa `gestionale.…`. Se il file ripete
// `"gestionale": {…}` come radice, ogni `__t('gestionale.x')` cerca
// `gestionale.gestionale.x` e la pagina mostra «Chiave di traduzione mancante».

$file = __DIR__.'/../lang/it/gestionale.json';
$dati = json_decode((string) file_get_contents($file), true);

$frase = static function (string $chiave) use ($dati): ?string {
    if (!str_starts_with($chiave, 'gestionale.')) {
        return null;
    }

    $nodo = $dati;

    foreach (explode('.', substr($chiave, strlen('gestionale.'))) as $passo) {
        if (!is_array($nodo) || !isset($nodo[$passo])) {
            return null;
        }

        $nodo = $nodo[$passo];
    }

    return is_string($nodo) ? $nodo : null;
};

$chiavi = static function (): array {
    $trovate = [];
    $cartella = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../src', FilesystemIterator::SKIP_DOTS));

    foreach ($cartella as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Solo le chiavi scritte per intero: quelle composte a runtime le coprono i test di chi le usa.
        preg_match_all('/__t\(\s*[\'"](gestionale\.[A-Za-z0-9_.]+)[\'"]\s*[,)]/', (string) file_get_contents($file->getPathname()), $uso);

        foreach ($uso[1] as $chiave) {
            $trovate[$chiave] = true;
        }
    }

    ksort($trovate);

    return array_keys($trovate);
};

check('ogni file di lingua è un JSON valido senza la radice del suo nome', function () {
    foreach (glob(__DIR__.'/../lang/*/*.json') ?: [] as $percorso) {
        $contenuto = json_decode((string) file_get_contents($percorso), true);
        $nome = basename($percorso, '.json');

        if (!is_array($contenuto)) {
            throw new RuntimeException('JSON non valido: '.$percorso);
        }

        if (array_key_exists($nome, $contenuto)) {
            throw new RuntimeException("radice «{$nome}» ripetuta in ".$percorso);
        }
    }

    return true;
});

check('ogni __t(\'gestionale.…\') di src ha la sua frase', function () use ($chiavi, $frase) {
    $chiaviTrovate = $chiavi();
    $mancanti = array_values(array_filter($chiaviTrovate, static fn (string $chiave): bool => $frase($chiave) === null));

    if ($mancanti !== []) {
        throw new RuntimeException('senza frase: '.implode(', ', $mancanti));
    }

    return in_array('gestionale.contacts.relations.column', $chiaviTrovate, true);
});

summary();
