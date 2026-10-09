<?php
/** php tests/integrazione/CartShippingTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/spedizioni.php';

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRate;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Plugin\Gestionale\Support\Orders\PaymentTiming;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Sql\Transaction;

/** Serve a buttare via tutto quello che la prova scrive. */
final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            // Il sito di prova può avere i dati demo: i test partono da zone vuote.
            \Wonder\Plugin\Gestionale\Seeding\ShippingDemo::clear();
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    }

    return $esito;
}

/** Le righe di un tipo, come stanno scritte adesso. */
function righe(int $cart, string $tipo): array
{
    $trovate = OrderItem::find(['order_id' => $cart, 'type' => $tipo, 'deleted' => 'false']);

    if (!is_array($trovate) || $trovate === []) {
        return [];
    }

    return array_key_exists('id', $trovate) ? [$trovate] : array_values(array_filter($trovate, 'is_array'));
}

/** Un carrello con l'Italia coperta da un metodo «Standard» a scaglioni e il metodo già scelto. */
function carrelloConMetodo(array $righeCarrello, array $scaglioni = [[5, 8.0], [20, 15.0]], array $listino = [], array $ordine = []): array
{
    $it = zona('Italia', [['IT', '']]);
    $metodo = metodo('Standard');
    listino($metodo, $it, $scaglioni, $listino);
    $cart = carrello($righeCarrello, $ordine);
    Order::update(['fulfillment_type' => 'shipping', 'shipping_method_id' => $metodo], $cart);

    return [$cart, $metodo, $it];
}

check('con metodo e destinazione il carrello ha una riga di spedizione e i totali tornano', fn () => prova(static function (): bool {
    [$cart] = carrelloConMetodo([[articolo(2.0, 10.0), 1]]);

    $esito = Cart::recalculate($cart);
    $spedizione = righe($cart, 'shipping');
    $taxId = (int) (Setting::current()['shipping_tax_id'] ?? 0);
    $tax = $taxId > 0 ? Tax::findById($taxId) : null;

    return count($spedizione) === 1
        && $spedizione[0]['name'] === 'Standard'
        && (string) $spedizione[0]['line_total'] === '8.00'
        && (int) $spedizione[0]['position'] === 800
        && (int) $spedizione[0]['tax_id'] === $taxId
        && is_array($tax)
        && (float) $spedizione[0]['tax_rate'] === (float) $tax['rate']
        && (string) $esito['order']['shipping_total'] === '8.00'
        && (string) $esito['order']['products_total'] === '10.00'
        && (string) $esito['order']['total'] === '18.00'
        && $esito['shipping_dropped'] === '';
}));

check('due ricalcoli di fila lasciano una sola riga di spedizione', fn () => prova(static function (): bool {
    [$cart] = carrelloConMetodo([[articolo(2.0), 1]]);

    Cart::recalculate($cart);
    Cart::recalculate($cart);

    return count(righe($cart, 'shipping')) === 1;
}));

check('aggiungere un articolo pesante ricalcola il prezzo della spedizione', fn () => prova(static function (): bool {
    [$cart] = carrelloConMetodo([[articolo(2.0), 1]]);
    $prima = Cart::recalculate($cart)['order']['shipping_total'];

    $dopo = Cart::add($cart, ['product_id' => articolo(10.0), 'quantity' => 1]);

    return (string) $prima === '8.00'
        && (string) $dopo['order']['shipping_total'] === '15.00'
        && count(righe($cart, 'shipping')) === 1;
}));

check('una destinazione non coperta toglie la riga e dice perché, il metodo resta scelto', fn () => prova(static function (): bool {
    [$cart, $metodo] = carrelloConMetodo([[articolo(2.0), 1]]);
    Cart::recalculate($cart);

    Order::update(['shipping_country' => 'DE', 'shipping_province' => '', 'shipping_city' => 'Berlino'], $cart);
    $esito = Cart::recalculate($cart);

    return righe($cart, 'shipping') === []
        && (string) $esito['order']['shipping_total'] === '0.00'
        && $esito['shipping_dropped'] !== ''
        && str_contains($esito['shipping_dropped'], 'Standard')
        && (int) Order::findById($cart)['shipping_method_id'] === $metodo;
}));

check('con il ritiro o senza consegna non c\'è la riga', fn () => prova(static function (): bool {
    [$cart] = carrelloConMetodo([[articolo(2.0), 1]]);
    Cart::recalculate($cart);

    Order::update(['fulfillment_type' => 'pickup'], $cart);
    Cart::recalculate($cart);
    $ritiro = righe($cart, 'shipping');

    Order::update(['fulfillment_type' => 'none'], $cart);
    $esito = Cart::recalculate($cart);

    return $ritiro === []
        && righe($cart, 'shipping') === []
        && (string) $esito['order']['shipping_total'] === '0.00'
        && $esito['shipping_dropped'] === '';
}));

check('una riga di spedizione a mano resta intatta e non se ne aggiunge un\'altra', fn () => prova(static function (): bool {
    [$cart] = carrelloConMetodo([[articolo(2.0), 1]]);
    OrderItem::create([
        'order_id' => $cart,
        'type' => 'shipping',
        'name' => 'Corriere del cliente',
        'list_price' => '3.00',
        'unit_price' => '3.00',
        'price_source' => 'manual',
        'quantity' => '1.000',
        'line_total' => '3.00',
        'position' => 90,
    ]);

    $esito = Cart::recalculate($cart);
    Cart::recalculate($cart);
    $spedizione = righe($cart, 'shipping');

    return count($spedizione) === 1
        && $spedizione[0]['name'] === 'Corriere del cliente'
        && (string) $spedizione[0]['unit_price'] === '3.00'
        && (string) $esito['order']['shipping_total'] === '3.00';
}));

check('un carrello di soli servizi non ha la riga', fn () => prova(static function (): bool {
    [$cart] = carrelloConMetodo([[articolo(2.0, 10.0, [0, 0, 0], 'false'), 1]]);

    $esito = Cart::recalculate($cart);

    return righe($cart, 'shipping') === [] && (string) $esito['order']['shipping_total'] === '0.00';
}));

check('con la funzionalità spenta non si crea niente', fn () => prova(static function (): bool {
    [$cart] = carrelloConMetodo([[articolo(2.0), 1]]);
    spegniFunzionalita(['shipping']);

    $esito = Cart::recalculate($cart);

    return righe($cart, 'shipping') === [] && (string) $esito['order']['shipping_total'] === '0.00';
}));

check('con la funzionalità spenta una riga calcolata già scritta non si tocca', fn () => prova(static function (): bool {
    [$cart] = carrelloConMetodo([[articolo(2.0), 1]]);
    Cart::recalculate($cart);
    spegniFunzionalita(['shipping']);

    Cart::recalculate($cart);

    return count(righe($cart, 'shipping')) === 1;
}));

check('il coupon di spedizione gratuita azzera la riga e dice quanto si è risparmiato', fn () => prova(static function (): bool {
    accendiFunzionalita(['coupons']);
    [$cart] = carrelloConMetodo([[articolo(2.0, 10.0), 1]]);
    $codice = 'K'.strtoupper(substr(uniqid(), -8));
    Coupon::create([
        'code' => $codice, 'name' => 'Prova', 'discount_type' => 'free_shipping', 'discount_value' => '0.00',
        'applies_to_all' => 'true', 'applies_online' => 'true', 'active' => 'true',
    ]);

    Coupons::apply($cart, $codice);
    $esito = Cart::recalculate($cart);
    $spedizione = righe($cart, 'shipping');

    return count($spedizione) === 1
        && (string) $spedizione[0]['line_total'] === '0.00'
        && (string) $esito['order']['shipping_total'] === '0.00'
        && (string) $esito['order']['total'] === '10.00'
        && $esito['shipping_saved'] === '8.00';
}));

check('la soglia gratuita conta la merce prima del coupon, non dopo', fn () => prova(static function (): bool {
    accendiFunzionalita(['coupons']);
    // Merce da 25 €, soglia a 24 €: il coupon del 10 % porta lo scontrino a 22,50 €,
    // ma la spedizione si decide sulla merce a prezzo di riga e resta gratuita.
    [$cart] = carrelloConMetodo([[articolo(2.0, 25.0), 1]], [[5, 8.0]], ['free_over_amount' => '24.00']);
    $codice = 'K'.strtoupper(substr(uniqid(), -8));
    Coupon::create([
        'code' => $codice, 'name' => 'Prova', 'discount_type' => 'percent', 'discount_value' => '10.00',
        'applies_to_all' => 'true', 'applies_online' => 'true', 'active' => 'true',
    ]);

    $senza = Cart::recalculate($cart);
    $con = Coupons::apply($cart, $codice);

    return (string) $senza['order']['shipping_total'] === '0.00'
        && count(righe($cart, 'shipping')) === 1
        && (string) $con['order']['discount_total'] === '2.50'
        && (string) $con['order']['shipping_total'] === '0.00'
        && (string) $con['order']['total'] === '22.50';
}));

check('un ordine già fatto non cambia se il listino cambia dopo', fn () => prova(static function (): bool {
    [$cart, $metodo, $zona] = carrelloConMetodo([[articolo(2.0), 1]]);
    Cart::recalculate($cart);
    Order::update(['stage' => 'order', 'status' => 'pending'], $cart);

    $rate = ShippingRate::find(['shipping_method_id' => $metodo, 'shipping_zone_id' => $zona, 'deleted' => 'false'], 1);
    ShippingRate::update(['min_price' => '50.00'], (int) $rate['id']);

    try {
        Cart::recalculate($cart);
        $rifiutato = false;
    } catch (UserError) {
        $rifiutato = true;
    }

    $riga = righe($cart, 'shipping');

    return $rifiutato
        && count($riga) === 1
        && (string) $riga[0]['line_total'] === '8.00'
        && (string) Order::findById($cart)['shipping_total'] === '8.00';
}));

/** Un metodo di pagamento di prova col contrassegno o altro, con la sua commissione. */
function pagamento(string $timing, string $feeType = 'none', float $feeValue = 0, float $feePercent = 0): int
{
    return (int) (PaymentMethod::create([
        'code' => 'tst_'.uniqid(),
        'name' => 'Prova '.$timing,
        'provider' => $timing === 'on_delivery' ? 'cash' : 'bank_transfer',
        'timing' => $timing,
        'fee_type' => $feeType,
        'fee_value' => in_array($feeType, ['amount', 'amount_percent'], true) ? number_format($feeValue, 2, '.', '') : '0.00',
        'fee_percent' => $feeType === 'percent' ? number_format($feeValue, 2, '.', '') : ($feeType === 'amount_percent' ? number_format($feePercent, 2, '.', '') : '0.00'),
        'available_for' => 'all',
        'active' => 'true',
        'position' => 1,
        'instructions' => 'Istruzioni di prova',
    ])->insert_id ?? 0);
}

/** Manda in cassa il carrello con il metodo di pagamento dato; ridà la riga `fee`, se c'è. */
function incassa(int $cart, int $metodoDiPagamento, int $metodoDiSpedizione): ?array
{
    Mailer::useTransport(static fn (): bool => true);

    try {
        Checkout::place($cart, [
            'email' => 'cliente@example.com',
            'payment_method_id' => $metodoDiPagamento,
            'fulfillment_type' => 'shipping',
            'shipping_method_id' => $metodoDiSpedizione,
            'billing' => [
                'country' => 'IT', 'province' => 'MI', 'city' => 'Milano', 'cap' => '20100',
                'street' => 'Via Prova', 'number' => '1', 'name' => 'Mario', 'surname' => 'Rossi',
            ],
        ]);
    } finally {
        Mailer::useTransport(null);
    }

    return righe($cart, 'fee')[0] ?? null;
}

check('il contrassegno prende il cod_fee del listino al posto della commissione del metodo', fn () => prova(static function (): bool {
    [$cart, $metodo] = carrelloConMetodo([[articolo(2.0, 10.0), 1]], [[5, 8.0]], ['cod_fee' => '4.00']);
    $fee = incassa($cart, pagamento(PaymentTiming::ON_DELIVERY, 'percent', 2.0), $metodo);

    return $fee !== null && (string) $fee['line_total'] === '4.00';
}));

check('senza cod_fee resta la commissione del metodo di pagamento', fn () => prova(static function (): bool {
    [$cart, $metodo] = carrelloConMetodo([[articolo(2.0, 10.0), 1]], [[5, 8.0]]);
    $fee = incassa($cart, pagamento(PaymentTiming::ON_DELIVERY, 'percent', 2.0), $metodo);

    return $fee !== null && (string) $fee['line_total'] === '0.20';
}));

check('un metodo che non è il contrassegno ignora il cod_fee', fn () => prova(static function (): bool {
    [$cart, $metodo] = carrelloConMetodo([[articolo(2.0, 10.0), 1]], [[5, 8.0]], ['cod_fee' => '4.00']);
    $fee = incassa($cart, pagamento(PaymentTiming::DEFERRED, 'percent', 2.0), $metodo);

    return $fee !== null && (string) $fee['line_total'] === '0.20';
}));

check('il contrassegno col cod_fee ma senza commissione propria mette comunque il cod_fee', fn () => prova(static function (): bool {
    [$cart, $metodo] = carrelloConMetodo([[articolo(2.0, 10.0), 1]], [[5, 8.0]], ['cod_fee' => '4.00']);
    $fee = incassa($cart, pagamento(PaymentTiming::ON_DELIVERY), $metodo);

    return $fee !== null && (string) $fee['line_total'] === '4.00';
}));

check('importo fisso + percentuale: la commissione è la somma dei due', fn () => prova(static function (): bool {
    [$cart, $metodo] = carrelloConMetodo([[articolo(2.0, 10.0), 1]], [[5, 8.0]]);
    $fee = incassa($cart, pagamento(PaymentTiming::DEFERRED, 'amount_percent', 1.5, 2.0), $metodo);

    return $fee !== null && (string) $fee['line_total'] === '1.70';
}));

check('con commissione fissa la percentuale avanzata non conta', fn () => prova(static function (): bool {
    [$cart, $metodo] = carrelloConMetodo([[articolo(2.0, 10.0), 1]], [[5, 8.0]]);
    $fee = incassa($cart, pagamento(PaymentTiming::DEFERRED, 'amount', 1.5, 2.0), $metodo);

    return $fee !== null && (string) $fee['line_total'] === '1.50';
}));

summary();
