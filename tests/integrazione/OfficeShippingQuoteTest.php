<?php
/** php tests/integrazione/OfficeShippingQuoteTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/spedizioni.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderForm;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeShippingQuote;
use Wonder\Sql\Transaction;

/*
 * Il costo di spedizione del nuovo ordine da ufficio, dal listino, prima che
 * la bozza esista; e la sede del ritiro che diventa la sede dell'ordine.
 */

final class Annulla extends RuntimeException {}

function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            \Wonder\Plugin\Gestionale\Seeding\ShippingDemo::clear();
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
}

check('il metodo scelto dà il prezzo del listino per peso e destinazione', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $standard = metodo('Standard');
    listino($standard, $it, [[1, 5.0], [5, 8.0]]);
    $righe = json_encode([['type' => 'product', 'product_id' => articolo(2.0), 'quantity' => 1, 'line_total' => 10]]);

    $esito = OfficeShippingQuote::quote(['shipping_method_id' => $standard, 'lines' => $righe, 'billing_country' => 'IT']);

    return $esito === ['ok' => true, 'amount' => '8.00', 'free' => false, 'message' => ''];
}));

check('sopra la soglia del listino è gratis', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $standard = metodo('Standard');
    listino($standard, $it, [[5, 8.0]], ['free_over_amount' => '50.00']);
    $righe = [['type' => 'product', 'product_id' => articolo(2.0), 'quantity' => 2, 'line_total' => 60]];

    $esito = OfficeShippingQuote::quote(['shipping_method_id' => $standard, 'lines' => $righe]);

    return $esito['ok'] && $esito['amount'] === '0.00' && $esito['free'] === true;
}));

check('una destinazione senza listino dice il motivo; senza righe o metodo niente costo', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $standard = metodo('Standard');
    listino($standard, $it, [[5, 8.0]]);
    $righe = [['type' => 'product', 'product_id' => articolo(2.0), 'quantity' => 1, 'line_total' => 10]];
    $estero = OfficeShippingQuote::quote(['shipping_method_id' => $standard, 'lines' => $righe, 'shipping_country' => 'DE', 'shipping_city' => 'Berlin']);
    $vuoto = OfficeShippingQuote::quote(['shipping_method_id' => $standard, 'lines' => '[]']);
    $nessuno = OfficeShippingQuote::quote(['shipping_method_id' => 0, 'lines' => $righe]);

    return $estero['ok'] === false && $estero['amount'] === null && $estero['message'] !== ''
        && $vuoto['ok'] === false && $vuoto['message'] !== ''
        && $nessuno === ['ok' => false, 'amount' => null, 'free' => false, 'message' => ''];
}));

check('col ritiro la sede del ritiro diventa la sede dell\'ordine; con la spedizione no', function () {
    $ritiro = OfficeOrderForm::header(['fulfillment_type' => 'pickup', 'location_id' => '3', 'pickup_location_id' => '7']);
    $spedizione = OfficeOrderForm::header(['fulfillment_type' => 'shipping', 'location_id' => '3', 'pickup_location_id' => '7']);

    return $ritiro['location_id'] === '7' && $spedizione['location_id'] === '3'
        && !isset($ritiro['pickup_location_id']);
});

check('col ritiro la sede deve essere un punto di ritiro', fn () => prova(static function (): bool {
    $punto = sede(true);
    $magazzino = sede(false);
    $riga = [['type' => 'product', 'product_id' => articolo(1.0), 'quantity' => '1']];
    $buona = OfficeOrders::save(0, ['fulfillment_type' => 'pickup', 'location_id' => (string) $punto, 'lines' => $riga]);

    try {
        OfficeOrders::save(0, ['fulfillment_type' => 'pickup', 'location_id' => (string) $magazzino, 'lines' => $riga]);
        $rifiutata = false;
    } catch (UserError $errore) {
        $rifiutata = $errore->key() === 'stock_document.location_unknown';
    }

    return (int) (Order::findById($buona)['location_id'] ?? 0) === $punto && $rifiutata;
}));

summary();
