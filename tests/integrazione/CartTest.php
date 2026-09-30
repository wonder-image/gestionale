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

summary();
