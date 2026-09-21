<?php
/** php tests/CatalogModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

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

check('le tre tabelle hanno il prefisso del gestionale', fn () =>
    Brand::$table === 'gst_brands'
    && Category::$table === 'gst_categories'
    && Tag::$table === 'gst_tags'
);

check('il catalogo non viaggia con il deploy', fn () =>
    Brand::syncSchema() === null
    && Category::syncSchema() === null
    && Tag::syncSchema() === null
);

check('ogni tassonomia ha il suo prefisso nel codice', function () use ($campo) {
    $prefisso = static fn (string $model): ?string => $campo($model, 'code')
        ?->getSchema('unique_code')['prefix'] ?? null;

    return $prefisso(Brand::class) === Codes::BRAND
        && $prefisso(Category::class) === Codes::CATEGORY
        && $prefisso(Tag::class) === Codes::TAG;
});

check('codice e slug non si cambiano dopo la creazione', function () use ($campo) {
    foreach ([Brand::class, Category::class, Tag::class] as $model) {
        foreach (['code', 'slug'] as $key) {
            if (empty($campo($model, $key)?->getSchema('immutable_on_update'))) {
                return false;
            }
        }
    }

    return true;
});

check('lo slug è unico in ogni tassonomia', function () use ($colonne) {
    foreach ([Brand::class, Category::class, Tag::class] as $model) {
        if (($colonne($model)['slug']->schema['unique'] ?? false) !== true) {
            return false;
        }
    }

    return true;
});

check('la categoria punta alla sua categoria padre', function () use ($colonne) {
    $parent = $colonne(Category::class)['parent_id'] ?? null;

    return $parent !== null && ($parent->schema['foreign_table'] ?? '') === Category::$table;
});

check('marchi e categorie hanno posizione e visibilità', function () use ($colonne) {
    $brand = $colonne(Brand::class);
    $category = $colonne(Category::class);

    return isset($brand['position'], $brand['visible'], $brand['logo'])
        && isset($category['position'], $category['visible'], $category['image']);
});

summary();
