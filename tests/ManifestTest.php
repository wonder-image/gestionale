<?php
/** php tests/ManifestTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\LegacyGlobals;
use Wonder\App\Module\Manifest;
use Wonder\App\Module\ManifestValidator;

LegacyGlobals::share(['ROOT' => dirname(__DIR__)]);

$manifest = Manifest::fromFile(dirname(__DIR__).'/module.json', 'local');

check('manifest valido per il core', function () use ($manifest) {
    $errors = ManifestValidator::errors($manifest);

    if ($errors !== []) {
        echo '    '.implode("\n    ", $errors)."\n";
    }

    return $errors === [];
});

check('slug, namespace e versione', fn () =>
    $manifest->slug() === 'gestionale'
    && $manifest->namespace() === 'Wonder\\Plugin\\Gestionale\\'
    && $manifest->version() === '0.1.0'
);

// DA CONFERMARE AL RILASCIO DEL CORE: `2.4.0-beta.8` è la versione attesa di
// wonder-image/app con la coda dei ridimensionamenti e il campo media
// (app#214), non ancora rilasciata. Se il numero sarà un altro cambia qui e
// in `module.json`; nello stesso momento va alzato `require` in
// `composer.json` (con il lock) e la riga del vincolo in
// `docs/dev/concetti/mcp.md`, che restano a beta.6 finché il core non esce:
// alzarli prima rompe `composer install` contro il checkout accanto.
check('richiede il core 2.4.0-beta.8 e PHP 8.2', fn () =>
    ($manifest->frameworkCompatibility()['wonder-app'] ?? '') === '^2.4.0-beta.8'
    && ($manifest->frameworkCompatibility()['php'] ?? '') === '^8.2'
);

check('il modulo dichiara i suoi comandi e dipende solo da customer-account', fn () =>
    $manifest->consoleCommands() === [
        'Wonder\\Plugin\\Gestionale\\Console\\DemoCommand',
        'Wonder\\Plugin\\Gestionale\\Console\\ImagesCommand',
        'Wonder\\Plugin\\Gestionale\\Console\\FeaturesDocCommand',
        'Wonder\\Plugin\\Gestionale\\Console\\StockAlertsCommand',
        'Wonder\\Plugin\\Gestionale\\Console\\VariantSlugsCommand',
    ]
    && (array) $manifest->get('dependencies.modules', []) === ['customer-account']
);

summary();
