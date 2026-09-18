<?php
/** php tests/GestionaleTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;

check('percorsi del modulo', fn () =>
    Gestionale::root() === dirname(__DIR__)
    && Gestionale::manifestPath() === dirname(__DIR__).'/module.json'
    && Gestionale::langPath() === dirname(__DIR__).'/lang'
);

check('configurazione predefinita', fn () =>
    Gestionale::config('docs.merchant_url') === 'https://guide.wonderimage.it/gestionale'
    && Gestionale::config('extensions') === []
    && Gestionale::config('features.unlock') === []
    && Gestionale::config('chiave.inesistente', 'ripiego') === 'ripiego'
);

check('indirizzo della guida', fn () =>
    Gestionale::docsUrl('primi-passi') === 'https://guide.wonderimage.it/gestionale/primi-passi'
    && Gestionale::docsUrl('/sedi/') === 'https://guide.wonderimage.it/gestionale/sedi'
    && Gestionale::docsUrl('') === ''
);

check('Resource base senza funzionalità né guida dichiarate', fn () =>
    GestionaleResource::$feature === '' && GestionaleResource::$docsPage === ''
);

summary();
