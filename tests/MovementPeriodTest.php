<?php
/** php tests/MovementPeriodTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\MovementPeriod;

$adesso = new DateTimeImmutable('2025-10-15 10:30:00');

check('i periodi offerti sono cinque, con la loro etichetta', fn () =>
    array_keys(MovementPeriod::OPTIONS) === ['oggi', '7-giorni', 'questo-mese', 'mese-scorso', 'quest-anno']
    && MovementPeriod::OPTIONS['oggi'] === 'Oggi'
    && MovementPeriod::filterOptions() === ['' => 'Sempre'] + MovementPeriod::OPTIONS
);

check('oggi va da mezzanotte alle 23:59:59 di quel giorno', fn () =>
    MovementPeriod::range('oggi', $adesso) === ['from' => '2025-10-15 00:00:00', 'to' => '2025-10-15 23:59:59']
);

check('7 giorni parte da sei giorni prima, a mezzanotte, e arriva a stasera', fn () =>
    MovementPeriod::range('7-giorni', $adesso) === ['from' => '2025-10-09 00:00:00', 'to' => '2025-10-15 23:59:59']
);

check('questo mese va dal 1° all\'ultimo giorno', fn () =>
    MovementPeriod::range('questo-mese', $adesso) === ['from' => '2025-10-01 00:00:00', 'to' => '2025-10-31 23:59:59']
);

check('mese scorso va dal 1° al 30 settembre', fn () =>
    MovementPeriod::range('mese-scorso', $adesso) === ['from' => '2025-09-01 00:00:00', 'to' => '2025-09-30 23:59:59']
);

check('quest\'anno va dal 1° gennaio al 31 dicembre', fn () =>
    MovementPeriod::range('quest-anno', $adesso) === ['from' => '2025-01-01 00:00:00', 'to' => '2025-12-31 23:59:59']
);

check('il 1° del mese a mezzanotte «questo mese» è già il nuovo mese e «mese scorso» il precedente', function () {
    $primo = new DateTimeImmutable('2025-10-01 00:00:00');

    return MovementPeriod::range('questo-mese', $primo)['from'] === '2025-10-01 00:00:00'
        && MovementPeriod::range('mese-scorso', $primo) === ['from' => '2025-09-01 00:00:00', 'to' => '2025-09-30 23:59:59'];
});

check('il 31 dicembre alle 23:59:59 l\'anno e il mese sono ancora quelli', function () {
    $fine = new DateTimeImmutable('2025-12-31 23:59:59');

    return MovementPeriod::range('quest-anno', $fine) === ['from' => '2025-01-01 00:00:00', 'to' => '2025-12-31 23:59:59']
        && MovementPeriod::range('questo-mese', $fine) === ['from' => '2025-12-01 00:00:00', 'to' => '2025-12-31 23:59:59'];
});

check('a gennaio «mese scorso» è dicembre dell\'anno prima', fn () =>
    MovementPeriod::range('mese-scorso', new DateTimeImmutable('2026-01-10 08:00:00'))
        === ['from' => '2025-12-01 00:00:00', 'to' => '2025-12-31 23:59:59']
);

check('a marzo di un anno bisestile «mese scorso» arriva al 29 febbraio', fn () =>
    MovementPeriod::range('mese-scorso', new DateTimeImmutable('2028-03-05 12:00:00'))
        === ['from' => '2028-02-01 00:00:00', 'to' => '2028-02-29 23:59:59']
);

check('«7 giorni» a cavallo del mese pesca nel mese prima', fn () =>
    MovementPeriod::range('7-giorni', new DateTimeImmutable('2025-11-03 09:00:00'))
        === ['from' => '2025-10-28 00:00:00', 'to' => '2025-11-03 23:59:59']
);

check('una chiave sconosciuta o vuota non dà un intervallo', fn () =>
    MovementPeriod::range('boh', $adesso) === null
    && MovementPeriod::range('', $adesso) === null
);

check('la condizione SQL usa gli estremi, inclusi, sulla colonna data', fn () =>
    MovementPeriod::sql('oggi', 'creation', $adesso)
        === "`creation` >= '2025-10-15 00:00:00' AND `creation` <= '2025-10-15 23:59:59'"
);

check('nessun periodo, nessuna condizione', fn () =>
    MovementPeriod::sql('', 'creation', $adesso) === '' && MovementPeriod::sql('boh', 'creation', $adesso) === ''
);

check('una chiave scritta per fare danni non entra mai nell\'SQL', function () use ($adesso) {
    $sql = MovementPeriod::sql("oggi'; DROP TABLE gst_stock_movements; --", 'creation', $adesso);

    return $sql === '';
});

check('il nome della colonna è protetto', function () use ($adesso) {
    $sql = MovementPeriod::sql('oggi', 'ordered_at', $adesso);

    return str_starts_with($sql, '`ordered_at` >=');
});

summary();
