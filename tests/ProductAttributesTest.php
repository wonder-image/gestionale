<?php
/** php tests/ProductAttributesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductAttribute;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelAttribute;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariantAttribute;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;

$colonne = static function (string $model): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

check('ogni livello ha la sua tabella', fn () =>
    ProductAttributes::table('model') === ProductModelAttribute::$table
    && ProductAttributes::table('variant') === ProductVariantAttribute::$table
    && ProductAttributes::table('product') === ProductAttribute::$table
);

check('ogni livello sa da chi dipende', fn () =>
    ProductAttributes::parentKey('model') === 'product_model_id'
    && ProductAttributes::parentKey('variant') === 'product_variant_id'
    && ProductAttributes::parentKey('product') === 'product_id'
);

check('un livello inventato si ferma subito', function () {
    try {
        ProductAttributes::table('negozio');
    } catch (Throwable $errore) {
        return str_contains($errore->getMessage(), 'negozio');
    }

    return false;
});

check('i collegamenti puntano al loro padre e all\'attributo', function () use ($colonne) {
    $coppie = [
        [ProductModelAttribute::class, 'product_model_id', ProductModel::$table],
        [ProductVariantAttribute::class, 'product_variant_id', ProductVariant::$table],
        [ProductAttribute::class, 'product_id', Product::$table],
    ];

    foreach ($coppie as [$model, $chiave, $tabella]) {
        $righe = $colonne($model);

        if ($righe[$chiave]->getSchema('foreign_table') !== $tabella
            || $righe['attribute_id']->getSchema('foreign_table') !== 'gst_attributes') {
            return false;
        }
    }

    return true;
});

check('un collegamento tiene tutte e tre le forme del valore', function () use ($colonne) {
    $righe = $colonne(ProductAttribute::class);

    return isset($righe['attribute_value_id'], $righe['value_text'], $righe['value_number']);
});

check('gli attributi di una riga si leggono con nome e valore', function () {
    $attributi = [
        ['id' => 1, 'name' => 'Colore', 'type' => 'color', 'unit' => ''],
        ['id' => 2, 'name' => 'Peso', 'type' => 'number', 'unit' => 'g'],
        ['id' => 3, 'name' => 'Materiale', 'type' => 'text', 'unit' => ''],
    ];
    $collegamenti = [
        1 => ['attribute_id' => 1, 'attribute_value_id' => 7],
        2 => ['attribute_id' => 2, 'value_number' => 1.5],
        3 => ['attribute_id' => 3, 'value_text' => 'Cotone'],
    ];
    $valori = [7 => ['id' => 7, 'label' => 'Blu']];

    return ProductAttributes::describe($attributi, $collegamenti, $valori) === [
        'Colore' => 'Blu',
        'Peso' => '1,5 g',
        'Materiale' => 'Cotone',
    ];
});

check('un attributo senza valore non si racconta', function () {
    $attributi = [['id' => 1, 'name' => 'Colore', 'type' => 'color', 'unit' => '']];

    return ProductAttributes::describe($attributi, [], []) === [];
});

check('un attributo a più valori si racconta con i valori in fila', function () {
    $attributi = [['id' => 1, 'name' => 'Lavaggio', 'type' => 'icon', 'unit' => '']];
    $collegamenti = [1 => [
        ['attribute_id' => 1, 'attribute_value_id' => 4],
        ['attribute_id' => 1, 'attribute_value_id' => 9],
    ]];
    $valori = [4 => ['id' => 4, 'label' => '30°'], 9 => ['id' => 9, 'label' => 'Non candeggiare']];

    return ProductAttributes::describe($attributi, $collegamenti, $valori) === ['Lavaggio' => '30°, Non candeggiare'];
});

check('righe singole ed elenchi convivono, e i valori vuoti si saltano', function () {
    $attributi = [
        ['id' => 1, 'name' => 'Lavaggio', 'type' => 'icon', 'unit' => ''],
        ['id' => 2, 'name' => 'Materiale', 'type' => 'text', 'unit' => ''],
        ['id' => 3, 'name' => 'Colore', 'type' => 'color', 'unit' => ''],
    ];
    $collegamenti = [
        1 => [
            ['attribute_id' => 1, 'attribute_value_id' => 4],
            ['attribute_id' => 1, 'attribute_value_id' => 77],
            ['attribute_id' => 1, 'attribute_value_id' => 9],
        ],
        2 => ['attribute_id' => 2, 'value_text' => 'Cotone'],
        3 => [['attribute_id' => 3, 'attribute_value_id' => 77]],
    ];
    $valori = [4 => ['id' => 4, 'label' => '30°'], 9 => ['id' => 9, 'label' => 'Non candeggiare']];

    return ProductAttributes::describe($attributi, $collegamenti, $valori) === [
        'Lavaggio' => '30°, Non candeggiare',
        'Materiale' => 'Cotone',
    ];
});

check('un elenco vuoto non si racconta', function () {
    $attributi = [['id' => 1, 'name' => 'Lavaggio', 'type' => 'icon', 'unit' => '']];

    return ProductAttributes::describe($attributi, [1 => []], []) === [];
});

summary();
