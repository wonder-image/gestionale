<?php
/** php tests/OrderTotalsStackTest.php — più sconti sul totale (gestionale#113). */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Pricing\OrderTotals;

$riga = static function (int $id, float $totale, string $tipo = 'product'): array {
    return [
        'id' => $id,
        'type' => $tipo,
        'quantity' => '1.000',
        'line_total' => number_format($totale, 2, '.', ''),
        'tax_rate' => 22,
        'tax_nature' => '',
    ];
};

/** Somma delle quote scritte sulle righe. */
$quote = static function (array $totali): float {
    $somma = 0.0;

    foreach ($totali['lines'] as $linea) {
        $somma = round($somma + (float) $linea['order_discount_amount'], 2);
    }

    return $somma;
};

check('due percentuali si applicano una sul residuo dell\'altra', function () use ($riga) {
    $totali = OrderTotals::of([$riga(1, 100)], ['discounts' => [
        ['type' => 'percent', 'value' => 10],
        ['type' => 'percent', 'value' => 10],
    ]]);

    return $totali['discount_total'] === '19.00'
        && $totali['discounts'] === ['10.00', '9.00']
        && $totali['total'] === '81.00';
});

check('le percentuali vanno prima degli importi, in qualunque ordine arrivino', function () use ($riga) {
    $totali = OrderTotals::of([$riga(1, 100)], ['discounts' => [
        ['type' => 'amount', 'value' => 10],
        ['type' => 'percent', 'value' => 10],
    ]]);

    // 10% di 100 = 10, poi 10 € sul residuo: l'importo resta nella sua posizione.
    return $totali['discount_total'] === '20.00'
        && $totali['discounts'] === ['10.00', '10.00'];
});

check('lo sconto totale non supera mai la merce', function () use ($riga) {
    $totali = OrderTotals::of([$riga(1, 100)], ['discounts' => [
        ['type' => 'percent', 'value' => 50],
        ['type' => 'amount', 'value' => 60],
    ]]);

    return $totali['discount_total'] === '100.00'
        && $totali['discounts'] === ['50.00', '50.00']
        && $totali['total'] === '0.00';
});

check('sconti, quote e totale tornano al centesimo', function () use ($riga, $quote) {
    $totali = OrderTotals::of([$riga(1, 33.33), $riga(2, 33.33), $riga(3, 33.33)], ['discounts' => [
        ['type' => 'percent', 'value' => 15],
        ['type' => 'amount', 'value' => 10],
        ['type' => 'percent', 'value' => 7],
    ]]);
    $somma = round(array_sum(array_map('floatval', $totali['discounts'])), 2);

    foreach ($totali['lines'] as $linea) {
        if ((float) $linea['order_discount_amount'] > (float) $linea['line_total']) {
            return false;
        }
    }

    return $somma === (float) $totali['discount_total']
        && $quote($totali) === (float) $totali['discount_total'];
});

check('ogni sconto tocca solo le sue righe', function () use ($riga) {
    $totali = OrderTotals::of([$riga(1, 60), $riga(2, 40), $riga(3, 10, 'shipping')], ['discounts' => [
        ['type' => 'percent', 'value' => 50, 'lines' => [1]],
        ['type' => 'amount', 'value' => 100, 'lines' => [2]],
    ]]);

    return $totali['discounts'] === ['30.00', '40.00']
        && $totali['lines'][0]['order_discount_amount'] === '30.00'
        && $totali['lines'][1]['order_discount_amount'] === '40.00'
        && $totali['lines'][2]['order_discount_amount'] === '0.00';
});

check('un importo su righe in comune prende il residuo dopo la percentuale', function () use ($riga) {
    $totali = OrderTotals::of([$riga(1, 60), $riga(2, 40)], ['discounts' => [
        ['type' => 'percent', 'value' => 50, 'lines' => [1]],
        ['type' => 'amount', 'value' => 50, 'lines' => [1, 2]],
    ]]);

    // Residuo: 30 + 40 = 70; i 50 € si ripartiscono 30/70 e 40/70.
    return $totali['discounts'] === ['30.00', '50.00']
        && $totali['lines'][0]['order_discount_amount'] === '51.43'
        && $totali['lines'][1]['order_discount_amount'] === '28.57';
});

check('senza discounts la risposta è quella di prima', function () use ($riga) {
    $righe = [$riga(1, 33.33), $riga(2, 33.33), $riga(3, 33.33)];
    $prima = OrderTotals::of($righe, ['discount_type' => 'amount', 'discount_value' => 10]);
    $dopo = OrderTotals::of($righe, ['discounts' => [['type' => 'amount', 'value' => 10]]]);

    return $prima['discount_total'] === $dopo['discount_total']
        && array_column($prima['lines'], 'order_discount_amount') === array_column($dopo['lines'], 'order_discount_amount')
        && $prima['discounts'] === ['10.00'];
});

check('senza sconto la lista degli sconti è vuota', function () use ($riga) {
    return OrderTotals::of([$riga(1, 10)])['discounts'] === [];
});

summary();
