<?php
/** php tests/OptionsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/** Un catalogo finto: Colore e Gusto hanno pagina propria, Taglia e Lunghezza no. */
$scheda = new class extends ProductModelResource {
    public static function attributes(): array
    {
        return [
            ['id' => 1, 'name' => 'Colore', 'type' => 'select', 'level' => 'variant', 'unit' => '', 'position' => 1],
            ['id' => 2, 'name' => 'Taglia', 'type' => 'select', 'level' => 'product', 'unit' => '', 'position' => 2],
            ['id' => 3, 'name' => 'Lunghezza', 'type' => 'select', 'level' => 'product', 'unit' => '', 'position' => 3],
            ['id' => 4, 'name' => 'Materiale', 'type' => 'select', 'level' => 'model', 'unit' => '', 'position' => 4],
            ['id' => 5, 'name' => 'Gusto', 'type' => 'select', 'level' => 'variant', 'unit' => '', 'position' => 5],
            ['id' => 6, 'name' => 'Peso', 'type' => 'number', 'level' => 'product', 'unit' => 'kg', 'position' => 6],
        ];
    }

    public static function attributeValues(): array
    {
        return [
            10 => ['id' => 10, 'attribute_id' => 1, 'label' => 'Blu'],
            11 => ['id' => 11, 'attribute_id' => 1, 'label' => 'Rosso'],
            20 => ['id' => 20, 'attribute_id' => 2, 'label' => 'S'],
            21 => ['id' => 21, 'attribute_id' => 2, 'label' => 'M'],
            30 => ['id' => 30, 'attribute_id' => 3, 'label' => 'Corta'],
            40 => ['id' => 40, 'attribute_id' => 4, 'label' => 'Cotone'],
            50 => ['id' => 50, 'attribute_id' => 5, 'label' => 'Fragola'],
        ];
    }
};

check('l\'albero mostra solo le opzioni, non le caratteristiche', function () use ($scheda) {
    $albero = $scheda::optionTree();

    return isset($albero['attr_1'], $albero['attr_2'], $albero['attr_3'], $albero['attr_5'])
        && !isset($albero['attr_4'])
        && $albero['attr_1']['name'] === 'Colore'
        // PHP riporta a numero le chiavi numeriche di un array.
        && array_keys($albero['attr_1']['child']) === [10, 11];
});

check('un\'opzione senza valori non compare', function () use ($scheda) {
    // "Peso" crea versioni ma è un numero: non ha niente da spuntare.
    return !isset($scheda::optionTree()['attr_6']);
});

check('le spunte si dividono fra pagina propria e resto', function () use ($scheda) {
    $scelte = $scheda::chosenAxes(['attr_1', '10', '11', '20', '21', '30']);

    return count($scelte['variant']) === 2
        && $scelte['variant'][0] === ['id' => 10, 'label' => 'Blu']
        && count($scelte['axes']) === 2;
});

check('ogni opzione è un asse a sé', function () use ($scheda) {
    $scelte = $scheda::chosenAxes(['20', '21', '30']);
    $misure = array_map('count', $scelte['axes']);
    sort($misure);

    return $scelte['variant'] === [] && $misure === [1, 2];
});

check('le caratteristiche spuntate per sbaglio non contano', function () use ($scheda) {
    $scelte = $scheda::chosenAxes(['40', '20']);

    return $scelte['variant'] === [] && count($scelte['axes']) === 1;
});

check('due opzioni con pagina propria sono un rifiuto', function () use ($scheda) {
    try {
        $scheda::chosenAxes(['10', '50']);
    } catch (UserError $errore) {
        return $errore->key() === 'product.one_page_option';
    }

    return false;
});

check('due valori della stessa opzione con pagina propria vanno bene', function () use ($scheda) {
    $scelte = $scheda::chosenAxes(['10', '11']);

    return count($scelte['variant']) === 2 && $scelte['axes'] === [];
});

check('niente spuntato, niente assi', function () use ($scheda) {
    $scelte = $scheda::chosenAxes([]);

    return $scelte === ['variant' => [], 'axes' => []];
});

summary();
