<?php
/** php tests/BundleModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\BundleComponent;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleGroup;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleGroupOption;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;

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

check('le tabelle hanno il prefisso del gestionale e la loro cartella', fn () =>
    BundleComponent::$table === 'gst_bundle_components'
    && BundleGroup::$table === 'gst_bundle_groups'
    && BundleGroupOption::$table === 'gst_bundle_group_options'
    && BundleComponent::$folder === 'gestionale/models'
    && BundleGroup::$folder === 'gestionale/models'
    && BundleGroupOption::$folder === 'gestionale/models'
);

check('i multiprodotti non viaggiano con il deploy', fn () =>
    BundleComponent::syncSchema() === null
    && BundleGroup::syncSchema() === null
    && BundleGroupOption::syncSchema() === null
);

check('ogni tabella ha le sue colonne', function () use ($colonne) {
    $attese = [
        BundleComponent::class => ['product_model_id', 'product_id', 'quantity', 'position'],
        BundleGroup::class => ['product_model_id', 'name', 'min_choices', 'max_choices', 'position'],
        BundleGroupOption::class => ['bundle_group_id', 'product_id', 'surcharge', 'position'],
    ];

    foreach ($attese as $model => $nomi) {
        if (array_diff($nomi, array_keys($colonne($model))) !== []) {
            return false;
        }
    }

    return true;
});

check('la quantità del componente ha tre decimali, il sovrapprezzo due', function () use ($colonne, $campo) {
    $quantita = $colonne(BundleComponent::class)['quantity'];
    $sovrapprezzo = $colonne(BundleGroupOption::class)['surcharge'];

    return $quantita->getSchema('type') === 'DECIMAL' && $quantita->getSchema('length') === '12,3'
        && $sovrapprezzo->getSchema('type') === 'DECIMAL' && $sovrapprezzo->getSchema('length') === '12,2'
        && $campo(BundleComponent::class, 'quantity') !== null
        && $campo(BundleGroupOption::class, 'surcharge') !== null;
});

check('le chiavi esterne puntano all\'articolo, al prodotto e al gruppo', function () use ($colonne) {
    return $colonne(BundleComponent::class)['product_model_id']->getSchema('foreign_table') === ProductModel::$table
        && $colonne(BundleComponent::class)['product_id']->getSchema('foreign_table') === Product::$table
        && $colonne(BundleGroup::class)['product_model_id']->getSchema('foreign_table') === ProductModel::$table
        && $colonne(BundleGroupOption::class)['bundle_group_id']->getSchema('foreign_table') === BundleGroup::$table
        && $colonne(BundleGroupOption::class)['product_id']->getSchema('foreign_table') === Product::$table;
});

check('gli indici dei collegamenti ci sono', function () {
    return array_key_exists('ind_model', BundleComponent::tablePseudos())
        && array_key_exists('ind_product', BundleComponent::tablePseudos())
        && array_key_exists('ind_model', BundleGroup::tablePseudos())
        && array_key_exists('ind_group', BundleGroupOption::tablePseudos())
        && array_key_exists('ind_product', BundleGroupOption::tablePseudos());
});

check('nessuna colonna usa una parola riservata di MySQL', function () use ($colonne) {
    $riservate = ['key', 'group', 'order', 'index', 'default', 'min', 'max'];

    foreach ([BundleComponent::class, BundleGroup::class, BundleGroupOption::class] as $model) {
        foreach (array_keys($colonne($model)) as $nome) {
            if (in_array(strtolower((string) $nome), $riservate, true)) {
                return false;
            }
        }
    }

    return true;
});

check('il gruppo ha il nome e l\'intervallo di scelte come numeri interi', function () use ($campo) {
    return $campo(BundleGroup::class, 'name') !== null
        && $campo(BundleGroup::class, 'min_choices') !== null
        && $campo(BundleGroup::class, 'max_choices') !== null;
});

summary();
