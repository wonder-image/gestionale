<?php
/** php tests/ProductSheetFeaturesTest.php */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\VariantResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Measures;
use Wonder\Plugin\Gestionale\Support\Features\FeatureGuards;

// Le funzionalità di «Scheda prodotto»: Suggeriti, Alternativi, Varianti,
// Misure, Aliquote e Scheda tecnica. Spente, la scheda di un articolo nuovo ha l'essenziale.

$forza = static function (?array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
};

$campi = static function (): array {
    $out = [];

    foreach (ProductModelResource::formSchema() as $campo) {
        $out[(string) $campo->name] = $campo;
    }

    return $out;
};

check('il catalogo mette le sei nella card «Scheda prodotto»', function () {
    $catalogo = require __DIR__.'/../config/features.php';

    foreach (['related_suggested', 'related_alternative', 'variants', 'measures', 'tax_categories', 'technical_sheet'] as $chiave) {
        if (($catalogo[$chiave]['area'] ?? '') !== 'Scheda prodotto') {
            return false;
        }
    }

    return ($catalogo['packages']['area'] ?? '') !== 'Scheda prodotto';
});

check('senza «Misure» le misure valgono zero, come se non ci fossero', function () use ($forza) {
    $riga = ['weight' => '1.5', 'length' => '10', 'width' => '20', 'height' => '30'];

    $forza(['measures' => false]);
    $spenta = Measures::of($riga);
    $forza(['measures' => true]);
    $accesa = Measures::of($riga);
    $vuota = Measures::of(null);
    $forza(null);

    return $spenta === ['weight' => 0.0, 'length' => 0.0, 'width' => 0.0, 'height' => 0.0]
        && $accesa === ['weight' => 1.5, 'length' => 10.0, 'width' => 20.0, 'height' => 30.0]
        && $vuota === ['weight' => 0.0, 'length' => 0.0, 'width' => 0.0, 'height' => 0.0];
});

check('spente, la scheda non chiede misure, unità, tipo fiscale né varianti', function () use ($forza, $campi) {
    $forza([]);

    try {
        $c = $campi();
    } finally {
        $forza(null);
    }

    foreach (['unit', 'weight', 'length', 'width', 'height', 'tax_category_id'] as $nome) {
        if (isset($c[$nome])) {
            return false;
        }
    }

    // La domanda delle varianti resta, nascosta e a «no»: il core la salva.
    return isset($c['has_variants']) && $c['has_variants']->get('value') === 'false';
});

check('accese, la scheda chiede misure, unità e tipo fiscale', function () use ($forza, $campi) {
    $forza(['measures' => true, 'tax_categories' => true, 'variants' => true]);

    try {
        $c = $campi();
    } finally {
        $forza(null);
    }

    foreach (['unit', 'weight', 'length', 'width', 'height', 'tax_category_id', 'has_variants'] as $nome) {
        if (!isset($c[$nome])) {
            return false;
        }
    }

    return true;
});

check('la pagina Varianti segue la funzionalità', function () use ($forza) {
    $forza(['variants' => false]);
    $spenta = VariantResource::featureActive();
    $forza(['variants' => true]);
    $accesa = VariantResource::featureActive();
    $forza(null);

    return VariantResource::$feature === 'variants' && !$spenta && $accesa;
});

check('il messaggio della guardia conta gli articoli con varianti', fn () =>
    FeatureGuards::variantsLabel(1) === 'c\'è 1 articolo con varianti'
    && FeatureGuards::variantsLabel(3) === 'ci sono 3 articoli con varianti'
);

check('spegnere altro non tocca gli articoli con varianti', function () {
    FeatureGuards::assertAllowed(['variants' => false, 'measures' => true], ['variants' => false, 'measures' => false]);

    return true;
});

summary();
