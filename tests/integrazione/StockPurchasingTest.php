<?php
/** php tests/integrazione/StockPurchasingTest.php — G3 piano 2, Acquisti */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\PurchaseTax;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentRules;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;
use Wonder\Plugin\Gestionale\Support\Stock\StockOut;
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

function fornitore(string $nome): int
{
    return (int) (Contact::create([
        'type' => 'business',
        'business_name' => $nome,
        'country' => 'IT',
        'is_customer' => 'false',
        'is_supplier' => 'true',
        'active' => 'true',
    ])->insert_id ?? 0);
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

    ksort($out);

    return $out;
}

/** @return list<array<string, mixed>> */
function movimentiDi(int $documento): array
{
    $r = StockMovement::find("`reference_type` = 'stock_document' AND `reference_id` = {$documento} AND `deleted` = 'false'", null, 'id', 'ASC');

    return !is_array($r) || $r === [] ? [] : (isset($r['id']) ? [$r] : array_values($r));
}

$utente = 1;

check('con Acquisti il carico entra nel secchio del fornitore, col costo, e la scheda impara il costo', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['purchasing']);
    $f = fornitore('Fornitore G3 uno');
    $a = articoloConGiacenza(1, 'TST-ACQ-1');
    $id = StockDocuments::create('receipt', ['supplier_id' => (string) $f, 'supplier_document_number' => 'FT 12', 'supplier_document_date' => '2026-10-10'], [
        ['product_id' => $a, 'quantity' => '4', 'cost' => '2,50'],
    ], ['user_id' => $utente]);
    $doc = StockDocument::findById($id);
    StockDocuments::confirm($id, ['user_id' => $utente]);
    $mov = movimentiDi($id);
    $legame = ProductSupplier::find(['product_id' => $a, 'supplier_id' => $f, 'deleted' => 'false'], 1);

    return (int) $doc['supplier_id'] === $f && $doc['supplier_document_number'] === 'FT 12'
        && (float) $doc['total_cost'] === 10.0
        && secchi($a) === ['0/0' => 1.0, '0/'.$f => 4.0]
        && count($mov) === 1 && (int) $mov[0]['supplier_id'] === $f && (float) $mov[0]['unit_cost'] === 2.5
        && is_array($legame) && (float) $legame['cost'] === 2.5;
}));

check('senza Acquisti fornitore e costi del carico si ignorano: tutto come prima', fn () => prova(static function () use ($utente): bool {
    spegniFunzionalita(['purchasing']);
    $f = fornitore('Fornitore G3 spento');
    $a = articoloConGiacenza(1, 'TST-ACQ-2');
    $id = StockDocuments::create('receipt', ['supplier_id' => (string) $f], [['product_id' => $a, 'quantity' => '3', 'cost' => '9']], ['user_id' => $utente]);
    StockDocuments::confirm($id, ['user_id' => $utente]);
    $mov = movimentiDi($id);
    $legame = ProductSupplier::find(['product_id' => $a, 'deleted' => 'false'], 1);

    return (int) StockDocument::findById($id)['supplier_id'] === 0
        && secchi($a) === ['0/0' => 4.0]
        && count($mov) === 1 && (float) $mov[0]['unit_cost'] === 0.0
        && (!is_array($legame) || $legame === []);
}));

check('un fornitore che non è un fornitore è un rifiuto', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['purchasing']);
    $a = articoloConGiacenza(1, 'TST-ACQ-3');

    return rifiuto(fn () => StockDocuments::create('receipt', ['supplier_id' => '999999999'], [['product_id' => $a, 'quantity' => '1']], ['user_id' => $utente]))
        === 'stock_document.supplier_unknown';
}));

check('la vendita scoperta si compensa al carico, e l\'annullo del carico la riporta com\'era', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['purchasing']);
    $f = fornitore('Fornitore G3 compensa');
    $a = articoloConGiacenza(1, 'TST-ACQ-4');
    Stock::apply(['product_id' => $a, 'quantity' => -3, 'type' => 'sale', 'allow_negative' => true]);
    $id = StockDocuments::create('receipt', ['supplier_id' => (string) $f], [['product_id' => $a, 'quantity' => '5', 'cost' => '1']], ['user_id' => $utente]);
    StockDocuments::confirm($id, ['user_id' => $utente]);
    $dopo = secchi($a);
    $compensazioni = array_values(array_filter(movimentiDi($id), static fn (array $m): bool => $m['reason'] === 'batch_assignment'));
    StockDocuments::cancel($id, ['user_id' => $utente]);

    return $dopo === ['0/0' => 0.0, '0/'.$f => 3.0]
        && count($compensazioni) === 2
        && secchi($a) === ['0/0' => -2.0, '0/'.$f => 0.0];
}));

check('lo scarico si divide fra i fornitori, dal meno caro', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['purchasing']);
    [$caro, $economico] = [fornitore('Fornitore G3 caro'), fornitore('Fornitore G3 economico')];
    $a = articoloConGiacenza(1, 'TST-ACQ-5');
    Stock::apply(['product_id' => $a, 'quantity' => -1, 'type' => 'adjustment', 'reason' => 'other']);
    foreach ([[$caro, '5'], [$economico, '1']] as [$f, $costo]) {
        $c = StockDocuments::create('receipt', ['supplier_id' => (string) $f], [['product_id' => $a, 'quantity' => '2', 'cost' => $costo]], ['user_id' => $utente]);
        StockDocuments::confirm($c, ['user_id' => $utente]);
    }
    $s = StockDocuments::create('issue', ['reason' => 'damaged'], [['product_id' => $a, 'quantity' => '3']], ['user_id' => $utente]);
    StockDocuments::confirm($s, ['user_id' => $utente]);

    return secchi($a) === ['0/0' => 0.0, '0/'.$caro => 1.0, '0/'.$economico => 0.0];
}));

check('la rettifica in meno esce dai secchi; senza merce si ferma come prima', fn () => prova(static function (): bool {
    accendiFunzionalita(['purchasing']);
    $f = fornitore('Fornitore G3 rettifica');
    $a = articoloConGiacenza(1, 'TST-ACQ-6');
    Stock::apply(['product_id' => $a, 'supplier_id' => $f, 'quantity' => 2, 'type' => 'purchase']);
    StockOut::take(['product_id' => $a, 'quantity' => -2, 'type' => 'adjustment', 'reason' => 'damaged']);

    return secchi($a) === ['0/0' => 0.0, '0/'.$f => 1.0]
        && rifiuto(fn () => StockOut::take(['product_id' => $a, 'quantity' => -5, 'type' => 'adjustment', 'reason' => 'damaged'])) === 'stock.insufficient';
}));

check('il valore della colonna (LevelsSql) è quello di StockValue, anche con un costo vuoto', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['purchasing']);
    [$costoso, $senza] = [fornitore('Fornitore G3 valore'), fornitore('Fornitore G3 senza costo')];
    $a = articoloConGiacenza(1, 'TST-ACQ-7');
    $c = StockDocuments::create('receipt', ['supplier_id' => (string) $costoso], [['product_id' => $a, 'quantity' => '2', 'cost' => '3']], ['user_id' => $utente]);
    StockDocuments::confirm($c, ['user_id' => $utente]);
    Stock::apply(['product_id' => $a, 'supplier_id' => $senza, 'quantity' => 1, 'type' => 'purchase']);
    ProductSupplier::create(['product_id' => $a, 'supplier_id' => $senza, 'supplier_sku' => '', 'cost' => null, 'position' => 9]);

    $sql = Wonder\Plugin\Gestionale\Models\Stock\Stock::connection()->query('SELECT '.Wonder\Plugin\Gestionale\Support\Stock\LevelsSql::value((string) $a).' AS v');
    $colonna = $sql ? (float) $sql->fetch_assoc()['v'] : -1.0;
    $stock = Wonder\Plugin\Gestionale\Support\Stock\StockBuckets::describe(
        array_values(array_filter((array) StockRow::find(['product_id' => $a, 'deleted' => 'false']), 'is_array')),
        $a
    );
    $valore = Wonder\Plugin\Gestionale\Support\Stock\StockValue::of($stock['buckets']);

    // Secchio 0: 1 pezzo al costo più basso (3); 2 pezzi a 3; 1 pezzo senza costo.
    return $colonna === 9.0 && $valore === ['value' => 9.0, 'pieces' => 4.0, 'uncosted' => 1.0];
}));

check('il carico scritto IVA inclusa tiene il costo netto, con lo scritto e l\'aliquota sulla riga', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['purchasing']);
    $f = fornitore('Fornitore G3 IVA inclusa');
    $a = articoloConGiacenza(1, 'TST-ACQ-IVA-1');
    $aliquota = PurchaseTax::rates([$a])[$a] ?? 0.0;
    $netto = StockDocumentRules::net(1.22, $aliquota);
    $id = StockDocuments::create('receipt', ['supplier_id' => (string) $f, 'prices_include_tax' => 'true'], [
        ['product_id' => $a, 'quantity' => '10', 'cost' => '1,22'],
    ], ['user_id' => $utente]);
    $doc = StockDocument::findById($id);
    $riga = StockDocuments::items($id)[0];
    StockDocuments::confirm($id, ['user_id' => $utente]);
    $mov = movimentiDi($id);
    $legame = ProductSupplier::find(['product_id' => $a, 'supplier_id' => $f, 'deleted' => 'false'], 1);

    return $doc['prices_include_tax'] === 'true'
        && $riga['entered_cost'] === 1.22 && $riga['tax_rate'] === round($aliquota, 2) && $riga['unit_cost'] === $netto
        && (float) StockDocument::findById($id)['total_cost'] === round($netto * 10, 2)
        && (float) $mov[0]['unit_cost'] === $netto
        && is_array($legame) && (float) $legame['cost'] === $netto;
}));

check('la bozza nuova parte dall\'impostazione; tornando a IVA esclusa lo scorporo sparisce', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['purchasing']);
    $a = articoloConGiacenza(1, 'TST-ACQ-IVA-2');
    $prima = (string) (Wonder\Plugin\Gestionale\Models\System\Setting::current()['stock_prices_include_tax'] ?? 'false');
    $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '1', 'cost' => '2,50']], ['user_id' => $utente]);
    $nuovo = StockDocument::findById($id)['prices_include_tax'];
    StockDocuments::saveDraft($id, ['prices_include_tax' => 'true'], [['product_id' => $a, 'quantity' => '1', 'cost' => '2,50']]);
    $inclusa = StockDocuments::items($id)[0];
    StockDocuments::saveDraft($id, ['prices_include_tax' => 'false'], [['product_id' => $a, 'quantity' => '1', 'cost' => '2,50']]);
    $esclusa = StockDocuments::items($id)[0];

    return $nuovo === $prima
        && $inclusa['entered_cost'] === 2.5 && $inclusa['tax_rate'] !== null
        // IVA esclusa: niente scorporo, ma l'aliquota del prodotto resta sulla riga.
        && $esclusa['entered_cost'] === null && $esclusa['tax_rate'] === $inclusa['tax_rate'] && $esclusa['unit_cost'] === 2.5;
}));

check('l\'aliquota d\'acquisto si cambia per riga: scorpora con quella e resta anche IVA esclusa', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['purchasing']);
    $a = articoloConGiacenza(1, 'TST-ACQ-IVA-4');
    // Un forfettario vende senza IVA ma compra con il 10%: la riga dice 10, non l'aliquota di vendita.
    $id = StockDocuments::create('receipt', ['prices_include_tax' => 'true'], [['product_id' => $a, 'quantity' => '2', 'cost' => '1,10', 'tax_rate' => '10']], ['user_id' => $utente]);
    $inclusa = StockDocuments::items($id)[0];
    StockDocuments::saveDraft($id, ['prices_include_tax' => 'false'], [['product_id' => $a, 'quantity' => '2', 'cost' => '1,10', 'tax_rate' => '4%']]);
    $esclusa = StockDocuments::items($id)[0];
    $copia = StockDocuments::duplicate($id, ['user_id' => $utente]);
    StockDocuments::saveDraft($id, [], [['product_id' => $a, 'quantity' => '2', 'cost' => '1,10', 'tax_rate' => '']]);
    $vuota = StockDocuments::items($id)[0];
    $rifiuto = false;

    try {
        StockDocuments::saveDraft($id, [], [['product_id' => $a, 'quantity' => '2', 'cost' => '1,10', 'tax_rate' => '150']]);
    } catch (UserError $e) {
        $rifiuto = str_contains($e->getMessage(), 'aliquota');
    }

    return $inclusa['tax_rate'] === 10.0 && $inclusa['entered_cost'] === 1.1 && $inclusa['unit_cost'] === 1.0
        && $esclusa['tax_rate'] === 4.0 && $esclusa['entered_cost'] === null && $esclusa['unit_cost'] === 1.1
        && StockDocuments::items($copia)[0]['tax_rate'] === 4.0
        // Vuota = l'aliquota di vendita dell'articolo.
        && $vuota['tax_rate'] === (isset(PurchaseTax::rates([$a])[$a]) ? round(PurchaseTax::rates([$a])[$a], 2) : null)
        && $rifiuto;
}));

check('l\'aliquota scelta dal menu resta sulla riga con la sua natura, anche nella copia', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['purchasing']);
    $a = articoloConGiacenza(1, 'TST-ACQ-IVA-5');
    $scelte = PurchaseTax::choices();
    $dieci = array_values(array_filter($scelte, static fn (array $t): bool => $t['rate'] === 10.0))[0] ?? null;
    $zero = array_values(array_filter($scelte, static fn (array $t): bool => $t['rate'] === 0.0 && str_contains($t['label'], 'N')))[0] ?? null;

    if ($dieci === null || $zero === null) {
        return false;
    }

    // L'id vince sul valore mandato insieme.
    $id = StockDocuments::create('receipt', ['prices_include_tax' => 'true'], [['product_id' => $a, 'quantity' => '1', 'cost' => '1,10', 'tax_id' => (string) $dieci['id'], 'tax_rate' => '22']], ['user_id' => $utente]);
    $dieciRiga = StockDocuments::items($id)[0];
    StockDocuments::saveDraft($id, [], [['product_id' => $a, 'quantity' => '1', 'cost' => '1,10', 'tax_id' => (string) $zero['id']]]);
    $zeroRiga = StockDocuments::items($id)[0];
    $copia = StockDocuments::items(StockDocuments::duplicate($id, ['user_id' => $utente]))[0];
    $rifiuto = false;

    try {
        StockDocuments::saveDraft($id, [], [['product_id' => $a, 'quantity' => '1', 'cost' => '1', 'tax_id' => '999999999']]);
    } catch (UserError $e) {
        $rifiuto = str_contains($e->getMessage(), 'aliquota');
    }

    return $dieciRiga['tax_id'] === $dieci['id'] && $dieciRiga['tax_rate'] === 10.0 && $dieciRiga['unit_cost'] === 1.0
        && $zeroRiga['tax_id'] === $zero['id'] && $zeroRiga['tax_rate'] === 0.0 && $zeroRiga['unit_cost'] === 1.1
        && $copia['tax_id'] === $zero['id']
        && $rifiuto;
}));

check('il duplicato del carico IVA inclusa riparte dallo scritto, non dal netto', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['purchasing']);
    $a = articoloConGiacenza(1, 'TST-ACQ-IVA-3');
    $id = StockDocuments::create('receipt', ['prices_include_tax' => 'true'], [['product_id' => $a, 'quantity' => '1', 'cost' => '2,44']], ['user_id' => $utente]);
    $copia = StockDocuments::duplicate($id, ['user_id' => $utente]);

    return StockDocument::findById($copia)['prices_include_tax'] === 'true'
        && StockDocuments::items($copia)[0]['entered_cost'] === 2.44
        && StockDocuments::items($copia)[0]['unit_cost'] === StockDocuments::items($id)[0]['unit_cost'];
}));

summary();
