<?php
/** php tests/integrazione/StockDocumentListTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\App\Models\User\User;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Resources\Stock\StockDocumentResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockIssueResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockReceiptResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockStocktakeResource;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;
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

    return $esito;
}

function articolo(float $pezzi = 10): int
{
    return articoloConGiacenza($pezzi, 'TST-ELE-'.substr((string) microtime(true), -6).random_int(10, 99));
}

/** La riga dell'elenco come la legge il core: le colonne del documento più la `select()`. */
function rigaElenco(int $id, string $resource = StockReceiptResource::class): array
{
    $t = '`'.StockDocument::$table.'`';
    $r = StockDocument::connection()->query('SELECT '.$t.'.*, '.$resource::select().' FROM '.$t.' WHERE '.$t.'.`id` = '.$id);

    return $r ? (array) $r->fetch_assoc() : [];
}

function nomeUtente(int $id): string
{
    $u = User::findById($id);
    $nome = trim(trim((string) ($u['name'] ?? '')).' '.trim((string) ($u['surname'] ?? '')));

    return $nome !== '' ? $nome : (string) ($u['username'] ?? '');
}

$utente = 1;
$altro = (int) (User::find("`id` <> 1 AND `deleted` = 'false'", 1)['id'] ?? 0);

check('elenco: righe, pezzi, data della bozza e chi l\'ha creata', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$a, $b] = [articolo(), articolo()];
        $id = StockDocuments::create('receipt', [], [
            ['product_id' => $a, 'quantity' => '2,5'],
            ['product_id' => $b, 'quantity' => '3'],
        ], ['user_id' => $utente]);
        $r = rigaElenco($id);

        return (int) $r['row_count'] === 2
            && (float) $r['piece_count'] === 5.5
            && str_starts_with((string) $r['sort_date'], date('Y-m-d').' ')
            && $r['who_name'] === nomeUtente($utente);
    });
});

check('elenco inventari: righe, contate e con differenza al posto dei pezzi', function () {
    return prova(static function (): bool {
        [$a, $b, $c] = [articolo(5), articolo(3), articolo(2)];
        $id = StockDocuments::create('stocktake', [], [
            ['product_id' => $a, 'quantity' => '4'],
            ['product_id' => $b, 'quantity' => '3'],
            ['product_id' => $c, 'quantity' => ''],
        ]);
        $r = rigaElenco($id, StockStocktakeResource::class);
        $colonne = json_encode(StockStocktakeResource::tableSchema());

        return (int) $r['row_count'] === 3
            && (int) $r['counted_count'] === 2
            && (int) $r['difference_count'] === 1
            && !array_key_exists('piece_count', $r)
            && str_contains($colonne, 'counted_count') && str_contains($colonne, 'difference_count')
            && !str_contains($colonne, 'piece_count');
    });
});

check('elenco: una bozza senza righe ha 0 righe e 0 pezzi', function () {
    return prova(static function (): bool {
        $r = rigaElenco(StockDocuments::create('receipt', [], []));

        return (int) $r['row_count'] === 0 && (float) $r['piece_count'] === 0.0 && $r['who_name'] === null;
    });
});

check('elenco: un confermato ha chi l\'ha confermato, non chi l\'ha creato', function () use ($utente, $altro) {
    if ($altro === 0) {
        return true; // Un solo utente nel DB di prova: il caso non si costruisce.
    }

    return prova(static function () use ($utente, $altro): bool {
        $id = StockDocuments::create('receipt', [], [['product_id' => articolo(), 'quantity' => '1']], ['user_id' => $utente]);
        StockDocuments::confirm($id, ['user_id' => $altro]);

        return rigaElenco($id)['who_name'] === nomeUtente($altro);
    });
});

check('elenco: l\'ordine va per data e poi per documento, i più recenti in cima', function () {
    return prova(static function (): bool {
        $primo = StockDocuments::create('receipt', [], []);
        $secondo = StockDocuments::create('receipt', [], []);

        return strcmp((string) rigaElenco($secondo)['sort_date'], (string) rigaElenco($primo)['sort_date']) > 0;
    });
});

check('periodo: «Oggi» prende la bozza di oggi, «Mese scorso» no; vuoto non filtra', function () {
    return prova(static function (): bool {
        $id = StockDocuments::create('receipt', [], []);
        $adesso = new DateTimeImmutable('now');
        $conta = static function (string $chiave) use ($id, $adesso): int {
            $condizione = StockReceiptResource::periodCondition([$chiave], $adesso);
            $r = StockDocument::connection()->query('SELECT COUNT(*) AS n FROM `'.StockDocument::$table.'` WHERE `id` = '.$id.($condizione !== '' ? ' AND '.$condizione : ''));

            return (int) ($r ? $r->fetch_assoc()['n'] : -1);
        };

        return $conta('oggi') === 1
            && $conta('mese-scorso') === 0
            && $conta('') === 1
            && StockReceiptResource::periodCondition(["x' OR '1'='1"], $adesso) === '';
    });
});

check('elimina: una bozza sì, con le righe; un confermato è un rifiuto', function () {
    return prova(static function (): bool {
        $bozza = StockDocuments::create('receipt', [], [['product_id' => articolo(), 'quantity' => '1']]);
        $esito = StockReceiptResource::deleteRecord($bozza);
        $confermato = StockDocuments::create('receipt', [], [['product_id' => articolo(), 'quantity' => '1']]);
        StockDocuments::confirm($confermato);

        try {
            StockReceiptResource::deleteRecord($confermato);
            $rifiutato = false;
        } catch (RuntimeException) {
            $rifiutato = true;
        }

        return !empty($esito->success)
            && StockDocument::findById($bozza) === []
            && StockDocuments::items($bozza) === []
            && $rifiutato
            && StockDocument::findById($confermato)['status'] === 'completed';
    });
});

check('tipi: ogni Resource vede il suo, la pagina giusta per lo stato', function () {
    $carico = ['id' => 7, 'type' => 'receipt', 'status' => 'draft'];
    $scarico = ['id' => 8, 'type' => 'issue', 'status' => 'completed'];

    return StockReceiptResource::type() === 'receipt'
        && StockIssueResource::type() === 'issue'
        && StockDocumentResource::classFor('receipt') === StockReceiptResource::class
        && StockDocumentResource::classFor('issue') === StockIssueResource::class
        && StockDocumentResource::classFor('stocktake') === StockStocktakeResource::class
        && StockStocktakeResource::type() === 'stocktake'
        && StockStocktakeResource::newLabel() === 'Nuovo inventario'
        && StockStocktakeResource::confirmLabel() === 'Conferma inventario'
        && StockDocumentResource::pageUrl($carico) === StockReceiptResource::editUrl(7)
        && StockDocumentResource::pageUrl($scarico) === StockIssueResource::viewUrl(8)
        && str_contains(StockReceiptResource::editUrl(7), '/7/edit')
        && str_contains(StockIssueResource::viewUrl(8), '/8')
        && StockReceiptResource::newLabel() === 'Nuovo carico'
        && StockIssueResource::confirmLabel() === 'Conferma scarico'
        && str_contains(json_encode(StockReceiptResource::querySchema()), "receipt")
        && str_contains(json_encode(StockIssueResource::querySchema()), "issue");
});

summary();
