<?php
/** php tests/CatalogAttributeModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Support\Codes;

$colonne = static function (string $model): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

$campo = static function (string $model, string $key): ?object {
    foreach ($model::dataSchema() as $field) {
        if ((string) $field->key === $key) {
            return $field;
        }
    }

    return null;
};

check('le due tabelle hanno il prefisso del gestionale', fn () =>
    Attribute::$table === 'gst_attributes'
    && AttributeValue::$table === 'gst_attribute_values'
);

check('gli attributi non viaggiano con il deploy', fn () =>
    Attribute::syncSchema() === null && AttributeValue::syncSchema() === null
);

check('il codice dell\'attributo ha il suo prefisso', function () use ($campo) {
    return ($campo(Attribute::class, 'code')?->getSchema('unique_code')['prefix'] ?? null)
        === Codes::ATTRIBUTE;
});

check('codice e nome macchina non si cambiano dopo la creazione', function () use ($campo) {
    foreach (['code', 'slug'] as $key) {
        if (empty($campo(Attribute::class, $key)?->getSchema('immutable_on_update'))) {
            return false;
        }
    }

    return true;
});

check('nessuna colonna usa una parola riservata di MySQL', function () use ($colonne) {
    // `key` e `group` romperebbero un ORDER BY: il costruttore di query mette
    // le virgolette solo su INSERT, UPDATE e WHERE.
    $riservate = ['key', 'group', 'order', 'index', 'default'];

    foreach ([Attribute::class, AttributeValue::class] as $model) {
        foreach (array_keys($colonne($model)) as $nome) {
            if (in_array(strtolower((string) $nome), $riservate, true)) {
                return false;
            }
        }
    }

    return true;
});

check('il nome macchina è unico', function () use ($colonne) {
    return !empty($colonne(Attribute::class)['slug']->getSchema('unique'));
});

check('i valori appartengono a un attributo', function () use ($colonne) {
    $colonna = $colonne(AttributeValue::class)['attribute_id'];

    return $colonna->getSchema('foreign_table') === Attribute::$table
        && $colonna->getSchema('foreign_key') === 'id';
});

check('tipo e livello sono elenchi chiusi', function () use ($colonne) {
    return $colonne(Attribute::class)['type']->getSchema('enum') === ['select', 'color', 'text', 'number']
        && $colonne(Attribute::class)['level']->getSchema('enum') === ['model', 'variant', 'product'];
});

summary();
