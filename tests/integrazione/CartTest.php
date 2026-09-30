<?php
/** php tests/integrazione/CartTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Sql\Transaction;

/** Serve a buttare via tutto quello che la prova scrive. */
final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    }

    return $esito;
}

check('un carrello nuovo nasce con il gettone dell\'ospite', function () {
    return prova(static function (): bool {
        $carrello = Cart::open(['cart_token' => 'tok-'.uniqid()]);

        return (int) $carrello['id'] > 0
            && $carrello['stage'] === 'cart'
            && $carrello['status'] === 'draft'
            && trim((string) $carrello['last_activity_at']) !== '';
    });
});

check('lo stesso gettone ritrova il carrello di prima', function () {
    return prova(static function (): bool {
        $gettone = 'tok-'.uniqid();

        return (int) Cart::open(['cart_token' => $gettone])['id']
            === (int) Cart::open(['cart_token' => $gettone])['id'];
    });
});

check('la riga aggiunta porta prezzo, quantità e totale', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        Product::update(['price' => '20.00'], $prodotto);

        $carrello = Cart::open(['cart_token' => 'tok-'.uniqid()]);
        $esito = Cart::add((int) $carrello['id'], ['product_id' => $prodotto, 'quantity' => 2]);
        $riga = $esito['items'][0] ?? [];

        return count($esito['items']) === 1
            && (string) $riga['unit_price'] === '20.00'
            && (string) $riga['line_total'] === '40.00'
            && (string) $esito['order']['products_total'] === '40.00';
    });
});

check('due volte lo stesso articolo fanno una riga sola', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        Product::update(['price' => '20.00'], $prodotto);

        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 1]);
        $esito = Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 2]);

        return count($esito['items']) === 1
            && (float) $esito['items'][0]['quantity'] === 3.0
            && (string) $esito['order']['products_total'] === '60.00';
    });
});

check('più pezzi di quanti ce ne sono: rifiutato, e dice quanti restano', function () {
    return prova(static function (): string {
        $prodotto = articoloConGiacenza(3, 'TST-CART-'.substr((string) microtime(true), -6));
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];

        try {
            Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 4]);
        } catch (UserError $errore) {
            return $errore->getMessage();
        }

        return 'nessun rifiuto';
    }) !== 'nessun rifiuto';
});

check('quantità zero: rifiutata', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(3, 'TST-CART-'.substr((string) microtime(true), -6));
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];

        try {
            Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 0]);
        } catch (UserError) {
            return true;
        }

        return false;
    });
});

check('la quantità cambiata rifà il totale', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        Product::update(['price' => '15.00'], $prodotto);

        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $esito = Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 1]);
        $riga = (int) $esito['items'][0]['id'];
        $dopo = Cart::setQuantity($carrello, $riga, 4);

        return (float) $dopo['items'][0]['quantity'] === 4.0
            && (string) $dopo['order']['products_total'] === '60.00';
    });
});

check('la quantità a zero toglie la riga', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $esito = Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 1]);
        $dopo = Cart::setQuantity($carrello, (int) $esito['items'][0]['id'], 0);

        return $dopo['items'] === [] && (string) $dopo['order']['products_total'] === '0.00';
    });
});

check('la riga tolta non torna', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $esito = Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 2]);

        return Cart::remove($carrello, (int) $esito['items'][0]['id'])['items'] === [];
    });
});

check('la riga di un altro carrello non si tocca', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        $mio = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $altrui = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $esito = Cart::add($altrui, ['product_id' => $prodotto, 'quantity' => 1]);

        try {
            Cart::remove($mio, (int) $esito['items'][0]['id']);
        } catch (UserError) {
            return true;
        }

        return false;
    });
});

check('due carrelli uniti sommano le righe uguali', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        Product::update(['price' => '10.00'], $prodotto);

        $ospite = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $cliente = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($ospite, ['product_id' => $prodotto, 'quantity' => 2]);
        Cart::add($cliente, ['product_id' => $prodotto, 'quantity' => 1]);

        $unito = Cart::merge($ospite, $cliente);

        return count($unito['items']) === 1
            && (float) $unito['items'][0]['quantity'] === 3.0
            && (string) $unito['order']['products_total'] === '30.00'
            && empty(Wonder\Plugin\Gestionale\Models\Sales\Order::find(
                ['id' => $ospite, 'deleted' => 'false'],
                1
            ));
    });
});

check('l\'unione non scrive più pezzi di quanti ce ne sono', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(3, 'TST-CART-'.substr((string) microtime(true), -6));
        $ospite = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $cliente = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($ospite, ['product_id' => $prodotto, 'quantity' => 2]);
        Cart::add($cliente, ['product_id' => $prodotto, 'quantity' => 2]);

        // Quattro pezzi chiesti, tre sul banco: l'unione si ferma a tre invece
        // di scrivere una quantità che il checkout non potrebbe prenotare.
        return (float) Cart::merge($ospite, $cliente)['items'][0]['quantity'] === 3.0;
    });
});

check('l\'articolo spento sotto il carrello esce, e si sa', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 1]);

        Product::update(['active' => 'false'], $prodotto);
        $dopo = Cart::recalculate($carrello);

        return $dopo['items'] === []
            && count($dopo['removed']) === 1
            && (string) $dopo['order']['total'] === '0.00';
    });
});

summary();
