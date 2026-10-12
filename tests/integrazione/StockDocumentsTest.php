<?php
/** php tests/integrazione/StockDocumentsTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\App\Models\Config\SocietyLocation;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Esegue `$corpo` in una transazione che poi si annulla: il database resta com'era. */
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

    return $esito;
}

/** La chiave del rifiuto, o null se non c'è stato. */
function rifiuto(callable $fn): ?string
{
    try {
        $fn();
    } catch (UserError $errore) {
        return $errore->key();
    }

    return null;
}

/** @return list<array<string, mixed>> */
function righe(string $classe, string $where): array
{
    $r = $classe::find($where, null, 'id', 'ASC');

    if (!is_array($r) || $r === []) {
        return [];
    }

    return isset($r['id']) ? [$r] : array_values(array_filter($r, 'is_array'));
}

function articolo(float $pezzi = 10): int
{
    return articoloConGiacenza($pezzi, 'TST-DOC-'.substr((string) microtime(true), -6).random_int(10, 99));
}

function giacenza(int $prodotto, int $sede = 0): float
{
    $sede = $sede ?: Locations::mainId();

    return (float) (Levels::byLocationForProducts([$prodotto])[$prodotto][$sede]['quantity'] ?? 0);
}

/** Una seconda sede che tiene merce: la riga del core e quella del gestionale. */
function secondaSede(): int
{
    $core = SocietyLocation::create([
        'label' => 'Magazzino prova documenti',
        'slug' => Slug::make('magazzino-prova-documenti-'.uniqid()),
        'position' => 99,
        'visible' => 'true',
    ]);
    $riga = Location::create(['society_location_id' => (int) ($core->insert_id ?? 0), 'has_stock' => 'true', 'active' => 'true']);

    return (int) ($riga->insert_id ?? 0);
}

$utente = 1;

check('creare: la bozza nasce senza numero né data, con le sue righe in ordine', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$a, $b] = [articolo(), articolo()];
        $id = StockDocuments::create('receipt', ['note' => ' Merce di ottobre '], [
            ['product_id' => $b, 'quantity' => '3'],
            ['product_id' => 0, 'quantity' => ''],
            ['product_id' => $a, 'quantity' => '2,5'],
        ], ['user_id' => $utente]);
        $doc = StockDocument::findById($id);
        $righe = StockDocuments::items($id);

        return $doc['status'] === 'draft'
            && in_array($doc['number'] ?? null, [null, ''], true)
            && in_array($doc['date'] ?? null, [null, ''], true)
            && (int) $doc['location_id'] === Locations::mainId()
            && (int) $doc['user_id'] === $utente
            && $doc['note'] === 'Merce di ottobre'
            && str_starts_with((string) $doc['code'], 'stk_')
            && count($righe) === 2
            && $righe[0]['product_id'] === $b && $righe[0]['quantity'] === 3.0 && $righe[0]['position'] === 1
            && $righe[1]['product_id'] === $a && $righe[1]['quantity'] === 2.5 && $righe[1]['position'] === 2
            && $righe[0]['expected_quantity'] === null;
    });
});

check('creare: la bozza non muove la giacenza', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        StockDocuments::create('issue', ['reason' => 'damaged'], [['product_id' => $a, 'quantity' => '4']]);

        return giacenza($a) === 10.0;
    });
});

check('creare: un tipo che non c\'è, una sede sconosciuta, una versione sparita sono rifiuti', function () {
    return prova(static function (): bool {
        $a = articolo();

        return rifiuto(fn () => StockDocuments::create('transfer', [], [])) === 'stock_document.unknown_type'
            && rifiuto(fn () => StockDocuments::create('receipt', ['location_id' => 999999], [])) === 'stock_document.location_unknown'
            && rifiuto(fn () => StockDocuments::create('receipt', [], [['product_id' => 999999, 'quantity' => '1']])) === 'stock.product_missing'
            && rifiuto(fn () => StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '1'], ['product_id' => $a, 'quantity' => '2']])) === 'stock_document.duplicate_product';
    });
});

check('inventario: la riga non contata resta vuota, NULL e non zero, anche l\'atteso', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '']]);
        $riga = righe(StockDocumentItem::class, 'stock_document_id = '.$id)[0] ?? [];
        $doc = StockDocument::findById($id);

        return array_key_exists('quantity', $riga) && $riga['quantity'] === null
            && $riga['expected_quantity'] === null
            && $doc['reason'] === 'inventory';
    });
});

check('inventario: l\'atteso è la giacenza della sede quando si salva il contato', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '8']]);

        return StockDocuments::items($id)[0]['expected_quantity'] === 10.0;
    });
});

check('inventario: lo stesso contato salvato di nuovo tiene l\'atteso di prima', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '8']]);
        Stock::apply(['product_id' => $a, 'quantity' => -2, 'type' => 'sale']);
        StockDocuments::saveDraft($id, [], [['product_id' => $a, 'quantity' => '8']]);
        $tenuto = StockDocuments::items($id)[0]['expected_quantity'];
        StockDocuments::saveDraft($id, [], [['product_id' => $a, 'quantity' => '7']]);

        return $tenuto === 10.0 && StockDocuments::items($id)[0]['expected_quantity'] === 8.0;
    });
});

check('inventario: cambiando sede l\'atteso si rilegge da quella sede', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $altra = secondaSede();
        Stock::apply(['product_id' => $a, 'quantity' => 4, 'type' => 'purchase', 'location_id' => $altra]);
        $id = StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '9']]);
        $prima = StockDocuments::items($id)[0]['expected_quantity'];
        StockDocuments::saveDraft($id, ['location_id' => $altra], [['product_id' => $a, 'quantity' => '9']]);

        return $prima === 10.0
            && StockDocuments::items($id)[0]['expected_quantity'] === 4.0
            && (int) StockDocument::findById($id)['location_id'] === $altra;
    });
});

check('salvare: le chiavi che mancano tengono il valore di prima, le righe si riscrivono', function () {
    return prova(static function (): bool {
        [$a, $b] = [articolo(), articolo()];
        $id = StockDocuments::create('issue', ['reason' => 'gift', 'note' => 'Omaggi'], [['product_id' => $a, 'quantity' => '1']]);
        StockDocuments::saveDraft($id, ['note' => 'Omaggi fiera'], [['product_id' => $b, 'quantity' => '2']]);
        $doc = StockDocument::findById($id);
        $righe = StockDocuments::items($id);

        return $doc['reason'] === 'gift'
            && $doc['note'] === 'Omaggi fiera'
            && count($righe) === 1 && $righe[0]['product_id'] === $b && $righe[0]['quantity'] === 2.0;
    });
});

check('salvare: una causale che non va con lo scarico è un rifiuto e la bozza resta com\'era', function () {
    return prova(static function (): bool {
        $a = articolo();
        $id = StockDocuments::create('issue', ['reason' => 'gift'], [['product_id' => $a, 'quantity' => '1']]);

        return rifiuto(fn () => StockDocuments::saveDraft($id, ['reason' => 'initial_stock'], [])) === 'stock_document.unknown_reason'
            && StockDocument::findById($id)['reason'] === 'gift'
            && count(StockDocuments::items($id)) === 1;
    });
});

check('salvare: 501 righe sono un rifiuto', function () {
    return prova(static function (): bool {
        $righe = array_map(static fn (int $i): array => ['product_id' => $i, 'quantity' => '1'], range(1, 501));

        return rifiuto(fn () => StockDocuments::create('receipt', [], $righe)) === 'stock_document.too_many_rows';
    });
});

check('salvare: un documento che non c\'è è un rifiuto', fn () =>
    rifiuto(fn () => StockDocuments::saveDraft(999999999, [], [])) === 'stock_document.not_found'
);

summary();
