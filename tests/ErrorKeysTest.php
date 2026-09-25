<?php
/** php tests/ErrorKeysTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

// Una chiave senza frase non esplode: arriva a chi usa il gestionale così
// com'è, «product.supplier_missing». Qui si cerca ogni chiave scritta per
// intero in `UserError::make()` o `UserError::refusal()` e si guarda che il
// file di lingua abbia la sua frase. Le chiavi composte a runtime non si
// vedono: quelle le coprono i test di chi le usa.

$chiavi = static function (): array {
    $trovate = [];
    $cartella = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../src', FilesystemIterator::SKIP_DOTS));

    foreach ($cartella as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all('/UserError::(?:make|refusal)\(\s*[\'"]([a-z0-9_.]+)[\'"]/', (string) file_get_contents($file->getPathname()), $uso);

        foreach ($uso[1] as $chiave) {
            $trovate[$chiave] = true;
        }
    }

    ksort($trovate);

    return array_keys($trovate);
};

$frase = static function (string $chiave): ?string {
    $dati = json_decode((string) file_get_contents(__DIR__.'/../lang/it/gestionale.json'), true);
    $nodo = $dati['gestionale']['errors'] ?? null;

    foreach (explode('.', $chiave) as $passo) {
        if (!is_array($nodo) || !isset($nodo[$passo])) {
            return null;
        }

        $nodo = $nodo[$passo];
    }

    return is_string($nodo) && trim($nodo) !== '' ? $nodo : null;
};

check('la ricerca trova le chiavi, anche quelle che vanno a capo', function () use ($chiavi) {
    $trovate = $chiavi();

    // `stock.insufficient` va a capo dopo la parentesi.
    return in_array('stock.insufficient', $trovate, true)
        && in_array('contact.has_account', $trovate, true)
        && count($trovate) > 20;
});

check('ogni errore scritto nel codice ha la sua frase', function () use ($chiavi, $frase) {
    $mancanti = array_values(array_filter($chiavi(), static fn (string $chiave): bool => $frase($chiave) === null));

    if ($mancanti !== []) {
        throw new RuntimeException('senza frase: '.implode(', ', $mancanti));
    }

    return true;
});

check('le frasi dei fornitori ci sono tutte', function () use ($frase) {
    foreach ([
        'product.supplier_missing',
        'product.supplier_invalid',
        'product.supplier_cost_negative',
        'product.supplier_duplicate',
        'contact.supplier_in_use',
        'contact.supplier_role_in_use',
    ] as $chiave) {
        if ($frase($chiave) === null) {
            throw new RuntimeException('senza frase: '.$chiave);
        }
    }

    return str_contains((string) $frase('product.supplier_duplicate'), '{{supplier}}')
        && str_contains((string) $frase('contact.supplier_in_use'), '{{count}}')
        && str_contains((string) $frase('contact.supplier_role_in_use'), '{{count}}');
});

summary();
