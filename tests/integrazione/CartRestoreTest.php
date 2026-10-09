<?php
/** php tests/integrazione/CartRestoreTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Sql\Transaction;

/** Serve a buttare via tutto quello che la prova scrive. */
final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            accendiFunzionalita(['orders', 'customizations', 'bundles', 'coupons']);
            spegniFunzionalita(['shipping']);
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    }

    Gestionale::reset();

    return $esito;
}

/** Un articolo a 20,00 con la giacenza data. */
function articoloDa20(float $giacenza): int
{
    $prodotto = articoloConGiacenza($giacenza, 'TST-RST-'.strtoupper(substr(uniqid(), -7)));
    Product::update(['price' => '20.00'], $prodotto);

    return $prodotto;
}

/** Il carrello che diventa l'ordine rifiutato: le righe restano sue. */
function ordineRifiutato(callable $riempi): int
{
    $ordine = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
    $riempi($ordine);
    Order::update(['stage' => 'order', 'status' => 'cancelled'], $ordine);

    return $ordine;
}

/** Il carrello nuovo dove tornano le cose. */
function carrelloNuovo(): int
{
    return (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
}

/** @return list<array<string, mixed>> */
function righeDi(int $ordine): array
{
    $righe = OrderItem::find(['order_id' => $ordine, 'deleted' => 'false']);

    return isset($righe['id']) ? [$righe] : array_values((array) $righe);
}

check('le righe tornano nel carrello e restano anche sull\'ordine annullato', function () {
    $x = prova(static function (): array {
        $prodotto = articoloDa20(10);
        $ordine = ordineRifiutato(static fn (int $id) => Cart::add($id, ['product_id' => $prodotto, 'quantity' => 2]));
        $carrello = carrelloNuovo();

        return ['esito' => Cart::restore($ordine, $carrello), 'prodotto' => $prodotto, 'righe_ordine' => count(righeDi($ordine))];
    });
    $riga = $x['esito']['items'][0] ?? [];

    return count($x['esito']['items']) === 1
        && (int) $riga['product_id'] === $x['prodotto']
        && (float) $riga['quantity'] === 2.0
        && (string) $riga['unit_price'] === '20.00'
        && $x['esito']['removed'] === []
        && $x['righe_ordine'] === 1;
});

check('la personalizzazione torna con il suo valore e il sovrapprezzo', function () {
    $x = prova(static function (): array {
        $prodotto = articoloDa20(10);
        $incisione = personalizzazioneDiProva(['name' => 'Incisione', 'surcharge' => '5.00']);
        collegaPersonalizzazione(modelloDi($prodotto), $incisione);
        $ordine = ordineRifiutato(static fn (int $id) => Cart::add($id, ['product_id' => $prodotto, 'customization' => [$incisione => 'Marco']]));

        return Cart::restore($ordine, carrelloNuovo());
    });
    $campo = $x['items'][0]['customization'][0] ?? [];

    return count($x['items']) === 1
        && (string) $x['items'][0]['unit_price'] === '25.00'
        && ($campo['value'] ?? '') === 'Marco';
});

check('un multiprodotto torna con le opzioni scelte', function () {
    $x = prova(static function (): array {
        [$a, $c, $d] = array_map(static fn (): int => articoloDa20(20), [1, 2, 3]);
        $confezione = multiprodottoDiProva('mixed', [['product_id' => $a, 'quantity' => 2]], [
            ['name' => 'Colore', 'min' => 1, 'max' => 1, 'options' => [['product_id' => $c], ['product_id' => $d]]],
        ]);
        Product::update(['price' => '25.00'], $confezione);
        $opzioneD = (int) Bundles::forModel(modelloDi($confezione))['groups'][0]['options'][1]['id'];
        $ordine = ordineRifiutato(static fn (int $id) => Cart::add($id, ['product_id' => $confezione, 'quantity' => 2, 'choices' => [$opzioneD]]));

        return ['esito' => Cart::restore($ordine, carrelloNuovo()), 'a' => $a, 'd' => $d];
    });
    $madre = $x['esito']['items'][0] ?? [];
    $pezzi = [];
    foreach ($madre['children'] ?? [] as $figlia) {
        $pezzi[(int) $figlia['product_id']] = (float) $figlia['quantity'];
    }
    ksort($pezzi);
    $atteso = [$x['a'] => 4.0, $x['d'] => 2.0];
    ksort($atteso);

    return count($x['esito']['items']) === 1
        && (float) ($madre['quantity'] ?? 0) === 2.0
        && $pezzi === $atteso;
});

check('con meno merce la quantità si taglia, e un articolo spento esce con il nome', function () {
    $x = prova(static function (): array {
        $scarso = articoloDa20(5);
        $spento = articoloDa20(5);
        $ordine = ordineRifiutato(static function (int $id) use ($scarso, $spento): void {
            Cart::add($id, ['product_id' => $scarso, 'quantity' => 3]);
            Cart::add($id, ['product_id' => $spento, 'quantity' => 1]);
        });
        // Qualcun altro ha comprato nel frattempo: dei 5 pezzi ne resta 1.
        Stock::apply(['product_id' => $scarso, 'quantity' => -4, 'reason' => 'damaged']);
        Product::update(['active' => 'false'], $spento);

        return ['esito' => Cart::restore($ordine, carrelloNuovo()), 'scarso' => $scarso, 'nome' => (string) righeDi($ordine)[1]['name']];
    });

    return count($x['esito']['items']) === 1
        && (int) $x['esito']['items'][0]['product_id'] === $x['scarso']
        && (float) $x['esito']['items'][0]['quantity'] === 1.0
        && $x['esito']['removed'] === [$x['nome']];
});

check('contatti, consegna, pagamento, indirizzi e coupon tornano sul carrello', function () {
    $x = prova(static function (): array {
        $prodotto = articoloDa20(10);
        $codice = 'K'.strtoupper(substr(uniqid(), -8));
        Coupon::create([
            'code' => $codice, 'name' => 'Prova '.$codice, 'discount_type' => 'percent', 'discount_value' => '10.00',
            'applies_to_all' => 'true', 'applies_online' => 'true', 'active' => 'true',
        ]);
        $campi = [
            'email' => 'cliente@example.com',
            'phone' => '3330000000',
            'fulfillment_type' => 'shipping',
            'payment_method_id' => 77,
            'customer_note' => 'Citofono rotto',
            'shipping_name' => 'Mario',
            'shipping_city' => 'Milano',
            'billing_name' => 'Mario',
            'billing_street' => 'Via Prova',
        ];
        $ordine = ordineRifiutato(static function (int $id) use ($prodotto, $codice, $campi): void {
            Cart::add($id, ['product_id' => $prodotto, 'quantity' => 1]);
            Coupons::apply($id, $codice);
            Order::update($campi, $id);
        });
        $carrello = carrelloNuovo();
        Cart::restore($ordine, $carrello);

        return ['carrello' => Order::findById($carrello), 'campi' => $campi, 'coupon' => (int) Order::findById($ordine)['coupon_id']];
    });

    foreach ($x['campi'] as $chiave => $valore) {
        if ((string) $x['carrello'][$chiave] !== (string) $valore) {
            return false;
        }
    }

    return $x['coupon'] > 0
        && (int) $x['carrello']['coupon_id'] === $x['coupon']
        && (string) $x['carrello']['stage'] === 'cart';
});

check('dentro un ordine che non c\'è, il carrello resta com\'è', function () {
    $x = prova(static fn (): array => Cart::restore(999999999, carrelloNuovo()));

    return $x['items'] === [] && $x['removed'] === [];
});

summary();
