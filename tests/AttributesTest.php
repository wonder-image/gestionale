<?php
/** php tests/AttributesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;

$colore = ['id' => 1, 'name' => 'Colore', 'type' => 'color', 'level' => 'variant', 'unit' => '', 'group_name' => ''];
$taglia = ['id' => 2, 'name' => 'Taglia', 'type' => 'select', 'level' => 'product', 'unit' => '', 'group_name' => 'Misure'];
$peso = ['id' => 3, 'name' => 'Peso', 'type' => 'number', 'level' => 'model', 'unit' => 'g', 'group_name' => 'Misure'];
$materiale = ['id' => 4, 'name' => 'Materiale', 'type' => 'text', 'level' => 'model', 'unit' => '', 'group_name' => ''];

check('solo elenco e colore hanno dei valori', fn () =>
    Attributes::usesValues('select')
    && Attributes::usesValues('color')
    && !Attributes::usesValues('text')
    && !Attributes::usesValues('number')
);

check('i livelli e i tipi hanno un nome da leggere', fn () =>
    Attributes::levels()['variant'] === 'Variante'
    && Attributes::types()['select'] === 'Elenco'
);

check('ogni livello vede solo i suoi attributi', function () use ($colore, $taglia, $peso, $materiale) {
    $modello = Attributes::byLevel([$colore, $taglia, $peso, $materiale], 'model');

    return count($modello) === 2
        && array_column($modello, 'name') === ['Peso', 'Materiale'];
});

check('senza gruppo si finisce in Generale', function () use ($colore, $taglia) {
    $gruppi = Attributes::grouped([$colore, $taglia]);

    return array_keys($gruppi) === ['Generale', 'Misure']
        && count($gruppi['Generale']) === 1;
});

check('un elenco scrive l\'id del valore e basta', function () use ($taglia) {
    $riga = Attributes::assignment($taglia, '7');

    return $riga['attribute_value_id'] === 7
        && $riga['value_text'] === ''
        && $riga['value_number'] === null;
});

check('un numero accetta la virgola', function () use ($peso) {
    $riga = Attributes::assignment($peso, '1,5');

    return $riga['value_number'] === 1.5
        && $riga['attribute_value_id'] === null
        && $riga['value_text'] === '';
});

check('un testo resta un testo', function () use ($materiale) {
    return Attributes::assignment($materiale, ' Cotone ')['value_text'] === 'Cotone';
});

check('un valore vuoto non scrive niente', function () use ($taglia, $peso, $materiale) {
    return Attributes::assignment($taglia, '')['attribute_value_id'] === null
        && Attributes::assignment($peso, '')['value_number'] === null
        && Attributes::assignment($materiale, null)['value_text'] === '';
});

check('un numero che non è un numero non passa', function () use ($peso) {
    return Attributes::assignment($peso, 'pesante')['value_number'] === null;
});

check('un elenco si legge con l\'etichetta del valore', function () use ($taglia) {
    $valori = [7 => ['id' => 7, 'label' => 'M']];

    return Attributes::format($taglia, ['attribute_value_id' => 7], $valori) === 'M';
});

check('un numero si legge con la sua unità', function () use ($peso) {
    return Attributes::format($peso, ['value_number' => 1.5]) === '1,5 g'
        && Attributes::format($peso, ['value_number' => 120]) === '120 g';
});

check('un valore che non c\'è più non inventa niente', function () use ($taglia) {
    return Attributes::format($taglia, ['attribute_value_id' => 999], []) === '';
});

summary();
