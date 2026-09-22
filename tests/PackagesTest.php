<?php
/** php tests/PackagesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Package;
use Wonder\Plugin\Gestionale\Resources\Catalog\PackageResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Packages;
use Wonder\Plugin\Gestionale\Support\Codes;

$colonne = static function (): array {
    $colonne = [];

    foreach (Package::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

check('la tabella ha il prefisso del gestionale', fn () => Package::$table === 'gst_packages');

check('gli imballaggi non viaggiano con il deploy', fn () =>
    // Le scatole di un negozio sono sue, come il resto del catalogo.
    Package::syncSchema() === null
);

check('ci sono nome, misure, tara e predefinito', function () use ($colonne) {
    $attese = ['code', 'name', 'length', 'width', 'height', 'weight', 'is_default', 'position', 'active'];

    foreach ($attese as $attesa) {
        if (!isset($colonne()[$attesa])) {
            return false;
        }
    }

    return true;
});

check('la tara tiene i grammi', function () use ($colonne) {
    $peso = $colonne()['weight'];

    // Il generatore del core farebbe DECIMAL(10,2): una busta da 50 grammi
    // diventerebbe 0,05 arrotondato al centesimo, e una da 5 sparirebbe.
    return ($peso->schema['type'] ?? '') === 'DECIMAL'
        && ($peso->schema['length'] ?? '') === '10,3';
});

check('il codice ha il suo prefisso, e lo genera il core', function () {
    foreach (Package::dataSchema() as $field) {
        if ((string) $field->key === 'code') {
            return Codes::PACKAGE === 'pkg_'
                && ($field->getSchema('unique_code')['prefix'] ?? '') === 'pkg_';
        }
    }

    return false;
});

check('la pagina sta in Set up, sotto i tipi fiscali', function () {
    $navigazione = PackageResource::navigationSchema()->toArray();

    return $navigazione['section_key'] === 'set-up'
        && $navigazione['title'] === 'Imballaggi'
        && $navigazione['authority'] === ['admin'];
});

check('lo store API è aperto al "+" della scheda prodotto', function () {
    $schema = PackageResource::apiSchema()->toArray();

    return ($schema['routes']['store'] ?? false) === true
        && ($schema['fields']['store'] ?? []) === ['name', 'weight'];
});

check('alla creazione arriva la posizione', function () {
    $valori = PackageResource::mutateRequestValues(['name' => 'Scatola media'], 'store');

    return ($valori['position'] ?? 0) >= 1;
});

check('in aggiornamento la posizione non si tocca', function () {
    $valori = PackageResource::mutateRequestValues(['name' => 'Scatola media', 'position' => 4], 'update');

    return !isset($valori['position']);
});

check('il peso spedito è prodotto più tara', fn () =>
    Packages::shippingWeight(1.2, ['weight' => '0.2']) === 1.4
);

check('senza imballaggio vale il solo prodotto', fn () =>
    Packages::shippingWeight(1.2, null) === 1.2
    && Packages::shippingWeight(1.2, ['weight' => '']) === 1.2
);

check('senza peso del prodotto resta la sola scatola', fn () =>
    Packages::shippingWeight(0.0, ['weight' => '0.05']) === 0.05
);

check('i pesi si scrivono come si leggono', fn () =>
    Packages::kg(1.4) === '1,4'
    && Packages::kg(0.05) === '0,05'
    && Packages::kg(2.0) === '2'
    && Packages::kg(0.0) === '0'
);

check('la frase dice da dove viene il numero', fn () =>
    Packages::describe(1.2, ['weight' => '0.2']) === '1,4 kg — 1,2 di prodotto e 0,2 di scatola'
);

check('senza il peso del prodotto la frase dice cosa manca', fn () =>
    Packages::describe(0.0, ['weight' => '0.05']) === 'Manca il peso del prodotto'
    && Packages::describe(0.0, null) === 'Manca il peso del prodotto'
);

check('senza scatola la frase non la nomina', fn () =>
    Packages::describe(1.2, null) === '1,2 kg — senza imballaggio scelto'
);

check('una scatola senza tara lo dice, invece di far finta', fn () =>
    Packages::describe(1.2, ['weight' => '0']) === '1,2 kg — la scatola scelta non ha una tara'
);

summary();
