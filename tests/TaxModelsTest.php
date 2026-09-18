<?php
/** php tests/TaxModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Models\Tax\TaxRule;
use Wonder\Sql\TableSchema as Column;

/** @return array<string, Column> */
$colonne = static function (string $model): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

check('le tabelle hanno il prefisso del gestionale', fn () =>
    Tax::$table === 'gst_taxes'
    && TaxCategory::$table === 'gst_tax_categories'
    && TaxRule::$table === 'gst_tax_rules'
);

check('le tre tabelle si modificano solo in locale e tengono gli id', function () {
    foreach ([Tax::class, TaxCategory::class, TaxRule::class] as $model) {
        $schema = $model::syncSchema();

        if ($schema === null || !$schema->keepIds || !$schema->localOnly) {
            return false;
        }
    }

    return true;
});

check('l\'aliquota ha valore, natura e visibilità', function () use ($colonne) {
    $colonne = $colonne(Tax::class);

    return isset($colonne['code'], $colonne['name'], $colonne['invoice_description'], $colonne['rate'], $colonne['nature'], $colonne['visible']);
});

check('il tipo fiscale ha posizione e visibilità', function () use ($colonne) {
    $colonne = $colonne(TaxCategory::class);

    return isset($colonne['code'], $colonne['name'], $colonne['position'], $colonne['visible']);
});

check('la regola lega paese, tipo cliente e tipo fiscale a un\'aliquota', function () use ($colonne) {
    $colonne = $colonne(TaxRule::class);

    return isset($colonne['country'], $colonne['customer_type'], $colonne['tax_category_id'], $colonne['tax_id']);
});

check('una regola sola per paese, tipo cliente e tipo fiscale', function () use ($colonne) {
    $unica = $colonne(TaxRule::class)['tax_category_id']->schema['unique'] ?? null;

    return $unica === ['country', 'customer_type', 'tax_category_id'];
});

check('le regole puntano davvero alle altre due tabelle', function () use ($colonne) {
    $colonne = $colonne(TaxRule::class);

    return ($colonne['tax_category_id']->schema['foreign_table'] ?? '') === TaxCategory::$table
        && ($colonne['tax_id']->schema['foreign_table'] ?? '') === Tax::$table;
});

check('il codice non si cambia dopo l\'inserimento', function () {
    foreach ([Tax::class, TaxCategory::class, TaxRule::class] as $model) {
        foreach ($model::dataSchema() as $field) {
            if ((string) $field->key !== 'code') {
                continue;
            }

            if (empty($field->schema['immutable_on_update'])) {
                return false;
            }
        }
    }

    return true;
});

summary();
