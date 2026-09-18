<?php
/** php tests/ConventionsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;

$classi = [];

foreach (glob(dirname(__DIR__).'/src/Resources/*/*.php') ?: [] as $file) {
    $classi[] = 'Wonder\\Plugin\\Gestionale\\Resources\\'
        .basename(dirname($file)).'\\'.basename($file, '.php');
}

check('le Resource del modulo sono autoloadabili', function () use ($classi) {
    $mancanti = array_values(array_filter($classi, static fn (string $c): bool => !class_exists($c)));

    if ($mancanti !== []) {
        echo '    '.implode("\n    ", $mancanti)."\n";
    }

    return $mancanti === [];
});

check('ogni Resource con dati dichiara la sua funzionalità', function () use ($classi) {
    // Le pagine sempre attive (3.4 della spec) sono elencate qui.
    $sempreAttive = [];
    $mancanti = [];

    foreach ($classi as $classe) {
        if (!class_exists($classe) || !is_subclass_of($classe, GestionaleResource::class)) {
            continue;
        }

        if ($classe::$feature === '' && !in_array($classe, $sempreAttive, true)) {
            $mancanti[] = $classe;
        }
    }

    if ($mancanti !== []) {
        echo '    '.implode("\n    ", $mancanti)."\n";
    }

    return $mancanti === [];
});

check('le pagine senza dati sono NavigationOnlyResource', function () use ($classi) {
    foreach ($classi as $classe) {
        if (!class_exists($classe)) {
            continue;
        }

        if (!is_subclass_of($classe, GestionaleResource::class) && !is_subclass_of($classe, NavigationOnlyResource::class)) {
            return false;
        }
    }

    return true;
});

check('ogni Model del modulo usa il prefisso gst_', function () {
    $fuori = [];

    foreach (glob(dirname(__DIR__).'/src/Models/*/*.php') ?: [] as $file) {
        $classe = 'Wonder\\Plugin\\Gestionale\\Models\\'
            .basename(dirname($file)).'\\'.basename($file, '.php');

        if (class_exists($classe) && !str_starts_with($classe::$table, 'gst_')) {
            $fuori[] = $classe::$table;
        }
    }

    if ($fuori !== []) {
        echo '    '.implode(', ', $fuori)."\n";
    }

    return $fuori === [];
});

check('le tabelle sincronizzate del modulo usano id stabili', function () {
    foreach (glob(dirname(__DIR__).'/src/Models/*/*.php') ?: [] as $file) {
        $classe = 'Wonder\\Plugin\\Gestionale\\Models\\'
            .basename(dirname($file)).'\\'.basename($file, '.php');

        if (!class_exists($classe)) {
            continue;
        }

        $schema = $classe::syncSchema();

        if ($schema !== null && !$schema->singleton && !$schema->keepIds) {
            return false;
        }
    }

    return true;
});

summary();
