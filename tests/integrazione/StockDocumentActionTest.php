<?php
/** php tests/integrazione/StockDocumentActionTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\App\Models\User\User;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Stock\StockDocumentActionResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockIssueResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockReceiptResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockStocktakeResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentSheet;
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
    return articoloConGiacenza($pezzi, 'TST-AZN-'.substr((string) microtime(true), -6).random_int(10, 99));
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

function storni(int $documento): int
{
    return count(righe(StockMovement::class, "`reference_type` = 'stock_document_cancel' AND `reference_id` = ".$documento." AND `deleted` = 'false'"));
}

function nomeUtente(int $id): string
{
    $u = User::findById($id);
    $nome = trim(trim((string) ($u['name'] ?? '')).' '.trim((string) ($u['surname'] ?? '')));

    return $nome !== '' ? $nome : (string) ($u['username'] ?? '');
}

function messaggio(string $chiave): string
{
    return UserError::make($chiave)->getMessage();
}

/** Un carico confermato con una riga; restituisce id e numero. */
function caricoConfermato(int $articolo, string $pezzi, int $utente): array
{
    $id = StockDocuments::create('receipt', ['note' => 'Bolla 7'], [['product_id' => $articolo, 'quantity' => $pezzi]], ['user_id' => $utente]);

    return [$id, StockDocuments::confirm($id, ['user_id' => $utente])];
}

$utente = 1;
$altro = (int) (User::find("`id` <> 1 AND `deleted` = 'false'", 1)['id'] ?? 0);
$oggi = date('d/m/Y');
$annulla = StockDocumentActionResource::CANCEL;
$duplica = StockDocumentActionResource::DUPLICATE;

check('annulla: il carico confermato diventa annullato, con gli storni, e si torna alla scheda', function () use ($utente, $annulla) {
    return prova(static function () use ($utente, $annulla): bool {
        [$id, $numero] = caricoConfermato(articolo(0), '3', $utente);
        $esito = StockDocumentActionResource::run($annulla, $id, $utente);

        return $esito === ['ok' => true, 'message' => 'Il carico '.$numero.' è annullato.', 'url' => StockReceiptResource::viewUrl($id)]
            && StockDocument::findById($id)['status'] === 'cancelled'
            && storni($id) === 1;
    });
});

check('annulla: lo scarico dice «Lo scarico … è annullato.»', function () use ($utente, $annulla) {
    return prova(static function () use ($utente, $annulla): bool {
        $id = StockDocuments::create('issue', ['reason' => 'internal_use'], [['product_id' => articolo(5), 'quantity' => '2']]);
        $numero = StockDocuments::confirm($id, ['user_id' => $utente]);
        $esito = StockDocumentActionResource::run($annulla, $id, $utente);

        return $esito['ok'] && $esito['message'] === 'Lo scarico '.$numero.' è annullato.'
            && $esito['url'] === StockIssueResource::viewUrl($id);
    });
});

check('annulla due volte (due schede): la seconda è il rifiuto cannot_cancel, niente storni in più', function () use ($utente, $annulla) {
    return prova(static function () use ($utente, $annulla): bool {
        [$id] = caricoConfermato(articolo(0), '3', $utente);
        StockDocumentActionResource::run($annulla, $id, $utente);
        $seconda = StockDocumentActionResource::run($annulla, $id, $utente);

        return $seconda === ['ok' => false, 'message' => messaggio('stock_document.cannot_cancel'), 'url' => StockReceiptResource::viewUrl($id)]
            && storni($id) === 1;
    });
});

check('annulla su una bozza: cannot_cancel e si torna alla bozza', function () use ($utente, $annulla) {
    return prova(static function () use ($utente, $annulla): bool {
        $id = StockDocuments::create('receipt', [], [['product_id' => articolo(), 'quantity' => '1']]);

        return StockDocumentActionResource::run($annulla, $id, $utente)
            === ['ok' => false, 'message' => messaggio('stock_document.cannot_cancel'), 'url' => StockReceiptResource::editUrl($id)];
    });
});

check('annulla fermato dai pezzi già venduti: l\'errore del servizio, il documento resta confermato', function () use ($utente, $annulla) {
    return prova(static function () use ($utente, $annulla): bool {
        $a = articolo(0);
        [$id] = caricoConfermato($a, '5', $utente);
        Stock::apply(['product_id' => $a, 'quantity' => -4, 'type' => 'sale']);

        try {
            StockDocuments::cancel($id);
            $atteso = '';
        } catch (UserError $errore) {
            $atteso = $errore->getMessage();
        }

        $esito = StockDocumentActionResource::run($annulla, $id, $utente);

        return $atteso !== ''
            && $esito === ['ok' => false, 'message' => $atteso, 'url' => StockReceiptResource::viewUrl($id)]
            && StockDocument::findById($id)['status'] === 'completed';
    });
});

check('duplica: una bozza nuova con le stesse righe, e si apre', function () use ($utente, $duplica) {
    return prova(static function () use ($utente, $duplica): bool {
        $a = articolo(0);
        [$id] = caricoConfermato($a, '2,5', $utente);
        $esito = StockDocumentActionResource::run($duplica, $id, $utente);
        $bozze = righe(StockDocument::class, "`type` = 'receipt' AND `status` = 'draft' AND `deleted` = 'false'");
        $nuovo = (int) (end($bozze)['id'] ?? 0);
        $copia = StockDocument::findById($nuovo);
        $righe = StockDocuments::items($nuovo);

        return $esito['ok'] && $esito['message'] === 'Copia creata: questa è una bozza nuova.'
            && $nuovo !== $id
            && $esito['url'] === StockReceiptResource::editUrl($nuovo)
            && $copia['status'] === 'draft' && $copia['type'] === 'receipt'
            && count($righe) === 1 && $righe[0]['product_id'] === $a && $righe[0]['quantity'] === 2.5;
    });
});

check('azione sconosciuta e documento che non c\'è', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$id] = caricoConfermato(articolo(0), '1', $utente);

        return StockDocumentActionResource::run('cancella-tutto', $id, $utente)
                === ['ok' => false, 'message' => 'Azione non riconosciuta.', 'url' => StockReceiptResource::viewUrl($id)]
            && StockDocumentActionResource::run(StockDocumentActionResource::CANCEL, 0, $utente)
                === ['ok' => false, 'message' => 'Documento non trovato.', 'url' => StockMovementResource::listUrl()];
    });
});

check('cancelQuestion e named: le frasi della spec', fn () =>
    StockReceiptResource::named() === 'Il carico'
    && StockIssueResource::named() === 'Lo scarico'
    && StockReceiptResource::cancelQuestion() === 'Il carico {numero} si annulla: i suoi movimenti si stornano e il documento resta, annullato.'
    && StockIssueResource::cancelQuestion() === 'Lo scarico {numero} si annulla: i suoi movimenti si stornano e il documento resta, annullato.'
    && StockStocktakeResource::named() === "L'inventario"
    && StockStocktakeResource::cancelQuestion() === "L'inventario {numero} si annulla: i suoi movimenti si stornano e il documento resta, annullato."
);

check('scheda inventario: attesi, contati e differenza col segno; la riga non contata ha «—»; il totale dell\'inventario', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$a, $b, $c, $d] = [articolo(10), articolo(4), articolo(2), articolo(7)];
        $id = StockDocuments::create('stocktake', [], [
            ['product_id' => $a, 'quantity' => '8'],
            ['product_id' => $b, 'quantity' => '5,5'],
            ['product_id' => $c, 'quantity' => '2'],
            ['product_id' => $d, 'quantity' => ''],
        ]);
        StockDocuments::confirm($id, ['user_id' => $utente]);
        $scheda = StockDocumentSheet::for(StockDocument::findById($id));
        $celle = array_map(static fn (array $r): array => [$r['expected'], $r['quantity'], $r['difference'], $r['tone']], $scheda['lines']);

        return $celle === [
                ['10', '8', '−2', 'down'],
                ['4', '5,5', '+1,5', 'up'],
                ['2', '2', '0', ''],
                ['—', '—', '—', ''],
            ]
            && $scheda['total'] === '4 righe · 3 contate · 2 con differenza';
    });
});

check('scheda: righe con nome e SKU, totale, nota, chi ha confermato e chi ha annullato', function () use ($utente, $altro, $oggi, $annulla) {
    return prova(static function () use ($utente, $altro, $oggi, $annulla): bool {
        $a = articolo(0);
        [$id] = caricoConfermato($a, '2,5', $utente);
        StockDocumentActionResource::run($annulla, $id, $altro);
        $p = Product::findById($a);
        $scheda = StockDocumentSheet::for(StockDocument::findById($id));

        $attesa = [
            'label' => html_entity_decode(ProductNames::full($p, ProductNames::models([$p])), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'sku' => (string) $p['sku'],
            'quantity' => '2,5',
            'expected' => '',
            'difference' => '',
            'tone' => '',
        ];

        // Da G3 la riga porta anche costo, totale di riga e lotto/fornitore,
        // e con *Acquisti* il totale anche il valore: qui conta il resto.
        return count($scheda['lines']) === 1
            && array_intersect_key($scheda['lines'][0], $attesa) === $attesa
            && str_starts_with($scheda['total'], '1 riga · 2,5 pezzi')
            && $scheda['note'] === 'Bolla 7'
            && $scheda['reason'] === ''
            && $scheda['confirmed'] === 'Confermato da '.nomeUtente($utente).' il '.$oggi
            && $scheda['cancelled'] === 'Annullato'.($altro > 0 ? ' da '.nomeUtente($altro) : '').' il '.$oggi;
    });
});

check('scheda: una versione eliminata dopo la conferma tiene il suo nome; la causale dello scarico', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        $a = articolo(5);
        $causale = 'internal_use';
        $id = StockDocuments::create('issue', ['reason' => $causale], [['product_id' => $a, 'quantity' => '1']]);
        StockDocuments::confirm($id, ['user_id' => $utente]);
        sqlModify(Product::$table, ['deleted' => 'true'], 'id', $a);
        $scheda = StockDocumentSheet::for(StockDocument::findById($id));

        return $scheda['lines'][0]['label'] !== '' && $scheda['lines'][0]['label'] !== 'Versione eliminata'
            && $scheda['reason'] === Reasons::label($causale)
            && $scheda['cancelled'] === '';
    });
});

check('pageSchema: la scheda con il suo template, titolo «Carico»', function () {
    $schema = StockReceiptResource::pageSchema();
    $template = Gestionale::viewPath('pages/stock-document-show.php');

    return !empty(((array) $schema->get('pages'))['view'])
        && (((array) $schema->get('views'))['show'] ?? '') === $template
        && is_file($template)
        && (((array) $schema->get('titles'))['view'] ?? '') === 'Carico'
        && ((array) StockIssueResource::pageSchema()->get('titles'))['view'] === 'Scarico';
});

check('intestazione: Duplica e Annulla sul confermato, solo Duplica sull\'annullato', function () {
    $azioni = ((array) StockReceiptResource::pageSchema()->get('actions'))['view'] ?? null;
    $etichette = static fn (array $item): array => array_column(is_callable($azioni) ? $azioni($item) : (array) $azioni, 'label');

    return $etichette(['id' => 7, 'status' => 'completed']) === ['Duplica', 'Annulla']
        && $etichette(['id' => 7, 'status' => 'cancelled']) === ['Duplica'];
});

check('hiddenForms: il form di Annulla con la domanda solo sul confermato; Duplica sempre', function () {
    $confermato = StockReceiptResource::hiddenForms(['id' => 7, 'status' => 'completed', 'number' => '2026/090003']);
    $annullato = StockIssueResource::hiddenForms(['id' => 8, 'status' => 'cancelled', 'number' => '2026/100001']);
    $azione = htmlspecialchars(StockDocumentActionResource::submitUrl(), ENT_QUOTES, 'UTF-8');

    return str_contains($confermato, 'id="wi-doc-cancel"')
        && str_contains($confermato, 'data-wi-confirm="Il carico 2026/090003 si annulla: i suoi movimenti si stornano e il documento resta, annullato."')
        && str_contains($confermato, 'data-wi-confirm-ok="Annulla il carico"')
        && str_contains($confermato, 'data-wi-confirm-variant="danger"')
        && str_contains($confermato, 'action="'.$azione.'"')
        && str_contains($confermato, 'name="action" value="annulla"')
        && str_contains($confermato, 'name="document_id" value="7"')
        && str_contains($confermato, 'id="wi-doc-duplicate"')
        && !str_contains($annullato, 'wi-doc-cancel')
        && str_contains($annullato, 'id="wi-doc-duplicate"')
        && str_contains($annullato, 'name="action" value="duplica"')
        && StockReceiptResource::hiddenForms([]) === '';
});

check('azioni: indirizzo e permessi di admin e administrator', function () {
    $permessi = (array) (StockDocumentActionResource::permissionSchema()->toArray()['backend'] ?? []);

    return str_contains(StockDocumentActionResource::submitUrl(), 'documento-magazzino-azione')
        && ($permessi['edit'] ?? []) === ['admin', 'administrator']
        && ($permessi['update'] ?? []) === ['admin', 'administrator'];
});

summary();
