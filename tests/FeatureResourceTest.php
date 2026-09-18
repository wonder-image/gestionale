<?php
/** php tests/FeatureResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\System\FeatureResource;

check('dipendenze mancanti di una funzionalità', fn () =>
    FeatureResource::missingDependencies('deferred_invoicing', ['e_invoicing' => true])
        === ['delivery_notes', 'orders']
);

check('nessuna dipendenza mancante se sono tutte sbloccate', fn () =>
    FeatureResource::missingDependencies('returns', ['orders' => true]) === []
);

check('funzionalità che dipendono da una data', function () {
    $dipendenti = FeatureResource::dependents('orders');

    return in_array('returns', $dipendenti, true)
        && in_array('backorders', $dipendenti, true)
        && !in_array('orders', $dipendenti, true);
});

check('dipendenti calcolati a catena', fn () =>
    in_array('deferred_invoicing', FeatureResource::dependents('delivery_notes'), true)
);

check('ogni Resource del modulo dichiara la sua funzionalità', function () {
    $mancanti = [];
    $sempreAttive = [FeatureResource::class];

    foreach (glob(dirname(__DIR__).'/src/Resources/*/*.php') ?: [] as $file) {
        $classe = 'Wonder\\Plugin\\Gestionale\\Resources\\'
            .basename(dirname($file)).'\\'.basename($file, '.php');

        if (!class_exists($classe)) {
            $mancanti[] = $classe.' non autoloadabile';
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

check('pagina riservata ad admin e sola lettura fuori dal locale', fn () =>
    (array) (FeatureResource::permissionSchema()->get('backend')['list'] ?? []) === ['admin']
    && FeatureResource::modelClass()::syncSchema()->localOnly === true
);

summary();
