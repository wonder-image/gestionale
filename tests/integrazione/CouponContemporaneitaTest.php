<?php
/** php tests/integrazione/CouponContemporaneitaTest.php */

/**
 * L'ultimo utilizzo di un coupon lo prende uno solo.
 *
 * Due carrelli con lo stesso coupon (limite: un uso) fanno il checkout nello
 * stesso istante, in due processi PHP veri: una transazione sola non sa
 * vedere questa gara, perché ognuno conta gli usi su una fotografia vecchia.
 * Il file fa da padre e da figlio, come `ContemporaneitaTest`: senza
 * argomenti prepara i dati, lancia i due processi e giudica; con un argomento
 * è uno dei due. I dati sono committati davvero e la pulizia è a mano.
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
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentStatusLog;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Plugin\Gestionale\Support\Orders\PaymentTiming;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;

$ruolo = $argv[1] ?? 'padre';

/* ---------------------------------------------------------------- figli -- */

if ($ruolo === 'ordina') {
    [$carrello, $dati, $barriera] = [(int) $argv[2], json_decode((string) $argv[3], true), (string) $argv[4]];

    attendi($barriera);
    Mailer::useTransport(static fn (): bool => true);

    try {
        Checkout::place($carrello, $dati);

        echo 'OK';
    } catch (UserError $e) {
        echo 'KO:'.$e->key();
    } catch (Throwable $e) {
        echo 'ERRORE: '.$e->getMessage();
    }

    exit;
}

/* ---------------------------------------------------------------- padre -- */

require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

$spazzatura = ['prodotti' => [], 'ordini' => [], 'coupon' => [], 'metodi' => []];

register_shutdown_function(static function () use (&$spazzatura): void {
    foreach ($spazzatura['ordini'] as $ordine) {
        foreach (righe(Payment::find("order_id = {$ordine}")) as $pagamento) {
            sqlDelete(PaymentStatusLog::$table, 'payment_id = '.(int) $pagamento['id']);
        }

        sqlDelete(Payment::$table, "order_id = {$ordine}");
        sqlDelete(CouponRedemption::$table, "order_id = {$ordine}");
        sqlDelete(OrderTaxSummary::$table, "order_id = {$ordine}");
        sqlDelete(StockReservation::$table, "order_id = {$ordine}");
        sqlDelete(StockMovement::$table, "reference_id = {$ordine} AND reference_type = 'order'");
        sqlDelete(OrderItem::$table, "order_id = {$ordine}");
        sqlDelete(OrderStatusLog::$table, "order_id = {$ordine}");
        sqlDelete(Order::$table, "id = {$ordine}");
    }

    foreach ($spazzatura['coupon'] as $coupon) {
        sqlDelete(CouponRedemption::$table, "coupon_id = {$coupon}");
        sqlDelete(Coupon::$table, "id = {$coupon}");
    }

    foreach ($spazzatura['metodi'] as $metodo) {
        sqlDelete(PaymentMethod::$table, "id = {$metodo}");
    }

    foreach ($spazzatura['prodotti'] as $prodotto) {
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
});

check('l\'ultimo utilizzo di un coupon lo prende uno solo dei due checkout', function () use (&$spazzatura) {
    accendiFunzionalita(['orders', 'coupons']);

    $prodotto = articoloConGiacenza(10, 'TST-CPGARA-'.substr((string) microtime(true), -6));
    // Un articolo da non spedire: la gara è sul coupon, non sul metodo di spedizione.
    ProductModel::update(['requires_shipping' => 'false'], modelloDi($prodotto));
    Product::update(['price' => '50.00', 'sale_price' => '0.00'], $prodotto);
    $spazzatura['prodotti'][] = $prodotto;

    $codice = 'G'.strtoupper(substr(uniqid(), -8));
    $coupon = (int) (Coupon::create([
        'code' => $codice, 'name' => 'Gara '.$codice, 'discount_type' => 'percent', 'discount_value' => '10.00',
        'applies_to_all' => 'true', 'applies_online' => 'true', 'active' => 'true', 'usage_limit' => '1',
    ])->insert_id ?? 0);
    $spazzatura['coupon'][] = $coupon;

    $metodo = (int) (PaymentMethod::create([
        'code' => 'tst_'.uniqid(), 'name' => 'Prova gara', 'provider' => 'stripe', 'timing' => PaymentTiming::IMMEDIATE,
        'fee_type' => 'none', 'fee_value' => '0.00', 'available_for' => 'all', 'active' => 'true',
        'position' => 1, 'instructions' => 'Istruzioni di prova',
    ])->insert_id ?? 0);
    $spazzatura['metodi'][] = $metodo;

    $barriera = barriera();
    $processi = [];

    foreach (['uno@example.com', 'due@example.com'] as $email) {
        // Tutti e due arrivano al checkout con il coupon sul carrello: all'applicazione c'è posto per entrambi.
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $spazzatura['ordini'][] = $carrello;
        Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 2]);
        Coupons::apply($carrello, $codice);

        $dati = [
            'email' => $email, 'payment_method_id' => $metodo, 'fulfillment_type' => 'shipping',
            'billing' => [
                'country' => 'IT', 'province' => 'MI', 'city' => 'Milano', 'cap' => '20100',
                'street' => 'Via Prova', 'number' => '1', 'name' => 'Mario', 'surname' => 'Rossi',
            ],
        ];
        $processi[] = avvia('ordina', [$carrello, json_encode($dati), $barriera]);
    }

    via($barriera);
    $esiti = array_map('esito', $processi);
    sort($esiti);

    $nati = (int) sqlCount(Order::$table, "id IN (".implode(',', $spazzatura['ordini']).") AND stage = 'order'");
    $usi = (int) sqlCount(CouponRedemption::$table, "coupon_id = {$coupon} AND released_at IS NULL");

    return $esiti === ['KO:coupon.exhausted', 'OK'] && $nati === 1 && $usi === 1;
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
    usleep(300000);
    touch($barriera);
}

function attendi(string $barriera): void
{
    $scadenza = microtime(true) + 15;

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
