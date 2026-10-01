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

check('lo sconto di una riga si legge in percentuale, in euro o con un trattino', fn () =>
    OrderSheet::discount(['discount_type' => 'percent', 'discount_value' => '10.000']) === '10%'
    && OrderSheet::discount(['discount_type' => 'amount', 'discount_value' => '5.00']) === '5,00 €'
    && OrderSheet::discount(['discount_type' => 'none', 'discount_value' => '0']) === '—'
);

check('lo storico traduce campo e stati con le parole del gestionale', fn () =>
    OrderSheet::logField('payment_status') === 'Pagamento'
    && OrderSheet::logValue('status', 'pending') === 'In attesa'
    && OrderSheet::logValue('payment_status', 'unpaid') === 'Da pagare'
    && OrderSheet::logValue('status', '') === '—'
);

check('lo stato di un pagamento è un badge', fn () =>
    str_contains(OrderSheet::paymentBadge('paid'), 'badge') && str_contains(OrderSheet::paymentBadge('paid'), 'Pagato')
);

summary();
