<?php
/** php tests/OrderLinesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Orders\OrderLines;

$riga = static fn (int $id, int $padre = 0, string $tipo = 'product', int $prodotto = 1, float $quantita = 1.0): array => [
    'id' => $id, 'type' => $tipo, 'product_id' => $prodotto, 'quantity' => $quantita, 'parent_item_id' => $padre,
];

$piatta = static fn () => [
    $riga(10, 0, 'product', 0),
    $riga(11, 10, 'product', 5, 2.0),
    $riga(12, 10, 'product', 6, 1.0),
    $riga(20, 0, 'product', 7, 3.0),
    $riga(30, 0, 'text', 0, 1.0),
];

$ids = static fn (array $righe): array => array_map(static fn (array $r): int => (int) $r['id'], $righe);

check('goods: le figlie e la riga semplice, mai la madre né il testo', function () use ($piatta, $ids) {
    return $ids(OrderLines::goods($piatta())) === [11, 12, 20];
});

check('sold: la madre, la semplice e il testo, non le figlie', function () use ($piatta, $ids) {
    return $ids(OrderLines::sold($piatta())) === [10, 20, 30];
});

check('children: le figlie di una madre, vuota per un id che non c\'è', function () use ($piatta, $ids) {
    return $ids(OrderLines::children($piatta(), 10)) === [11, 12]
        && OrderLines::children($piatta(), 99) === [];
});

check('la lista annidata dà gli stessi id di quella piatta', function () use ($riga, $ids) {
    $madre = $riga(10, 0, 'product', 0) + ['children' => [$riga(11, 10, 'product', 5, 2.0), $riga(12, 10, 'product', 6)]];
    $annidata = [$madre, $riga(20, 0, 'product', 7, 3.0), $riga(30, 0, 'text', 0)];

    return $ids(OrderLines::goods($annidata)) === [11, 12, 20]
        && $ids(OrderLines::sold($annidata)) === [10, 20, 30]
        && $ids(OrderLines::children($annidata, 10)) === [11, 12]
        && $ids(OrderLines::flat($annidata)) === [10, 11, 12, 20, 30];
});

check('flat toglie la chiave children e mette le figlie dopo la madre', function () use ($riga) {
    $annidata = [$riga(10) + ['children' => [$riga(11, 10)]], $riga(20)];
    $piatta = OrderLines::flat($annidata);

    return count($piatta) === 3 && array_key_exists('children', $piatta[0]) === false
        && (int) $piatta[1]['id'] === 11 && (int) $piatta[2]['id'] === 20;
});

check('quantità zero e prodotto 0 non sono merce', function () use ($riga, $ids) {
    return OrderLines::goods([$riga(1, 0, 'product', 3, 0.0), $riga(2, 0, 'product', 0, 1.0)]) === [];
});

check('una madre senza figlie nella lista (caso corrotto) resta merce', function () use ($riga, $ids) {
    return $ids(OrderLines::goods([$riga(10, 0, 'product', 4, 1.0)])) === [10];
});

summary();
