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
    Gestionale::config('docs.merchant_url') === 'https://wonder-image.gitbook.io/wonder-image-gestionale/user'
    && Gestionale::config('extensions') === []
    && Gestionale::config('features.unlock') === []
    && Gestionale::config('chiave.inesistente', 'ripiego') === 'ripiego'
);

check('indirizzo della guida commercianti', fn () =>
    Gestionale::docsUrl('primi-passi/funzionalita')
        === 'https://wonder-image.gitbook.io/wonder-image-gestionale/user/primi-passi/funzionalita'
    && Gestionale::docsUrl('') === ''
);

check('indirizzo della guida composto dalla configurazione del sito', function () {
    $proprieta = new ReflectionProperty(Gestionale::class, 'config');
    $proprieta->setAccessible(true);
    $proprieta->setValue(null, ['docs' => ['merchant_url' => 'https://guida.esempio.it/guida']]);

    $composto = [
        Gestionale::docsUrl('funzionalita'),
        Gestionale::docsUrl('/sedi/'),
        Gestionale::docsUrl(''),
    ];

    Gestionale::reset();

    return $composto === [
        'https://guida.esempio.it/guida/funzionalita',
        'https://guida.esempio.it/guida/sedi',
        '',
    ];
});

check('Resource base senza funzionalità né guida dichiarate', fn () =>
    GestionaleResource::$feature === '' && GestionaleResource::$docsPage === ''
);

summary();
