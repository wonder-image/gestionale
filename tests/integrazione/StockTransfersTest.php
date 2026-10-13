<?php
/** php tests/integrazione/StockTransfersTest.php — G3 piano 4, trasferimenti e guardia di «Più sedi» */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\App\Models\Config\SocietyLocation;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Features\FeatureGuards;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\LevelsSql;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
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

/** Una seconda sede che tiene merce: la riga del core e quella del gestionale. */
function secondaSede(string $nome = 'Negozio prova trasferimenti'): int
{
    $core = SocietyLocation::create([
        'label' => $nome,
        'slug' => Slug::make('negozio-prova-trasferimenti-'.uniqid()),
        'position' => 99,
        'visible' => 'true',
    ]);
    $riga = Location::create(['society_location_id' => (int) ($core->insert_id ?? 0), 'has_stock' => 'true', 'active' => 'true']);
    Locations::reset();

    return (int) ($riga->insert_id ?? 0);
}

function pezziIn(int $prodotto, int $sede): float
{
    return (float) (Levels::byLocationForProducts([$prodotto])[$prodotto][$sede]['quantity'] ?? 0);
}

function inViaggio(int $prodotto): float
{
    $riga = Product::query()->Select(Product::$table, '`id` = '.$prodotto, 1, null, null, LevelsSql::inTransit('`'.Product::$table.'`.`id`').' AS v')->row;

    return round((float) ($riga['v'] ?? 0), 3);
}

function trasferimento(int $prodotto, float $pezzi, int $da, int $a, array $riga = []): int
{
    return StockDocuments::create('transfer', ['location_id' => $da, 'to_location_id' => $a], [
        ['product_id' => $prodotto, 'quantity' => (string) $pezzi] + $riga,
    ], ['user_id' => 1]);
}

$utente = ['user_id' => 1];

check('invia: la merce lascia la partenza ed è in viaggio; ricevi: entra tutta all\'arrivo', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['multi_location']);
    $principale = Locations::mainId();
    $negozio = secondaSede();
    $a = articoloConGiacenza(5, 'TST-TRF-1');
    $id = trasferimento($a, 3, $principale, $negozio);
    StockDocuments::confirm($id, $utente);
    $inviato = StockDocument::findById($id);
    $dopoInvio = [pezziIn($a, $principale), pezziIn($a, $negozio), inViaggio($a)];
    StockDocuments::receive($id, $utente);
    $ricevuto = StockDocument::findById($id);

    return $inviato['status'] === 'in_transit' && $inviato['number'] !== ''
        && $dopoInvio === [2.0, 0.0, 3.0]
        && $ricevuto['status'] === 'completed'
        && [pezziIn($a, $principale), pezziIn($a, $negozio), inViaggio($a)] === [2.0, 3.0, 0.0];
}));

check('le sedi: la stessa sede è un rifiuto, l\'arrivo serve per inviare', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['multi_location']);
    $principale = Locations::mainId();
    $a = articoloConGiacenza(5, 'TST-TRF-2');
    $senzaArrivo = StockDocuments::create('transfer', ['location_id' => $principale], [['product_id' => $a, 'quantity' => '1']], $utente);

    return rifiuto(fn () => trasferimento($a, 1, $principale, $principale)) === 'stock_document.transfer_same_location'
        && rifiuto(fn () => StockDocuments::confirm($senzaArrivo, $utente)) === 'stock_document.transfer_destination_required';
}));

check('pezzi che mancano: non parte, nemmeno con la vendita senza giacenza', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['multi_location', 'orders', 'backorders']);
    $a = articoloConGiacenza(1, 'TST-TRF-3');
    Product::update(['allow_backorder' => 'true'], $a);
    $id = trasferimento($a, 2, Locations::mainId(), secondaSede());

    return rifiuto(fn () => StockDocuments::confirm($id, $utente)) === 'stock.insufficient'
        && StockDocument::findById($id)['status'] === 'draft';
}));

check('annulla in viaggio: la merce torna alla partenza', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['multi_location']);
    $principale = Locations::mainId();
    $a = articoloConGiacenza(4, 'TST-TRF-4');
    $id = trasferimento($a, 4, $principale, secondaSede());
    StockDocuments::confirm($id, $utente);
    StockDocuments::cancel($id, $utente);

    return StockDocument::findById($id)['status'] === 'cancelled'
        && pezziIn($a, $principale) === 4.0 && inViaggio($a) === 0.0;
}));

check('annulla ricevuto: esce dall\'arrivo e torna alla partenza; con la merce già uscita all\'arrivo si rifiuta', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['multi_location']);
    $principale = Locations::mainId();
    $negozio = secondaSede();
    $a = articoloConGiacenza(4, 'TST-TRF-5');
    $b = articoloConGiacenza(4, 'TST-TRF-6');
    $uno = trasferimento($a, 2, $principale, $negozio);
    StockDocuments::confirm($uno, $utente);
    StockDocuments::receive($uno, $utente);
    StockDocuments::cancel($uno, $utente);
    $due = trasferimento($b, 2, $principale, $negozio);
    StockDocuments::confirm($due, $utente);
    StockDocuments::receive($due, $utente);
    StockOut::take(['product_id' => $b, 'location_id' => $negozio, 'quantity' => -1, 'type' => 'adjustment', 'reason' => 'damaged']);

    return [pezziIn($a, $principale), pezziIn($a, $negozio)] === [4.0, 0.0]
        && rifiuto(fn () => StockDocuments::cancel($due, $utente)) === 'stock_document.transfer_moved'
        && StockDocument::findById($due)['status'] === 'completed';
}));

check('ricevi solo in viaggio; un trasferimento ricevuto non si riceve due volte', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['multi_location']);
    $a = articoloConGiacenza(2, 'TST-TRF-7');
    $id = trasferimento($a, 1, Locations::mainId(), secondaSede());
    $bozza = rifiuto(fn () => StockDocuments::receive($id, $utente));
    StockDocuments::confirm($id, $utente);
    StockDocuments::receive($id, $utente);

    return $bozza === 'stock_document.cannot_receive'
        && rifiuto(fn () => StockDocuments::receive($id, $utente)) === 'stock_document.cannot_receive';
}));

check('i movimenti: transfer_out alla partenza, transfer_in all\'arrivo, con la riga', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['multi_location']);
    $principale = Locations::mainId();
    $negozio = secondaSede();
    $a = articoloConGiacenza(3, 'TST-TRF-8');
    $id = trasferimento($a, 3, $principale, $negozio);
    StockDocuments::confirm($id, $utente);
    StockDocuments::receive($id, $utente);
    $mosse = StockMovement::find("`reference_type` = 'stock_document' AND `reference_id` = ".$id, null, 'id', 'ASC');
    $mosse = isset($mosse['id']) ? [$mosse] : array_values((array) $mosse);
    $riga = StockDocuments::items($id)[0]['id'];

    return count($mosse) === 2
        && $mosse[0]['type'] === 'transfer_out' && (int) $mosse[0]['location_id'] === $principale && (float) $mosse[0]['quantity'] === -3.0
        && $mosse[1]['type'] === 'transfer_in' && (int) $mosse[1]['location_id'] === $negozio && (float) $mosse[1]['quantity'] === 3.0
        && (int) $mosse[0]['reference_item_id'] === $riga && (int) $mosse[1]['reference_item_id'] === $riga;
}));

check('la guardia: «Più sedi» non si spegne con pezzi in un\'altra sede o in viaggio; senza, sì', fn () => prova(static function () use ($utente): bool {
    accendiFunzionalita(['multi_location']);
    $principale = Locations::mainId();
    $negozio = secondaSede('Negozio guardia');
    $spegni = static fn (): ?string => rifiuto(fn () => FeatureGuards::assertAllowed(['multi_location' => true], ['multi_location' => false]));
    $libera = $spegni();
    $a = articoloConGiacenza(2, 'TST-TRF-9');
    $id = trasferimento($a, 2, $principale, $negozio);
    StockDocuments::confirm($id, $utente);
    $viaggio = $spegni();
    StockDocuments::receive($id, $utente);
    $negozioPieno = $spegni();
    $messaggio = '';

    try {
        FeatureGuards::assertAllowed(['multi_location' => true], ['multi_location' => false]);
    } catch (UserError $e) {
        $messaggio = $e->getMessage();
    }

    // Senza niente fuori dalla principale si spegne; il sito di prova può già avere pezzi altrove.
    $altrove = (int) sqlCount('gst_stock', "`location_id` NOT IN (".$principale.', '.$negozio.") AND `quantity` <> 0 AND `deleted` = 'false'") > 0;

    return ($libera === null || $altrove)
        && $viaggio === 'feature.multi_location_in_use'
        && $negozioPieno === 'feature.multi_location_in_use'
        && str_contains($messaggio, 'Negozio guardia');
}));

summary();
