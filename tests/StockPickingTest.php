<?php
/** php tests/StockPickingTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\StockPicking;

$secchio = static fn (int $lotto, int $fornitore, float $pezzi, ?string $scadenza = null, ?float $costo = null): array =>
    ['batch_id' => $lotto, 'supplier_id' => $fornitore, 'quantity' => $pezzi, 'expiry_date' => $scadenza, 'cost' => $costo];
$parti = static fn (array $esito): array => array_map(
    static fn (array $p): string => $p['batch_id'].'/'.$p['supplier_id'].':'.$p['quantity'],
    $esito['parts']
);
$tutto = ['today' => '2026-10-12', 'use_batches' => true, 'use_suppliers' => true];

check('inerzia: con il solo secchio 0 una parte sola, come prima di G3', fn () =>
    StockPicking::split([$secchio(0, 0, 10)], 3) === ['parts' => [['batch_id' => 0, 'supplier_id' => 0, 'quantity' => 3.0]], 'missing' => 0.0]
    && StockPicking::split([$secchio(0, 0, 10)], 3, $tutto) === ['parts' => [['batch_id' => 0, 'supplier_id' => 0, 'quantity' => 3.0]], 'missing' => 0.0]
);

check('inerzia: senza merce, o sotto zero, niente parti e tutto in missing', fn () =>
    StockPicking::split([], 2) === ['parts' => [], 'missing' => 2.0]
    && StockPicking::split([$secchio(0, 0, -3)], 2) === ['parts' => [], 'missing' => 2.0]
    && StockPicking::split([$secchio(0, 0, 1.5)], 2) === ['parts' => [['batch_id' => 0, 'supplier_id' => 0, 'quantity' => 1.5]], 'missing' => 0.5]
);

check('una quantità nulla o negativa non prende niente', fn () =>
    StockPicking::split([$secchio(0, 0, 5)], 0) === ['parts' => [], 'missing' => 0.0]
    && StockPicking::split([$secchio(0, 0, 5)], -1) === ['parts' => [], 'missing' => 0.0]
);

check('il lotto 0 esce prima, poi i lotti dalla scadenza più vicina, quelli senza scadenza in fondo', fn () =>
    $parti(StockPicking::split([
        $secchio(7, 0, 2, null),
        $secchio(5, 0, 2, '2027-05-01'),
        $secchio(6, 0, 2, '2026-12-01'),
        $secchio(0, 0, 2),
    ], 7, $tutto)) === ['0/0:2', '6/0:2', '5/0:2', '7/0:1']
);

check('dentro lo stesso lotto prima il fornitore 0, poi il costo più basso, i costi sconosciuti in fondo', fn () =>
    $parti(StockPicking::split([
        $secchio(0, 9, 1, null, null),
        $secchio(0, 3, 1, null, 4.5),
        $secchio(0, 4, 1, null, 2.0),
        $secchio(0, 0, 1),
    ], 4, $tutto)) === ['0/0:1', '0/4:1', '0/3:1', '0/9:1']
);

check('con lotti e acquisti spenti scadenze e costi non contano: ordine degli id', fn () =>
    $parti(StockPicking::split([
        $secchio(6, 3, 1, '2026-01-01', 9.0),
        $secchio(5, 4, 1, '2027-01-01', 1.0),
    ], 2, ['today' => '2026-10-12'])) === ['5/4:1', '6/3:1']
);

check('i lotti scaduti restano fuori; il giorno della scadenza si vende ancora', fn () =>
    $parti(StockPicking::split([
        $secchio(5, 0, 3, '2026-10-11'),
        $secchio(6, 0, 3, '2026-10-12'),
    ], 5, $tutto)) === ['6/0:3']
    && StockPicking::split([$secchio(5, 0, 3, '2026-10-11')], 2, $tutto)['missing'] === 2.0
);

check('gli scaduti entrano se li chiede chi scarica, o se sceglie quel lotto', fn () =>
    $parti(StockPicking::split([$secchio(5, 0, 3, '2026-10-01'), $secchio(6, 0, 3, '2027-01-01')], 4, $tutto + ['include_expired' => true])) === ['5/0:3', '6/0:1']
    && $parti(StockPicking::split([$secchio(5, 0, 3, '2026-10-01'), $secchio(6, 0, 3, '2027-01-01')], 2, $tutto + ['batch_id' => 5])) === ['5/0:2']
);

check('lotto e fornitore scelti a mano restringono ai loro secchi', fn () =>
    $parti(StockPicking::split([$secchio(0, 3, 5, null, 1.0), $secchio(0, 4, 5, null, 2.0)], 6, $tutto + ['supplier_id' => 4])) === ['0/4:5']
    && StockPicking::split([$secchio(0, 3, 5, null, 1.0), $secchio(0, 4, 5, null, 2.0)], 6, $tutto + ['supplier_id' => 4])['missing'] === 1.0
);

check('i decimali restano a tre cifre', fn () =>
    $parti(StockPicking::split([$secchio(0, 0, 0.3), $secchio(5, 0, 1.0, '2027-01-01')], 0.7, $tutto)) === ['0/0:0.3', '5/0:0.4']
);

check('isExpired: vuota e zeri di MySQL non scadono mai', fn () =>
    StockPicking::isExpired(null, '2026-10-12') === false
    && StockPicking::isExpired('0000-00-00', '2026-10-12') === false
    && StockPicking::isExpired('2026-10-11', '2026-10-12') === true
    && StockPicking::isExpired('2026-10-12', '2026-10-12') === false
);

summary();
