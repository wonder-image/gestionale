<?php
/** php tests/PriceListsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Pricing\PriceListResource;
use Wonder\Plugin\Gestionale\Support\Pricing\PriceListSeeder;
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

// Regola in percentuale e più listini (#162).
check('regola: ricarico sul prezzo base', fn () => PriceLists::byRule('markup', 10, 20.0) === 22.0);
check('regola: sconto sul prezzo base', fn () => PriceLists::byRule('discount', 15, 20.0) === 17.0);
check('regola: nessuna regola, 0', fn () => PriceLists::byRule('none', 10, 20.0) === 0.0);
check('regola: percentuale zero, 0', fn () => PriceLists::byRule('markup', 0, 20.0) === 0.0);
check('regola: sconto del 100% o più, 0 (non regala)', fn () => PriceLists::byRule('discount', 100, 20.0) === 0.0);
check('regola: prezzo base zero, 0', fn () => PriceLists::byRule('discount', 10, 0.0) === 0.0);

$cliente = ['id' => 3, 'prices_include_tax' => true, 'rule' => 'none', 'percent' => 0.0, 'items' => [7 => [['min_quantity' => 1.0, 'price' => 9.0]]]];
$canale = ['id' => 5, 'prices_include_tax' => true, 'rule' => 'discount', 'percent' => 20.0, 'items' => [8 => [['min_quantity' => 1.0, 'price' => 6.0]]]];
$soloRighe = ['id' => 6, 'prices_include_tax' => true, 'rule' => 'none', 'percent' => 0.0, 'items' => [8 => [['min_quantity' => 1.0, 'price' => 6.0]]]];

check('più listini: il listino del cliente vince su quello del canale', fn () => PriceLists::resolve([$cliente, $canale], 7, 1, 10.0, true, 22) === ['price' => 9.0, 'price_list_id' => 3]);
check('più listini: prodotto fuori dal listino cliente, vale la riga del canale', fn () => PriceLists::resolve([$cliente, $canale], 8, 1, 10.0, true, 22) === ['price' => 6.0, 'price_list_id' => 5]);
check('più listini: prodotto senza righe, vale la percentuale del canale', fn () => PriceLists::resolve([$cliente, $canale], 9, 1, 10.0, true, 22) === ['price' => 8.0, 'price_list_id' => 5]);
check('più listini: prodotto in nessun listino, prezzo di catalogo', fn () => PriceLists::resolve([$cliente, $soloRighe], 9, 1, 10.0, true, 22) === ['price' => 0.0, 'price_list_id' => 0]);
check('più listini: la riga vince sulla percentuale dello stesso listino', fn () => PriceLists::resolve([$canale], 8, 1, 10.0, true, 22)['price'] === 6.0);
check('più listini: la percentuale vale a ogni quantità', fn () => PriceLists::resolve([$canale], 9, 500, 10.0, true, 22)['price'] === 8.0);
check('più listini: le percentuali non si sommano, vince il primo', fn () => PriceLists::resolve([['rule' => 'markup', 'percent' => 10.0] + $soloRighe, $canale], 9, 1, 10.0, true, 22) === ['price' => 11.0, 'price_list_id' => 6]);
check('più listini: righe senza lo scaglione raggiunto, vale la percentuale', fn () => PriceLists::resolve([['items' => [9 => [['min_quantity' => 10.0, 'price' => 5.0]]]] + $canale], 9, 2, 10.0, true, 22)['price'] === 8.0);
check('più listini: un listino scaduto non arriva (load lo scarta), vale il successivo', fn () => PriceLists::resolve([null, $canale], 9, 1, 10.0, true, 22)['price_list_id'] === 5);
check('più listini: nessun listino, prezzo di catalogo', fn () => PriceLists::resolve([], 7, 1, 10.0, true, 22) === ['price' => 0.0, 'price_list_id' => 0]);
check('testata: il primo listino usato da una riga', fn () => PriceLists::headerOf([$cliente, $canale], [5]) === 5);
check('testata: nessuna riga col listino, il primo che vale', fn () => PriceLists::headerOf([$cliente, $canale], []) === 3);
check('testata: nessun listino, 0', fn () => PriceLists::headerOf([], []) === 0);

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

// «Si applica a»: la regola vale solo dentro il selettore.
$selezione = ['all' => false, 'categories' => [4], 'tags' => [], 'brands' => [], 'models' => [], 'excluded_models' => [31]];
$scontoScelto = ['id' => 9, 'prices_include_tax' => true, 'rule' => 'discount', 'percent' => 10.0, 'scope' => $selezione, 'items' => [12 => [['min_quantity' => 1.0, 'price' => 5.0]]]];
$fatti = [
    10 => ['categories' => [4], 'tags' => [], 'brand_id' => 0, 'model_id' => 30],
    11 => ['categories' => [4], 'tags' => [], 'brand_id' => 0, 'model_id' => 31],
    12 => ['categories' => [8], 'tags' => [], 'brand_id' => 0, 'model_id' => 32],
    13 => ['categories' => [8], 'tags' => [], 'brand_id' => 0, 'model_id' => 33],
];
$daFatti = static fn (int $id): array => $fatti[$id] ?? [];

check('selettore: prodotto della selezione, vale la regola', fn () => PriceLists::resolve([$scontoScelto], 10, 1, 20.0, true, 22, $daFatti) === ['price' => 18.0, 'price_list_id' => 9]);
check('selettore: prodotto escluso, prezzo di catalogo', fn () => PriceLists::resolve([$scontoScelto], 11, 1, 20.0, true, 22, $daFatti)['price_list_id'] === 0);
check('selettore: prodotto fuori dalla selezione, prezzo di catalogo', fn () => PriceLists::resolve([$scontoScelto], 13, 1, 20.0, true, 22, $daFatti)['price_list_id'] === 0);
check('selettore: la riga vince anche fuori dalla selezione', fn () => PriceLists::resolve([$scontoScelto], 12, 1, 20.0, true, 22, $daFatti)['price'] === 5.0);
check('selettore: i fatti anche come array', fn () => PriceLists::resolve([$scontoScelto], 10, 1, 20.0, true, 22, $fatti[10])['price'] === 18.0);
check('selettore: fuori selezione si passa al listino dopo', fn () => PriceLists::resolve([$scontoScelto, $canale], 13, 1, 20.0, true, 22, $daFatti) === ['price' => 16.0, 'price_list_id' => 5]);
check('selettore: senza chiave scope vale tutto il catalogo', fn () => PriceLists::scopeIsAll($canale));
check('selettore: tutto il catalogo con esclusi non è «tutto»', fn () => !PriceLists::scopeIsAll(['scope' => ['all' => true, 'excluded_models' => [3]]]));
check('selettore: fatti solo se una regola ha una selezione', fn () => PriceLists::needsFacts([$canale, $scontoScelto]) && !PriceLists::needsFacts([$canale, $cliente, null]));
check('selettore: senza regola i fatti non servono', fn () => !PriceLists::needsFacts([['rule' => 'none', 'scope' => $selezione]]));

// «Crea le righe dalla selezione».
$catalogo = [
    10 => ['price' => 20.0, 'facts' => $fatti[10]],
    11 => ['price' => 20.0, 'facts' => $fatti[11]],
    12 => ['price' => 30.0, 'facts' => ['categories' => [4], 'tags' => [], 'brand_id' => 0, 'model_id' => 32]],
    13 => ['price' => 0.0, 'facts' => ['categories' => [4], 'tags' => [], 'brand_id' => 0, 'model_id' => 33]],
    14 => ['price' => 50.0, 'facts' => $fatti[13]],
];

check('righe: solo la selezione, senza esclusi, senza chi ha già la riga, senza prezzo zero', fn () => array_column(PriceListSeeder::rows(['rule' => 'none'], $selezione, $catalogo, [12], true, []), 'product_id') === [10]);
check('righe: senza regola il prezzo di catalogo', fn () => PriceListSeeder::rows(['rule' => 'none'], $selezione, $catalogo, [], true, [])[1] === ['product_id' => 12, 'min_quantity' => '1', 'price' => '30.00']);
check('righe: con la regola il prezzo scontato', fn () => PriceListSeeder::rows(['rule' => 'discount', 'percent' => 10], $selezione, $catalogo, [], true, [])[0]['price'] === '18.00');
check('righe: tutto il catalogo prende anche gli altri', fn () => count(PriceListSeeder::rows(['rule' => 'none'], ['all' => true], $catalogo, [], true, [])) === 4);
check('righe: listino IVA esclusa da catalogo IVA inclusa', fn () => PriceListSeeder::rows(['rule' => 'none', 'prices_include_tax' => false], $selezione, $catalogo, [12], true, [30 => 25.0])[0]['price'] === '16.00');
check('righe: aliquota sconosciuta, prezzo non convertito', fn () => PriceListSeeder::rows(['rule' => 'none', 'prices_include_tax' => false], $selezione, $catalogo, [12], true, [])[0]['price'] === '20.00');

/** Il tipo dell'errore del form: il controller del backend intercetta solo InvalidArgumentException. */
$errore = static function (array $valori): string {
    try {
        PriceListResource::mutateRequestValues($valori, 'store', 'backend', ['currency' => 'EUR']);
    } catch (Throwable $e) {
        return get_class($e);
    }

    return '';
};

check('form: sconto oltre 100 torna sul form, non è un 500', fn () => is_a($errore(['price_rule' => 'discount', 'percent' => '150']), InvalidArgumentException::class, true));
check('form: ricarico a zero torna sul form', fn () => is_a($errore(['price_rule' => 'markup', 'percent' => '0']), InvalidArgumentException::class, true));
check('form: date al contrario tornano sul form', fn () => is_a($errore(['valid_from' => '2026-12-31', 'valid_to' => '2026-01-01']), InvalidArgumentException::class, true));

summary();
