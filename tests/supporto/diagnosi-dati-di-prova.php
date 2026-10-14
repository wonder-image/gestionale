<?php
/**
 * php tests/supporto/diagnosi-dati-di-prova.php
 *
 * Perché un articolo di prova non se ne va dal sito di prova. Dice, per ogni
 * articolo di prova, se ha i lotti accesi, quali lotti ha, e quali documenti
 * di magazzino e ordini **non di prova** usano le sue versioni; poi prova la
 * pulizia completa (come `gestionale:demo --fresh`) e riporta le sue note.
 *
 * Non cambia niente: la pulizia gira in una transazione annullata alla fine.
 */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Stock\Batch;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Seeding\Demo;
use Wonder\Plugin\Gestionale\Seeding\DemoCode;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentFiles;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

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

echo "Articoli di prova sul sito: ".count($modelli)."\n";

foreach ($modelli as $modello) {
    $versioni = righe(Product::find('`product_model_id` = '.(int) $modello['id']." AND `deleted` IN ('true', 'false')"));
    $ids = array_map(static fn (array $p): int => (int) $p['id'], $versioni);
    $in = $ids === [] ? '0' : implode(',', $ids);

    $lotti = righe(Batch::find('`product_id` IN ('.$in.')'));
    $documenti = [];

    foreach (righe(StockDocumentItem::find('`product_id` IN ('.$in.")")) as $riga) {
        $doc = StockDocument::findById((int) $riga['stock_document_id']);

        if (is_array($doc) && $doc !== [] && !DemoCode::is((string) ($doc['code'] ?? ''))) {
            $documenti[(int) $doc['id']] = ($doc['number'] ?: '#'.$doc['id']).' ('.$doc['type'].', '.$doc['status'].($doc['deleted'] === 'true' ? ', eliminato' : '').')';
        }
    }

    $ordini = [];

    foreach (righe(OrderItem::find('`product_id` IN ('.$in.")")) as $riga) {
        $ordine = Order::findById((int) $riga['order_id']);

        if (is_array($ordine) && $ordine !== [] && !DemoCode::is((string) ($ordine['code'] ?? ''))) {
            $ordini[(int) $ordine['id']] = ($ordine['order_number'] ?: '#'.$ordine['id']).' ('.$ordine['stage'].', '.$ordine['status'].', '.$ordine['channel'].')';
        }
    }

    echo "\n· ".(string) $modello['name'];
    echo ($modello['deleted'] === 'true' ? ' [eliminato]' : '').' — lotti accesi: '.(string) ($modello['track_batches'] ?? 'false')."\n";
    echo '  lotti: '.($lotti === [] ? 'nessuno' : implode(', ', array_map(static fn (array $b): string => (string) $b['number'], $lotti)))."\n";
    echo '  documenti di magazzino non di prova: '.($documenti === [] ? 'nessuno' : implode(', ', $documenti))."\n";
    echo '  ordini non di prova: '.($ordini === [] ? 'nessuno' : implode(', ', $ordini))."\n";
}

echo "\nPulizia completa di prova (annullata alla fine):\n";

// I file non stanno nella transazione: le foto si fotografano e tornano, i
// PDF dei documenti si cercano in una cartella vuota, così non se ne tocca nessuno.
$foto = Istantanea::di(rtrim((string) $GLOBALS['ROOT'], '/').'/assets/upload'.ProductImages::folder());
DocumentFiles::useBase(sys_get_temp_dir().'/wi-diagnosi-'.getmypid());

try {
    Transaction::run(static function (): void {
        DemoData::reset();
        Demo::registerAll();

        foreach (DemoData::inClearOrder() as $dati) {
            $tolti = (int) ($dati['clear'])();
            echo '· '.$dati['title'].': '.$tolti." tolti\n";

            foreach (DemoData::notes() as $nota) {
                echo '    nota: '.$nota."\n";
            }
        }

        throw new Annulla();
    });
} catch (Annulla) {
} finally {
    DocumentFiles::useBase(null);
    $foto->ripristina();
}

echo "\nFatto: niente è cambiato sul sito.\n";
