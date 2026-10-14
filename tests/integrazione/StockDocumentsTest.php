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
use Wonder\Plugin\Gestionale\Resources\Stock\StockIssueResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;
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

check('creare: un codice dato resta quello, senza codice ne nasce uno nuovo', function () {
    return prova(function (): bool {
        $prodotto = articolo();
        $dato = StockDocuments::create('receipt', [], [['product_id' => $prodotto, 'quantity' => 1]], ['code' => 'stk_prova-codice-dato']);
        $nuovo = StockDocuments::create('receipt', [], [['product_id' => $prodotto, 'quantity' => 1]]);

        return (string) StockDocument::find(['id' => $dato], 1)['code'] === 'stk_prova-codice-dato'
            && str_starts_with((string) StockDocument::find(['id' => $nuovo], 1)['code'], 'stk_')
            && (string) StockDocument::find(['id' => $nuovo], 1)['code'] !== 'stk_prova-codice-dato';
    });
});

check('creare: un tipo che non c\'è, una sede sconosciuta, una versione sparita sono rifiuti', function () {
    return prova(static function (): bool {
        $a = articolo();

        // `transfer` è un tipo vero da G3 (piano 4): il tipo che non c'è è un altro.
        return rifiuto(fn () => StockDocuments::create('regalo', [], [])) === 'stock_document.unknown_type'
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

/** Il numero che viene dopo `$numero` nella stessa sequenza: «2026/100007» → «2026/100008». */
function seguente(string $numero): string
{
    return substr($numero, 0, 7).str_pad((string) ((int) substr($numero, 7) + 1), 4, '0', STR_PAD_LEFT);
}

check('confermare: numero, data, stato, log e movimenti con il documento e chi conferma', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        $a = articolo(10);
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '5']], ['user_id' => 0]);
        $numero = StockDocuments::confirm($id, ['user_id' => $utente]);
        $doc = StockDocument::findById($id);
        $mov = righe(StockMovement::class, "reference_type = 'stock_document' AND reference_id = ".$id);
        $log = righe(StockDocumentStatusLog::class, 'stock_document_id = '.$id);

        return preg_match('#^\d{4}/\d{6,}$#', $numero) === 1
            && $doc['number'] === $numero
            && $doc['status'] === 'completed'
            && $doc['date'] === date('Y-m-d')
            && count($mov) === 1
            && $mov[0]['type'] === 'purchase'
            && (float) $mov[0]['quantity'] === 5.0
            && (int) $mov[0]['location_id'] === Locations::mainId()
            && (int) $mov[0]['user_id'] === $utente
            && giacenza($a) === 15.0
            && count($log) === 1
            && $log[0]['from_value'] === 'draft' && $log[0]['to_value'] === 'completed'
            && (int) $log[0]['user_id'] === $utente;
    });
});

check('confermare: lo scarico toglie con la sua causale', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('issue', ['reason' => 'expired'], [['product_id' => $a, 'quantity' => '3']]);
        StockDocuments::confirm($id);
        $mov = righe(StockMovement::class, "reference_type = 'stock_document' AND reference_id = ".$id);

        return count($mov) === 1
            && $mov[0]['type'] === 'adjustment'
            && $mov[0]['reason'] === 'expired'
            && (float) $mov[0]['quantity'] === -3.0
            && giacenza($a) === 7.0;
    });
});

check('confermare: ogni tipo ha la sua sequenza, senza buchi dopo una conferma fermata', function () {
    return prova(static function (): bool {
        [$a, $b] = [articolo(10), articolo(1)];
        $primo = StockDocuments::confirm(StockDocuments::create('issue', ['reason' => 'gift'], [['product_id' => $a, 'quantity' => '1']]));
        $fermo = StockDocuments::create('issue', ['reason' => 'gift'], [['product_id' => $b, 'quantity' => '5']]);
        $errore = rifiuto(fn () => StockDocuments::confirm($fermo));
        $secondo = StockDocuments::confirm(StockDocuments::create('issue', ['reason' => 'gift'], [['product_id' => $a, 'quantity' => '1']]));
        $carico = StockDocuments::confirm(StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '1']]));

        return $errore === 'stock.insufficient'
            && $secondo === seguente($primo)
            && $carico !== '';
    });
});

check('confermare: fermata da stock.insufficient non lascia numero, movimenti né log', function () {
    return prova(static function (): bool {
        [$a, $b] = [articolo(10), articolo(1)];
        $id = StockDocuments::create('issue', ['reason' => 'damaged'], [
            ['product_id' => $a, 'quantity' => '2'],
            ['product_id' => $b, 'quantity' => '5'],
        ]);
        $errore = rifiuto(fn () => StockDocuments::confirm($id));
        $doc = StockDocument::findById($id);

        return $errore === 'stock.insufficient'
            && $doc['status'] === 'draft'
            && in_array($doc['number'] ?? null, [null, ''], true)
            && righe(StockMovement::class, "reference_type = 'stock_document' AND reference_id = ".$id) === []
            && righe(StockDocumentStatusLog::class, 'stock_document_id = '.$id) === []
            && count(StockDocuments::items($id)) === 2
            && giacenza($a) === 10.0;
    });
});

check('confermare: quello che manca è un rifiuto, il documento resta bozza', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $senzaCausale = StockDocuments::create('issue', [], [['product_id' => $a, 'quantity' => '1']]);
        $vuoto = StockDocuments::create('receipt', [], []);
        $aZero = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '0']]);
        $nonContato = StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '']]);

        return rifiuto(fn () => StockDocuments::confirm($senzaCausale)) === 'stock_document.reason_required'
            && rifiuto(fn () => StockDocuments::confirm($vuoto)) === 'stock_document.no_rows'
            && rifiuto(fn () => StockDocuments::confirm($aZero)) === 'stock_document.quantity_required'
            && rifiuto(fn () => StockDocuments::confirm($nonContato)) === 'stock_document.nothing_counted'
            && StockDocument::findById($aZero)['status'] === 'draft';
    });
});

check('confermare due volte: la seconda è un rifiuto e non muove niente', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '5']]);
        $numero = StockDocuments::confirm($id);

        return rifiuto(fn () => StockDocuments::confirm($id)) === 'stock_document.not_draft'
            && StockDocument::findById($id)['number'] === $numero
            && count(righe(StockMovement::class, "reference_type = 'stock_document' AND reference_id = ".$id)) === 1
            && giacenza($a) === 15.0;
    });
});

check('confermato: non si salva più', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '5']]);
        StockDocuments::confirm($id);

        return rifiuto(fn () => StockDocuments::saveDraft($id, [], [['product_id' => $a, 'quantity' => '9']])) === 'stock_document.not_draft';
    });
});

check('inventario: muove solo le righe contate con una differenza', function () {
    return prova(static function (): bool {
        [$a, $b, $c] = [articolo(10), articolo(5), articolo(3)];
        $id = StockDocuments::create('stocktake', [], [
            ['product_id' => $a, 'quantity' => '7'],
            ['product_id' => $b, 'quantity' => '5'],
            ['product_id' => $c, 'quantity' => ''],
        ]);
        StockDocuments::confirm($id);
        $mov = righe(StockMovement::class, "reference_type = 'stock_document' AND reference_id = ".$id);

        return count($mov) === 1
            && (int) $mov[0]['product_id'] === $a
            && $mov[0]['reason'] === 'inventory'
            && (float) $mov[0]['quantity'] === -3.0
            && giacenza($a) === 7.0 && giacenza($b) === 5.0 && giacenza($c) === 3.0;
    });
});

check('inventario: una vendita fra il salvataggio della conta e la conferma resta', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '8']]);
        Stock::apply(['product_id' => $a, 'quantity' => -2, 'type' => 'sale']);
        StockDocuments::confirm($id);

        // Contati 8 su attesi 10: −2 sopra la giacenza di adesso (8) → 6.
        return giacenza($a) === 6.0;
    });
});

check('annullare: uno storno per movimento, segno opposto, con il suo riferimento', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$a, $b] = [articolo(10), articolo(5)];
        $id = StockDocuments::create('stocktake', [], [
            ['product_id' => $a, 'quantity' => '7'],
            ['product_id' => $b, 'quantity' => '8'],
        ]);
        StockDocuments::confirm($id);
        StockDocuments::cancel($id, ['user_id' => $utente]);
        $storni = righe(StockMovement::class, "reference_type = 'stock_document_cancel' AND reference_id = ".$id);
        $log = righe(StockDocumentStatusLog::class, 'stock_document_id = '.$id);
        $perProdotto = array_column($storni, 'quantity', 'product_id');

        return count($storni) === 2
            && (float) $perProdotto[$a] === 3.0
            && (float) $perProdotto[$b] === -3.0
            && $storni[0]['type'] === 'adjustment' && $storni[0]['reason'] === 'inventory'
            && (int) $storni[0]['user_id'] === $utente
            && giacenza($a) === 10.0 && giacenza($b) === 5.0
            && StockDocument::findById($id)['status'] === 'cancelled'
            && end($log)['from_value'] === 'completed' && end($log)['to_value'] === 'cancelled';
    });
});

check('confermare: i movimenti seguono l\'ordine delle versioni, non quello delle righe', function () {
    return prova(static function (): bool {
        [$a, $b] = [articolo(10), articolo(10)];
        $id = StockDocuments::create('receipt', [], [
            ['product_id' => $b, 'quantity' => '1'],
            ['product_id' => $a, 'quantity' => '2'],
        ]);
        StockDocuments::confirm($id);
        $mov = righe(StockMovement::class, "reference_type = 'stock_document' AND reference_id = ".$id);

        return array_map('intval', array_column($mov, 'product_id')) === [$a, $b];
    });
});

check('annullare: gli storni seguono l\'ordine delle versioni', function () {
    return prova(static function (): bool {
        [$a, $b] = [articolo(10), articolo(10)];
        $id = StockDocuments::create('receipt', [], [
            ['product_id' => $b, 'quantity' => '1'],
            ['product_id' => $a, 'quantity' => '2'],
        ]);
        StockDocuments::confirm($id);
        StockDocuments::cancel($id);
        $storni = righe(StockMovement::class, "reference_type = 'stock_document_cancel' AND reference_id = ".$id);

        return array_map('intval', array_column($storni, 'product_id')) === [$a, $b];
    });
});

check('annullare: lo storno di un carico già venduto si ferma e il documento resta confermato', function () {
    return prova(static function (): bool {
        $a = articolo(0);
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '5']]);
        StockDocuments::confirm($id);
        Stock::apply(['product_id' => $a, 'quantity' => -4, 'type' => 'sale']);
        $errore = rifiuto(fn () => StockDocuments::cancel($id));

        return $errore === 'stock.insufficient'
            && StockDocument::findById($id)['status'] === 'completed'
            && righe(StockMovement::class, "reference_type = 'stock_document_cancel' AND reference_id = ".$id) === []
            && count(righe(StockDocumentStatusLog::class, 'stock_document_id = '.$id)) === 1
            && giacenza($a) === 1.0;
    });
});

check('annullare: solo un confermato, e una volta sola', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $bozza = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '5']]);
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '5']]);
        StockDocuments::confirm($id);
        StockDocuments::cancel($id);

        return rifiuto(fn () => StockDocuments::cancel($bozza)) === 'stock_document.cannot_cancel'
            && rifiuto(fn () => StockDocuments::cancel($id)) === 'stock_document.cannot_cancel'
            && count(righe(StockMovement::class, "reference_type = 'stock_document_cancel' AND reference_id = ".$id)) === 1
            && giacenza($a) === 10.0;
    });
});

/** Il messaggio del rifiuto per l'endpoint che cancella, o null se non c'è stato. */
function rifiutoDiCancellazione(callable $fn): ?string
{
    try {
        $fn();
    } catch (RuntimeException $errore) {
        return $errore->getMessage();
    }

    return null;
}

check('duplicare: una bozza nuova con sede, causale, nota e righe; senza numero', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$a, $b] = [articolo(10), articolo(10)];
        $id = StockDocuments::create('issue', ['reason' => 'internal_use', 'note' => 'Vetrina'], [
            ['product_id' => $a, 'quantity' => '2'],
            ['product_id' => $b, 'quantity' => '1'],
        ]);
        StockDocuments::confirm($id);
        StockDocuments::cancel($id);
        $copia = StockDocuments::duplicate($id, ['user_id' => $utente]);
        $doc = StockDocument::findById($copia);
        $righe = StockDocuments::items($copia);

        return $copia !== $id
            && $doc['type'] === 'issue' && $doc['status'] === 'draft'
            && in_array($doc['number'] ?? null, [null, ''], true)
            && $doc['reason'] === 'internal_use' && $doc['note'] === 'Vetrina'
            && (int) $doc['user_id'] === $utente
            && array_column($righe, 'product_id') === [$a, $b]
            && array_column($righe, 'quantity') === [2.0, 1.0];
    });
});

check('duplicare: l\'inventario rilegge gli attesi di adesso', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '8']]);
        Stock::apply(['product_id' => $a, 'quantity' => -3, 'type' => 'sale']);
        $copia = StockDocuments::duplicate($id);

        return StockDocuments::items($id)[0]['expected_quantity'] === 10.0
            && StockDocuments::items($copia)[0]['expected_quantity'] === 7.0
            && StockDocuments::items($copia)[0]['quantity'] === 8.0;
    });
});

check('duplicare: le versioni sparite si saltano', function () {
    return prova(static function (): bool {
        [$a, $b] = [articolo(10), articolo(10)];
        $id = StockDocuments::create('receipt', [], [
            ['product_id' => $a, 'quantity' => '2'],
            ['product_id' => $b, 'quantity' => '1'],
        ]);
        sqlModify(\Wonder\Plugin\Gestionale\Models\Catalog\Product::$table, ['deleted' => 'true'], 'id', $b);
        $copia = StockDocuments::duplicate($id);

        return array_column(StockDocuments::items($copia), 'product_id') === [$a];
    });
});

check('eliminare: una bozza sparisce con le sue righe', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '2']]);
        StockDocuments::assertDeletable($id);
        StockDocuments::delete($id);

        return StockDocument::findById($id) === [] || StockDocument::findById($id) === null
            ? righe(StockDocumentItem::class, 'stock_document_id = '.$id) === []
            : false;
    });
});

check('eliminare: un confermato o un annullato no, e lo dice', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '2']]);
        StockDocuments::confirm($id);
        $testo = UserError::make('stock_document.cannot_delete')->getMessage();
        $primo = rifiutoDiCancellazione(fn () => StockDocuments::assertDeletable($id));
        StockDocuments::cancel($id);

        return $primo === $testo
            && rifiutoDiCancellazione(fn () => StockDocuments::assertDeletable($id)) === $testo
            && rifiuto(fn () => StockDocuments::delete($id)) === 'stock_document.cannot_delete'
            && StockDocument::findById($id)['status'] === 'cancelled';
    });
});

check('eliminare: un documento che non c\'è è un rifiuto', fn () =>
    rifiutoDiCancellazione(fn () => StockDocuments::assertDeletable(999999999))
        === UserError::make('stock_document.not_found')->getMessage()
);

check('Movimenti: la cella Tipo legge il documento e lo storno', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('issue', ['reason' => 'damaged'], [['product_id' => $a, 'quantity' => '1']]);
        $numero = StockDocuments::confirm($id);
        StockDocuments::cancel($id);
        $mov = righe(StockMovement::class, "reference_type = 'stock_document' AND reference_id = ".$id)[0];
        $storno = righe(StockMovement::class, "reference_type = 'stock_document_cancel' AND reference_id = ".$id)[0];

        // Il numero apre la scheda dello scarico, anche dallo storno.
        $link = '<a href="'.htmlspecialchars(StockIssueResource::viewUrl($id), ENT_QUOTES).'">'.htmlspecialchars($numero, ENT_QUOTES).'</a>';

        return StockMovementResource::typeCellOf($mov) === 'Scarico · '.$link
            && StockMovementResource::typeCellOf($storno) === 'Annullamento dello scarico · '.$link;
    });
});

check('Movimenti: il filtro Tipo trova nel database le righe giuste', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $scarico = StockDocuments::create('issue', ['reason' => 'gift'], [['product_id' => $a, 'quantity' => '1']]);
        StockDocuments::confirm($scarico);
        StockDocuments::cancel($scarico);
        StockDocuments::confirm(StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '4']]));
        Stock::apply(['product_id' => $a, 'quantity' => 1, 'type' => 'adjustment', 'reason' => 'other']);
        // Una query diretta, come fa SSP nell'elenco: Model::find non aggiunge il suo
        // WHERE a una condizione che ne contiene già uno (la sottoquery).
        $trova = static fn (string $voce): array => array_map(
            static fn (array $r): string => $r['type'].'|'.($r['reference_type'] ?? ''),
            StockMovement::query()->mysqli->query(
                'SELECT `type`, `reference_type` FROM `'.StockMovement::$table.'` WHERE `product_id` = '.$a
                .' AND '.StockMovementResource::typeCondition([$voce]).' ORDER BY `id`'
            )->fetch_all(MYSQLI_ASSOC)
        );

        return $trova('doc_issue') === ['adjustment|stock_document', 'adjustment|stock_document_cancel']
            && $trova('doc_stocktake') === ['adjustment|stock_document']
            && $trova('doc_receipt') === ['purchase|']
            && $trova('adjustment') === ['adjustment|'];
    });
});

summary();
