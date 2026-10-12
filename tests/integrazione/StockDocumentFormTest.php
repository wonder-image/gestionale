<?php
/** php tests/integrazione/StockDocumentFormTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Stock\StockIssueResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockReceiptResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentForm;
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
    return articoloConGiacenza($pezzi, 'TST-FRM-'.substr((string) microtime(true), -6).random_int(10, 99));
}

function conta(string $tabella, string $where): int
{
    $r = StockDocument::connection()->query('SELECT COUNT(*) AS n FROM `'.$tabella.'` WHERE '.$where);

    return (int) ($r ? $r->fetch_assoc()['n'] : -1);
}

function movimenti(int $id): int
{
    return conta(StockMovement::$table, "`reference_type` = 'stock_document' AND `reference_id` = ".$id." AND `deleted` = 'false'");
}

function messaggio(string $chiave): string
{
    return UserError::make($chiave)->getMessage();
}

/** @param list<array{0: int, 1: string}> $righe versione e pezzi */
function post(array $righe, array $altro = []): array
{
    return ['lines' => json_encode(array_map(static fn (array $r): array => ['product_id' => $r[0], 'quantity' => $r[1]], $righe))] + $altro;
}

$utente = 1;
$causale = (string) array_key_first(Reasons::forDocument('issue'));

check('nuovo, Salva bozza: nasce la bozza con le righe, la nota e chi l\'ha creata', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$a, $b] = [articolo(), articolo()];
        $esito = StockDocumentForm::submit('receipt', 0, post([[$a, '2,5'], [$b, '']], ['note' => 'Fattura 12', 'intent' => 'save']), $utente);
        $doc = StockDocument::findById($esito['id']);
        $righe = StockDocuments::items($esito['id']);

        return $esito['id'] > 0 && $esito['number'] === '' && $esito['error'] === ''
            && $doc['status'] === 'draft' && $doc['type'] === 'receipt'
            && $doc['note'] === 'Fattura 12' && (int) $doc['user_id'] === $utente
            && array_column($righe, 'product_id') === [$a, $b]
            && array_column($righe, 'quantity') === [2.5, null];
    });
});

check('nuovo, Conferma: un numero e un movimento per riga', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$a, $b] = [articolo(), articolo()];
        $esito = StockDocumentForm::submit('receipt', 0, post([[$a, '2'], [$b, '3']], ['intent' => 'confirm']), $utente);

        return $esito['error'] === '' && $esito['number'] !== ''
            && StockDocument::findById($esito['id'])['status'] === 'completed'
            && movimenti($esito['id']) === 2;
    });
});

check('nuovo scarico sotto zero: una bozza sola, con le righe e l\'errore; il secondo invio non ne crea un\'altra', function () use ($utente, $causale) {
    return prova(static function () use ($utente, $causale): bool {
        $a = articolo(1);
        $prima = conta(StockDocument::$table, "`type` = 'issue'");
        $primo = StockDocumentForm::submit('issue', 0, post([[$a, '5']], ['reason' => $causale, 'intent' => 'confirm']), $utente);
        $dopo = conta(StockDocument::$table, "`type` = 'issue'");
        $secondo = StockDocumentForm::submit('issue', $primo['id'], post([[$a, '5']], ['reason' => $causale, 'intent' => 'confirm']), $utente);

        return $primo['id'] > 0 && $primo['number'] === ''
            && str_contains($primo['error'], 'abbastanza giacenza')
            && $dopo === $prima + 1
            && StockDocument::findById($primo['id'])['status'] === 'draft'
            && count(StockDocuments::items($primo['id'])) === 1
            && movimenti($primo['id']) === 0
            && $secondo['id'] === $primo['id'] && $secondo['error'] !== ''
            && conta(StockDocument::$table, "`type` = 'issue'") === $dopo;
    });
});

check('seconda conferma (doppio clic, due schede): nessun movimento in più, il messaggio, la scheda in sola lettura', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        $a = articolo();
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '2']]);
        $uno = StockReceiptResource::handleSubmit(post([[$a, '2']], ['intent' => 'confirm']), $id, $utente);
        $due = StockReceiptResource::handleSubmit(post([[$a, '2']], ['intent' => 'confirm']), $id, $utente);
        $numero = StockDocument::findById($id)['number'];

        return $uno['ok'] && $uno['message'] === 'Carico '.$numero.' confermato.'
            && $uno['url'] === StockReceiptResource::viewUrl($id)
            && !$due['ok'] && $due['message'] === messaggio('stock_document.not_draft')
            && $due['url'] === StockReceiptResource::viewUrl($id)
            && movimenti($id) === 1;
    });
});

check('Salva bozza su un documento confermato non scrive niente', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$a, $b] = [articolo(), articolo()];
        $id = StockDocuments::create('receipt', ['note' => 'Prima'], [['product_id' => $a, 'quantity' => '2']]);
        StockDocuments::confirm($id);
        $esito = StockReceiptResource::handleSubmit(post([[$b, '9']], ['note' => 'Dopo', 'intent' => 'save']), $id, $utente);

        return !$esito['ok'] && $esito['message'] === messaggio('stock_document.not_draft')
            && StockDocument::findById($id)['note'] === 'Prima'
            && array_column(StockDocuments::items($id), 'product_id') === [$a];
    });
});

check('righe che mancano o non si leggono: invalid_lines, niente creato e niente cancellato', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        $a = articolo();
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '2']]);
        $prima = conta(StockDocument::$table, "`type` = 'receipt'");
        $atteso = messaggio('stock_document.invalid_lines');

        foreach ([[], ['lines' => ''], ['lines' => 'abc'], ['lines' => '{"product_id":1}'], ['lines' => ['x']], ['lines' => '[[[[[[[[[[1]]]]]]]]]]']] as $post) {
            $nuovo = StockDocumentForm::submit('receipt', 0, $post + ['intent' => 'confirm'], $utente);
            $vecchio = StockDocumentForm::submit('receipt', $id, $post + ['intent' => 'save'], $utente);

            if ($nuovo['error'] !== $atteso || $nuovo['id'] !== 0 || $vecchio['error'] !== $atteso) {
                return false;
            }
        }

        return conta(StockDocument::$table, "`type` = 'receipt'") === $prima
            && array_column(StockDocuments::items($id), 'product_id') === [$a];
    });
});

check('una lista vuota è una bozza senza righe, non un errore', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        $esito = StockDocumentForm::submit('receipt', 0, ['lines' => '[]', 'intent' => 'save'], $utente);

        return $esito['error'] === '' && $esito['id'] > 0 && StockDocuments::items($esito['id']) === [];
    });
});

check('un carico inviato agli scarichi è not_found e resta com\'era', function () use ($utente, $causale) {
    return prova(static function () use ($utente, $causale): bool {
        $a = articolo();
        $id = StockDocuments::create('receipt', ['note' => 'Carico'], [['product_id' => $a, 'quantity' => '2']]);
        $esito = StockIssueResource::handleSubmit(post([], ['note' => 'Altro', 'reason' => $causale, 'intent' => 'save']), $id, $utente);

        return !$esito['ok'] && $esito['message'] === messaggio('stock_document.not_found')
            && $esito['url'] === StockIssueResource::listUrl()
            && StockDocument::findById($id)['note'] === 'Carico'
            && count(StockDocuments::items($id)) === 1;
    });
});

check('handleSubmit: bozza salvata torna alla bozza, un nuovo fallito senza bozza torna a «Nuovo»', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        $a = articolo();
        $id = StockDocuments::create('receipt', [], []);
        $salva = StockReceiptResource::handleSubmit(post([[$a, '1']], ['intent' => 'save']), $id, $utente);
        $rotto = StockReceiptResource::handleSubmit(['lines' => 'x', 'intent' => 'save'], 0, $utente);

        return $salva['ok'] && $salva['message'] === 'Bozza salvata.'
            && $salva['url'] === StockReceiptResource::editUrl($id)
            && StockReceiptResource::redirectFor(999999999) === StockReceiptResource::listUrl()
            && !$rotto['ok'] && $rotto['url'] === StockReceiptResource::createUrl();
    });
});

check('editorLines: l\'ordine del documento, i pezzi in testo, la versione eliminata tolta e contata', function () {
    return prova(static function (): bool {
        [$a, $b, $c] = [articolo(4), articolo(), articolo()];
        $id = StockDocuments::create('receipt', [], [
            ['product_id' => $c, 'quantity' => '2,5'],
            ['product_id' => $b, 'quantity' => '1'],
            ['product_id' => $a, 'quantity' => ''],
        ]);
        Product::connection()->query('UPDATE `'.Product::$table."` SET `deleted` = 'true' WHERE `id` = ".$b);
        $editor = StockDocumentForm::editorLines(StockDocuments::items($id), Locations::mainId());

        return $editor['dropped'] === 1
            && array_column($editor['lines'], 'id') === [$c, $a]
            && array_column($editor['lines'], 'quantity') === ['2,5', '']
            && (float) $editor['lines'][1]['stock'] === 4.0
            && $editor['lines'][0]['label'] !== '';
    });
});

check('quantityText: vuoto, interi senza decimali, virgola', fn () =>
    StockDocumentForm::quantityText(null) === ''
    && StockDocumentForm::quantityText(3.0) === '3'
    && StockDocumentForm::quantityText(2.5) === '2,5'
    && StockDocumentForm::quantityText(0.125) === '0,125'
    && StockDocumentForm::quantityText(1234.0) === '1234'
);

check('closedRedirect: la bozza si modifica, un confermato o annullato va in sola lettura', fn () =>
    StockReceiptResource::closedRedirect(['id' => 5, 'status' => 'draft'], 'edit') === null
    && StockReceiptResource::closedRedirect([], 'create') === null
    && StockReceiptResource::closedRedirect(['id' => 5, 'status' => 'completed'], 'edit') === StockReceiptResource::viewUrl(5)
    && StockIssueResource::closedRedirect(['id' => 6, 'status' => 'cancelled'], 'edit') === StockIssueResource::viewUrl(6)
);

summary();
