<?php
/** php tests/FeatureCatalogTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;

$pacchetto = [
    'orders' => ['name' => 'Ordini', 'description' => 'Gestione degli ordini', 'area' => 'Vendite'],
    'returns' => ['name' => 'Resi', 'description' => 'Resi e ricarico', 'area' => 'Vendite', 'requires' => ['orders']],
];

check('ogni voce ha chiave, nome, area e dipendenze normalizzate', function () use ($pacchetto) {
    $catalogo = FeatureCatalog::fromArray($pacchetto);

    return $catalogo['orders']['key'] === 'orders'
        && $catalogo['orders']['requires'] === []
        && $catalogo['orders']['module'] === ''
        && $catalogo['returns']['requires'] === ['orders']
        && $catalogo['returns']['area'] === 'Vendite';
});

check('le voci del sito si aggiungono al catalogo', function () use ($pacchetto) {
    $catalogo = FeatureCatalog::fromArray($pacchetto, [
        'loyalty' => ['name' => 'Raccolta punti', 'area' => 'Promozioni', 'requires' => ['orders']],
    ]);

    return isset($catalogo['loyalty']) && $catalogo['loyalty']['requires'] === ['orders'];
});

check('il sito non può sovrascrivere una funzionalità del pacchetto', function () use ($pacchetto) {
    try {
        FeatureCatalog::fromArray($pacchetto, ['orders' => ['name' => 'Altro']]);
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'orders');
    }

    return false;
});

check('dipendenza inesistente rifiutata', function () {
    try {
        FeatureCatalog::fromArray(['a' => ['name' => 'A', 'area' => 'X', 'requires' => ['manca']]]);
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'manca');
    }

    return false;
});

check('dipendenze circolari rifiutate', function () {
    try {
        FeatureCatalog::fromArray([
            'a' => ['name' => 'A', 'area' => 'X', 'requires' => ['b']],
            'b' => ['name' => 'B', 'area' => 'X', 'requires' => ['a']],
        ]);
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'circolare');
    }

    return false;
});

check('chiave non valida rifiutata', function () {
    try {
        FeatureCatalog::fromArray(['Ordini Online' => ['name' => 'X', 'area' => 'Y']]);
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'chiave');
    }

    return false;
});

check('il catalogo del pacchetto è valido e contiene le funzionalità della spec', function () {
    $catalogo = FeatureCatalog::all();
    $attese = [
        'orders', 'quotes', 'returns', 'delivery_notes', 'subscriptions', 'bundles',
        'customizations', 'barcode_labels', 'multi_location', 'purchasing', 'batch_tracking',
        'low_stock_alerts', 'backorders', 'customer_price_lists', 'discount_campaigns',
        'coupons', 'e_invoicing', 'deferred_invoicing', 'shipping', 'carriers', 'pos',
    ];

    return array_diff($attese, array_keys($catalogo)) === [];
});

check('una funzionalità è costruita salvo `created => false`', function () {
    $catalogo = FeatureCatalog::fromArray([
        'a' => ['name' => 'A', 'area' => 'X'],
        'b' => ['name' => 'B', 'area' => 'X', 'created' => false],
    ]);

    return $catalogo['a']['created'] === true && $catalogo['b']['created'] === false;
});

check('nel catalogo vero quelle già costruite non sono segnate come future', function () {
    $catalogo = FeatureCatalog::all();

    return $catalogo['orders']['created'] === true
        && $catalogo['returns']['created'] === true
        && $catalogo['bundles']['created'] === true
        && $catalogo['shipping']['created'] === true
        && $catalogo['batch_tracking']['created'] === true
        && $catalogo['carriers']['created'] === false;
});

check('requires_any normalizzata, vuota di serie', function () {
    $catalogo = FeatureCatalog::fromArray([
        'a' => ['name' => 'A', 'area' => 'X'],
        'b' => ['name' => 'B', 'area' => 'X'],
        'c' => ['name' => 'C', 'area' => 'X', 'requires_any' => ['a', ' ', 'b']],
    ]);

    return $catalogo['a']['requires_any'] === [] && $catalogo['c']['requires_any'] === ['a', 'b'];
});

check('requires_any inesistente rifiutata', function () {
    try {
        FeatureCatalog::fromArray(['a' => ['name' => 'A', 'area' => 'X', 'requires_any' => ['manca']]]);
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'manca');
    }

    return false;
});

check('requires_any circolare rifiutata', function () {
    try {
        FeatureCatalog::fromArray([
            'a' => ['name' => 'A', 'area' => 'X', 'requires_any' => ['b']],
            'b' => ['name' => 'B', 'area' => 'X', 'requires' => ['a']],
        ]);
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'circolare');
    }

    return false;
});

check('nomi di ciò che serve prima, con le alternative unite', function () {
    $catalogo = FeatureCatalog::fromArray([
        'a' => ['name' => 'Ordini', 'area' => 'X'],
        'b' => ['name' => 'Abbonamenti', 'area' => 'X'],
        'm' => ['name' => 'Magazzino', 'area' => 'X'],
        'c' => ['name' => 'C', 'area' => 'X', 'requires' => ['m'], 'requires_any' => ['a', 'b']],
    ]);

    return FeatureCatalog::requiredNames($catalogo, $catalogo['c']) === ['Magazzino', 'Ordini o Abbonamenti'];
});

summary();
