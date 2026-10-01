<?php
/** php tests/CustomerStatsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Contacts\CustomerStats;

$ordine = static fn (string $totale, string $stato = 'confirmed', string $pagamento = 'paid', string $data = '2026-09-10 10:00:00'): array => [
    'stage' => 'order', 'status' => $stato, 'payment_status' => $pagamento, 'total' => $totale, 'ordered_at' => $data,
];

check('senza ordini tutto è a zero e le date mancano', function () {
    $s = CustomerStats::of([], []);

    return $s['orders'] === 0 && $s['spent'] === 0.0 && $s['average'] === 0.0
        && $s['first_order'] === '' && $s['last_order'] === '' && $s['to_pay'] === 0 && $s['cart_value'] === 0.0;
});

check('gli ordini si contano e si sommano; il medio è la somma divisa per il numero', function () use ($ordine) {
    $s = CustomerStats::of([$ordine('100.00'), $ordine('50.00')], []);

    return $s['orders'] === 2 && $s['spent'] === 150.0 && $s['average'] === 75.0;
});

check('un ordine annullato o rimborsato non conta come speso', function () use ($ordine) {
    $s = CustomerStats::of([
        $ordine('100.00'),
        $ordine('40.00', 'cancelled', 'unpaid'),
        $ordine('30.00', 'confirmed', 'refunded'),
    ], []);

    return $s['orders'] === 1 && $s['spent'] === 100.0 && $s['cancelled'] === 2;
});

check('i carrelli e i preventivi non sono ordini', function () use ($ordine) {
    $carrello = ['stage' => 'cart', 'status' => 'draft', 'payment_status' => 'unpaid', 'total' => '99.00', 'ordered_at' => ''];

    return CustomerStats::of([$ordine('10.00'), $carrello], [])['orders'] === 1;
});

check('primo e ultimo ordine seguono la data, non l\'ordine delle righe', function () use ($ordine) {
    $s = CustomerStats::of([
        $ordine('1.00', 'confirmed', 'paid', '2026-09-20 09:00:00'),
        $ordine('1.00', 'confirmed', 'paid', '2026-08-01 09:00:00'),
        $ordine('1.00', 'confirmed', 'paid', '2026-09-05 09:00:00'),
    ], []);

    return $s['first_order'] === '2026-08-01 09:00:00' && $s['last_order'] === '2026-09-20 09:00:00';
});

check('da pagare sono gli ordini vivi con il denaro ancora in arrivo', function () use ($ordine) {
    $s = CustomerStats::of([
        $ordine('10.00', 'pending', 'unpaid'),
        $ordine('10.00', 'confirmed', 'pending'),
        $ordine('10.00', 'confirmed', 'partially_paid'),
        $ordine('10.00', 'confirmed', 'paid'),
        $ordine('10.00', 'cancelled', 'unpaid'),
    ], []);

    return $s['to_pay'] === 3;
});

check('il carrello vale la somma delle sue righe, e le note non contano', function () {
    $righe = [
        ['type' => 'product', 'quantity' => '2.000', 'unit_price' => '10.00', 'line_total' => '20.00'],
        ['type' => 'product', 'quantity' => '1.000', 'unit_price' => '5.50', 'line_total' => '5.50'],
        ['type' => 'text', 'quantity' => '1.000', 'unit_price' => '0.00', 'line_total' => '0.00'],
    ];
    $s = CustomerStats::of([], $righe);

    return $s['cart_value'] === 25.5 && $s['cart_items'] === 3;
});

summary();
