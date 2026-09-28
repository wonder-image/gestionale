<?php
/** php tests/LocationStockTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\LocationStock;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;

// Le giacenze scritte nelle righe per sede diventano movimenti: uno per
// sede cambiata, con la differenza fra quanto c'è e quanto è stato scritto.
check('una sede cambiata dà una rettifica con la differenza', fn () =>
    LocationStock::movements([1 => 10.0, 2 => 3.0], [1 => 12.0, 2 => 3.0], false) === [[
        'location_id' => 1,
        'quantity' => 2.0,
        'reason' => Reasons::DEFAULT,
        'note' => 'Rettifica dalla scheda dell\'articolo',
    ]]
);

check('una sede senza giacenza scritta prende la sua', fn () =>
    LocationStock::movements([], [2 => 4.5], false) === [[
        'location_id' => 2,
        'quantity' => 4.5,
        'reason' => Reasons::DEFAULT,
        'note' => 'Rettifica dalla scheda dell\'articolo',
    ]]
);

check('scendere sotto la giacenza di adesso dà una differenza negativa', fn () =>
    LocationStock::movements([1 => 10.0], [1 => 7.0], false)[0]['quantity'] === -3.0
);

check('niente cambia, niente movimenti', fn () =>
    LocationStock::movements([1 => 10.0, 2 => 3.0], [1 => 10.0, 2 => 3.0], false) === []
    && LocationStock::movements([], [], false) === []
);

check('un articolo nuovo carica la giacenza iniziale, solo dove è positiva', fn () =>
    LocationStock::movements([], [1 => 5.0, 2 => 0.0, 3 => -1.0], true) === [[
        'location_id' => 1,
        'quantity' => 5.0,
        'reason' => 'initial_stock',
        'note' => 'Giacenza iniziale, dalla scheda dell\'articolo',
    ]]
);

check('le sedi si scrivono nell\'ordine delle righe', fn () =>
    array_column(LocationStock::movements([], [3 => 1.0, 1 => 2.0], false), 'location_id') === [3, 1]
);

check('una giacenza sotto zero scritta a mano si riconosce, sede per sede', fn () =>
    LocationStock::negativeWritten([1 => 5.0], [1 => -3.0]) === true
    && LocationStock::negativeWritten([], [2 => -1.0]) === true
    && LocationStock::negativeWritten([1 => 5.0], [1 => 0.0, 2 => 4.0]) === false
    && LocationStock::negativeWritten([], []) === false
);

check('una sede già sotto zero per gli arretrati, riscritta com\'era, passa', fn () =>
    LocationStock::negativeWritten([1 => -2.0, 2 => 3.0], [1 => -2.0, 2 => 4.0]) === false
    && LocationStock::negativeWritten([1 => -2.0], [1 => -3.0]) === true
);

summary();
