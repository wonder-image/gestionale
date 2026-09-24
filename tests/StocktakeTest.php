<?php
/** php tests/StocktakeTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\Stocktake;

check('una casella vuota non è uno zero', fn () =>
    Stocktake::quantity('') === null
    && Stocktake::quantity(null) === null
    && Stocktake::quantity('   ') === null
);

check('la virgola è un decimale, come la scrivono tutti', fn () =>
    Stocktake::quantity('2,5') === 2.5
    && Stocktake::quantity('2.5') === 2.5
    && Stocktake::quantity('1.234,5') === 1234.5
);

check('l\'unità in coda non rompe la quantità', fn () =>
    Stocktake::quantity('12 pz') === 12.0
    && Stocktake::quantity('2,5 kg') === 2.5
    && Stocktake::quantity('1.234,5 kg') === 1234.5
    && Stocktake::quantity("2,500\u{a0}kg") === 2.5
);

check('quello che non è un numero non diventa zero', fn () =>
    Stocktake::quantity('abc') === null
    && Stocktake::quantity('--3') === null
    && Stocktake::quantity('pz') === null
);

check('lo zero scritto è uno zero vero', fn () =>
    Stocktake::quantity('0') === 0.0
);

check('cambia solo quello che è stato cambiato', fn () =>
    Stocktake::changes([1 => 10.0, 2 => 5.0], [1 => 10.0, 2 => 5.0]) === []
);

check('una riga scritta diversa diventa una differenza', function () {
    $cambi = Stocktake::changes([1 => 10.0, 2 => 5.0], [1 => 12.0]);

    return array_keys($cambi) === [1]
        && $cambi[1] === ['delta' => 2.0, 'before' => 10.0, 'after' => 12.0];
});

check('una riga non spedita resta com\'è', fn () =>
    Stocktake::changes([1 => 10.0, 2 => 5.0], [2 => 5.0]) === []
);

check('una riga senza giacenza parte da zero', function () {
    $cambi = Stocktake::changes([], [7 => 3.0]);

    return $cambi[7] === ['delta' => 3.0, 'before' => 0.0, 'after' => 3.0];
});

check('scrivere zero dove c\'era qualcosa svuota davvero', function () {
    $cambi = Stocktake::changes([1 => 4.0], [1 => 0.0]);

    return $cambi[1]['delta'] === -4.0 && $cambi[1]['after'] === 0.0;
});

check('i decimali non lasciano briciole', function () {
    $cambi = Stocktake::changes([1 => 0.1], [1 => 0.3]);

    return $cambi[1]['delta'] === 0.2;
});

summary();
