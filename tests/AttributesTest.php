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

check('elenco, colore, fantasia e icona hanno dei valori', fn () =>
    Attributes::usesValues('select')
    && Attributes::usesValues('color')
    && Attributes::usesValues('pattern')
    && Attributes::usesValues('icon')
    && !Attributes::usesValues('text')
    && !Attributes::usesValues('number')
);

check('solo testo e numero hanno un\'unità di misura', fn () =>
    Attributes::usesUnit('number')
    && Attributes::usesUnit('text')
    && !Attributes::usesUnit('select')
    && !Attributes::usesUnit('color')
    && !Attributes::usesUnit('pattern')
    && !Attributes::usesUnit('icon')
    && !Attributes::usesUnit('')
);

check('ogni tipo o ha dei valori o ha un\'unità, mai tutti e due', function () {
    foreach (array_keys(Attributes::types()) as $tipo) {
        if (Attributes::usesValues($tipo) === Attributes::usesUnit($tipo)) {
            return false;
        }
    }

    return true;
});

check('una fantasia si sceglie dai valori, come un elenco', function () {
    $fantasia = ['id' => 5, 'name' => 'Fantasia', 'type' => 'pattern', 'unit' => ''];

    return Attributes::assignment($fantasia, '12') === ['attribute_value_id' => 12, 'value_text' => '', 'value_number' => null]
        && Attributes::format($fantasia, ['attribute_value_id' => 12], [12 => ['label' => 'Scozzese']]) === 'Scozzese';
});

check('gli usi si chiamano come li capisce un negoziante', fn () =>
    Attributes::levels()['model'] === 'Scheda tecnica dell\'articolo'
    && Attributes::levels()['variant'] === 'Opzione con foto proprie'
    && Attributes::levels()['product'] === 'Opzione da scegliere'
);

check('la parola "carrello" non si legge: gli attributi li scrive chi vende', fn () =>
    array_filter(
        [...Attributes::LEVELS, ...Attributes::FREE_LEVELS],
        static fn (string $label): bool => stripos($label, 'carrello') !== false
    ) === []
);

check('testo e numero non hanno l\'uso con foto proprie', fn () =>
    array_keys(Attributes::levelsFor('text')) === ['model', 'product']
    && array_keys(Attributes::levelsFor('number')) === ['model', 'product']
    && Attributes::levelsFor('text')['product'] === 'Scheda tecnica di ogni opzione'
    && array_keys(Attributes::levelsFor('color')) === ['model', 'variant', 'product']
    && !Attributes::acceptsLevel('text', 'variant')
    && Attributes::acceptsLevel('pattern', 'variant')
);

check('nella tabella l\'uso si legge con le parole del tipo', fn () =>
    Attributes::levelLabel('number', 'product') === 'Scheda tecnica di ogni opzione'
    && Attributes::levelLabel('select', 'product') === 'Opzione da scegliere'
    && Attributes::levelLabel('text', 'variant') === 'Opzione con foto proprie'
    && Attributes::levelLabel('text', 'boh') === ''
);

check('la parola "versione" non si legge da nessuna parte', fn () =>
    array_filter(
        Attributes::levels(),
        static fn (string $label): bool => stripos($label, 'versio') !== false
            || stripos($label, 'variant') !== false
    ) === []
);

check('le chiavi salvate non cambiano', fn () =>
    array_keys(Attributes::levels()) === ['model', 'variant', 'product']
);

check('due livelli su tre creano opzioni in vendita', fn () =>
    Attributes::createsVersions('variant') === true
    && Attributes::createsVersions('product') === true
    && Attributes::createsVersions('model') === false
    && Attributes::createsVersions('') === false
);

check('i tipi hanno un nome da leggere', fn () =>
    Attributes::types()['select'] === 'Elenco'
    && Attributes::types()['pattern'] === 'Fantasia'
    && Attributes::types()['icon'] === 'Icona'
    && array_keys(Attributes::types()) === ['select', 'color', 'pattern', 'icon', 'text', 'number']
);

check('un colore si mostra con il suo pallino', fn () =>
    Attributes::valueVisual('color', ['color' => ' #1d4ed8 ']) === ['color' => '#1d4ed8']
    && Attributes::valueVisual('color', ['color' => '']) === []
);

check('una fantasia senza immagine non mostra niente', fn () =>
    Attributes::valueVisual('pattern', ['color' => '#000000']) === []
    && Attributes::valueVisual('pattern', [], '/uploads/scozzese.jpg') === ['image' => '/uploads/scozzese.jpg']
);

check('su un\'icona l\'immagine vince sul segno della raccolta', fn () =>
    Attributes::valueVisual('icon', ['icon' => 'bi-star'], '/uploads/stella.png') === ['image' => '/uploads/stella.png']
    && Attributes::valueVisual('icon', ['icon' => 'bi-star']) === ['icon' => 'bi-star']
    && Attributes::valueVisual('icon', ['icon' => ' ']) === []
);

check('un elenco che è stato un colore non mostra pallini', fn () =>
    Attributes::valueVisual('select', ['color' => '#ff0000', 'icon' => 'bi-star'], '/uploads/x.jpg') === []
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
