<?php
/** php tests/OfficeOrderRulesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderRules;

/** La chiave del rifiuto, o null se passa. */
$rifiuto = static function (callable $f): ?string {
    try {
        $f();
    } catch (UserError $e) {
        return $e->key();
    }

    return null;
};

check('una riga di prodotto: quantità all\'italiana, prezzo vuoto = listino', function () {
    $r = OfficeOrderRules::lines([['type' => 'product', 'product_id' => '7', 'quantity' => '2,5', 'unit_price' => '']]);

    return count($r) === 1 && $r[0]['product_id'] === 7 && $r[0]['quantity'] === 2.5 && $r[0]['unit_price'] === null
        && $r[0]['discount_type'] === 'none' && $r[0]['batch_id'] === 0;
});

check('prezzo a mano, sconto di riga, lotto e fornitore', function () {
    $r = OfficeOrderRules::lines([[
        'type' => 'product', 'product_id' => 3, 'quantity' => '1', 'unit_price' => '1.234,50',
        'discount_type' => 'percent', 'discount_value' => '10', 'batch_id' => '4', 'supplier_id' => '9',
    ]]);

    return $r[0]['unit_price'] === 1234.5 && $r[0]['discount_type'] === 'percent' && $r[0]['discount_value'] === 10.0
        && $r[0]['batch_id'] === 4 && $r[0]['supplier_id'] === 9;
});

check('riga libera con categoria IVA, riga di testo a zero', function () {
    $r = OfficeOrderRules::lines([
        ['type' => 'custom', 'name' => 'Montaggio', 'quantity' => '1', 'unit_price' => '50', 'tax_category_id' => '2'],
        ['type' => 'text', 'name' => 'Consegna al piano'],
    ]);

    return $r[0]['type'] === 'custom' && $r[0]['name'] === 'Montaggio' && $r[0]['unit_price'] === 50.0 && $r[0]['tax_category_id'] === 2
        && $r[1]['type'] === 'text' && $r[1]['quantity'] === 0.0 && $r[1]['name'] === 'Consegna al piano';
});

check('le righe vuote si saltano', fn () =>
    OfficeOrderRules::lines([
        ['type' => 'product', 'product_id' => ''],
        ['type' => 'custom', 'name' => '', 'unit_price' => ''],
        ['type' => 'text', 'name' => '  '],
    ]) === []
);

check('i rifiuti dicono la riga', fn () =>
    $rifiuto(fn () => OfficeOrderRules::lines([['type' => 'product', 'product_id' => 1, 'quantity' => '0']])) === 'office_order.invalid_quantity'
    && $rifiuto(fn () => OfficeOrderRules::lines([['type' => 'product', 'product_id' => 1, 'quantity' => '']])) === 'office_order.quantity_required'
    && $rifiuto(fn () => OfficeOrderRules::lines([['type' => 'product', 'product_id' => 1, 'quantity' => '1', 'unit_price' => '-3']])) === 'office_order.invalid_price'
    && $rifiuto(fn () => OfficeOrderRules::lines([['type' => 'custom', 'name' => 'X', 'quantity' => '1', 'unit_price' => '']])) === 'office_order.price_required'
    && $rifiuto(fn () => OfficeOrderRules::lines([['type' => 'custom', 'name' => '', 'quantity' => '1', 'unit_price' => '5']])) === 'office_order.name_required'
    && $rifiuto(fn () => OfficeOrderRules::lines([['type' => 'product', 'product_id' => 1, 'quantity' => '1', 'discount_type' => 'percent', 'discount_value' => '120']])) === 'office_order.invalid_discount'
    && $rifiuto(fn () => OfficeOrderRules::lines([['type' => 'boh']])) === 'office_order.invalid_lines'
    && $rifiuto(fn () => OfficeOrderRules::lines(array_fill(0, 301, []))) === 'office_order.too_many_rows'
);

check('uno sconto a zero è nessuno sconto', fn () =>
    OfficeOrderRules::lines([['type' => 'product', 'product_id' => 1, 'quantity' => '1', 'discount_type' => 'amount', 'discount_value' => '0']])[0]['discount_type'] === 'none'
);

check('la testata: consegna e sconto sul totale', fn () =>
    OfficeOrderRules::header(['fulfillment_type' => 'pickup', 'manual_discount_type' => 'amount', 'manual_discount_value' => '15,50'])
        === ['fulfillment_type' => 'pickup', 'manual_discount_type' => 'amount', 'manual_discount_value' => 15.5]
    && $rifiuto(fn () => OfficeOrderRules::header(['fulfillment_type' => 'teletrasporto'])) === 'office_order.invalid_fulfillment'
    && $rifiuto(fn () => OfficeOrderRules::header(['manual_discount_type' => 'percent', 'manual_discount_value' => '101'])) === 'office_order.invalid_order_discount'
);

check('la conferma vuole bozza, cliente, fatturazione, una riga e l\'indirizzo se si spedisce', function () use ($rifiuto) {
    $bozza = ['status' => 'draft', 'customer_id' => 5, 'fulfillment_type' => 'shipping', 'shipping_street' => 'Via Roma', 'shipping_city' => 'Milano', 'shipping_country' => 'IT',
        'billing_type' => 'private', 'billing_name' => 'Anna', 'billing_street' => 'Via Roma', 'billing_city' => 'Milano', 'billing_country' => 'IT'];
    $righe = [['type' => 'product', 'quantity' => '1.000', 'parent_item_id' => 0]];

    return $rifiuto(fn () => OfficeOrderRules::assertConfirmable($bozza, $righe)) === null
        && $rifiuto(fn () => OfficeOrderRules::assertConfirmable(['status' => 'confirmed'] + $bozza, $righe)) === 'office_order.not_draft'
        && $rifiuto(fn () => OfficeOrderRules::assertConfirmable(['customer_id' => 0] + $bozza, $righe)) === 'office_order.customer_required'
        && $rifiuto(fn () => OfficeOrderRules::assertConfirmable($bozza, [['type' => 'text', 'quantity' => 0]])) === 'office_order.no_rows'
        && $rifiuto(fn () => OfficeOrderRules::assertConfirmable(['billing_street' => ''] + $bozza, $righe)) === 'office_order.billing_required'
        && $rifiuto(fn () => OfficeOrderRules::assertConfirmable(['billing_type' => 'business'] + $bozza, $righe)) === 'office_order.billing_required'
        && $rifiuto(fn () => OfficeOrderRules::assertConfirmable(['shipping_city' => ''] + $bozza, $righe)) === 'office_order.address_required'
        && $rifiuto(fn () => OfficeOrderRules::assertConfirmable(['fulfillment_type' => 'pickup', 'shipping_city' => ''] + $bozza, $righe)) === null;
});

check('annullabili: al massimo quanto non è partito, vuoto e zero non contano', function () use ($rifiuto) {
    $ok = OfficeOrderRules::cancellable([10 => '1', 11 => '', 12 => '0'], [10 => 2.0, 11 => 3.0, 12 => 1.0]);

    return $ok === [10 => 1.0]
        && $rifiuto(fn () => OfficeOrderRules::cancellable([10 => '3'], [10 => 2.0], [10 => 'Maglia'])) === 'office_order.cancel_too_much'
        && $rifiuto(fn () => OfficeOrderRules::cancellable([10 => ''], [10 => 2.0])) === 'office_order.nothing_to_cancel'
        && $rifiuto(fn () => OfficeOrderRules::cancellable([99 => '1'], [10 => 2.0])) === 'office_order.cancel_too_much'
        && $rifiuto(fn () => OfficeOrderRules::cancellable([10 => 'due'], [10 => 2.0])) === 'office_order.invalid_cancel';
});

summary();
