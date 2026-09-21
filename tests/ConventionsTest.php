<?php
/** php tests/ConventionsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\Resources\Support\SingletonResource;
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
    // Le pagine sempre attive (3.4 della spec) sono elencate qui: le tabelle
    // fiscali servono a qualsiasi documento, non si sbloccano.
    $sempreAttive = [
        'Wonder\\Plugin\\Gestionale\\Resources\\Tax\\TaxResource',
        'Wonder\\Plugin\\Gestionale\\Resources\\Tax\\TaxCategoryResource',
        'Wonder\\Plugin\\Gestionale\\Resources\\Tax\\TaxRuleResource',
    ];
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

check('ogni Resource parte da una base del modulo o del core', function () use ($classi) {
    // GestionaleResource per le pagine legate a una funzionalità,
    // NavigationOnlyResource per quelle senza dati, SingletonResource per le
    // righe uniche (impostazioni).
    $basi = [GestionaleResource::class, NavigationOnlyResource::class, SingletonResource::class];

    foreach ($classi as $classe) {
        if (!class_exists($classe)) {
            continue;
        }

        $ok = false;

        foreach ($basi as $base) {
            if (is_subclass_of($classe, $base)) {
                $ok = true;
                break;
            }
        }

        if (!$ok) {
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

        // Le classi astratte (es. StatusLog) non hanno una tabella propria.
        if (!class_exists($classe) || (new ReflectionClass($classe))->isAbstract()) {
            continue;
        }

        if (!str_starts_with($classe::$table, 'gst_')) {
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

        if (!class_exists($classe) || (new ReflectionClass($classe))->isAbstract()) {
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
