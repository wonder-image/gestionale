<?php
/** php tests/TaxTotalsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Tax\TaxTotals;

check('prezzi IVA esclusa: imposta sull\'imponibile', function () {
    $riepiloghi = TaxTotals::summaries([
        ['total' => 100.00, 'rate' => 22.0],
        ['total' => 100.00, 'rate' => 22.0],
    ], false);

    return count($riepiloghi) === 1
        && $riepiloghi[0]['taxable'] === 200.00
        && $riepiloghi[0]['tax'] === 44.00
        && $riepiloghi[0]['total'] === 244.00;
});

check('prezzi IVA inclusa: l\'imposta si scorpora', function () {
    $riepiloghi = TaxTotals::summaries([
        ['total' => 122.00, 'rate' => 22.0],
    ], true);

    return $riepiloghi[0]['taxable'] === 100.00
        && $riepiloghi[0]['tax'] === 22.00
        && $riepiloghi[0]['total'] === 122.00;
});

check('l\'imposta si calcola sul totale dell\'aliquota, non riga per riga', function () {
    // 0.33 × 3 = 0.99 → 0.2178 → 0.22. Riga per riga sarebbe 0.07 × 3 = 0.21.
    $riepiloghi = TaxTotals::summaries([
        ['total' => 0.33, 'rate' => 22.0],
        ['total' => 0.33, 'rate' => 22.0],
        ['total' => 0.33, 'rate' => 22.0],
    ], false);

    return $riepiloghi[0]['taxable'] === 0.99 && $riepiloghi[0]['tax'] === 0.22;
});

check('un riepilogo per aliquota, dal più alto al più basso', function () {
    $riepiloghi = TaxTotals::summaries([
        ['total' => 100.00, 'rate' => 4.0],
        ['total' => 100.00, 'rate' => 22.0],
        ['total' => 100.00, 'rate' => 10.0],
    ], false);

    return array_column($riepiloghi, 'rate') === [22.0, 10.0, 4.0]
        && $riepiloghi[1]['tax'] === 10.00;
});

check('le operazioni a zero tengono la loro natura', function () {
    $riepiloghi = TaxTotals::summaries([
        ['total' => 50.00, 'rate' => 0.0, 'nature' => 'N3.2'],
        ['total' => 50.00, 'rate' => 0.0, 'nature' => 'N4'],
        ['total' => 30.00, 'rate' => 0.0, 'nature' => 'N3.2'],
    ], false);

    return count($riepiloghi) === 2
        && $riepiloghi[0]['nature'] === 'N3.2'
        && $riepiloghi[0]['taxable'] === 80.00
        && $riepiloghi[0]['tax'] === 0.00
        && $riepiloghi[1]['nature'] === 'N4';
});

check('imponibile e imposta sono arrotondati a due decimali', function () {
    $riepiloghi = TaxTotals::summaries([
        ['total' => 10.005, 'rate' => 10.0],
    ], false);

    return $riepiloghi[0]['taxable'] === 10.01 && $riepiloghi[0]['tax'] === 1.00;
});

check('senza righe non c\'è nessun riepilogo', fn () =>
    TaxTotals::summaries([], false) === [] && TaxTotals::summaries([], true) === []
);

summary();
