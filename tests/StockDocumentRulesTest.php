<?php
/** php tests/StockDocumentRulesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentRules as Regole;

/** La chiave del rifiuto, o null se non c'è stato. */
$rifiuto = static function (callable $fn): ?string {
    try {
        $fn();
    } catch (UserError $errore) {
        return $errore->key();
    }

    return null;
};

$riga = static fn (int $prodotto, mixed $pezzi): array => ['product_id' => $prodotto, 'quantity' => $pezzi];

check('tre tipi, con etichetta, storno e sequenza', fn () =>
    Regole::TYPES === ['receipt', 'issue', 'stocktake']
    && Regole::label('receipt') === 'Carico'
    && Regole::label('issue') === 'Scarico'
    && Regole::label('stocktake') === 'Inventario'
    && Regole::cancelLabel('receipt') === 'Annullamento del carico'
    && Regole::cancelLabel('issue') === 'Annullamento dello scarico'
    && Regole::cancelLabel('stocktake') === "Annullamento dell'inventario"
    && Regole::sequence('issue') === 'stock_issue'
    && !Regole::exists('transfer')
    && Regole::label('transfer') === ''
);

check('causale: lo scarico tiene la sua, vuota finché è bozza', fn () =>
    Regole::reason('issue', 'damaged') === 'damaged'
    && Regole::reason('issue', '  ') === ''
);

check('causale: l\'inventario usa sempre «inventory», il carico nessuna', fn () =>
    Regole::reason('stocktake', '') === 'inventory'
    && Regole::reason('stocktake', 'gift') === 'inventory'
    && Regole::reason('receipt', 'damaged') === ''
);

check('causale: una che non va con lo scarico è un rifiuto', fn () =>
    $rifiuto(fn () => Regole::reason('issue', 'initial_stock')) === 'stock_document.unknown_reason'
    && $rifiuto(fn () => Regole::reason('issue', 'inventory')) === 'stock_document.unknown_reason'
);

check('righe: i numeri all\'italiana si leggono, la casella vuota resta vuota', fn () =>
    Regole::lines([$riga(4, '2,5'), $riga(5, '1.234,5'), $riga(6, '12 pz'), $riga(7, ''), $riga(8, null), $riga(9, 3)]) === [
        ['product_id' => 4, 'quantity' => 2.5],
        ['product_id' => 5, 'quantity' => 1234.5],
        ['product_id' => 6, 'quantity' => 12.0],
        ['product_id' => 7, 'quantity' => null],
        ['product_id' => 8, 'quantity' => null],
        ['product_id' => 9, 'quantity' => 3.0],
    ]
);

check('righe: una riga senza versione si ignora', fn () =>
    Regole::lines([$riga(0, '3'), ['quantity' => '2'], $riga(4, '1')]) === [['product_id' => 4, 'quantity' => 1.0]]
);

check('righe: un numero che non si legge o negativo è un rifiuto con la riga', function () use ($rifiuto, $riga) {
    foreach (['abc', '-3', '--3', [1]] as $brutto) {
        if ($rifiuto(fn () => Regole::lines([$riga(4, '1'), $riga(5, $brutto)])) !== 'stock_document.invalid_quantity') {
            return false;
        }
    }

    try {
        Regole::lines([$riga(4, '1'), $riga(5, 'abc')]);
    } catch (UserError $errore) {
        return str_contains($errore->getMessage(), '2');
    }

    return false;
});

check('righe: una quantità oltre DECIMAL(10,3) o infinita è un rifiuto con la riga', function () use ($rifiuto, $riga) {
    foreach (['99999999', '1e400', '9999999.9999'] as $troppo) {
        if ($rifiuto(fn () => Regole::lines([$riga(4, '1'), $riga(5, $troppo)])) !== 'stock_document.invalid_quantity') {
            return false;
        }
    }

    try {
        Regole::lines([$riga(4, '1'), $riga(5, '99999999')]);
    } catch (UserError $errore) {
        return str_contains($errore->getMessage(), '2')
            && Regole::lines([$riga(4, '9999999.999')]) === [['product_id' => 4, 'quantity' => 9999999.999]];
    }

    return false;
});

check('righe: la stessa versione due volte è un rifiuto', fn () =>
    $rifiuto(fn () => Regole::lines([$riga(4, '1'), $riga(5, '1'), $riga(4, '2')])) === 'stock_document.duplicate_product'
);

check('righe: 500 passano, 501 no', function () use ($rifiuto, $riga) {
    $righe = array_map(static fn (int $i): array => $riga($i, '1'), range(1, 500));

    return count(Regole::lines($righe)) === 500
        && $rifiuto(fn () => Regole::lines([...$righe, $riga(501, '1')])) === 'stock_document.too_many_rows';
});

check('righe: oltre 500 si rifiuta prima di leggerle', fn () =>
    $rifiuto(fn () => Regole::lines(array_fill(0, 501, ['product_id' => 'abc', 'quantity' => '1']))) === 'stock_document.too_many_rows'
);

check('righe: un product_id che non è un intero positivo è un rifiuto', fn () =>
    $rifiuto(fn () => Regole::lines([['product_id' => '12abc', 'quantity' => '1']])) === 'stock_document.invalid_lines'
    && $rifiuto(fn () => Regole::lines([['product_id' => '-3', 'quantity' => '1']])) === 'stock_document.invalid_lines'
    && $rifiuto(fn () => Regole::lines([['product_id' => -3, 'quantity' => '1']])) === 'stock_document.invalid_lines'
    && $rifiuto(fn () => Regole::lines([['product_id' => [4], 'quantity' => '1']])) === 'stock_document.invalid_lines'
    && $rifiuto(fn () => Regole::lines(['4'])) === 'stock_document.invalid_lines'
);

check('righe: product_id vuoto o zero si salta, le cifre in testo valgono', fn () =>
    Regole::lines([
        ['product_id' => '', 'quantity' => ''],
        ['product_id' => 0, 'quantity' => '1'],
        ['product_id' => '0', 'quantity' => '1'],
        ['product_id' => null, 'quantity' => '1'],
        ['product_id' => ' 7 ', 'quantity' => '2'],
        ['product_id' => 9, 'quantity' => ''],
    ]) === [['product_id' => 7, 'quantity' => 2.0], ['product_id' => 9, 'quantity' => null]]
);

check('conferma: lo scarico chiede la causale', fn () =>
    $rifiuto(fn () => Regole::assertConfirmable('issue', '', [$riga(4, 1.0)])) === 'stock_document.reason_required'
    && $rifiuto(fn () => Regole::assertConfirmable('issue', 'gift', [$riga(4, 1.0)])) === null
);

check('conferma: almeno una riga', fn () =>
    $rifiuto(fn () => Regole::assertConfirmable('receipt', '', [])) === 'stock_document.no_rows'
    && $rifiuto(fn () => Regole::assertConfirmable('stocktake', 'inventory', [])) === 'stock_document.no_rows'
);

check('conferma: carico e scarico vogliono pezzi maggiori di zero su ogni riga', fn () =>
    $rifiuto(fn () => Regole::assertConfirmable('receipt', '', [$riga(4, 2.0), $riga(5, 0.0)])) === 'stock_document.quantity_required'
    && $rifiuto(fn () => Regole::assertConfirmable('receipt', '', [$riga(4, 2.0), $riga(5, null)])) === 'stock_document.quantity_required'
    && $rifiuto(fn () => Regole::assertConfirmable('receipt', '', [$riga(4, 2.0)])) === null
);

check('conferma: l\'inventario vuole almeno una riga contata, anche a zero', fn () =>
    $rifiuto(fn () => Regole::assertConfirmable('stocktake', 'inventory', [$riga(4, null), $riga(5, null)])) === 'stock_document.nothing_counted'
    && $rifiuto(fn () => Regole::assertConfirmable('stocktake', 'inventory', [$riga(4, null), $riga(5, 0.0)])) === null
);

check('movimento: il carico aggiunge, lo scarico toglie con la sua causale', fn () =>
    Regole::movement('receipt', '', ['quantity' => 5.0]) === ['type' => 'purchase', 'reason' => '', 'quantity' => 5.0]
    && Regole::movement('issue', 'damaged', ['quantity' => 2.5]) === ['type' => 'adjustment', 'reason' => 'damaged', 'quantity' => -2.5]
);

check('movimento: l\'inventario muove contati − attesi, in tutte e due le direzioni', fn () =>
    Regole::movement('stocktake', 'inventory', ['quantity' => 7.0, 'expected_quantity' => 10.0]) === ['type' => 'adjustment', 'reason' => 'inventory', 'quantity' => -3.0]
    && Regole::movement('stocktake', 'inventory', ['quantity' => 12.5, 'expected_quantity' => 10.0]) === ['type' => 'adjustment', 'reason' => 'inventory', 'quantity' => 2.5]
);

check('movimento: riga non contata o senza differenza non muove niente', fn () =>
    Regole::movement('stocktake', 'inventory', ['quantity' => null, 'expected_quantity' => null]) === null
    && Regole::movement('stocktake', 'inventory', ['quantity' => 10.0, 'expected_quantity' => 10.0]) === null
    && Regole::movement('stocktake', 'inventory', ['quantity' => 0.1 + 0.2, 'expected_quantity' => 0.3]) === null
);

check('storno: stesso tipo e causale, segno opposto', fn () =>
    Regole::reversal(['type' => 'purchase', 'reason' => '', 'quantity' => '5.000']) === ['type' => 'purchase', 'reason' => '', 'quantity' => -5.0]
    && Regole::reversal(['type' => 'adjustment', 'reason' => 'inventory', 'quantity' => '-3.000']) === ['type' => 'adjustment', 'reason' => 'inventory', 'quantity' => 3.0]
    && Regole::reversal(['type' => 'adjustment', 'reason' => null, 'quantity' => '-1']) === ['type' => 'adjustment', 'reason' => '', 'quantity' => 1.0]
);

check('atteso: si legge quando il numero contato è nuovo o cambia', fn () =>
    Regole::expected(null, 5.0, false, 8.0) === 8.0
    && Regole::expected(['quantity' => null, 'expected_quantity' => null], 5.0, false, 8.0) === 8.0
    && Regole::expected(['quantity' => 4.0, 'expected_quantity' => 9.0], 5.0, false, 8.0) === 8.0
);

check('atteso: lo stesso numero tiene l\'atteso di prima', fn () =>
    Regole::expected(['quantity' => 5.0, 'expected_quantity' => 9.0], 5.0, false, 8.0) === 9.0
);

check('atteso: si rilegge quando cambia la sede', fn () =>
    Regole::expected(['quantity' => 5.0, 'expected_quantity' => 9.0], 5.0, true, 8.0) === 8.0
);

check('atteso: una riga non contata non ha atteso', fn () =>
    Regole::expected(['quantity' => 5.0, 'expected_quantity' => 9.0], null, false, 8.0) === null
);

summary();
