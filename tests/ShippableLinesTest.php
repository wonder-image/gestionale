<?php
/** php tests/ShippableLinesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Shipping\ShippableLines;

$riga = static fn (int $id, float $quantita, array $altro = []): array => $altro + [
    'id' => $id,
    'type' => 'product',
    'quantity' => $quantita,
    'requires_shipping' => true,
    'parent_item_id' => 0,
];

check('un prodotto intero ha per residuo la quantità ordinata', fn () =>
    ShippableLines::remaining([$riga(1, 3.0)], []) === [1 => 3.0]
);

check('con metà già in spedizione resta metà', fn () =>
    ShippableLines::remaining([$riga(1, 4.0)], [1 => 2.0]) === [1 => 2.0]
);

check('una riga già spedita per intero resta a zero, mai sotto', fn () =>
    ShippableLines::remaining([$riga(1, 2.0), $riga(2, 2.0)], [1 => 2.0, 2 => 5.0]) === [1 => 0.0, 2 => 0.0]
);

check('un servizio, una spedizione, una commissione e uno sconto non si spediscono', fn () =>
    ShippableLines::remaining([
        $riga(1, 1.0, ['requires_shipping' => false]),
        $riga(2, 1.0, ['type' => 'shipping']),
        $riga(3, 1.0, ['type' => 'fee']),
        $riga(4, 1.0, ['type' => 'custom']),
        $riga(5, 1.0, ['type' => 'text']),
        $riga(6, 2.0),
    ], []) === [6 => 2.0]
);

check('una confezione: la madre non c\'è, le figlie sì', fn () =>
    ShippableLines::remaining([
        $riga(1, 2.0),
        $riga(2, 4.0, ['parent_item_id' => 1]),
        $riga(3, 2.0, ['parent_item_id' => 1]),
    ], [2 => 1.0]) === [2 => 3.0, 3 => 2.0]
);

check('le figlie di una confezione che non si spedisce (servizio) restano fuori', fn () =>
    ShippableLines::remaining([
        $riga(1, 1.0),
        $riga(2, 1.0, ['parent_item_id' => 1, 'requires_shipping' => false]),
        $riga(3, 1.0, ['parent_item_id' => 1]),
    ], []) === [3 => 1.0]
);

check('le quantità frazionarie non perdono i millesimi', fn () =>
    ShippableLines::remaining([$riga(1, 2.5)], [1 => 0.75]) === [1 => 1.75]
);

check('una riga a quantità zero non si spedisce', fn () =>
    ShippableLines::remaining([$riga(1, 0.0), $riga(2, 1.0)], []) === [2 => 1.0]
);

check('quantità già assegnata a una riga che non c\'è non crea righe', fn () =>
    ShippableLines::remaining([$riga(1, 1.0)], [99 => 3.0]) === [1 => 1.0]
);

summary();
