<?php
/** php tests/OrderSheetTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

$ordine = [
    'billing_type' => 'private', 'billing_name' => '<b>x</b>', 'billing_surname' => 'Rossi',
    'billing_street' => 'Via Roma', 'billing_number' => '1', 'billing_cap' => '20100', 'billing_city' => 'Milano', 'billing_province' => 'MI',
    'email' => 'a@b.it', 'phone' => '', 'total' => '122.00', 'taxable_total' => '100.00', 'tax_total' => '22.00',
    'products_total' => '100.00', 'discount_total' => '0.00', 'shipping_total' => '0.00', 'fees_total' => '0.00',
];

check('l\'indirizzo si scrive su righe e il testo è escapato', function () use ($ordine) {
    $html = OrderSheet::address($ordine, 'billing');

    return str_contains($html, '&lt;b&gt;x&lt;/b&gt; Rossi')
        && !str_contains($html, '<b>x</b>')
        && str_contains($html, 'Via Roma 1')
        && str_contains($html, '20100 Milano (MI)');
});

check('un indirizzo vuoto si dice con un trattino', fn () =>
    str_contains(OrderSheet::address([], 'shipping'), '—')
);

check('le righe: quantità, prezzo, sconto, IVA e totale; la riga di testo non ha importi', function () {
    $html = OrderSheet::items([
        ['type' => 'product', 'name' => 'Crema', 'quantity' => '2.000', 'unit_price' => '20.00', 'discount_type' => 'percent', 'discount_value' => '10.00', 'tax_rate' => '22.00', 'line_total' => '36.00'],
        ['type' => 'text', 'name' => 'Nota per il cliente', 'quantity' => '0.000', 'unit_price' => '0.00', 'line_total' => '0.00'],
        ['type' => 'shipping', 'name' => 'Spedizione', 'quantity' => '1.000', 'unit_price' => '5.00', 'discount_type' => 'none', 'tax_rate' => '22.00', 'line_total' => '5.00'],
    ]);

    return str_contains($html, 'Crema') && str_contains($html, '>2<') && str_contains($html, '20,00 €')
        && str_contains($html, '10%') && str_contains($html, '22%') && str_contains($html, '36,00 €')
        && str_contains($html, 'Nota per il cliente')
        && str_contains($html, 'colspan')
        && !str_contains($html, ' 0,00 €')
        && str_contains($html, 'Spedizione');
});

check('senza righe c\'è una frase, non una tabella vuota', fn () =>
    str_contains(OrderSheet::items([]), 'Nessuna riga') && !str_contains(OrderSheet::items([]), '<table')
);

check('il riepilogo IVA ha aliquota, imponibile, imposta e totale', function () {
    $html = OrderSheet::taxSummary([['rate' => '22.00', 'nature' => '', 'taxable' => '100.00', 'tax' => '22.00', 'total' => '122.00']]);

    return str_contains($html, '22%') && str_contains($html, '100,00 €') && str_contains($html, '22,00 €') && str_contains($html, '122,00 €');
});

check('i totali mostrano le voci a zero solo quando contano', function () use ($ordine) {
    $html = OrderSheet::totals($ordine);

    return str_contains($html, 'Imponibile') && str_contains($html, 'IVA') && str_contains($html, '122,00 €')
        && !str_contains($html, 'Sconto') && !str_contains($html, 'Spedizione');
});

check('i totali con sconto e spedizione li mostrano', function () use ($ordine) {
    $html = OrderSheet::totals(['discount_total' => '10.00', 'shipping_total' => '5.00'] + $ordine);

    return str_contains($html, 'Sconto') && str_contains($html, 'Spedizione');
});

check('i pagamenti: tipo, stato, metodo e riferimento, tutto escapato', function () {
    $html = OrderSheet::payments([
        ['code' => 'PAG-1', 'type' => 'payment', 'amount' => '122.00', 'status' => 'paid', 'paid_at' => '2025-10-15 10:30:00',
            'payment_method_id' => 3, 'provider_reference' => '<i>rif</i>'],
        ['code' => 'PAG-2', 'type' => 'refund', 'amount' => '10.00', 'status' => 'pending', 'paid_at' => '', 'payment_method_id' => 0, 'provider_reference' => ''],
    ], [3 => 'Bonifico']);

    return str_contains($html, 'Incasso') && str_contains($html, 'Rimborso') && str_contains($html, 'Pagato')
        && str_contains($html, 'Bonifico') && str_contains($html, '&lt;i&gt;rif&lt;/i&gt;') && str_contains($html, '15/10/2025 10:30');
});

check('senza resi e senza pagamenti ci sono le frasi', fn () =>
    str_contains(OrderSheet::payments([], []), 'Nessun pagamento') && str_contains(OrderSheet::returns([]), 'Nessun reso')
);

check('lo storico traduce campo e stati', function () {
    $html = OrderSheet::history([
        ['creation' => '2025-10-15 10:30:00', 'field' => 'status', 'from_value' => 'pending', 'to_value' => 'confirmed', 'source' => 'admin', 'user_id' => 0],
        ['creation' => '2025-10-15 10:31:00', 'field' => 'payment_status', 'from_value' => 'unpaid', 'to_value' => 'paid', 'source' => 'system', 'user_id' => 0],
    ]);

    return str_contains($html, 'In attesa') && str_contains($html, 'Confermato')
        && str_contains($html, 'Da pagare') && str_contains($html, 'Pagato');
});

summary();
