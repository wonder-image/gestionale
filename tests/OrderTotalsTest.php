<?php
/** php tests/OrderTotalsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Pricing\OrderTotals;

/** Una riga di prodotto, con quello che serve ai totali. */
$riga = static function (float $totale, float $aliquota = 22, array $extra = []): array {
    return $extra + [
        'type' => 'product',
        'quantity' => '1.000',
        'line_total' => number_format($totale, 2, '.', ''),
        'tax_rate' => $aliquota,
        'tax_nature' => '',
    ];
};

check('con i prezzi IVA inclusa il cliente paga la cifra esposta', function () use ($riga) {
    $totali = OrderTotals::of([$riga(100)], ['prices_include_tax' => true]);

    return $totali['products_total'] === '100.00'
        && $totali['taxable_total'] === '81.97'
        && $totali['tax_total'] === '18.03'
        && $totali['total'] === '100.00';
});

check('con i prezzi IVA esclusa l\'imposta si aggiunge', function () use ($riga) {
    $totali = OrderTotals::of([$riga(100)], ['prices_include_tax' => false]);

    return $totali['taxable_total'] === '100.00'
        && $totali['tax_total'] === '22.00'
        && $totali['total'] === '122.00';
});

check('senza righe i totali sono tutti a zero', function () {
    $totali = OrderTotals::of([]);

    return $totali['products_total'] === '0.00'
        && $totali['taxable_total'] === '0.00'
        && $totali['tax_total'] === '0.00'
        && $totali['total'] === '0.00'
        && $totali['total_weight'] === '0.000'
        && $totali['tax_summaries'] === [];
});

check('spedizione e commissioni hanno il loro totale', function () use ($riga) {
    $totali = OrderTotals::of([
        $riga(100),
        ['type' => 'shipping', 'line_total' => '7.90', 'tax_rate' => 22, 'quantity' => '1.000'],
        ['type' => 'fee', 'line_total' => '3.00', 'tax_rate' => 22, 'quantity' => '1.000'],
    ]);

    return $totali['products_total'] === '100.00'
        && $totali['shipping_total'] === '7.90'
        && $totali['fees_total'] === '3.00'
        && $totali['total'] === '110.90';
});

check('lo sconto sul totale si riparte in proporzione sulle righe', function () use ($riga) {
    $totali = OrderTotals::of(
        [$riga(75), $riga(25)],
        ['discount_type' => 'amount', 'discount_value' => 20]
    );

    return $totali['lines'][0]['order_discount_amount'] === '15.00'
        && $totali['lines'][1]['order_discount_amount'] === '5.00'
        && $totali['discount_total'] === '20.00'
        && $totali['total'] === '80.00';
});

check('lo sconto a percentuale vale sulla merce scontabile', function () use ($riga) {
    $totali = OrderTotals::of(
        [$riga(100), ['type' => 'shipping', 'line_total' => '10.00', 'tax_rate' => 22]],
        ['discount_type' => 'percent', 'discount_value' => 10]
    );

    // Il 10% si calcola su 100, non su 110: la spedizione non si sconta.
    return $totali['discount_total'] === '10.00' && $totali['total'] === '100.00';
});

check('l\'ultimo centesimo del resto va alla riga più alta', function () use ($riga) {
    // Tre righe da 33,33 e dieci euro di sconto: 3,33 a testa fa 9,99. Il
    // centesimo che manca deve finire da qualche parte, o la somma delle righe
    // non fa più il totale e i riepiloghi IVA sballano.
    $totali = OrderTotals::of(
        [$riga(33.33), $riga(33.33), $riga(33.33)],
        ['discount_type' => 'amount', 'discount_value' => 10]
    );

    $ripartito = 0.0;

    foreach ($totali['lines'] as $linea) {
        $ripartito += (float) $linea['order_discount_amount'];
    }

    return round($ripartito, 2) === 10.00
        && $totali['discount_total'] === '10.00'
        && $totali['lines'][0]['order_discount_amount'] === '3.34';
});

check('il resto va alla riga più alta anche quando non è la prima', function () use ($riga) {
    // 0,83 + 8,33 + 0,83 fa 9,99: il centesimo va alla riga da cento euro.
    $totali = OrderTotals::of(
        [$riga(10), $riga(100), $riga(10)],
        ['discount_type' => 'amount', 'discount_value' => 10]
    );

    return $totali['lines'][0]['order_discount_amount'] === '0.83'
        && $totali['lines'][1]['order_discount_amount'] === '8.34'
        && $totali['lines'][2]['order_discount_amount'] === '0.83'
        && $totali['discount_total'] === '10.00';
});

check('uno sconto più grande della merce si ferma alla merce', function () use ($riga) {
    // Un coupon da 500 € su un ordine da 100 € non regala la spedizione e non
    // porta il totale sotto zero.
    $totali = OrderTotals::of(
        [$riga(100), ['type' => 'shipping', 'line_total' => '7.90', 'tax_rate' => 22]],
        ['discount_type' => 'amount', 'discount_value' => 500]
    );

    return $totali['discount_total'] === '100.00' && $totali['total'] === '7.90';
});

check('senza righe scontabili lo sconto non si riparte e non divide per zero', function () {
    $totali = OrderTotals::of(
        [['type' => 'shipping', 'line_total' => '7.90', 'tax_rate' => 22]],
        ['discount_type' => 'percent', 'discount_value' => 50]
    );

    return $totali['discount_total'] === '0.00' && $totali['total'] === '7.90';
});

check('righe a zero non prendono sconto e non dividono per zero', function () use ($riga) {
    $totali = OrderTotals::of(
        [$riga(0), $riga(0)],
        ['discount_type' => 'amount', 'discount_value' => 10]
    );

    return $totali['discount_total'] === '0.00'
        && $totali['lines'][0]['order_discount_amount'] === '0.00';
});

check('uno sconto a zero o negativo non tocca niente', function () use ($riga) {
    foreach ([0, -5] as $valore) {
        $totali = OrderTotals::of([$riga(100)], ['discount_type' => 'amount', 'discount_value' => $valore]);

        if ($totali['discount_total'] !== '0.00' || $totali['total'] !== '100.00') {
            return false;
        }
    }

    return true;
});

check('una riga di testo non si sconta e non pesa sui totali', function () use ($riga) {
    $totali = OrderTotals::of(
        [$riga(100), ['type' => 'text', 'line_total' => '0.00', 'tax_rate' => 0]],
        ['discount_type' => 'amount', 'discount_value' => 10]
    );

    return $totali['lines'][1]['order_discount_amount'] === '0.00'
        && $totali['discount_total'] === '10.00';
});

check('una riga può essere esclusa dallo sconto a mano', function () use ($riga) {
    $totali = OrderTotals::of(
        [$riga(50), $riga(50, 22, ['discountable' => 'false'])],
        ['discount_type' => 'amount', 'discount_value' => 10]
    );

    return $totali['lines'][0]['order_discount_amount'] === '10.00'
        && $totali['lines'][1]['order_discount_amount'] === '0.00';
});

check('i riepiloghi IVA arrivano uno per aliquota, dalla più alta', function () use ($riga) {
    $totali = OrderTotals::of([$riga(100, 10), $riga(122, 22), $riga(50, 10)]);

    return count($totali['tax_summaries']) === 2
        && $totali['tax_summaries'][0]['rate'] === 22.0
        && $totali['tax_summaries'][1]['rate'] === 10.0
        && $totali['tax_summaries'][1]['total'] === 150.0;
});

check('lo sconto entra nei riepiloghi IVA, non resta fuori', function () use ($riga) {
    $totali = OrderTotals::of(
        [$riga(100)],
        ['discount_type' => 'percent', 'discount_value' => 50]
    );

    // Imponibile e imposta si calcolano su 50, non su 100.
    return $totali['tax_summaries'][0]['total'] === 50.0
        && $totali['taxable_total'] === '40.98'
        && $totali['total'] === '50.00';
});

check('due nature esenti diverse restano due riepiloghi', function () use ($riga) {
    $totali = OrderTotals::of([
        $riga(100, 0, ['tax_nature' => 'N2.2']),
        $riga(50, 0, ['tax_nature' => 'N3.2']),
    ]);

    return count($totali['tax_summaries']) === 2 && $totali['tax_total'] === '0.00';
});

check('il peso totale tiene tre decimali', function () {
    $totali = OrderTotals::of([
        ['type' => 'product', 'quantity' => '3.000', 'line_total' => '30.00', 'tax_rate' => 22, 'weight' => 0.25],
        ['type' => 'product', 'quantity' => '2.000', 'line_total' => '20.00', 'tax_rate' => 22, 'weight' => 1.5],
    ]);

    return $totali['total_weight'] === '3.750';
});

check('la spedizione non ha peso e non lo inventa', function () use ($riga) {
    $totali = OrderTotals::of([
        $riga(100, 22, ['weight' => 2]),
        ['type' => 'shipping', 'line_total' => '7.90', 'tax_rate' => 22, 'quantity' => '1.000'],
    ]);

    return $totali['total_weight'] === '2.000';
});

check('le righe tornano indietro tutte, nello stesso ordine', function () use ($riga) {
    $totali = OrderTotals::of([$riga(10), $riga(20), $riga(30)]);

    return count($totali['lines']) === 3
        && $totali['lines'][0]['line_total'] === '10.00'
        && $totali['lines'][2]['line_total'] === '30.00';
});

check('lo sconto di una riga non supera mai il totale della riga', function () use ($riga) {
    // Uno sconto quasi pari alla merce: tutte le quote arrotondano per
    // difetto e il resto, tutto in una volta sulla riga più alta, la
    // sfonderebbe — una riga con imponibile negativo non entra in fattura.
    $righe = array_map(
        static fn (float $totale): array => $riga($totale, 22.0),
        [0.20, 0.22, 0.17, 0.24, 0.18]
    );

    $totali = OrderTotals::of($righe, ['discount_type' => 'amount', 'discount_value' => 0.98]);
    $somma = 0.0;

    foreach ($totali['lines'] as $linea) {
        $sconto = (float) $linea['order_discount_amount'];
        $somma = round($somma + $sconto, 2);

        if ($sconto < 0.0 || $sconto > (float) $linea['line_total']) {
            return false;
        }
    }

    // E la somma delle quote fa ancora esattamente lo sconto.
    return $somma === 0.98 && $totali['discount_total'] === '0.98';
});

check('una riga con discountable falso non riceve quota dello sconto', function () use ($riga) {
    $totali = OrderTotals::of(
        [$riga(60, 22, ['discountable' => true]), $riga(40, 22, ['discountable' => false])],
        ['discount_type' => 'percent', 'discount_value' => 50]
    );

    return $totali['discount_total'] === '30.00'
        && $totali['lines'][0]['order_discount_amount'] === '30.00'
        && $totali['lines'][1]['order_discount_amount'] === '0.00';
});

check('la spedizione gratuita azzera la riga e riporta il risparmio', function () use ($riga) {
    $totali = OrderTotals::of(
        [$riga(100), ['type' => 'shipping', 'unit_price' => '7.90', 'list_price' => '7.90', 'line_total' => '7.90', 'tax_rate' => 22, 'quantity' => '1.000']],
        ['free_shipping' => true]
    );
    $spedizione = $totali['lines'][1];

    return $totali['shipping_saved'] === '7.90'
        && $totali['shipping_total'] === '0.00'
        && $spedizione['line_total'] === '0.00'
        && $spedizione['unit_price'] === '0.00'
        && $spedizione['list_price'] === '7.90'
        && $totali['total'] === '100.00';
});

check('senza spedizione o senza coupon il risparmio è zero', function () use ($riga) {
    $senzaRiga = OrderTotals::of([$riga(100)], ['free_shipping' => true]);
    $senzaCoupon = OrderTotals::of(
        [$riga(100), ['type' => 'shipping', 'line_total' => '7.90', 'tax_rate' => 22]]
    );

    return $senzaRiga['shipping_saved'] === '0.00'
        && $senzaCoupon['shipping_saved'] === '0.00'
        && $senzaCoupon['shipping_total'] === '7.90';
});

check('la spedizione azzerata non cambia lo sconto sulla merce', function () use ($riga) {
    $contesto = ['discount_type' => 'amount', 'discount_value' => 10];
    $righe = [$riga(100), ['type' => 'shipping', 'line_total' => '7.90', 'tax_rate' => 22]];

    $con = OrderTotals::of($righe, $contesto + ['free_shipping' => true]);
    $senza = OrderTotals::of($righe, $contesto);

    return $con['discount_total'] === '10.00'
        && $con['discount_total'] === $senza['discount_total']
        && $con['total'] === '90.00'
        && $senza['total'] === '97.90';
});

check('lo sconto su righe adatte e non adatte somma esattamente il valore', function () use ($riga) {
    $totali = OrderTotals::of(
        [
            $riga(33.33, 22, ['discountable' => true]),
            $riga(33.33, 22, ['discountable' => true]),
            $riga(33.33, 22, ['discountable' => true]),
            $riga(20, 22, ['discountable' => false]),
        ],
        ['discount_type' => 'amount', 'discount_value' => 10]
    );
    $somma = 0.0;

    foreach ($totali['lines'] as $linea) {
        $somma = round($somma + (float) $linea['order_discount_amount'], 2);
    }

    return $somma === 10.0
        && $totali['discount_total'] === '10.00'
        && $totali['lines'][3]['order_discount_amount'] === '0.00';
});

summary();
