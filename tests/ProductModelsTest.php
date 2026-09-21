<?php
/** php tests/ProductModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelTag;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
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

check('le tabelle del modello hanno il prefisso del gestionale', fn () =>
    ProductModel::$table === 'gst_product_models'
    && ProductModelCategory::$table === 'gst_product_model_categories'
    && ProductModelTag::$table === 'gst_product_model_tags'
);

check('i modelli non viaggiano con il deploy', fn () =>
    ProductModel::syncSchema() === null
    && ProductModelCategory::syncSchema() === null
    && ProductModelTag::syncSchema() === null
);

check('il codice del modello ha il suo prefisso', function () use ($campo) {
    return ($campo(ProductModel::class, 'code')?->getSchema('unique_code')['prefix'] ?? null)
        === Codes::MODEL;
});

check('codice e indirizzo non si cambiano dopo la creazione', function () use ($campo) {
    foreach (['code', 'slug'] as $key) {
        if (empty($campo(ProductModel::class, $key)?->getSchema('immutable_on_update'))) {
            return false;
        }
    }

    return true;
});

check('l\'indirizzo del modello è unico', function () use ($colonne) {
    return !empty($colonne(ProductModel::class)['slug']->getSchema('unique'));
});

check('lo SKU non ha un indice unico', function () use ($colonne) {
    // Il framework scrive stringhe vuote, non NULL: due modelli senza SKU si
    // scontrerebbero. L'unicità la controlla il codice, con una frase da leggere.
    return empty($colonne(ProductModel::class)['sku']->getSchema('unique'));
});

check('il tipo è un elenco chiuso che nasce semplice', function () use ($colonne) {
    $tipo = $colonne(ProductModel::class)['type'];

    return $tipo->getSchema('enum') === ['simple', 'bundle']
        && $tipo->getSchema('default') === 'simple';
});

check('l\'unità di misura nasce a pezzi', function () use ($colonne) {
    return $colonne(ProductModel::class)['unit']->getSchema('default') === 'pz';
});

check('peso e misure tengono i decimali', function () use ($colonne) {
    foreach (['weight', 'length', 'width', 'height'] as $key) {
        if ($colonne(ProductModel::class)[$key]->getSchema('type') !== 'DECIMAL') {
            return false;
        }
    }

    return true;
});

check('i collegamenti puntano dove devono', function () use ($colonne) {
    $categorie = $colonne(ProductModelCategory::class);
    $tag = $colonne(ProductModelTag::class);

    return $categorie['product_model_id']->getSchema('foreign_table') === ProductModel::$table
        && $categorie['category_id']->getSchema('foreign_table') === Category::$table
        && $tag['product_model_id']->getSchema('foreign_table') === ProductModel::$table
        && $tag['tag_id']->getSchema('foreign_table') === Tag::$table;
});

check('una sola categoria principale per modello si riconosce', function () use ($colonne) {
    return $colonne(ProductModelCategory::class)['is_main']->getSchema('enum') === ['true', 'false'];
});

summary();
