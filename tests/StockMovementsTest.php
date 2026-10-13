<?php
/** php tests/StockMovementsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\StockMovements;

$mov = static fn (string $tipo, int $lotto, int $fornitore, float $pezzi): array =>
    ['type' => $tipo, 'batch_id' => $lotto, 'supplier_id' => $fornitore, 'quantity' => $pezzi];
$testo = static fn (array $parti): array => array_map(
    static fn (array $p): string => $p['batch_id'].'/'.$p['supplier_id'].':'.$p['quantity'],
    $parti
);

check('il netto per secchio: vendite meno annulli e resi, nell\'ordine di uscita', fn () =>
    $testo(StockMovements::net([
        $mov('sale', 5, 0, -2),
        $mov('sale', 6, 0, -3),
        $mov('return', 6, 0, 1),
        $mov('sale_cancel', 5, 0, 2),
        $mov('adjustment', 6, 0, -9),
    ])) === ['6/0:2']
);

check('il rientro parte dall\'ultimo secchio uscito, mai oltre quanto ha dato', fn () =>
    $testo(StockMovements::refill([
        ['batch_id' => 5, 'supplier_id' => 0, 'quantity' => 2.0],
        ['batch_id' => 6, 'supplier_id' => 0, 'quantity' => 3.0],
    ], 4)) === ['6/0:3', '5/0:1']
);

check('inerzia: senza movimenti della riga tutto rientra nel secchio 0, con un movimento solo', fn () =>
    $testo(StockMovements::refill([], 3)) === ['0/0:3']
    && $testo(StockMovements::refill([['batch_id' => 0, 'supplier_id' => 0, 'quantity' => 2.0]], 3)) === ['0/0:3']
);

check('quello che avanza dopo i secchi della riga va nel secchio 0', fn () =>
    $testo(StockMovements::refill([['batch_id' => 5, 'supplier_id' => 0, 'quantity' => 1.0]], 3)) === ['5/0:1', '0/0:2']
);

check('addToZero aggiunge alla parte del secchio 0 se c\'è, altrimenti in fondo', fn () =>
    $testo(StockMovements::addToZero([['batch_id' => 0, 'supplier_id' => 0, 'quantity' => 1.0]], 3)) === ['0/0:4']
    && $testo(StockMovements::addToZero([['batch_id' => 5, 'supplier_id' => 0, 'quantity' => 1.0]], 3)) === ['5/0:1', '0/0:3']
    && $testo(StockMovements::addToZero([], 2)) === ['0/0:2']
);

check('senza riga d\'ordine netOut non legge niente', fn () =>
    StockMovements::netOut(0) === []
);

summary();
