<?php
/** php tests/LinePriceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Pricing\LinePrice;

check('senza altro vince il prezzo base', function () {
    $riga = LinePrice::of(['price' => 10, 'quantity' => 2]);

    return $riga['price_source'] === 'base'
        && $riga['list_price'] === '10.00'
        && $riga['unit_price'] === '10.00'
        && $riga['line_total'] === '20.00';
});

check('il prezzo scontato del prodotto batte il base', function () {
    $riga = LinePrice::of(['price' => 10, 'sale_price' => 7.5, 'quantity' => 2]);

    return $riga['price_source'] === 'sale_price'
        && $riga['list_price'] === '10.00'
        && $riga['unit_price'] === '7.50'
        && $riga['line_total'] === '15.00';
});

check('un prezzo scontato più alto del base non è uno sconto', function () {
    // Succede quando il listino sale e nessuno svuota la colonna.
    $riga = LinePrice::of(['price' => 10, 'sale_price' => 12]);

    return $riga['price_source'] === 'base' && $riga['unit_price'] === '10.00';
});

check('un prezzo scontato uguale al base non vince', fn () =>
    LinePrice::of(['price' => 10, 'sale_price' => 10])['price_source'] === 'base'
);

check('un prezzo scontato vuoto o a zero non vince', fn () =>
    LinePrice::of(['price' => 10, 'sale_price' => ''])['price_source'] === 'base'
    && LinePrice::of(['price' => 10, 'sale_price' => 0])['price_source'] === 'base'
);

check('il listino batte tutto il resto', function () {
    $riga = LinePrice::of(['price' => 10, 'sale_price' => 7.5, 'price_list_price' => 6]);

    return $riga['price_source'] === 'price_list' && $riga['unit_price'] === '6.00';
});

check('la campagna batte il prezzo scontato ma non il listino', function () {
    $conCampagna = LinePrice::of(['price' => 10, 'sale_price' => 7.5, 'campaign_price' => 7]);
    $conListino = LinePrice::of(['price' => 10, 'campaign_price' => 7, 'price_list_price' => 6]);

    return $conCampagna['price_source'] === 'campaign'
        && $conCampagna['unit_price'] === '7.00'
        && $conListino['price_source'] === 'price_list';
});

check('un prezzo scritto a mano vince su tutti', function () {
    $riga = LinePrice::of(['price' => 10, 'price_list_price' => 6, 'manual_unit_price' => '4.20']);

    return $riga['price_source'] === 'manual' && $riga['unit_price'] === '4.20';
});

check('un prezzo a mano a zero è un prezzo, non un campo vuoto', function () {
    // Un omaggio si scrive così: zero euro, scelto da una persona.
    $riga = LinePrice::of(['price' => 10, 'manual_unit_price' => '0']);

    return $riga['price_source'] === 'manual' && $riga['unit_price'] === '0.00';
});

check('lo sconto a importo si toglie dal prezzo unitario', function () {
    $riga = LinePrice::of([
        'price' => 10, 'quantity' => 3,
        'discount_type' => 'amount', 'discount_value' => 2,
    ]);

    return $riga['unit_price'] === '8.00'
        && $riga['line_total'] === '24.00'
        && $riga['discount_type'] === 'amount'
        && $riga['discount_value'] === '2.00';
});

check('lo sconto a percentuale si calcola sul prezzo che ha vinto', function () {
    $riga = LinePrice::of([
        'price' => 10, 'sale_price' => 8,
        'discount_type' => 'percent', 'discount_value' => 25,
    ]);

    return $riga['unit_price'] === '6.00';
});

check('uno sconto di riga fa diventare manuale la sorgente', fn () =>
    LinePrice::of([
        'price' => 10, 'sale_price' => 8,
        'discount_type' => 'percent', 'discount_value' => 25,
    ])['price_source'] === 'manual'
);

check('uno sconto più grande del prezzo si ferma a zero', function () {
    // Mai un prezzo negativo: un ordine con un totale sotto zero è un rimborso
    // che nessuno ha chiesto.
    $importo = LinePrice::of([
        'price' => 10, 'quantity' => 2,
        'discount_type' => 'amount', 'discount_value' => 30,
    ]);
    $percentuale = LinePrice::of([
        'price' => 10,
        'discount_type' => 'percent', 'discount_value' => 150,
    ]);

    return $importo['unit_price'] === '0.00'
        && $importo['line_total'] === '0.00'
        && $percentuale['unit_price'] === '0.00';
});

check('uno sconto a zero o negativo non è uno sconto', function () {
    $zero = LinePrice::of(['price' => 10, 'discount_type' => 'amount', 'discount_value' => 0]);
    $negativo = LinePrice::of(['price' => 10, 'discount_type' => 'amount', 'discount_value' => -5]);

    return $zero['discount_type'] === 'none'
        && $zero['unit_price'] === '10.00'
        && $zero['price_source'] === 'base'
        && $negativo['discount_type'] === 'none'
        && $negativo['unit_price'] === '10.00';
});

check('uno sconto di tipo sconosciuto non tocca il prezzo', fn () =>
    LinePrice::of([
        'price' => 10, 'discount_type' => 'marziano', 'discount_value' => 5,
    ])['unit_price'] === '10.00'
);

check('quantità zero, negativa o scritta male fa una riga da zero', function () {
    // Un carrello non deve andare in pagina 500 perché arriva una quantità
    // storta: la riga vale zero e il commerciante la vede.
    foreach ([0, -3, '', 'due'] as $quantità) {
        $riga = LinePrice::of(['price' => 10, 'quantity' => $quantità]);

        if ($riga['line_total'] !== '0.00' || $riga['quantity'] !== '0.000') {
            return false;
        }
    }

    return true;
});

check('senza quantità se ne vende uno', fn () =>
    LinePrice::of(['price' => 10])['line_total'] === '10.00'
);

check('la quantità tiene tre decimali', function () {
    $riga = LinePrice::of(['price' => 10, 'quantity' => 0.75]);

    return $riga['quantity'] === '0.750' && $riga['line_total'] === '7.50';
});

check('il sovrapprezzo della personalizzazione si somma al prezzo unitario', function () {
    $riga = LinePrice::of([
        'price' => 10, 'quantity' => 2, 'customization_surcharge' => 1.5,
    ]);

    return $riga['unit_price'] === '11.50'
        && $riga['customization_surcharge'] === '1.50'
        && $riga['line_total'] === '23.00';
});

check('il sovrapprezzo si somma dopo lo sconto, non prima', function () {
    $riga = LinePrice::of([
        'price' => 10, 'customization_surcharge' => 2,
        'discount_type' => 'percent', 'discount_value' => 50,
    ]);

    // 50% di 10 fa 5, più 2 di personalizzazione: 7,00 e non 6,00.
    return $riga['unit_price'] === '7.00';
});

check('un prezzo negativo si legge come zero', fn () =>
    LinePrice::of(['price' => -10])['unit_price'] === '0.00'
);

check('i centesimi si arrotondano una volta sola', function () {
    // 3 × 3,335 farebbe 10,005: la riga costa 10,01, non 10,00 né 10,02.
    $riga = LinePrice::of(['price' => 3.335, 'quantity' => 3]);

    return $riga['unit_price'] === '3.34' && $riga['line_total'] === '10.02';
});

check('senza tipo di sconto la riga non sconta niente e non lamenta niente', function () {
    // Il carrello può passare un valore senza il tipo: la riga vale pieno e
    // il tipo torna 'none', senza warning di PHP.
    $errore = null;
    set_error_handler(static function (int $livello, string $messaggio) use (&$errore): bool {
        $errore = $messaggio;

        return true;
    });

    $riga = LinePrice::of(['price' => 10, 'discount_value' => 3]);

    restore_error_handler();

    return $errore === null
        && $riga['discount_type'] === 'none'
        && $riga['discount_value'] === '0.00'
        && $riga['line_total'] === '10.00';
});

summary();
