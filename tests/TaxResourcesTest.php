<?php
/** php tests/TaxResourcesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Models\Tax\TaxRule;
use Wonder\Plugin\Gestionale\Resources\Tax\TaxCategoryResource;
use Wonder\Plugin\Gestionale\Resources\Tax\TaxResource;
use Wonder\Plugin\Gestionale\Resources\Tax\TaxRuleResource;

check('ogni pagina ha il suo model e il suo percorso', fn () =>
    TaxResource::$model === Tax::class
    && TaxCategoryResource::$model === TaxCategory::class
    && TaxRuleResource::$model === TaxRule::class
    && TaxResource::path() === 'app/gestionale/aliquote-iva'
    && TaxCategoryResource::path() === 'app/gestionale/tipi-fiscali'
    && TaxRuleResource::path() === 'app/gestionale/regole-iva'
);

check('le pagine fiscali stanno in Set Up e sono solo di admin', function () {
    foreach ([TaxResource::class, TaxCategoryResource::class, TaxRuleResource::class] as $resource) {
        $navigation = $resource::navigationSchema()->toArray();
        $permissions = $resource::permissionSchema()->toArray();

        if (($navigation['section_key'] ?? '') !== 'set-up') {
            return false;
        }

        foreach ($permissions['backend'] ?? [] as $authorities) {
            // Le azioni disattivate restano con la lista vuota.
            if ($authorities !== [] && $authorities !== ['admin']) {
                return false;
            }
        }
    }

    return true;
});

check('le pagine fiscali sono sempre attive', fn () =>
    TaxResource::$feature === '' && TaxCategoryResource::$feature === '' && TaxRuleResource::$feature === ''
);

check('un\'aliquota non si elimina', function () {
    try {
        TaxResource::assertDeletable(1);
    } catch (RuntimeException $exception) {
        return str_contains($exception->getMessage(), 'nascondi');
    }

    return false;
});

check('l\'elenco mostra codice, valore e visibilità', function () {
    $colonne = array_map(
        static fn (object $column): string => (string) $column->name,
        TaxResource::tableSchema()
    );

    return in_array('code', $colonne, true)
        && in_array('rate', $colonne, true)
        && in_array('visible', $colonne, true);
});

check('la natura ha i codici validi del core', function () {
    foreach (TaxResource::formSchema() as $field) {
        if ((string) $field->name !== 'nature') {
            continue;
        }

        $valori = (array) ($field->get('options') ?? []);

        return isset($valori['N3.2']) && !isset($valori['N2']) && isset($valori['']);
    }

    return false;
});

check('la posizione del tipo fiscale non si scrive a mano', function () {
    foreach (TaxCategoryResource::formSchema() as $field) {
        if ((string) $field->name === 'position') {
            return false;
        }
    }

    $creazione = TaxCategoryResource::mutateRequestValues(['name' => 'Alimentari'], 'store');
    $modifica = TaxCategoryResource::mutateRequestValues(['name' => 'Alimentari', 'position' => 9], 'update');

    return (int) ($creazione['position'] ?? 0) >= 1 && !isset($modifica['position']);
});

check('il codice della regola non si scrive a mano', function () {
    foreach (TaxRuleResource::formSchema() as $field) {
        if ((string) $field->name === 'code') {
            return false;
        }
    }

    return true;
});

check('il codice della regola racconta la scelta', function () {
    $valori = TaxRuleResource::mutateRequestValues([
        'country' => 'IT',
        'customer_type' => 'private',
        'tax_category_id' => 7,
    ], 'store');

    // Senza database il tipo fiscale resta l'id: il formato è quello.
    return ($valori['code'] ?? '') === 'it-private-7';
});

check('il codice non cambia più dopo la creazione', function () {
    $valori = TaxRuleResource::mutateRequestValues([
        'code' => 'a-mano',
        'country' => 'DE',
        'customer_type' => 'business',
        'tax_category_id' => 7,
    ], 'update');

    return !isset($valori['code']);
});

summary();
