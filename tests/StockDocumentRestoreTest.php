<?php
/** php tests/StockDocumentRestoreTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentForm;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentRules;

$post = static fn (mixed $lines, array $altro = []): array => ['lines' => is_string($lines) ? $lines : json_encode($lines)] + $altro;

check('le righe tornano com\'erano scritte, anche quella rifiutata', function () use ($post) {
    $r = StockDocumentForm::restorable($post([
        ['product_id' => 12, 'quantity' => '2,5'],
        ['product_id' => '7', 'quantity' => 'abc'],
        ['product_id' => 9, 'quantity' => ''],
    ]));

    return $r['items'] === [
        ['product_id' => 12, 'quantity' => '2,5'],
        ['product_id' => 7, 'quantity' => 'abc'],
        ['product_id' => 9, 'quantity' => ''],
    ];
});

check('righe senza versione, versioni storte e doppioni restano fuori', function () use ($post) {
    $r = StockDocumentForm::restorable($post([
        ['product_id' => 0, 'quantity' => '1'],
        ['product_id' => '12abc', 'quantity' => '1'],
        ['product_id' => -3, 'quantity' => '1'],
        'riga',
        ['product_id' => 5, 'quantity' => '1'],
        ['product_id' => 5, 'quantity' => '2'],
    ]));

    return $r['items'] === [['product_id' => 5, 'quantity' => '1']];
});

check('un campo lines illeggibile o assente non lascia righe', function () use ($post) {
    return StockDocumentForm::restorable($post('x'))['items'] === []
        && StockDocumentForm::restorable($post('{"a":1}'))['items'] === []
        && StockDocumentForm::restorable([])['items'] === [];
});

check('pezzi non testuali diventano una casella vuota, quelli lunghi si tagliano', function () use ($post) {
    $r = StockDocumentForm::restorable($post([
        ['product_id' => 1, 'quantity' => ['3']],
        ['product_id' => 2, 'quantity' => 4],
        ['product_id' => 3, 'quantity' => str_repeat('9', 100)],
        ['product_id' => 4],
    ]));

    return array_column($r['items'], 'quantity') === ['', '4', str_repeat('9', 32), ''];
});

check('al massimo MAX_LINES righe', function () use ($post) {
    $righe = [];

    for ($i = 1; $i <= StockDocumentRules::MAX_LINES + 5; $i++) {
        $righe[] = ['product_id' => $i, 'quantity' => '1'];
    }

    return count(StockDocumentForm::restorable($post($righe))['items']) === StockDocumentRules::MAX_LINES;
});

check('sede, causale e nota tornano; la nota oltre 500 caratteri si taglia', function () use ($post) {
    $r = StockDocumentForm::restorable($post([], ['location_id' => '3', 'reason' => 'damaged', 'note' => str_repeat('è', 600)]));

    return $r['header']['location_id'] === 3
        && $r['header']['reason'] === 'damaged'
        && mb_strlen($r['header']['note']) === 500;
});

check('senza sessione attiva remember non scrive e recall non trova niente', function () use ($post) {
    StockDocumentForm::remember('receipt', $post([['product_id' => 1, 'quantity' => '1']]));

    return !isset($_SESSION) && StockDocumentForm::recall('receipt') === null;
});

check('recall: solo il suo tipo, una volta sola, non quando è vecchio', function () {
    $_SESSION = [StockDocumentForm::RESTORE_KEY => ['type' => 'receipt', 'at' => time(), 'header' => ['note' => 'Bolla 4'], 'items' => [['product_id' => 1, 'quantity' => '2']]]];
    $altro = StockDocumentForm::recall('issue');
    $uno = StockDocumentForm::recall('receipt');
    $due = StockDocumentForm::recall('receipt');
    $_SESSION = [StockDocumentForm::RESTORE_KEY => ['type' => 'receipt', 'at' => time() - 3600, 'header' => [], 'items' => []]];
    $vecchio = StockDocumentForm::recall('receipt');
    $vuota = $_SESSION;
    unset($_SESSION);

    return $altro === null
        && $uno === ['header' => ['note' => 'Bolla 4'], 'items' => [['product_id' => 1, 'quantity' => '2']]]
        && $due === null
        && $vecchio === null && $vuota === [];
});

check('G3, Acquisti: il costo del carico torna scritto com\'era, e il fornitore della testata con lui', function () use ($post) {
    $salvato = StockDocumentForm::restorable($post([
        ['product_id' => 3, 'quantity' => '2', 'cost' => '4,5'],
        ['product_id' => 4, 'quantity' => '1', 'cost' => 'abc'],
        ['product_id' => 5, 'quantity' => '1'],
    ]) + ['supplier_id' => '7', 'supplier_document_number' => 'DDT 12', 'supplier_document_date' => '2026-10-13']);

    return $salvato['items'] === [
            ['product_id' => 3, 'quantity' => '2', 'cost' => '4,5'],
            ['product_id' => 4, 'quantity' => '1', 'cost' => 'abc'],
            ['product_id' => 5, 'quantity' => '1'],
        ]
        && ($salvato['header']['supplier_id'] ?? null) === '7'
        && ($salvato['header']['supplier_document_number'] ?? null) === 'DDT 12'
        && ($salvato['header']['supplier_document_date'] ?? null) === '2026-10-13';
});

check('G3, lotti: la stessa versione torna su più righe, una per lotto, con scadenza e scelte dello scarico', function () use ($post) {
    $salvato = StockDocumentForm::restorable($post([
        ['product_id' => 3, 'quantity' => '2', 'batch_number' => 'L1', 'batch_expiry' => '2027-01-31'],
        ['product_id' => 3, 'quantity' => '1', 'batch_number' => 'L2', 'batch_expiry' => ''],
        ['product_id' => 6, 'quantity' => '1', 'batch_id' => '9', 'supplier_id' => '0'],
        ['product_id' => 5, 'quantity' => '1'],
        ['product_id' => 5, 'quantity' => '4'],
    ]));

    return $salvato['items'] === [
        ['product_id' => 3, 'quantity' => '2', 'batch_number' => 'L1', 'batch_expiry' => '2027-01-31'],
        ['product_id' => 3, 'quantity' => '1', 'batch_number' => 'L2', 'batch_expiry' => ''],
        ['product_id' => 6, 'quantity' => '1', 'batch_id' => '9', 'supplier_id' => '0'],
        ['product_id' => 5, 'quantity' => '1'],
    ];
});

summary();
