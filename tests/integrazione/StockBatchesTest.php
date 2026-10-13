<?php
/** php tests/integrazione/StockBatchesTest.php — G3 piano 3a, Lotti e scadenze */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Backend\Widgets\ExpiringBatchesWidget;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Resources\Stock\BatchResource;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Plugin\Gestionale\Support\Stock\BatchCustomers;
use Wonder\Plugin\Gestionale\Models\Stock\Batch;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Batches;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;
use Wonder\Plugin\Gestionale\Support\Stock\StockVersionSearch;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    Locations::reset();
    Wonder\Plugin\Gestionale\Gestionale::reset();

    return $esito;
}

function rifiuto(callable $fn): ?string
{
    try {
        $fn();
    } catch (UserError $errore) {
        return $errore->key();
    }

    return null;
}

/** Una versione il cui articolo gestisce i lotti, senza pezzi. */
function conLotti(string $sku): int
{
    $a = articoloConGiacenza(0, $sku);
    ProductModel::update(['track_batches' => 'true'], modelloDi($a));

    return $a;
}

/** Il lotto della versione con quel numero, o null. */
function lotto(int $prodotto, string $numero): ?array
{
    $r = Batch::find(['product_id' => $prodotto, 'number' => $numero, 'deleted' => 'false'], 1);

    return is_array($r) && isset($r['id']) ? $r : null;
}

/** I secchi della versione nella principale, `"lotto/fornitore" => pezzi`. */
function secchi(int $prodotto): array
{
    $out = [];

    foreach ((array) StockRow::find(['product_id' => $prodotto, 'location_id' => Locations::mainId(), 'deleted' => 'false']) as $riga) {
        if (is_array($riga) && isset($riga['batch_id'])) {
            $out[(int) $riga['batch_id'].'/'.(int) $riga['supplier_id']] = round((float) $riga['quantity'], 3);
        }
    }

    // Naturale: il lotto 10 viene dopo il 9, come l'ordine di creazione.
    ksort($out, SORT_NATURAL);

    return $out;
}

function carico(int $prodotto, array $righe): int
{
    $id = StockDocuments::create('receipt', [], array_map(static fn (array $r): array => ['product_id' => $prodotto] + $r, $righe), ['user_id' => 1]);
    StockDocuments::confirm($id, ['user_id' => 1]);

    return $id;
}

$domani = date('Y-m-d', strtotime('+1 day'));
$fraUnMese = date('Y-m-d', strtotime('+30 days'));
$ieri = date('Y-m-d', strtotime('-1 day'));

check('carico con due lotti della stessa versione: due lotti, due secchi, il lotto sulle righe', fn () => prova(static function () use ($domani, $fraUnMese): bool {
    accendiFunzionalita(['batch_tracking']);
    $a = conLotti('TST-LOT-1');
    $id = carico($a, [
        ['quantity' => '3', 'batch_number' => 'L1', 'batch_expiry' => $domani],
        ['quantity' => '2', 'batch_number' => 'L2', 'batch_expiry' => $fraUnMese],
    ]);
    $l1 = lotto($a, 'L1');
    $l2 = lotto($a, 'L2');
    $righe = array_column(StockDocuments::items($id), 'batch_id');

    return $l1 !== null && $l2 !== null
        && substr((string) $l1['expiry_date'], 0, 10) === $domani
        && secchi($a) === [$l1['id'].'/0' => 3.0, $l2['id'].'/0' => 2.0]
        && $righe === [(int) $l1['id'], (int) $l2['id']];
}));

check('nel carico la versione con i lotti vuole il lotto, e lo stesso lotto due volte è un rifiuto', fn () => prova(static function (): bool {
    accendiFunzionalita(['batch_tracking']);
    $a = conLotti('TST-LOT-2');
    $senza = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '1']], ['user_id' => 1]);

    return rifiuto(fn () => StockDocuments::confirm($senza, ['user_id' => 1])) === 'stock_document.batch_required'
        && rifiuto(fn () => StockDocuments::create('receipt', [], [
            ['product_id' => $a, 'quantity' => '1', 'batch_number' => 'L1'],
            ['product_id' => $a, 'quantity' => '1', 'batch_number' => 'l1'],
        ], ['user_id' => 1])) === 'stock_document.duplicate_batch';
}));

check('lotto che c\'è: scadenza diversa è un rifiuto, vuota va bene, su un lotto senza scadenza la scrive', fn () => prova(static function () use ($domani, $fraUnMese): bool {
    accendiFunzionalita(['batch_tracking']);
    $a = conLotti('TST-LOT-3');
    carico($a, [['quantity' => '1', 'batch_number' => 'L1', 'batch_expiry' => $domani]]);
    carico($a, [['quantity' => '1', 'batch_number' => 'L2', 'batch_expiry' => '']]);
    $diversa = rifiuto(fn () => carico($a, [['quantity' => '1', 'batch_number' => 'L1', 'batch_expiry' => $fraUnMese]]));
    carico($a, [['quantity' => '1', 'batch_number' => 'L1', 'batch_expiry' => '']]);
    carico($a, [['quantity' => '1', 'batch_number' => 'L2', 'batch_expiry' => $fraUnMese]]);

    return $diversa === 'batch.expiry_mismatch'
        && (float) secchi($a)[lotto($a, 'L1')['id'].'/0'] === 2.0
        && substr((string) lotto($a, 'L2')['expiry_date'], 0, 10) === $fraUnMese;
}));

check('lo stesso lotto nuovo risolto due volte è un lotto solo', fn () => prova(static function (): bool {
    accendiFunzionalita(['batch_tracking']);
    $a = conLotti('TST-LOT-4');
    $primo = Batches::resolve($a, 'NUOVO', null, 0, 1);
    $secondo = Batches::resolve($a, 'nuovo', null, 0, 2);

    return $primo > 0 && $primo === $secondo;
}));

check('lo scarico esce dal lotto scelto; un lotto di un\'altra versione è un rifiuto', fn () => prova(static function () use ($domani, $fraUnMese): bool {
    accendiFunzionalita(['batch_tracking']);
    $a = conLotti('TST-LOT-5');
    $b = conLotti('TST-LOT-6');
    carico($a, [
        ['quantity' => '3', 'batch_number' => 'VICINO', 'batch_expiry' => $domani],
        ['quantity' => '3', 'batch_number' => 'LONTANO', 'batch_expiry' => $fraUnMese],
    ]);
    carico($b, [['quantity' => '1', 'batch_number' => 'ALTRO']]);
    $vicino = (int) lotto($a, 'VICINO')['id'];
    $lontano = (int) lotto($a, 'LONTANO')['id'];
    $s = StockDocuments::create('issue', ['reason' => 'damaged'], [['product_id' => $a, 'quantity' => '2', 'batch_id' => (string) $lontano]], ['user_id' => 1]);
    StockDocuments::confirm($s, ['user_id' => 1]);
    $auto = StockDocuments::create('issue', ['reason' => 'damaged'], [['product_id' => $a, 'quantity' => '1']], ['user_id' => 1]);
    StockDocuments::confirm($auto, ['user_id' => 1]);
    $sbagliato = StockDocuments::create('issue', ['reason' => 'damaged'], [['product_id' => $a, 'quantity' => '1', 'batch_id' => (string) lotto($b, 'ALTRO')['id']]], ['user_id' => 1]);

    return secchi($a) === [$vicino.'/0' => 2.0, $lontano.'/0' => 1.0]
        && rifiuto(fn () => StockDocuments::confirm($sbagliato, ['user_id' => 1])) === 'batch.wrong_product';
}));

check('i lotti scaduti escono dal disponibile e lo scarico automatico non li prende', fn () => prova(static function () use ($ieri, $fraUnMese): bool {
    accendiFunzionalita(['batch_tracking']);
    $a = conLotti('TST-LOT-7');
    carico($a, [
        ['quantity' => '2', 'batch_number' => 'SCADUTO', 'batch_expiry' => $ieri],
        ['quantity' => '3', 'batch_number' => 'BUONO', 'batch_expiry' => $fraUnMese],
    ]);
    $livelli = Levels::of($a);
    $vendita = Levels::forSale([$a], Locations::forSale())[$a];
    $troppi = StockDocuments::create('issue', ['reason' => 'damaged'], [['product_id' => $a, 'quantity' => '4']], ['user_id' => 1]);
    $scelto = StockDocuments::create('issue', ['reason' => 'expired'], [['product_id' => $a, 'quantity' => '2', 'batch_id' => (string) lotto($a, 'SCADUTO')['id']]], ['user_id' => 1]);
    StockDocuments::confirm($scelto, ['user_id' => 1]);

    return (float) $livelli['quantity'] === 5.0 && (float) $livelli['available'] === 3.0
        && (float) $vendita['expired'] === 2.0 && (float) $vendita['available'] === 3.0
        && rifiuto(fn () => StockDocuments::confirm($troppi, ['user_id' => 1])) === 'stock.insufficient'
        && (float) secchi($a)[lotto($a, 'SCADUTO')['id'].'/0'] === 0.0;
}));

check('la ricerca delle versioni dice chi ha i lotti e da quali lotti può uscire la merce', fn () => prova(static function () use ($ieri, $fraUnMese): bool {
    accendiFunzionalita(['batch_tracking']);
    $a = conLotti('TST-LOT-8');
    carico($a, [
        ['quantity' => '1', 'batch_number' => 'B', 'batch_expiry' => $fraUnMese],
        ['quantity' => '2', 'batch_number' => 'A', 'batch_expiry' => $ieri],
    ]);
    $v = StockVersionSearch::describe([$a], Locations::mainId())[$a];

    return $v['tracks_batches'] === true
        && array_column($v['lots'], 'number') === ['A', 'B']
        && $v['lots'][0]['expired'] === true && $v['lots'][1]['expired'] === false;
}));

check('«Gestisci lotti e scadenze» non si spegne con pezzi in un lotto', fn () => prova(static function (): bool {
    accendiFunzionalita(['batch_tracking']);
    $a = conLotti('TST-LOT-9');
    $modello = modelloDi($a);
    $libero = rifiuto(fn () => ProductModelResource::assertBatchesOff($modello, ['track_batches' => 'false']));
    carico($a, [['quantity' => '1', 'batch_number' => 'L1']]);

    return $libero === null
        && rifiuto(fn () => ProductModelResource::assertBatchesOff($modello, ['track_batches' => 'false'])) === 'catalog.batches_in_stock';
}));

check('inerzia: funzionalità accesa, articolo senza lotti → carico e scarico come prima', fn () => prova(static function (): bool {
    accendiFunzionalita(['batch_tracking']);
    $a = articoloConGiacenza(1, 'TST-LOT-10');
    $id = carico($a, [['quantity' => '2', 'batch_number' => 'IGNORATO']]);
    $s = StockDocuments::create('issue', ['reason' => 'damaged'], [['product_id' => $a, 'quantity' => '1']], ['user_id' => 1]);
    StockDocuments::confirm($s, ['user_id' => 1]);
    $riga = StockDocumentItem::find(['stock_document_id' => $id, 'deleted' => 'false'], 1);

    return secchi($a) === ['0/0' => 2.0] && lotto($a, 'IGNORATO') === null && (int) $riga['batch_id'] === 0
        && !isset(StockVersionSearch::describe([$a], Locations::mainId())[$a]['lots'][0])
        && StockVersionSearch::describe([$a], Locations::mainId())[$a]['tracks_batches'] === false;
}));

check('inerzia: funzionalità spenta, articolo con i lotti → niente lotti, niente chiavi nuove', fn () => prova(static function (): bool {
    spegniFunzionalita(['batch_tracking']);
    $a = conLotti('TST-LOT-11');
    carico($a, [['quantity' => '2', 'batch_number' => 'IGNORATO']]);
    $v = StockVersionSearch::describe([$a], Locations::mainId())[$a];

    return secchi($a) === ['0/0' => 2.0] && lotto($a, 'IGNORATO') === null
        && !array_key_exists('tracks_batches', $v) && !array_key_exists('lots', $v);
}));

// --- 3b: pagina Lotti, clienti, riquadro ---

function rigaDOrdine(int $ordine, int $prodotto, float $pezzi): int
{
    return (int) (OrderItem::create([
        'order_id' => $ordine,
        'type' => 'product',
        'product_id' => $prodotto,
        'name' => 'Prova lotti',
        'list_price' => '1.00',
        'unit_price' => '1.00',
        'quantity' => number_format($pezzi, 3, '.', ''),
        'line_total' => '1.00',
        'position' => 1,
    ])->insert_id ?? 0);
}

check('clienti del lotto: la vendita conta, il reso si toglie, l\'ordine reso del tutto sparisce', fn () => prova(static function () use ($fraUnMese): bool {
    accendiFunzionalita(['batch_tracking']);
    $a = conLotti('TST-LOT-12');
    carico($a, [['quantity' => '5', 'batch_number' => 'RICHIAMO', 'batch_expiry' => $fraUnMese]]);
    $lotto = (int) lotto($a, 'RICHIAMO')['id'];
    $sede = Locations::forSale();
    $uno = ordineDiProva();
    Order::update(['order_number' => 'TST-1', 'email' => 'uno@example.com'], $uno);
    $due = ordineDiProva();
    $rigaUno = rigaDOrdine($uno, $a, 3);
    $rigaDue = rigaDOrdine($due, $a, 1);
    Allocation::commit(['product_id' => $a, 'quantity' => 3, 'location_id' => $sede, 'order_id' => $uno, 'order_item_id' => $rigaUno, 'payment_ok' => true]);
    Allocation::commit(['product_id' => $a, 'quantity' => 1, 'location_id' => $sede, 'order_id' => $due, 'order_item_id' => $rigaDue, 'payment_ok' => true]);
    Allocation::returnGoods(['product_id' => $a, 'quantity' => 1, 'location_id' => $sede, 'order_id' => $uno, 'order_item_id' => $rigaUno, 'sales_return_id' => resoDiProva($uno, $sede), 'restock' => true]);
    Allocation::returnGoods(['product_id' => $a, 'quantity' => 1, 'location_id' => $sede, 'order_id' => $due, 'order_item_id' => $rigaDue, 'sales_return_id' => resoDiProva($due, $sede), 'restock' => true]);
    $clienti = BatchCustomers::of($lotto);

    return count($clienti) === 1 && $clienti[0]['order_id'] === $uno && $clienti[0]['quantity'] === 2.0
        && $clienti[0]['email'] === 'uno@example.com'
        && str_contains(BatchCustomers::csv($clienti), 'TST-1;');
}));

check('un lotto con movimenti non si elimina; uno senza sì', fn () => prova(static function (): bool {
    accendiFunzionalita(['batch_tracking']);
    $a = conLotti('TST-LOT-13');
    carico($a, [['quantity' => '1', 'batch_number' => 'MOSSO']]);
    $vuoto = Batches::resolve($a, 'VUOTO', null, 0, 1);

    try {
        BatchResource::assertDeletable((int) lotto($a, 'MOSSO')['id']);
        $mosso = 'eliminabile';
    } catch (RuntimeException $e) {
        $mosso = 'rifiutato';
    }

    return $mosso === 'rifiutato' && rifiuto(fn () => BatchResource::assertDeletable($vuoto)) === null;
}));

check('riquadro: i lotti con pezzi scaduti o in scadenza, non quelli lontani né finiti', fn () => prova(static function () use ($ieri, $domani): bool {
    accendiFunzionalita(['batch_tracking']);
    $a = conLotti('TST-LOT-14');
    carico($a, [
        ['quantity' => '1', 'batch_number' => 'W-SCADUTO', 'batch_expiry' => $ieri],
        ['quantity' => '1', 'batch_number' => 'W-DOMANI', 'batch_expiry' => $domani],
        ['quantity' => '1', 'batch_number' => 'W-LONTANO', 'batch_expiry' => date('Y-m-d', strtotime('+400 days'))],
    ]);
    $html = (new ExpiringBatchesWidget())->render();

    return str_contains($html, 'W-SCADUTO') && str_contains($html, 'W-DOMANI') && !str_contains($html, 'W-LONTANO');
}));

check('riquadro e pagina Lotti non esistono a funzionalità spenta', fn () => prova(static function (): bool {
    spegniFunzionalita(['batch_tracking']);

    return (new ExpiringBatchesWidget())->render() === ''
        && !in_array(true, (array) BatchResource::pageSchema()->get('pages'), true);
}));

summary();
