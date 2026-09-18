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

check('richiede il core 2.2.4 e PHP 8.2', fn () =>
    ($manifest->frameworkCompatibility()['wonder-app'] ?? '') === '^2.2.4'
    && ($manifest->frameworkCompatibility()['php'] ?? '') === '^8.2'
);

check('nessun comando e nessuna dipendenza da altri moduli', fn () =>
    $manifest->consoleCommands() === []
    && (array) $manifest->get('dependencies.modules', []) === []
);

summary();
