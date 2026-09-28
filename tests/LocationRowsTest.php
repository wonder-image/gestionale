<?php
/** php tests/LocationRowsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\LocationRows;

$sedi = [['id' => 1, 'label' => 'Milano'], ['id' => 2, 'label' => 'Roma'], ['id' => 3, 'label' => 'Bari']];
$livelli = [
    2 => ['quantity' => 3.0, 'reserved' => 0.0, 'available' => 3.0],
    9 => ['quantity' => 4.0, 'reserved' => 0.0, 'available' => 4.0],
];
$soglie = [1 => 5.0, 9 => 2.0];

check('una riga per ogni sede con pezzi o soglia, nell\'ordine delle sedi', fn () =>
    LocationRows::compose($sedi, $livelli, $soglie) === [
        ['location_id' => 1, 'stock' => 0.0, 'min_stock' => 5.0],
        ['location_id' => 2, 'stock' => 3.0, 'min_stock' => 0.0],
    ]
);

check('una sede fuori dall\'elenco non fa riga, anche con pezzi', fn () =>
    array_column(LocationRows::compose($sedi, $livelli, $soglie), 'location_id') === [1, 2]
);

check('senza pezzi né soglie non c\'è nessuna riga', fn () =>
    LocationRows::compose($sedi, [], []) === []
    && LocationRows::compose($sedi, [3 => ['quantity' => 0.0, 'reserved' => 0.0, 'available' => 0.0]], [3 => 0.0]) === []
);

check('il JSON della griglia diventa righe, o niente', fn () =>
    LocationRows::fromForm('[{"location_id":"2","stock":"3","min_stock":"1"}]') === [['location_id' => '2', 'stock' => '3', 'min_stock' => '1']]
    && LocationRows::fromForm('[]') === []
    && LocationRows::fromForm('') === null
    && LocationRows::fromForm(null) === null
    && LocationRows::fromForm('{"location_id":2}') === null
    && LocationRows::fromForm('[1,2]') === null
    && LocationRows::fromForm('non json') === null
    && LocationRows::fromForm([['location_id' => 2]]) === [['location_id' => 2]]
    && LocationRows::fromForm(['location_id' => 2]) === null
);

check('le righe scritte si leggono con la virgola e la casella vuota resta vuota', fn () =>
    LocationRows::normalize([
        ['location_id' => '2', 'stock' => '3', 'min_stock' => '1,5'],
        ['location_id' => '', 'stock' => '9', 'min_stock' => '9'],
        ['location_id' => '1', 'stock' => '', 'min_stock' => ''],
        ['location_id' => '3', 'stock' => '0', 'min_stock' => '0'],
    ], $sedi) === [
        ['location_id' => 2, 'stock' => 3.0, 'min_stock' => 1.5],
        ['location_id' => 1, 'stock' => null, 'min_stock' => 0.0],
        ['location_id' => 3, 'stock' => 0.0, 'min_stock' => 0.0],
    ]
);

$rifiuta = static function (array $righe, string $chiave) use ($sedi): bool {
    try {
        LocationRows::normalize($righe, $sedi);
    } catch (UserError $errore) {
        return $errore->key() === $chiave && $errore->getMessage() !== '' && $errore->getMessage() !== $chiave;
    }

    return false;
};

check('la stessa sede due volte è un errore che nomina la sede', fn () =>
    $rifiuta([['location_id' => '2', 'stock' => '1'], ['location_id' => 2, 'stock' => '2']], 'stock.location_duplicate')
);

check('una sede fuori dall\'elenco è un errore', fn () =>
    $rifiuta([['location_id' => '7', 'stock' => '1']], 'stock.location_unknown')
);

check('una scorta minima sotto zero o non numerica è un errore', fn () =>
    $rifiuta([['location_id' => '1', 'min_stock' => '-1']], 'product.min_stock_invalid')
    && $rifiuta([['location_id' => '1', 'min_stock' => 'tre']], 'product.min_stock_invalid')
    && $rifiuta([['location_id' => '1', 'min_stock' => ['1']]], 'product.min_stock_invalid')
);

check('le soglie per sede si tirano fuori dalle righe', fn () =>
    LocationRows::thresholds([
        ['location_id' => 2, 'stock' => 3.0, 'min_stock' => 1.5],
        ['location_id' => 1, 'stock' => null, 'min_stock' => 0.0],
    ]) === [2 => 1.5, 1 => 0.0]
);

check('le quantità scritte si tirano fuori dalle righe, senza le caselle vuote', fn () =>
    LocationRows::quantities([
        ['location_id' => 2, 'stock' => 3.0, 'min_stock' => 1.5],
        ['location_id' => 1, 'stock' => null, 'min_stock' => 0.0],
        ['location_id' => 3, 'stock' => 0.0, 'min_stock' => 0.0],
    ]) === [2 => 3.0, 3 => 0.0]
);

check('il riassunto della griglia: «Milano 12 · Roma 3», o niente', fn () =>
    LocationRows::summary($sedi, [1 => ['quantity' => 12.0], 2 => ['quantity' => 3.0]]) === 'Milano 12 · Roma 3'
    && LocationRows::summary($sedi, [2 => ['quantity' => 2.5]]) === 'Roma 2,5'
    && LocationRows::summary($sedi, []) === ''
);

check('il riassunto delle righe scritte: nell\'ordine delle righe, senza le caselle vuote e gli zeri', fn () =>
    LocationRows::summaryOfRows($sedi, [
        ['location_id' => 2, 'stock' => 20, 'min_stock' => ''],
        ['location_id' => '1', 'stock' => -3, 'min_stock' => ''],
        ['location_id' => 3, 'stock' => '', 'min_stock' => '4'],
    ]) === 'Roma 20 · Milano -3'
    && LocationRows::summaryOfRows($sedi, [['location_id' => 1, 'stock' => '2,5 pz'], ['location_id' => 2, 'stock' => 0]]) === 'Milano 2,5'
    && LocationRows::summaryOfRows($sedi, [['location_id' => 9, 'stock' => 4], ['location_id' => '', 'stock' => 4]]) === ''
    && LocationRows::summaryOfRows($sedi, []) === ''
);

check('le righe per sede si scrivono con l\'unità dell\'articolo, e tengono i decimali di una sede sola', fn () =>
    LocationRows::format('pz', [20.0, 3.0], [5.0])
        === ['stock' => 0, 'min_stock' => 0, 'suffix' => ' pz']
    // Una somma tonda non nasconde i decimali di una sede: arrotondarli
    // vorrebbe dire salvare un movimento che nessuno ha chiesto.
    && LocationRows::format('pz', [1.5, 1.5], [0.25])
        === ['stock' => 3, 'min_stock' => 3, 'suffix' => ' pz']
    && LocationRows::format('pz', [2.0], [0.5])
        === ['stock' => 0, 'min_stock' => 3, 'suffix' => ' pz']
    && LocationRows::format('kg', [], []) === ['stock' => 3, 'min_stock' => 3, 'suffix' => ' kg']
    && LocationRows::format('', [], []) === ['stock' => 3, 'min_stock' => 3, 'suffix' => '']
);

summary();
