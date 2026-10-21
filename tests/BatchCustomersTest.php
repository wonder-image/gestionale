<?php
/** php tests/BatchCustomersTest.php — G3.20, i clienti di un lotto */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\BatchCustomers;

$mov = static fn (string $tipo, int $riga, float $pezzi): array => ['type' => $tipo, 'reference_item_id' => $riga, 'quantity' => $pezzi];

check('perItem: il netto per riga, al netto di annulli e resi', fn () =>
    BatchCustomers::perItem([
        $mov('sale', 1, -3), $mov('return', 1, 1),
        $mov('sale', 2, -2), $mov('sale_cancel', 2, 2),
        $mov('sale', 3, -1.5),
    ]) === [1 => 2.0, 3 => 1.5]
);

check('perItem: rettifiche, carichi e movimenti senza riga non contano', fn () =>
    BatchCustomers::perItem([
        $mov('adjustment', 4, -5), $mov('purchase', 4, 5), $mov('sale', 0, -1),
    ]) === []
);

check('perOrder: le righe dello stesso ordine si sommano; una riga senza ordine si perde', fn () =>
    BatchCustomers::perOrder([1 => 2.0, 3 => 1.5, 9 => 4.0], [1 => 10, 3 => 10]) === [10 => 3.5]
);

check('csv: punto e virgola, BOM, virgola decimale, formule disinnescate', function () {
    $csv = BatchCustomers::csv([[
        'order_id' => 1, 'order_number' => '2026/100001', 'ordered_at' => '2026-10-01 10:00:00',
        'customer' => '=Rossi; Mario', 'email' => 'm@x.it', 'phone' => '+39 333', 'quantity' => 1.5,
    ]]);
    $righe = explode("\n", trim($csv));

    return str_starts_with($csv, "\u{FEFF}Ordine;Data;Cliente;Email;Telefono;Pezzi")
        && $righe[1] === "2026/100001;2026-10-01;\"'=Rossi; Mario\";m@x.it;\"'+39 333\";1,5";
});

check('nome =HYPERLINK(...) esce con l\'apice', function () {
    $csv = BatchCustomers::csv([[
        'order_id' => 1, 'order_number' => '1', 'ordered_at' => '2026-10-01 10:00:00',
        'customer' => '=HYPERLINK("http://x","clic")', 'email' => '@x', 'phone' => '-1', 'quantity' => 1,
    ]]);
    $riga = explode("\n", trim($csv))[1];

    return $riga === "1;2026-10-01;\"'=HYPERLINK(\"\"http://x\"\",\"\"clic\"\")\";'@x;'-1;1";
});

summary();
