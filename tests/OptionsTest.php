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

check('c\'è un gruppo di caselle per ogni opzione, non per le caratteristiche', function () use ($scheda) {
    $campi = [];

    foreach ($scheda::optionFields() as $campo) {
        $campi[(string) $campo->name] = $campo;
    }

    return array_keys($campi) === ['option_1', 'option_2', 'option_3', 'option_5']
        && $campi['option_1']->get('label') === 'Colore'
        && $campi['option_1']->get('options') === ['10' => 'Blu', '11' => 'Rosso'];
});

check('ogni gruppo elenca i valori della sua opzione', function () use ($scheda) {
    $opzioni = $scheda::valuesOf(1);

    return $opzioni === ['10' => 'Blu', '11' => 'Rosso'];
});

check('un\'opzione senza valori non compare', function () use ($scheda) {
    // "Peso" crea versioni ma è un numero: non ha niente da spuntare.
    $chiavi = array_map(static fn ($c): string => (string) $c->name, $scheda::optionFields());

    return !in_array('option_6', $chiavi, true);
});

check('le spunte si dividono fra pagina propria e resto', function () use ($scheda) {
    $scelte = $scheda::chosenAxes([
        'option_1' => ['10', '11'],
        'option_2' => ['20', '21'],
        'option_3' => ['30'],
    ]);

    return count($scelte['variant']) === 2
        && $scelte['variant'][0] === ['id' => 10, 'label' => 'Blu']
        && count($scelte['axes']) === 2;
});

check('ogni opzione è un asse a sé', function () use ($scheda) {
    $scelte = $scheda::chosenAxes(['option_2' => ['20', '21'], 'option_3' => ['30']]);
    $misure = array_map('count', $scelte['axes']);
    sort($misure);

    return $scelte['variant'] === [] && $misure === [1, 2];
});

check('quello che non è un valore di quell\'opzione non conta', function () use ($scheda) {
    $scelte = $scheda::chosenAxes(['option_2' => ['20', '40', 'niente']]);

    return $scelte['variant'] === [] && count($scelte['axes']) === 1;
});

check('due opzioni con pagina propria sono un rifiuto', function () use ($scheda) {
    try {
        $scheda::chosenAxes(['option_1' => ['10'], 'option_5' => ['50']]);
    } catch (UserError $errore) {
        return $errore->key() === 'product.one_page_option';
    }

    return false;
});

check('due valori della stessa opzione con pagina propria vanno bene', function () use ($scheda) {
    $scelte = $scheda::chosenAxes(['option_1' => ['10', '11']]);

    return count($scelte['variant']) === 2 && $scelte['axes'] === [];
});

check('niente spuntato, niente assi', function () use ($scheda) {
    $scelte = $scheda::chosenAxes([]);

    return $scelte === ['variant' => [], 'axes' => []];
});

check('i valori di un colore o di un\'icona portano il loro pallino o la loro immagine', function () use ($scheda) {
    $colore = ['id' => 7, 'type' => 'color'];
    $icona = ['id' => 8, 'type' => 'icon'];
    $valori = new class extends ProductModelResource {
        public static function attributeValues(): array
        {
            return [
                70 => ['id' => 70, 'attribute_id' => 7, 'label' => 'Blu', 'color' => '#1d4ed8'],
                71 => ['id' => 71, 'attribute_id' => 7, 'label' => 'Senza', 'color' => ''],
                80 => ['id' => 80, 'attribute_id' => 8, 'label' => 'Vegano', 'image' => '["https://esempio.it/vegano.png"]'],
                81 => ['id' => 81, 'attribute_id' => 8, 'label' => 'Senza immagine', 'icon' => 'bi-leaf'],
            ];
        }
    };

    return $valori::valueChoices($colore) === [
            '70' => ['name' => 'Blu', 'color' => '#1d4ed8'],
            '71' => 'Senza',
        ]
        // L'icona è solo la sua immagine: un vecchio segno della raccolta non si mostra.
        && $valori::valueChoices($icona) === [
            '80' => ['name' => 'Vegano', 'image' => 'https://esempio.it/vegano.png'],
            '81' => 'Senza immagine',
        ]
        // Un elenco resta testo: niente segni da disegnare.
        && $scheda::valueChoices(['id' => 1, 'type' => 'select']) === ['10' => 'Blu', '11' => 'Rosso'];
});

summary();
