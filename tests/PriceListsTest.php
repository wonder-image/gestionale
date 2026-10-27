<?php
/** php tests/PriceListsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Pricing\PriceLists;
use Wonder\Plugin\Gestionale\Support\Pricing\PriceListTransfer;

const ORA = '2026-10-27 12:00:00';

/** Un listino attivo in euro, senza date, con quello che serve in più o in meno. */
$listino = static fn (array $in = []): array => $in + [
    'active' => 'true',
    'currency' => 'EUR',
    'valid_from' => null,
    'valid_to' => null,
];

$scaglioni = [
    ['min_quantity' => 1.0, 'price' => 10.0],
    ['min_quantity' => 10.0, 'price' => 9.0],
    ['min_quantity' => 50.0, 'price' => 8.0],
];

check('scaglione: sotto il primo gradino vale il prezzo normale', fn () => PriceLists::tier($scaglioni, 9) === 10.0);
check('scaglione: alla quantità minima esatta vale quel gradino', fn () => PriceLists::tier($scaglioni, 10) === 9.0);
check('scaglione: vale il gradino più alto che non supera la quantità', fn () => PriceLists::tier($scaglioni, 120) === 8.0);
check('scaglione: l\'ordine delle righe non conta', fn () => PriceLists::tier(array_reverse($scaglioni), 12) === 9.0);
check('scaglione: sotto ogni quantità minima il listino non copre la riga', fn () => PriceLists::tier([['min_quantity' => 5.0, 'price' => 7.0]], 2) === 0.0);
check('scaglione: quantità decimale', fn () => PriceLists::tier($scaglioni, 9.999) === 10.0);

check('listino attivo, senza date, in euro: vale', fn () => PriceLists::applies($listino(), ORA, 'EUR'));
check('listino spento: non vale', fn () => !PriceLists::applies($listino(['active' => 'false']), ORA, 'EUR'));
check('listino scaduto: non vale', fn () => !PriceLists::applies($listino(['valid_to' => '2026-10-26']), ORA, 'EUR'));
check('listino che scade oggi: vale fino a sera', fn () => PriceLists::applies($listino(['valid_to' => '2026-10-27']), ORA, 'EUR'));
check('listino che comincia domani: non vale ancora', fn () => !PriceLists::applies($listino(['valid_from' => '2026-10-28']), ORA, 'EUR'));
check('listino che comincia oggi: vale', fn () => PriceLists::applies($listino(['valid_from' => '2026-10-27', 'valid_to' => '2026-12-31']), ORA, 'EUR'));
check('date zero di MySQL: senza limite', fn () => PriceLists::applies($listino(['valid_from' => '0000-00-00', 'valid_to' => '0000-00-00']), ORA, 'EUR'));
check('valuta diversa dall\'ordine: non vale', fn () => !PriceLists::applies($listino(['currency' => 'USD']), ORA, 'EUR'));
check('valuta: maiuscole e spazi non contano', fn () => PriceLists::applies($listino(['currency' => ' eur']), ORA, 'EUR'));

check('IVA: stessa base, prezzo uguale', fn () => PriceLists::toBasis(12.2, true, true, 22) === 12.2);
check('IVA: listino escluso, ordine incluso: si aggiunge', fn () => PriceLists::toBasis(10.0, false, true, 22) === 12.2);
check('IVA: listino incluso, ordine escluso: si toglie', fn () => PriceLists::toBasis(12.2, true, false, 22) === 10.0);
check('IVA: aliquota zero, nessuna conversione', fn () => PriceLists::toBasis(10.0, false, true, 0) === 10.0);

$caricato = ['id' => 3, 'prices_include_tax' => false, 'items' => [7 => $scaglioni]];

check('riga: prodotto nel listino, scaglione e conversione insieme', fn () => PriceLists::unitPrice($caricato, 7, 10, true, 22) === 10.98);
check('riga: prodotto fuori listino, 0 (prezzo di catalogo)', fn () => PriceLists::unitPrice($caricato, 8, 10, true, 22) === 0.0);
check('riga: nessun listino, 0 (prezzo di catalogo)', fn () => PriceLists::unitPrice(null, 7, 10, true, 22) === 0.0);

$sku = ['ab-1' => 11, 'cd-2' => 22];

/** Il messaggio del rifiuto, o '' se il foglio passa. */
$rifiuto = static function (array $foglio) use ($sku): string {
    try {
        PriceListTransfer::fromTable($foglio, $sku);
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    return '';
};

check('import: il foglio scaricato si ricarica uguale', fn () => PriceListTransfer::fromTable([
    PriceListTransfer::HEADERS,
    ['AB-1', 'Maglia', 1, 9.5],
    ['AB-1', 'Maglia', 10, '8,75'],
], $sku) === [
    ['product_id' => 11, 'min_quantity' => 1.0, 'price' => 9.5],
    ['product_id' => 11, 'min_quantity' => 10.0, 'price' => 8.75],
]);
check('import: colonne trovate per nome in un altro ordine', fn () => PriceListTransfer::fromTable([
    ['Prezzo', 'Codice', 'Quantità'],
    ['7', 'cd-2', '3'],
], $sku) === [['product_id' => 22, 'min_quantity' => 3.0, 'price' => 7.0]]);
check('import: quantità minima vuota vale 1, righe vuote saltate', fn () => PriceListTransfer::fromTable([
    PriceListTransfer::HEADERS,
    ['', '', '', ''],
    ['CD-2', '', '', '4'],
], $sku) === [['product_id' => 22, 'min_quantity' => 1.0, 'price' => 4.0]]);
check('import: SKU sconosciuto, rifiuto con riga e SKU', fn () => str_contains($rifiuto([PriceListTransfer::HEADERS, ['AB-1', '', 1, 2], ['ZZ-9', '', 1, 2]]), 'Riga 3') && str_contains($rifiuto([PriceListTransfer::HEADERS, ['ZZ-9', '', 1, 2]]), 'ZZ-9'));
check('import: SKU mancante, rifiuto', fn () => str_contains($rifiuto([PriceListTransfer::HEADERS, ['', 'Maglia', 1, 2]]), 'manca lo SKU'));
check('import: prezzo non numerico, rifiuto', fn () => str_contains($rifiuto([PriceListTransfer::HEADERS, ['AB-1', '', 1, 'tanto']]), 'Riga 2'));
check('import: quantità minima zero, rifiuto', fn () => $rifiuto([PriceListTransfer::HEADERS, ['AB-1', '', 0, 2]]) !== '');
check('import: stesso SKU e stessa quantità due volte, rifiuto', fn () => str_contains($rifiuto([PriceListTransfer::HEADERS, ['AB-1', '', 1, 2], ['ab-1', '', '1', 3]]), 'stessa quantità'));
check('import: file senza righe, rifiuto', fn () => str_contains($rifiuto([PriceListTransfer::HEADERS]), 'non ha righe'));

summary();
