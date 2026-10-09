<?php
/** php tests/integrazione/CheckoutSpedizioneTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/spedizioni.php';
require_once __DIR__.'/supporto/FakePaymentProvider.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Plugin\Gestionale\Support\Orders\PaymentTiming;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/**
 * Ogni prova parte senza i metodi di spedizione e le sedi di ritiro del sito,
 * che altrimenti entrerebbero nelle scelte; la transazione rimette tutto.
 */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            // Il sito di prova può avere zone e metodi demo: si parte da vuoto.
            \Wonder\Plugin\Gestionale\Seeding\ShippingDemo::clear();
            sqlModify(ShippingMethod::$table, ['active' => 'false'], 'active', 'true');
            sqlModify(Location::$table, ['is_pickup_point' => 'false'], 'is_pickup_point', 'true');
            accendiFunzionalita(['orders', 'shipping', 'coupons']);
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
}

/** Le righe di un Model che rispondono alla condizione, in elenco. */
function righe(string $model, string $dove): array
{
    $rows = $model::find($dove);

    if (!is_array($rows) || $rows === []) {
        return [];
    }

    return array_key_exists('id', $rows) ? [$rows] : array_values(array_filter($rows, 'is_array'));
}

/** Le righe del carrello di un tipo (`shipping`, `fee`, …). */
function righeDi(int $cart, string $tipo): array
{
    return righe(OrderItem::class, "order_id = {$cart} AND type = '{$tipo}' AND deleted = 'false'");
}

/** La chiave del rifiuto, '' se non c'è stato. */
function rifiuto(callable $fn): string
{
    try {
        $fn();
    } catch (UserError $errore) {
        return $errore->key();
    }

    return '';
}

/** Un metodo di pagamento di prova: carta, bonifico o contanti alla consegna secondo il modo. */
function pagamento(string $timing, array $valori = []): int
{
    return (int) (PaymentMethod::create($valori + [
        'code' => 'tst_'.uniqid(),
        'name' => 'Prova '.$timing,
        'provider' => $timing === PaymentTiming::IMMEDIATE ? 'stripe' : ($timing === PaymentTiming::ON_DELIVERY ? 'cash' : 'bank_transfer'),
        'timing' => $timing,
        'fee_type' => 'none',
        'fee_value' => '0.00',
        'fee_percent' => '0.00',
        'available_for' => 'all',
        'active' => 'true',
        'position' => 1,
        'instructions' => 'Istruzioni di prova',
    ])->insert_id ?? 0);
}

/** Un metodo per tutta Italia: 8 € fino a 5 kg, 15 € fino a 20, contrassegno a 3,50 €. */
function standard(string $nome = 'Standard'): int
{
    $metodo = metodo($nome);
    listino($metodo, zona('Italia '.$nome, [['IT', '']]), [[5, 8.0], [20, 15.0]], ['cod_fee' => '3.50']);

    return $metodo;
}

function milano(): array
{
    return ['country' => 'IT', 'province' => 'MI', 'city' => 'Milano', 'cap' => '20100', 'street' => 'Via Prova', 'number' => '1'];
}

function parigi(): array
{
    return ['country' => 'FR', 'province' => '', 'city' => 'Paris', 'cap' => '75001', 'street' => 'Rue de Rivoli', 'number' => '1'];
}

/** La voce di un metodo di pagamento nell'anteprima, o null. */
function voce(array $opzioni, int $id): ?array
{
    foreach ($opzioni as $opzione) {
        if ($opzione['id'] === $id) {
            return $opzione;
        }
    }

    return null;
}

/** Un coupon di prova, al 10%. @return array{id: int, code: string} */
function couponDiProva(array $valori = []): array
{
    $codice = 'T'.strtoupper(substr(uniqid(), -8));
    $id = (int) (Coupon::create($valori + [
        'code' => $codice,
        'name' => 'Prova '.$codice,
        'discount_type' => 'percent',
        'discount_value' => '10.00',
        'applies_to_all' => 'true',
        'applies_online' => 'true',
        'active' => 'true',
    ])->insert_id ?? 0);

    return ['id' => $id, 'code' => $codice];
}

check('l\'anteprima prende dati a metà senza email, e il bonifico è un metodo a mano', fn () => prova(static function (): bool {
    standard();
    $bonifico = pagamento(PaymentTiming::DEFERRED);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    $r = Checkout::preview($cart, ['payment_method_id' => $bonifico, 'shipping' => milano()]);
    $voce = voce($r['payment_methods']['options'], $bonifico);

    return $r['invalid'] === []
        && $r['payment_methods']['selected'] === $bonifico
        && $voce !== null && $voce['manual'] === true && $voce['instructions'] === 'Istruzioni di prova'
        && (int) Order::findById($cart)['payment_method_id'] === $bonifico
        && trim((string) Order::findById($cart)['email']) === '';
}));

check('l\'unico metodo che copre l\'indirizzo si sceglie da solo; a Parigi se ne va con un avviso', fn () => prova(static function (): bool {
    $metodo = standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    $milano = Checkout::preview($cart, ['shipping' => milano()]);
    $parigi = Checkout::preview($cart, ['shipping' => parigi()]);

    return $milano['shipping_methods']['selected'] === $metodo
        && array_column($milano['shipping_methods']['options'], 'method_id') === [$metodo]
        && (float) $milano['order']['shipping_total'] === 8.0
        && $parigi['shipping_methods'] === ['options' => [], 'selected' => 0]
        && (float) $parigi['order']['shipping_total'] === 0.0
        && righeDi($cart, 'shipping') === []
        && count($parigi['notices']) === 1;
}));

check('la commissione segue il pagamento: 2 € col bonifico, 3,50 € col contrassegno, niente senza metodo', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $bonifico = pagamento(PaymentTiming::DEFERRED, ['fee_type' => 'amount', 'fee_value' => '2.00']);
    $contrassegno = pagamento(PaymentTiming::ON_DELIVERY);

    $a = Checkout::preview($cart, ['shipping' => milano(), 'payment_method_id' => $bonifico]);
    $b = Checkout::preview($cart, ['payment_method_id' => $contrassegno]);
    $fee = righeDi($cart, 'fee');
    $c = Checkout::preview($cart, ['payment_method_id' => 0]);

    return (float) $a['order']['fees_total'] === 2.0
        && (float) $b['order']['fees_total'] === 3.5
        && count($fee) === 1
        && (float) $c['order']['fees_total'] === 0.0
        && $c['payment_methods']['selected'] === 0
        && righeDi($cart, 'fee') === [];
}));

check('ogni pagamento dell\'anteprima porta la commissione che si paga: contrassegno dal listino, percentuale fino al 100%', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $bonifico = pagamento(PaymentTiming::DEFERRED, ['fee_type' => 'percent', 'fee_percent' => '150']);
    $contrassegno = pagamento(PaymentTiming::ON_DELIVERY, ['fee_type' => 'amount', 'fee_value' => '1.00']);
    $p = Checkout::preview($cart, ['shipping' => milano(), 'payment_method_id' => $bonifico]);
    $voci = array_column((array) $p['payment_methods']['options'], null, 'id');

    return ($voci[$bonifico]['fee'] ?? null) === 10.0
        && ($voci[$contrassegno]['fee'] ?? null) === 3.5
        && (float) $p['order']['fees_total'] === 10.0;
}));

check('col ritiro la sola sede si sceglie da sola, la spedizione sparisce e i pagamenti si filtrano', fn () => prova(static function (): bool {
    standard();
    $sede = sede();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $soloSpedizione = pagamento(PaymentTiming::DEFERRED, ['available_for' => 'shipping']);
    $perRitiro = pagamento(PaymentTiming::DEFERRED, ['available_for' => 'pickup']);

    $r = Checkout::preview($cart, ['fulfillment_type' => 'pickup', 'payment_method_id' => $soloSpedizione, 'shipping' => milano()]);
    $ids = array_column($r['payment_methods']['options'], 'id');
    $riga = Order::findById($cart);

    return $r['fulfillment'] === ['type' => 'pickup', 'choices' => ['shipping', 'pickup']]
        && $r['pickup_locations']['selected'] === $sede
        && array_column($r['pickup_locations']['options'], 'id') === [$sede]
        && $r['shipping_methods'] === ['options' => [], 'selected' => 0]
        && righeDi($cart, 'shipping') === []
        && in_array($perRitiro, $ids, true)
        && !in_array($soloSpedizione, $ids, true)
        && $r['payment_methods']['selected'] === 0
        && (int) $riga['location_id'] === $sede
        && (int) $riga['shipping_method_id'] === 0
        && (int) $riga['payment_method_id'] === 0;
}));

check('l\'anteprima mostra solo i pagamenti collegati, con icone e commissione', fn () => prova(static function (): bool {
    // Stripe non collegato anche se il sito di prova ha le chiavi nel .env.
    $scollegato = new FakePaymentProvider();
    $scollegato->connected = false;
    PaymentProviders::register($scollegato);
    $metodo = metodo('Standard');
    listino($metodo, zona('Italia', [['IT', '']]), [[5, 8.0]]);
    $stripe = pagamento(PaymentTiming::IMMEDIATE);
    $bonifico = pagamento(PaymentTiming::DEFERRED, ['icons' => 'genericbank,<x>', 'fee_type' => 'amount', 'fee_value' => '1.50']);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $p = Checkout::preview($cart, ['shipping_country' => 'IT', 'shipping_method_id' => $metodo]);
    PaymentProviders::reset();
    $voci = array_column((array) $p['payment_methods']['options'], null, 'id');

    return !isset($voci[$stripe]) && isset($voci[$bonifico])
        && $voci[$bonifico]['icons'] === ['genericbank']
        && $voci[$bonifico]['fee_type'] === 'amount'
        && $voci[$bonifico]['fee_value'] === 1.5;
}));

check('con più sedi vale quella scelta se è di ritiro, altrimenti nessuna', fn () => prova(static function (): bool {
    sede();
    $seconda = sede();
    $altra = sede(false);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    $scelta = Checkout::preview($cart, ['fulfillment_type' => 'pickup', 'location_id' => $seconda]);
    $sbagliata = Checkout::preview($cart, ['fulfillment_type' => 'pickup', 'location_id' => $altra]);

    return $scelta['pickup_locations']['selected'] === $seconda
        && $sbagliata['pickup_locations']['selected'] === 0
        && count($sbagliata['pickup_locations']['options']) === 2
        && (int) Order::findById($cart)['location_id'] === 0;
}));

check('senza sedi di ritiro la consegna torna spedizione', fn () => prova(static function (): bool {
    $altra = sede(false);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    $r = Checkout::preview($cart, ['fulfillment_type' => 'pickup', 'location_id' => $altra]);
    $riga = Order::findById($cart);

    return $r['fulfillment'] === ['type' => 'shipping', 'choices' => ['shipping']]
        && $r['pickup_locations'] === ['options' => [], 'selected' => 0]
        && (string) $riga['fulfillment_type'] === 'shipping'
        && (int) $riga['location_id'] === 0;
}));

check('il coupon che non regge più cade, con la sua frase fra gli avvisi', fn () => prova(static function (): bool {
    $prodotto = articolo(2.0, 40.0);
    $cart = carrello([[$prodotto, 1]]);
    $coupon = couponDiProva(['min_order_amount' => '30.00']);
    Coupons::apply($cart, $coupon['code']);
    Product::update(['price' => '5.00'], $prodotto);

    $r = Checkout::preview($cart, []);

    return $r['coupon'] === ['code' => '', 'dropped' => 'min_order']
        && in_array(UserError::make('coupon.min_order')->getMessage(), $r['notices'], true);
}));

check('l\'anteprima non prenota, non numera, non consuma il coupon e non apre pagamenti', fn () => prova(static function (): bool {
    standard();
    $prodotto = articolo(2.0, 10.0);
    $cart = carrello([[$prodotto, 1]]);
    $coupon = couponDiProva();
    Coupons::apply($cart, $coupon['code']);
    $prima = Levels::of($prodotto)['available'];

    Checkout::preview($cart, ['payment_method_id' => pagamento(PaymentTiming::IMMEDIATE), 'shipping' => milano()]);
    $riga = Order::findById($cart);

    return (string) $riga['stage'] === 'cart'
        && trim((string) $riga['order_number']) === ''
        && Levels::of($prodotto)['available'] === $prima
        && (int) sqlCount(StockReservation::$table, "order_id = {$cart} AND deleted = 'false'") === 0
        && (int) sqlCount(CouponRedemption::$table, "coupon_id = {$coupon['id']}") === 0
        && (int) sqlCount(Payment::$table, "order_id = {$cart} AND deleted = 'false'") === 0;
}));

check('un campo rifiutato torna in invalid e gli altri si scrivono lo stesso', fn () => prova(static function (): bool {
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    $r = Checkout::preview($cart, ['billing' => ['pec' => 'non-una-email', 'city' => 'Torino']]);
    $riga = Order::findById($cart);

    return $r['invalid'] === ['billing_pec']
        && (string) $riga['billing_city'] === 'Torino'
        && trim((string) $riga['billing_pec']) === '';
}));

check('con le spedizioni spente niente sedi e niente metodi', fn () => prova(static function (): bool {
    standard();
    $sede = sede();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    spegniFunzionalita(['shipping']);

    $r = Checkout::preview($cart, ['fulfillment_type' => 'pickup', 'location_id' => $sede, 'shipping' => milano()]);

    return $r['fulfillment'] === ['type' => 'shipping', 'choices' => ['shipping']]
        && $r['shipping_methods'] === ['options' => [], 'selected' => 0]
        && $r['pickup_locations'] === ['options' => [], 'selected' => 0]
        && righeDi($cart, 'shipping') === [];
}));

check('l\'anteprima di un ordine o di niente si rifiuta', fn () => prova(static function (): bool {
    [$ordine] = ordineDaSpedire([[articolo(1.0), 1]]);

    return rifiuto(fn () => Checkout::preview($ordine, [])) === 'cart.not_a_cart'
        && rifiuto(fn () => Checkout::preview(0, [])) === 'cart.not_a_cart';
}));

/** Il checkout, senza spedire posta: col bonifico e l'indirizzo di Milano se non si dice altro. */
function compra(int $cart, array $dati): array
{
    Mailer::useTransport(static fn (): bool => true);

    try {
        return Checkout::place($cart, $dati + [
            'email' => 'cliente@example.com',
            'payment_method_id' => pagamento(PaymentTiming::DEFERRED),
            'fulfillment_type' => 'shipping',
            'billing' => milano() + ['name' => 'Mario', 'surname' => 'Rossi'],
        ]);
    } finally {
        Mailer::useTransport(null);
    }
}

function restaCarrello(int $cart): bool
{
    return (string) Order::findById($cart)['stage'] === 'cart'
        && (int) sqlCount(StockReservation::$table, "order_id = {$cart} AND deleted = 'false'") === 0;
}

check('il ritiro in una sede che non è di ritiro, spenta o nessuna si rifiuta e il carrello resta', fn () => prova(static function (): bool {
    foreach ([sede(false), sede(true, true, 'false'), 0] as $sedeId) {
        $cart = carrello([[articolo(2.0, 10.0), 1]]);

        if (rifiuto(fn () => compra($cart, ['fulfillment_type' => 'pickup', 'location_id' => $sedeId])) !== 'order.pickup_location_unavailable'
            || !restaCarrello($cart)) {
            return false;
        }
    }

    return true;
}));

check('il ritiro in una sede fuori orario passa e prenota la merce di quella sede', fn () => prova(static function (): bool {
    $sede = sede(true, false);
    $prodotto = articolo(2.0, 10.0);
    giacenzaIn($prodotto, $sede, 5);
    $cart = carrello([[$prodotto, 1]]);

    $esito = compra($cart, ['fulfillment_type' => 'pickup', 'location_id' => $sede]);
    $prenotazioni = righe(StockReservation::class, "order_id = {$cart} AND deleted = 'false'");

    return $esito['status'] === 'pending'
        && count($prenotazioni) === 1
        && (int) $prenotazioni[0]['location_id'] === $sede;
}));

check('il ritiro in una sede senza la merce si ferma e il carrello resta', fn () => prova(static function (): bool {
    $sede = sede();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    return rifiuto(fn () => compra($cart, ['fulfillment_type' => 'pickup', 'location_id' => $sede])) === 'stock.insufficient'
        && restaCarrello($cart);
}));

check('una spedizione senza metodo si rifiuta; col metodo passa con la sua riga', fn () => prova(static function (): bool {
    $metodo = standard();
    $senza = carrello([[articolo(2.0, 10.0), 1]]);
    $con = carrello([[articolo(2.0, 10.0), 1]]);

    $rifiuto = rifiuto(fn () => compra($senza, []));
    $esito = compra($con, ['shipping_method_id' => $metodo]);

    return $rifiuto === 'order.shipping_method_required'
        && restaCarrello($senza)
        && $esito['status'] === 'pending'
        && count(righeDi($con, 'shipping')) === 1;
}));

check('una spedizione dove nessun metodo arriva si rifiuta', fn () => prova(static function (): bool {
    $metodo = standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    return rifiuto(fn () => compra($cart, ['shipping_method_id' => $metodo, 'shipping' => parigi()])) === 'order.shipping_unavailable'
        && restaCarrello($cart);
}));

check('con le spedizioni spente si compra senza metodo', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    spegniFunzionalita(['shipping']);

    return compra($cart, [])['status'] === 'pending';
}));

check('un carrello di soli servizi non offre metodi e si compra senza', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(0.0, 10.0, [0, 0, 0], 'false'), 1]]);

    $anteprima = Checkout::preview($cart, ['shipping' => milano()]);

    return $anteprima['shipping_methods'] === ['options' => [], 'selected' => 0]
        && compra($cart, [])['status'] === 'pending';
}));

check('un ordine fatto dal sistema non chiede il metodo', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    return compra($cart, ['source' => 'system'])['status'] === 'pending';
}));

check('con due schede aperte vale il modulo inviato', fn () => prova(static function (): bool {
    $metodo = standard();
    $espresso = metodo('Espresso');
    listino($espresso, zona('Italia Espresso', [['IT', '']]), [[20, 20.0]]);
    $sede = sede();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    // L'altra scheda sceglie l'espresso, poi il ritiro.
    Checkout::preview($cart, ['shipping_method_id' => $espresso, 'shipping' => milano()]);
    Checkout::preview($cart, ['fulfillment_type' => 'pickup', 'location_id' => $sede]);
    compra($cart, ['shipping_method_id' => $metodo]);
    $riga = Order::findById($cart);

    return (string) $riga['fulfillment_type'] === 'shipping'
        && (int) $riga['shipping_method_id'] === $metodo
        && (int) $riga['location_id'] === 0
        && (float) $riga['shipping_total'] === 8.0;
}));

check('il negozio vero (source ecommerce) senza metodo si ferma come un cliente', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    return rifiuto(fn () => compra($cart, ['source' => 'ecommerce'])) === 'order.shipping_method_required'
        && restaCarrello($cart);
}));

check('«nessuna consegna» non scavalca il metodo per una merce da spedire', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    return rifiuto(fn () => compra($cart, ['fulfillment_type' => 'none'])) === 'order.shipping_method_required'
        && restaCarrello($cart);
}));

check('una spedizione non porta con sé la sede rimasta nel modulo', fn () => prova(static function (): bool {
    $metodo = standard();
    $sede = sede();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    // La sede di ritiro non ha la merce: la spedizione parte comunque dal magazzino principale.
    compra($cart, ['shipping_method_id' => $metodo, 'location_id' => $sede]);

    return (int) Order::findById($cart)['location_id'] === 0;
}));

check('l\'anteprima scrive email e telefono sul carrello', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $p = Checkout::preview($cart, ['shipping' => milano(), 'email' => 'c@example.com', 'phone' => '333 1234567']);
    $ordine = Order::findById($cart);

    return $p['invalid'] === [] && $ordine['email'] === 'c@example.com' && $ordine['phone'] === '333 1234567';
}));

check('un\'email non valida non si scrive e torna in invalid, il telefono sì', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $p = Checkout::preview($cart, ['shipping' => milano(), 'email' => 'non-una-email', 'phone' => '333 1234567']);
    $ordine = Order::findById($cart);

    return $p['invalid'] === ['email'] && (string) $ordine['email'] === '' && $ordine['phone'] === '333 1234567';
}));

check('un\'email vuota non cancella quella già sul carrello', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    Checkout::preview($cart, ['shipping' => milano(), 'email' => 'c@example.com']);
    Checkout::preview($cart, ['shipping' => milano(), 'email' => '']);

    return Order::findById($cart)['email'] === 'c@example.com';
}));

summary();
