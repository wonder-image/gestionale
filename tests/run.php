<?php
/** php tests/run.php — esegue tutti i test del modulo. */
declare(strict_types=1);

$failed = [];

$files = glob(__DIR__.'/*Test.php') ?: [];

// I test d'integrazione girano solo dove c'è il sito di prova con il suo database.
if (is_dir(getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site')) {
    $files = array_merge($files, glob(__DIR__.'/integrazione/*Test.php') ?: []);

    // La cache dei tipi Stripe del DB di prova può contenere quelli veri del
    // conto: si parte da vuoto, e ogni test mette i suoi.
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/supporto/svuota-cache-stripe.php'));
} else {
    echo "Sito di prova assente: test d'integrazione saltati.\n";
}

foreach ($files as $file) {
    echo basename($file)."\n";
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($file), $status);

    if ($status !== 0) {
        $failed[] = basename($file);
    }
}

echo $failed === []
    ? "\nTutti i test del gestionale passano.\n"
    : "\nFalliti: ".implode(', ', $failed)."\n";

exit($failed === [] ? 0 : 1);
