<?php
/** php tests/IstantaneaTest.php */
declare(strict_types=1);

require __DIR__ . '/harness.php';

/** Una cartella di prova, con dentro quello che le si dice. */
$cartella = static function (array $file): string {
    $dir = sys_get_temp_dir().'/wi-prova-istantanea-'.uniqid().'/';
    mkdir($dir, 0777, true);

    foreach ($file as $nome => $contenuto) {
        file_put_contents($dir.$nome, $contenuto);
    }

    return $dir;
};

/** Cosa c'è dentro, nome per contenuto. */
$dentro = static function (string $dir): array {
    $trovati = [];

    foreach (glob($dir.'*') ?: [] as $file) {
        $trovati[basename($file)] = (string) file_get_contents($file);
    }

    ksort($trovati);

    return $trovati;
};

check('un file cancellato torna al suo posto', function () use ($cartella, $dentro) {
    $dir = $cartella(['uno.txt' => 'primo', 'due.txt' => 'secondo']);
    $prima = $dentro($dir);

    $istantanea = Istantanea::di($dir);
    unlink($dir.'uno.txt');
    $istantanea->ripristina();

    return $dentro($dir) === $prima;
});

check('un file nato durante la prova se ne va con la prova', function () use ($cartella, $dentro) {
    $dir = $cartella(['uno.txt' => 'primo']);
    $prima = $dentro($dir);

    $istantanea = Istantanea::di($dir);
    file_put_contents($dir.'nuovo.txt', 'roba di passaggio');
    $istantanea->ripristina();

    return $dentro($dir) === $prima;
});

check('rimettere due volte non cambia niente', function () use ($cartella, $dentro) {
    $dir = $cartella(['uno.txt' => 'primo']);
    $prima = $dentro($dir);

    $istantanea = Istantanea::di($dir);
    unlink($dir.'uno.txt');
    $istantanea->ripristina();
    $istantanea->ripristina();

    return $dentro($dir) === $prima;
});

check('la copia di lavoro non resta in giro', function () use ($cartella) {
    $dir = $cartella(['uno.txt' => 'primo']);
    $prima = glob(sys_get_temp_dir().'/wi-istantanea-*') ?: [];

    Istantanea::di($dir)->ripristina();

    return (glob(sys_get_temp_dir().'/wi-istantanea-*') ?: []) === $prima;
});

check('se il test muore a metà, la cartella torna lo stesso', function () use ($cartella, $dentro) {
    $dir = $cartella(['uno.txt' => 'primo', 'due.txt' => 'secondo']);
    $prima = $dentro($dir);

    // Un processo a parte che cancella tutto e muore senza rimettere niente:
    // la rete di sicurezza è `register_shutdown_function`, e si vede solo
    // quando il processo finisce davvero.
    $script = $dir.'..'.DIRECTORY_SEPARATOR.'disastro-'.uniqid().'.php';
    file_put_contents($script, '<?php require '.var_export(__DIR__.'/harness.php', true).';'
        .' Istantanea::di('.var_export($dir, true).');'
        .' foreach (glob('.var_export($dir.'*', true).') ?: [] as $f) { unlink($f); }'
        .' throw new RuntimeException("muoio a metà");');

    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>/dev/null');
    unlink($script);

    return $dentro($dir) === $prima;
});

// Le cartelle di prova non devono restare in giro più del test.
foreach (glob(sys_get_temp_dir().'/wi-prova-istantanea-*') ?: [] as $dir) {
    foreach (glob($dir.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($dir);
}

summary();
