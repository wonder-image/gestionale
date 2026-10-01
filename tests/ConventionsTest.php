<?php
/** php tests/ConventionsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\Resources\Config\SocietyLocationResource;
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
        // Metodi e conti di pagamento sono la configurazione: servono a
        // qualsiasi ordine, non si sbloccano.
        'Wonder\\Plugin\\Gestionale\\Resources\\Payments\\PaymentMethodResource',
        'Wonder\\Plugin\\Gestionale\\Resources\\Payments\\PaymentAccountResource',
        // Il catalogo è sempre attivo (3.4 della spec di architettura).
        'Wonder\\Plugin\\Gestionale\\Resources\\Catalog\\AttributeResource',
        'Wonder\\Plugin\\Gestionale\\Resources\\Catalog\\BrandResource',
        'Wonder\\Plugin\\Gestionale\\Resources\\Catalog\\ProductModelResource',
        'Wonder\\Plugin\\Gestionale\\Resources\\Catalog\\ProductResource',
        'Wonder\\Plugin\\Gestionale\\Resources\\Catalog\\CategoryResource',
        'Wonder\\Plugin\\Gestionale\\Resources\\Catalog\\TagResource',
        // I valori delle opzioni non sono una pagina: esistono per lo store
        // API del "+ Aggiungi colore" della scheda prodotto.
        'Wonder\\Plugin\\Gestionale\\Resources\\Catalog\\AttributeValueResource',
        // Gli imballaggi servono alla scheda prodotto, che è sempre attiva:
        // il peso spedito è prodotto più scatola anche senza spedizioni.
        'Wonder\\Plugin\\Gestionale\\Resources\\Catalog\\PackageResource',
        // Il magazzino base è sempre attivo: le funzionalità sbloccano le sedi
        // in più, gli acquisti e i lotti, non la giacenza.
        'Wonder\\Plugin\\Gestionale\\Resources\\Stock\\StockLevelResource',
        'Wonder\\Plugin\\Gestionale\\Resources\\Stock\\StockMovementResource',
        // I clienti ci sono sempre; i fornitori arrivano con gli acquisti, e
        // infatti `SupplierResource` dichiara la sua funzionalità.
        'Wonder\\Plugin\\Gestionale\\Resources\\Contacts\\CustomerResource',
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
    // righe uniche (impostazioni) e SocietyLocationResource per la pagina del
    // core che il gestionale sostituisce.
    $basi = [
        GestionaleResource::class,
        NavigationOnlyResource::class,
        SingletonResource::class,
        SocietyLocationResource::class,
    ];

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
