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

$conAlternative = FeatureCatalog::fromArray([
    'orders' => ['name' => 'Ordini', 'area' => 'Vendite'],
    'subscriptions' => ['name' => 'Abbonamenti', 'area' => 'Vendite'],
    'customizations' => ['name' => 'Personalizzazione', 'area' => 'Catalogo', 'requires_any' => ['orders', 'subscriptions']],
]);

check('requires_any: attiva con una sola delle alternative', fn () =>
    FeatureState::resolve($conAlternative, ['subscriptions' => true, 'customizations' => true], [])['customizations'] === true
    && FeatureState::resolve($conAlternative, ['orders' => true, 'customizations' => true], [])['customizations'] === true
);

check('requires_any: spenta senza nessuna alternativa', fn () =>
    FeatureState::resolve($conAlternative, ['customizations' => true], [])['customizations'] === false
);

$eredi = FeatureCatalog::fromArray([
    'export_products' => ['name' => 'Esportazione prodotti', 'area' => 'Esportazioni', 'replaces' => ['export_xlsx', 'export_csv']],
]);

check('inherit: attiva se era attiva almeno una delle vecchie', fn () =>
    FeatureState::inherit($eredi, ['export_xlsx' => false, 'export_csv' => true])['export_products'] === true
    && FeatureState::inherit($eredi, ['export_xlsx' => true])['export_products'] === true
);

check('inherit: spenta se le vecchie erano spente o mancano', fn () =>
    !isset(FeatureState::inherit($eredi, ['export_xlsx' => false, 'export_csv' => false])['export_products'])
    && !isset(FeatureState::inherit($eredi, [])['export_products'])
);

check('inherit: con la sua riga vale la riga, non le vecchie', fn () =>
    FeatureState::inherit($eredi, ['export_products' => false, 'export_csv' => true])['export_products'] === false
    && FeatureState::inherit($eredi, ['export_products' => true])['export_products'] === true
);

check('inherit: lo stato ereditato passa da resolve', fn () =>
    FeatureState::resolve($eredi, FeatureState::inherit($eredi, ['export_csv' => true]), [])['export_products'] === true
);

check('defaults: una voce «default» senza riga conta come sbloccata, con la riga vale la riga', function () {
    $catalogo = ['products' => ['default' => true], 'bundles' => ['requires' => ['products']]];

    return FeatureState::defaults($catalogo, [])['products'] === true
        && !isset(FeatureState::defaults($catalogo, [])['bundles'])
        && FeatureState::defaults($catalogo, ['products' => false])['products'] === false
        && FeatureState::resolve($catalogo, FeatureState::defaults($catalogo, ['bundles' => true]), [])['bundles'] === true;
});

summary();
