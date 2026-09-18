<?php
/** php tests/FeatureStateTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;
use Wonder\Plugin\Gestionale\Support\Features\FeatureState;

$catalogo = FeatureCatalog::fromArray([
    'orders' => ['name' => 'Ordini', 'area' => 'Vendite'],
    'returns' => ['name' => 'Resi', 'area' => 'Vendite', 'requires' => ['orders']],
    'online_store' => ['name' => 'Negozio online', 'area' => 'Online', 'requires' => ['orders'], 'module' => 'ecommerce'],
]);

check('sbloccata senza dipendenze: attiva', fn () =>
    FeatureState::resolve($catalogo, ['orders' => true], ['gestionale'])['orders'] === true
);

check('bloccata: non attiva', fn () =>
    FeatureState::resolve($catalogo, [], ['gestionale'])['orders'] === false
);

check('dipendenza bloccata: non attiva anche se sbloccata', fn () =>
    FeatureState::resolve($catalogo, ['returns' => true], ['gestionale'])['returns'] === false
);

check('dipendenza sbloccata: attiva', fn () =>
    FeatureState::resolve($catalogo, ['orders' => true, 'returns' => true], ['gestionale'])['returns'] === true
);

check('modulo richiesto non abilitato: non attiva', fn () =>
    FeatureState::resolve($catalogo, ['orders' => true, 'online_store' => true], ['gestionale'])['online_store'] === false
);

check('modulo richiesto abilitato: attiva', fn () =>
    FeatureState::resolve($catalogo, ['orders' => true, 'online_store' => true], ['gestionale', 'ecommerce'])['online_store'] === true
);

check('chiave sconosciuta: non attiva, nessun errore', fn () =>
    FeatureState::isActive($catalogo, ['orders' => true], ['gestionale'], 'chiave_inesistente') === false
);

check('lo stato contiene tutte le chiavi del catalogo', fn () =>
    array_keys(FeatureState::resolve($catalogo, [], [])) === array_keys($catalogo)
);

summary();
