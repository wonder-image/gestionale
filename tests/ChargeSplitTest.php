<?php
/** php tests/ChargeSplitTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Tax\ChargeSplit;

$riga = fn (int $tax, float $rate, float $totale, float $sconto = 0.0, string $natura = ''): array => [
    'tax_id' => $tax, 'tax_rate' => $rate, 'tax_nature' => $natura,
    'line_total' => $totale, 'order_discount_amount' => $sconto,
];

/** La somma delle quote, in centesimi. */
$somma = fn (array $quote): int => (int) round(array_sum(array_column($quote, 'amount')) * 100);

check('una sola aliquota: la spesa la prende tutta', function () use ($riga) {
    $quote = ChargeSplit::shares(9.90, ChargeSplit::groups([$riga(1, 22, 50), $riga(1, 22, 30)], true));

    return count($quote) === 1 && $quote[0]['tax_id'] === 1 && $quote[0]['amount'] === 9.90;
});

check('22% e 4%: la spesa si divide sull\'imponibile, al centesimo', function () use ($riga, $somma) {
    // Prezzi IVA esclusa: imponibili 100 e 50, la spesa da 10 fa 6,67 e 3,33.
    $quote = ChargeSplit::shares(10.00, ChargeSplit::groups([$riga(1, 22, 100), $riga(2, 4, 50)], false));

    return count($quote) === 2
        && $quote[0]['tax_id'] === 1 && $quote[0]['amount'] === 6.67 && $quote[0]['rate'] === 22.0
        && $quote[1]['tax_id'] === 2 && $quote[1]['amount'] === 3.33 && $quote[1]['rate'] === 4.0
        && $somma($quote) === 1000;
});

check('con i prezzi IVA inclusa conta l\'imponibile scorporato', function () use ($riga) {
    // 122 al 22% e 104 al 4% sono 100 e 100 di imponibile: metà e metà.
    $quote = ChargeSplit::shares(5.00, ChargeSplit::groups([$riga(1, 22, 122), $riga(2, 4, 104)], true));

    return $quote[0]['amount'] === 2.50 && $quote[1]['amount'] === 2.50;
});

check('l\'ultima quota prende il resto dell\'arrotondamento', function () use ($riga, $somma) {
    // Tre aliquote con lo stesso imponibile: 3,33 + 3,33 + 3,34.
    $quote = ChargeSplit::shares(10.00, ChargeSplit::groups([$riga(1, 22, 10), $riga(2, 10, 10), $riga(3, 4, 10)], false));

    return array_column($quote, 'amount') === [3.33, 3.33, 3.34]
        && array_column($quote, 'tax_id') === [1, 2, 3]
        && $somma($quote) === 1000;
});

check('lo sconto sul totale cambia la proporzione', function () use ($riga) {
    // 100 al 22% scontati di 50 e 50 al 4%: metà e metà.
    $quote = ChargeSplit::shares(8.00, ChargeSplit::groups([$riga(1, 22, 100, 50), $riga(2, 4, 50)], false));

    return $quote[0]['amount'] === 4.00 && $quote[1]['amount'] === 4.00;
});

check('imponibile zero: la spesa va all\'aliquota più alta', function () use ($riga) {
    $quote = ChargeSplit::shares(7.00, ChargeSplit::groups([$riga(2, 4, 10, 10), $riga(1, 22, 20, 20)], false));

    return count($quote) === 1 && $quote[0]['tax_id'] === 1 && $quote[0]['amount'] === 7.00;
});

check('la natura dell\'esente passa alla spesa', function () use ($riga) {
    $quote = ChargeSplit::shares(5.00, ChargeSplit::groups([$riga(9, 0, 40, 0, 'N4')], true));

    return $quote[0]['nature'] === 'N4' && $quote[0]['rate'] === 0.0 && $quote[0]['amount'] === 5.00;
});

check('senza merce nessuna quota', fn () =>
    ChargeSplit::shares(5.00, ChargeSplit::groups([], true)) === []
    && ChargeSplit::shares(5.00, ChargeSplit::groups([$riga(0, 0, 10)], true)) === []
);

check('spesa zero: una quota sola da zero', function () use ($riga) {
    $quote = ChargeSplit::shares(0.0, ChargeSplit::groups([$riga(1, 22, 100), $riga(2, 4, 50)], false));

    return count($quote) === 1 && $quote[0]['amount'] === 0.0;
});

summary();
