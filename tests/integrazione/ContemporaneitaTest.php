<?php
/** php tests/integrazione/ContemporaneitaTest.php */

/**
 * Le due gare che una transazione sola non sa vedere.
 *
 * Il file fa da padre e da figlio: senza argomenti prepara i dati, lancia due
 * processi e giudica; con un argomento è uno dei due processi. I dati qui
 * sono committati davvero — è tutto il punto — e la pulizia è a mano, in
 * fondo.
 */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Sql\Transaction;

$ruolo = $argv[1] ?? 'padre';

/* ---------------------------------------------------------------- figli -- */

if ($ruolo === 'pezzo') {
    [$prodotto, $ordine, $barriera] = [(int) $argv[2], (int) $argv[3], (string) $argv[4]];

    attendi($barriera);

    try {
        Transaction::run(static function () use ($prodotto, $ordine): void {
            Allocation::reserve(['product_id' => $prodotto, 'quantity' => 1, 'order_id' => $ordine]);
            // Tiene il blocco quel tanto che basta perché l'altro ci sbatta.
            usleep(250000);
        });

        echo 'OK';
    } catch (Throwable) {
        echo 'KO';
    }

    exit;
}

if ($ruolo === 'annulla') {
    [$ordine, $barriera] = [(int) $argv[2], (string) $argv[3]];

    attendi($barriera);

    try {
        echo (string) Transaction::run(static function () use ($ordine): int {
            $chiuse = Allocation::release(['order_id' => $ordine]);
            // Tiene il blocco quel tanto che basta perché l'altro ci sbatta.
            usleep(250000);

            return $chiuse;
        });
    } catch (Throwable) {
        echo 'KO';
    }

    exit;
}

if ($ruolo === 'incasso') {
    [$ordine, $riferimento, $barriera] = [(int) $argv[2], (string) $argv[3], (string) $argv[4]];

    attendi($barriera);

    try {
        $esito = Transaction::run(static function () use ($ordine, $riferimento): array {
            $registrato = Ledger::register([
                'order_id' => $ordine,
                'amount' => 50.0,
                'provider' => 'stripe',
                'provider_reference' => $riferimento,
            ]);
            usleep(250000);

            return $registrato;
        });

        echo $esito['payment_id'];
    } catch (Throwable $e) {
        echo 'KO: '.$e->getMessage();
    }

    exit;
}

/* ---------------------------------------------------------------- padre -- */

require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

$spazzatura = ['prodotti' => [], 'ordini' => []];

register_shutdown_function(static function () use (&$spazzatura): void {
    foreach ($spazzatura['prodotti'] as $prodotto) {
        // Il prodotto si cancella per ultimo: prima va via tutto quello che lo
        // nomina, e dopo la variante e il modello che l'hanno generato.
        $riga = Product::findById($prodotto);

        sqlDelete(StockReservation::$table, "product_id = {$prodotto}");
        sqlDelete(StockRow::$table, "product_id = {$prodotto}");
        sqlDelete(StockMovement::$table, "product_id = {$prodotto}");
        sqlDelete(StockAlert::$table, "product_id = {$prodotto}");
        sqlDelete(Product::$table, "id = {$prodotto}");

        if (is_array($riga)) {
            sqlDelete(ProductVariant::$table, 'id = '.(int) $riga['product_variant_id']);
            sqlDelete(ProductModel::$table, 'id = '.(int) $riga['product_model_id']);
        }
    }

    foreach ($spazzatura['ordini'] as $ordine) {
        // La storia di un pagamento lo tiene in vita: se ne va prima lei.
        foreach (righe(Payment::find("order_id = {$ordine}")) as $pagamento) {
            sqlDelete(PaymentStatusLog::$table, 'payment_id = '.(int) $pagamento['id']);
        }

        sqlDelete(Payment::$table, "order_id = {$ordine}");
        sqlDelete(OrderStatusLog::$table, "order_id = {$ordine}");
        sqlDelete(Order::$table, "id = {$ordine}");
    }
});

check('l\'ultimo pezzo lo prende uno solo dei due', function () use (&$spazzatura) {
    $prodotto = articoloConGiacenza(1, 'TST-GARA-'.substr((string) microtime(true), -6));
    $primo = ordineDiProva();
    $secondo = ordineDiProva();

    $spazzatura['prodotti'][] = $prodotto;
    $spazzatura['ordini'][] = $primo;
    $spazzatura['ordini'][] = $secondo;

    $barriera = barriera();
    $processi = [
        avvia('pezzo', [$prodotto, $primo, $barriera]),
        avvia('pezzo', [$prodotto, $secondo, $barriera]),
    ];

    via($barriera);
    $esiti = array_map('esito', $processi);
    sort($esiti);

    // Il perdente non scrive niente: la sua transazione è tornata indietro.
    $righe = (int) sqlCount(StockReservation::$table, "product_id = {$prodotto} AND deleted = 'false'");

    return $esiti === ['KO', 'OK'] && $righe === 1;
});

check('lo stesso ordine annullato due volte libera la merce una volta sola', function () use (&$spazzatura) {
    $prodotto = articoloConGiacenza(5, 'TST-GARA-'.substr((string) microtime(true), -6));
    $ordine = ordineDiProva();

    $spazzatura['prodotti'][] = $prodotto;
    $spazzatura['ordini'][] = $ordine;

    Allocation::reserve(['product_id' => $prodotto, 'quantity' => 2, 'order_id' => $ordine]);

    $barriera = barriera();
    $processi = [
        avvia('annulla', [$ordine, $barriera]),
        avvia('annulla', [$ordine, $barriera]),
    ];

    via($barriera);
    $esiti = array_map('esito', $processi);
    sort($esiti);

    // Chi arriva secondo trova la riga già chiusa e non la richiude: se la
    // contasse anche lui, chi legge il ritorno crederebbe di aver rimesso sul
    // banco il doppio della merce.
    $vive = (int) sqlCount(
        StockReservation::$table,
        "order_id = {$ordine} AND released_at IS NULL AND deleted = 'false'"
    );

    return $esiti === ['0', '1'] && $vive === 0;
});

check('la stessa notifica da due processi incassa una volta sola', function () use (&$spazzatura) {
    $ordine = ordineDiProva(100.0);
    $spazzatura['ordini'][] = $ordine;

    $riferimento = 'pi_gara_'.uniqid();
    $barriera = barriera();
    $processi = [
        avvia('incasso', [$ordine, $riferimento, $barriera]),
        avvia('incasso', [$ordine, $riferimento, $barriera]),
    ];

    via($barriera);
    $esiti = array_map('esito', $processi);
    // Gli id che i due riportano: uno solo, comunque sia andata. Se si sono
    // pestati i piedi ne passa uno e l'altro trova l'indice unico; se uno è
    // arrivato a cose fatte, ritrova la riga del primo e ne riporta l'id. Due
    // id diversi vorrebbero dire due incassi.
    $vinti = array_values(array_unique(array_filter($esiti, 'ctype_digit')));

    // Un gateway a cui la notifica è andata storta la rimanda, e al secondo
    // giro — da solo — ritrova la riga che c'era già invece di incassare una
    // seconda volta.
    $ripetuta = Ledger::register([
        'order_id' => $ordine,
        'amount' => 50.0,
        'provider' => 'stripe',
        'provider_reference' => $riferimento,
    ]);

    $righe = (int) sqlCount(Payment::$table, "order_id = {$ordine}");
    $riga = Order::findById($ordine);

    return count($vinti) === 1
        && ($ripetuta['created'] ?? true) === false
        && (string) $ripetuta['payment_id'] === $vinti[0]
        && $righe === 1
        && is_array($riga)
        && $riga['payment_status'] === 'partially_paid';
});

summary();

/* -------------------------------------------------------------- arnesi -- */

/**
 * Le righe di una `find()`, sempre come lista.
 *
 * @return list<array<string, mixed>>
 */
function righe(mixed $risultato): array
{
    if (!is_array($risultato) || $risultato === []) {
        return [];
    }

    return isset($risultato['id']) ? [$risultato] : array_values(array_filter($risultato, 'is_array'));
}

/** Il file che dà il via: i figli aspettano che compaia. */
function barriera(): string
{
    return sys_get_temp_dir().'/wi-gara-'.uniqid().'.via';
}

function via(string $barriera): void
{
    // Un istante perché i due figli arrivino entrambi all'attesa.
    usleep(200000);
    touch($barriera);
}

function attendi(string $barriera): void
{
    $scadenza = microtime(true) + 10;

    while (!file_exists($barriera)) {
        if (microtime(true) > $scadenza) {
            echo 'KO: via mai dato';
            exit;
        }

        clearstatcache(true, $barriera);
        usleep(2000);
    }
}

/**
 * Lancia questo stesso file in un altro processo.
 *
 * @param list<int|string> $argomenti
 * @return array{0: mixed, 1: array<int, resource>}
 */
function avvia(string $ruolo, array $argomenti): array
{
    $comando = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($ruolo);

    foreach ($argomenti as $argomento) {
        $comando .= ' '.escapeshellarg((string) $argomento);
    }

    $pipe = [];
    $processo = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);

    return [$processo, $pipe];
}

/** @param array{0: mixed, 1: array<int, resource>} $avviato */
function esito(array $avviato): string
{
    [$processo, $pipe] = $avviato;

    $uscita = trim((string) stream_get_contents($pipe[1]));
    $errore = trim((string) stream_get_contents($pipe[2]));

    fclose($pipe[1]);
    fclose($pipe[2]);
    proc_close($processo);

    return $uscita !== '' ? $uscita : 'ERRORE: '.$errore;
}
