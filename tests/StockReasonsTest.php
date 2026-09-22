<?php
/** php tests/StockReasonsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\Availability;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;

check('le causali della spec ci sono tutte', fn () =>
    array_keys(Reasons::all()) === [
        'inventory', 'initial_stock', 'damaged', 'expired',
        'gift', 'internal_use', 'other',
    ]
);

check('la causale predefinita è l\'inventario, la prima dell\'elenco', fn () =>
    Reasons::DEFAULT === 'inventory'
    && array_key_first(Reasons::all()) === 'inventory'
);

check('ogni causale ha un\'etichetta che una persona capisce', function () {
    foreach (Reasons::all() as $chiave => $etichetta) {
        if (trim($etichetta) === '' || $etichetta === $chiave) {
            return false;
        }
    }

    return true;
});

check('una causale inventata non esiste', fn () =>
    Reasons::exists('inventory') === true
    && Reasons::exists('marziana') === false
    && Reasons::label('marziana') === ''
);

check('senza prenotazioni il disponibile è la giacenza', fn () =>
    Availability::of(10.0, []) === 10.0
);

check('una prenotazione attiva toglie pezzi al disponibile', fn () =>
    Availability::of(10.0, [
        ['quantity' => '3.000', 'expires_at' => '2026-12-31 23:59:59', 'released_at' => ''],
    ], '2026-09-22 10:00:00') === 7.0
);

check('una prenotazione scaduta non conta più', fn () =>
    Availability::of(10.0, [
        ['quantity' => '3.000', 'expires_at' => '2026-09-01 00:00:00', 'released_at' => ''],
    ], '2026-09-22 10:00:00') === 10.0
);

check('una prenotazione rilasciata non conta più', fn () =>
    Availability::of(10.0, [
        ['quantity' => '3.000', 'expires_at' => '', 'released_at' => '2026-09-20 10:00:00'],
    ], '2026-09-22 10:00:00') === 10.0
);

check('una prenotazione senza scadenza resta attiva', fn () =>
    Availability::reserved([
        ['quantity' => '2.500', 'expires_at' => '', 'released_at' => ''],
    ], '2026-09-22 10:00:00') === 2.5
);

check('le date vuote del database non ingannano nessuno', fn () =>
    // MySQL scrive gli zero, il framework a volte la stringa vuota: valgono
    // tutte e due "mai".
    Availability::reserved([
        ['quantity' => '1.000', 'expires_at' => '0000-00-00 00:00:00', 'released_at' => null],
    ], '2026-09-22 10:00:00') === 1.0
);

check('il disponibile può andare sotto zero e lo dice', fn () =>
    Availability::of(1.0, [
        ['quantity' => '3.000', 'expires_at' => '', 'released_at' => ''],
    ], '2026-09-22 10:00:00') === -2.0
);

summary();
