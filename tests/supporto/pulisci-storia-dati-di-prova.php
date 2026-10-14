<?php
/**
 * php tests/supporto/pulisci-storia-dati-di-prova.php [--esegui]
 *
 * Libera gli articoli di prova dalla storia di magazzino **vera** che li tiene
 * sul sito di prova: le righe dei documenti di magazzino non di prova che li
 * nominano, i loro lotti e l'interruttore dei lotti. Dopo, la pulizia dei dati
 * di prova (`gestionale:demo --fresh`, i test) li toglie come gli altri.
 *
 * Solo per il sito di prova. Senza `--esegui` dice cosa farebbe e non cambia
 * niente. Le righe degli articoli veri restano dove sono: un documento si
 * cancella solo se non gli resta nessuna riga.
 */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\Batch;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Seeding\DemoCode;
use Wonder\Sql\Transaction;

$esegui = in_array('--esegui', $argv, true);

/** @return list<array<string, mixed>> */
function righe(mixed $found): array
{
    if (!is_array($found) || $found === []) {
        return [];
    }

    return isset($found['id']) ? [$found] : array_values(array_filter($found, 'is_array'));
}

$prefisso = str_replace('_', '\\_', DemoCode::prefixOf(ProductModel::class));
$modelli = righe(ProductModel::find("`code` LIKE '".$prefisso."demo-%' AND `deleted` IN ('true', 'false')"));
$modelIds = array_map(static fn (array $m): int => (int) $m['id'], $modelli);
$prodotti = $modelIds === [] ? [] : righe(Product::find('`product_model_id` IN ('.implode(',', $modelIds).") AND `deleted` IN ('true', 'false')"));
$ids = array_map(static fn (array $p): int => (int) $p['id'], $prodotti);

if ($ids === []) {
    echo "Nessun articolo di prova sul sito: niente da fare.\n";
    exit(0);
}

$in = implode(',', $ids);

// Le righe dei documenti veri sugli articoli di prova, per documento.
$perDocumento = [];

foreach (righe(StockDocumentItem::find('`product_id` IN ('.$in.") AND `deleted` IN ('true', 'false')")) as $riga) {
    $perDocumento[(int) $riga['stock_document_id']][] = (int) $riga['id'];
}

$documenti = [];

foreach ($perDocumento as $docId => $righeDoc) {
    $doc = StockDocument::findById($docId);

    if (!is_array($doc) || $doc === [] || DemoCode::is((string) ($doc['code'] ?? ''))) {
        continue;
    }

    $tutte = righe(StockDocumentItem::find('`stock_document_id` = '.$docId." AND `deleted` IN ('true', 'false')"));
    $documenti[$docId] = [
        'nome' => ($doc['number'] ?: '#'.$docId).' ('.$doc['type'].', '.$doc['status'].')',
        'righe' => $righeDoc,
        'intero' => count($tutte) === count($righeDoc),
    ];
}

$lotti = righe(Batch::find('`product_id` IN ('.$in.')'));
$conLotti = array_values(array_filter($modelli, static fn (array $m): bool => (string) ($m['track_batches'] ?? 'false') === 'true'));

echo ($esegui ? 'Pulizia' : 'Prova (niente cambia; per farlo davvero aggiungi --esegui)').":\n";

foreach ($documenti as $doc) {
    echo '· documento '.$doc['nome'].': '.count($doc['righe']).' righe di articoli di prova'
        .($doc['intero'] ? ', il documento se ne va' : ', il documento resta con le righe degli articoli veri')."\n";
}

echo '· lotti degli articoli di prova: '.($lotti === [] ? 'nessuno' : implode(', ', array_map(static fn (array $b): string => (string) $b['number'], $lotti)))."\n";
echo '· lotti spenti su: '.($conLotti === [] ? 'nessuno' : implode(', ', array_map(static fn (array $m): string => (string) $m['name'], $conLotti)))."\n";

if (!$esegui) {
    exit(0);
}

Transaction::run(static function () use ($documenti, $lotti, $conLotti, $in): void {
    foreach ($documenti as $docId => $doc) {
        // I movimenti degli articoli di prova di questo documento; quelli degli
        // articoli veri restano con le loro righe.
        sqlDelete(StockMovement::$table, "reference_type IN ('stock_document', 'stock_document_cancel') AND reference_id = ".$docId.' AND product_id IN ('.$in.')');
        sqlDelete(StockDocumentItem::$table, 'id IN ('.implode(',', $doc['righe']).')');

        if ($doc['intero']) {
            sqlDelete(StockMovement::$table, "reference_type IN ('stock_document', 'stock_document_cancel') AND reference_id = ".$docId);
            sqlDelete(StockDocumentStatusLog::$table, 'stock_document_id = '.$docId);
            sqlDelete(StockDocument::$table, 'id = '.$docId);
        }
    }

    foreach ($lotti as $lotto) {
        $id = (int) $lotto['id'];
        sqlDelete(StockRow::$table, 'batch_id = '.$id.' AND product_id IN ('.$in.')');
        sqlDelete(Batch::$table, 'id = '.$id);
    }

    foreach ($conLotti as $modello) {
        ProductModel::update(['track_batches' => 'false'], (int) $modello['id']);
    }
});

echo "Fatto. Adesso la pulizia dei dati di prova toglie anche questi articoli.\n";
