<?php
/** php tests/SelfCallsTest.php — ogni `self::metodo()` ha il suo metodo */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

/*
 * Un file tagliato a metà da una modifica perde metodi privati che il resto
 * della classe chiama ancora: PHP lo scopre solo quando la riga gira, e molte
 * di quelle righe girano solo con il database (i test d'integrazione). Qui si
 * legge il sorgente: ogni `self::nome(` deve avere `function nome(` nello
 * stesso file, o in un trait che la classe usa (`use Nome;` nel corpo,
 * cercato nella stessa cartella: i trait del modulo stanno accanto alle classi).
 */
check('in src ogni chiamata self:: ha il suo metodo nello stesso file', function () {
    $mancanti = [];
    $cartella = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($cartella as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $sorgente = (string) file_get_contents($file->getPathname());

        preg_match_all('/^    use\s+([A-Za-z_][A-Za-z0-9_]*)\s*;/m', $sorgente, $trait);
        foreach ($trait[1] as $nome) {
            $percorso = $file->getPath().'/'.$nome.'.php';
            if (is_file($percorso)) {
                $sorgente .= (string) file_get_contents($percorso);
            }
        }
        preg_match_all('/self::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $sorgente, $chiamate);

        foreach (array_unique($chiamate[1]) as $metodo) {
            if (preg_match('/function\s+&?'.$metodo.'\s*\(/', $sorgente) !== 1) {
                $mancanti[] = str_replace(dirname(__DIR__).'/', '', $file->getPathname()).' → '.$metodo;
            }
        }
    }

    foreach ($mancanti as $riga) {
        echo '    '.$riga."\n";
    }

    return $mancanti === [];
});

summary();
