<?php
/** php tests/FeatureRulesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;
use Wonder\Plugin\Gestionale\Support\Features\FeatureRules;

$catalogo = FeatureCatalog::fromArray([
    'orders' => ['name' => 'Ordini', 'area' => 'Vendite'],
    'returns' => ['name' => 'Resi', 'area' => 'Vendite', 'requires' => ['orders']],
    'delivery_notes' => ['name' => 'DDT', 'area' => 'Vendite', 'requires' => ['orders']],
    'deferred' => ['name' => 'Fattura differita', 'area' => 'Fatturazione', 'requires' => ['delivery_notes']],
]);

$tutteBloccate = ['orders' => false, 'returns' => false, 'delivery_notes' => false, 'deferred' => false];

check('spuntando una funzionalità si sbloccano le sue dipendenze', function () use ($catalogo, $tutteBloccate) {
    $esito = FeatureRules::apply($catalogo, $tutteBloccate, ['returns' => true]);

    return $esito['state']['returns'] === true
        && $esito['state']['orders'] === true
        && $esito['unlocked'] === ['orders', 'returns'];
});

check('le dipendenze si sbloccano a catena', function () use ($catalogo, $tutteBloccate) {
    $esito = FeatureRules::apply($catalogo, $tutteBloccate, ['deferred' => true]);

    return $esito['state']['orders'] === true
        && $esito['state']['delivery_notes'] === true
        && $esito['state']['deferred'] === true;
});

check('togliendo la spunta si bloccano le funzionalità che dipendono', function () use ($catalogo) {
    $attuale = ['orders' => true, 'returns' => true, 'delivery_notes' => true, 'deferred' => true];
    $esito = FeatureRules::apply($catalogo, $attuale, [
        'orders' => false, 'returns' => true, 'delivery_notes' => true, 'deferred' => true,
    ]);

    return $esito['state'] === ['orders' => false, 'returns' => false, 'delivery_notes' => false, 'deferred' => false]
        && $esito['locked'] === ['orders', 'returns', 'delivery_notes', 'deferred'];
});

check('sbloccando si tira su la dipendenza che mancava', function () use ($catalogo) {
    $attuale = ['orders' => true, 'returns' => true, 'delivery_notes' => false, 'deferred' => false];
    $esito = FeatureRules::apply($catalogo, $attuale, ['orders' => true, 'returns' => true, 'deferred' => true]);

    return $esito['state']['deferred'] === true
        && $esito['state']['delivery_notes'] === true
        && $esito['unlocked'] === ['delivery_notes', 'deferred'];
});

check('uno stato incoerente nel database viene sistemato al salvataggio', function () use ($catalogo) {
    // deferred attiva senza il DDT: può succedere solo a mano nel database.
    $attuale = ['orders' => true, 'returns' => false, 'delivery_notes' => false, 'deferred' => true];
    $esito = FeatureRules::apply($catalogo, $attuale, $attuale);

    return $esito['state']['deferred'] === false && $esito['locked'] === ['deferred'];
});

check('senza cambiamenti non risulta niente sbloccato né bloccato', function () use ($catalogo) {
    $attuale = ['orders' => true, 'returns' => true, 'delivery_notes' => false, 'deferred' => false];
    $esito = FeatureRules::apply($catalogo, $attuale, $attuale);

    return $esito['unlocked'] === [] && $esito['locked'] === [] && $esito['state'] === $attuale;
});

check('dipendenze e dipendenti del catalogo vero', function () {
    $vero = FeatureCatalog::all();

    return FeatureRules::dependencies($vero, 'deferred_invoicing') === ['delivery_notes', 'orders', 'e_invoicing']
        && in_array('backorders', FeatureRules::dependents($vero, 'orders'), true);
});

// G10b.4: Personalizzazione con Ordini OPPURE Abbonamenti.
$conAlternative = FeatureCatalog::fromArray([
    'orders' => ['name' => 'Ordini', 'area' => 'Vendite'],
    'subscriptions' => ['name' => 'Abbonamenti', 'area' => 'Vendite'],
    'customizations' => ['name' => 'Personalizzazione', 'area' => 'Catalogo', 'requires_any' => ['orders', 'subscriptions']],
]);

check('requires_any: accesa senza alternative attive tira su la prima', function () use ($conAlternative) {
    $esito = FeatureRules::apply($conAlternative, [], ['customizations' => true]);

    return $esito['state'] === ['orders' => true, 'subscriptions' => false, 'customizations' => true];
});

check('requires_any: con gli abbonamenti accesi non tira su gli ordini', function () use ($conAlternative) {
    $esito = FeatureRules::apply($conAlternative, ['subscriptions' => true], ['customizations' => true]);

    return $esito['state'] === ['orders' => false, 'subscriptions' => true, 'customizations' => true]
        && $esito['unlocked'] === ['customizations'];
});

check('requires_any: spenta una alternativa resta accesa con l\'altra', function () use ($conAlternative) {
    $attuale = ['orders' => true, 'subscriptions' => true, 'customizations' => true];
    $esito = FeatureRules::apply($conAlternative, $attuale, ['orders' => false]);

    return $esito['state'] === ['orders' => false, 'subscriptions' => true, 'customizations' => true]
        && $esito['locked'] === ['orders'];
});

check('requires_any: spente tutte le alternative si spegne', function () use ($conAlternative) {
    $attuale = ['orders' => true, 'subscriptions' => false, 'customizations' => true];
    $esito = FeatureRules::apply($conAlternative, $attuale, ['orders' => false]);

    return $esito['state']['customizations'] === false
        && $esito['locked'] === ['orders', 'customizations'];
});

check('requires_any: a catena con le dipendenze normali', function () {
    $catalogo = FeatureCatalog::fromArray([
        'orders' => ['name' => 'Ordini', 'area' => 'Vendite'],
        'subscriptions' => ['name' => 'Abbonamenti', 'area' => 'Vendite'],
        'customizations' => ['name' => 'Personalizzazione', 'area' => 'Catalogo', 'requires_any' => ['orders', 'subscriptions']],
        'engraving' => ['name' => 'Incisione', 'area' => 'Catalogo', 'requires' => ['customizations']],
    ]);
    $su = FeatureRules::apply($catalogo, [], ['engraving' => true]);
    $giu = FeatureRules::apply($catalogo, $su['state'], ['orders' => false]);

    return $su['state'] === ['orders' => true, 'subscriptions' => false, 'customizations' => true, 'engraving' => true]
        && $giu['state'] === ['orders' => false, 'subscriptions' => false, 'customizations' => false, 'engraving' => false];
});

check('nel catalogo vero la Personalizzazione va con Ordini o Abbonamenti', function () {
    $vero = FeatureCatalog::all();

    return $vero['customizations']['requires'] === []
        && $vero['customizations']['requires_any'] === ['orders', 'subscriptions']
        && FeatureRules::dependencies($vero, 'customizations') === [];
});

summary();
