<?php
/** php tests/integrazione/ShippingTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/spedizioni.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRate;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRateBracket;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZone;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZoneArea;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Shipping\Shipping;
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

/** I nomi dei metodi offerti, nell'ordine. */
function nomi(array $opzioni): array
{
    return array_column($opzioni, 'name');
}

check('options elenca solo i metodi coperti, con il prezzo giusto per il peso', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $de = zona('Germania', [['DE', '']]);
    $standard = metodo('Standard', ['position' => 1]);
    $espresso = metodo('Espresso', ['position' => 2]);
    $solo_de = metodo('Solo Germania', ['position' => 3]);
    listino($standard, $it, [[1, 5.0], [5, 8.0]]);
    listino($espresso, $it, [[5, 14.0]]);
    listino($solo_de, $de, [[5, 9.0]]);
    $cart = carrello([[articolo(2.0), 1]]);

    $opzioni = Shipping::options($cart);

    return nomi($opzioni) === ['Standard', 'Espresso']
        && $opzioni[0]['price'] === '8.00'
        && $opzioni[1]['price'] === '14.00'
        && $opzioni[0]['free'] === false
        && $opzioni[0]['method_id'] === $standard
        && $opzioni[0]['description'] === 'Da 24 a 48 ore'
        && $opzioni[0]['carrier_id'] === 0;
}));

check('un metodo a prezzo fisso costa uguale, qualunque sia il peso e il volume', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $fisso = metodo('Fisso', ['position' => 1]);
    listino($fisso, $it, [], ['price_type' => 'fixed', 'fixed_price' => '6.90', 'volumetric_divisor' => 5000, 'min_price' => '50.00']);
    $leggero = Shipping::options(carrello([[articolo(0.2), 1]]));
    $pesante = Shipping::options(carrello([[articolo(80.0, 10.0, [100, 100, 100]), 3]]));

    return count($leggero) === 1 && $leggero[0]['price'] === '6.90'
        && count($pesante) === 1 && $pesante[0]['price'] === '6.90';
}));

check('un metodo senza listino per la zona non compare', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $de = zona('Germania', [['DE', '']]);
    $senza = metodo('Senza listino');
    listino($senza, $de, [[5, 9.0]]);
    $cart = carrello([[articolo(1.0), 1]]);

    return Shipping::options($cart) === [] && $it > 0;
}));

check('un metodo disattivato o un listino disattivato non compaiono', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $spento = metodo('Spento', ['active' => 'false']);
    $listinoSpento = metodo('Listino spento');
    listino($spento, $it, [[5, 9.0]]);
    listino($listinoSpento, $it, [[5, 9.0]], ['active' => 'false']);
    $cart = carrello([[articolo(1.0), 1]]);

    return Shipping::options($cart) === [];
}));

check('la zona della provincia senza il listino del metodo non ricade sul paese', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $isole = zona('Isole', [['IT', 'MI']]);
    $standard = metodo('Standard');
    listino($standard, $it, [[5, 8.0]]);
    $cart = carrello([[articolo(1.0), 1]]);

    return Shipping::options($cart) === [] && $isole > 0;
}));

check('un carrello di soli servizi non ha opzioni', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    listino(metodo('Standard'), $it, [[5, 8.0]]);
    $cart = carrello([[articolo(1.0, 10.0, [0, 0, 0], 'false'), 1]]);

    return Shipping::options($cart) === [];
}));

check('con la funzionalità spenta non ci sono opzioni', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    listino(metodo('Standard'), $it, [[5, 8.0]]);
    $cart = carrello([[articolo(1.0), 1]]);
    spegniFunzionalita(['shipping']);

    return Shipping::options($cart) === [];
}));

check('un peso oltre l\'ultimo scaglione toglie il metodo', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    listino(metodo('Piccoli'), $it, [[1, 5.0]]);
    listino(metodo('Grandi'), $it, [[30, 20.0]]);
    $cart = carrello([[articolo(7.0), 1]]);

    return nomi(Shipping::options($cart)) === ['Grandi'];
}));

check('il canale dell\'ordine decide quali metodi si offrono', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    listino(metodo('Solo online', ['applies_office' => 'false', 'position' => 1]), $it, [[5, 8.0]]);
    listino(metodo('Solo ufficio', ['applies_online' => 'false', 'position' => 2]), $it, [[5, 9.0]]);
    $online = carrello([[articolo(1.0), 1]]);
    $ufficio = carrello([[articolo(1.0), 1]], ['channel' => 'office']);

    return nomi(Shipping::options($online)) === ['Solo Online']
        && nomi(Shipping::options($ufficio)) === ['Solo Ufficio'];
}));

check('i metodi sono in ordine di position', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    listino(metodo('Secondo', ['position' => 2]), $it, [[5, 8.0]]);
    listino(metodo('Primo', ['position' => 1]), $it, [[5, 9.0]]);
    $cart = carrello([[articolo(1.0), 1]]);

    return nomi(Shipping::options($cart)) === ['Primo', 'Secondo'];
}));

check('quote di un metodo valido dà prezzo e gratuità', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $standard = metodo('Standard');
    listino($standard, $it, [[5, 8.0]]);
    $cart = carrello([[articolo(2.0), 1]]);

    return Shipping::quote($cart, $standard) === ['price' => '8.00', 'free' => false];
}));

check('quote di un metodo non coperto rifiuta con shipping.not_available', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $de = zona('Germania', [['DE', '']]);
    $solo_de = metodo('Solo Germania');
    listino($solo_de, $de, [[5, 9.0]]);
    $cart = carrello([[articolo(2.0), 1]]);

    try {
        Shipping::quote($cart, $solo_de);
    } catch (UserError $e) {
        return $e->key() === 'shipping.not_available' && str_contains($e->getMessage(), 'Solo Germania') && $it > 0;
    }

    return false;
}));

check('quote di un metodo che non esiste rifiuta', fn () => prova(static function (): bool {
    zona('Italia', [['IT', '']]);
    $cart = carrello([[articolo(2.0), 1]]);

    try {
        Shipping::quote($cart, 999999);
    } catch (UserError $e) {
        return $e->key() === 'shipping.not_available';
    }

    return false;
}));

check('il peso volumetrico di un articolo ingombrante cambia lo scaglione', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $senza = metodo('Reale', ['position' => 1]);
    $con = metodo('Volumetrico', ['position' => 2]);
    listino($senza, $it, [[5, 8.0], [15, 12.0]]);
    listino($con, $it, [[5, 8.0], [15, 12.0]], ['volumetric_divisor' => 5000]);
    // 2 kg reali, 50 × 40 × 30 cm = 12 kg volumetrici
    $cart = carrello([[articolo(2.0, 10.0, [50, 40, 30]), 1]]);

    $opzioni = Shipping::options($cart);

    return $opzioni[0]['price'] === '8.00' && $opzioni[1]['price'] === '12.00';
}));

check('sopra la soglia di gratuità il prezzo è zero e free è vero', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $standard = metodo('Standard');
    listino($standard, $it, [[5, 8.0]], ['free_over_amount' => '50.00']);
    $poco = carrello([[articolo(1.0, 30.0), 1]]);
    $tanto = carrello([[articolo(1.0, 30.0), 2]]);

    return Shipping::quote($poco, $standard) === ['price' => '8.00', 'free' => false]
        && Shipping::quote($tanto, $standard) === ['price' => '0.00', 'free' => true];
}));

check('senza indirizzo di consegna vale la fatturazione', fn () => prova(static function (): bool {
    $fr = zona('Francia', [['FR', '']]);
    $standard = metodo('Standard');
    listino($standard, $fr, [[5, 15.0]]);
    // Il paese di consegna ha il suo valore predefinito (IT): non conta, se la consegna non ha altro.
    $cart = carrello([[articolo(1.0), 1]], [
        'billing_country' => 'FR', 'billing_province' => '',
        'shipping_country' => 'IT', 'shipping_province' => '', 'shipping_city' => '',
    ]);

    return nomi(Shipping::options($cart)) === ['Standard'];
}));

check('con l\'indirizzo di consegna vale quello, anche se la fatturazione è altrove', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $standard = metodo('Standard');
    listino($standard, $it, [[5, 8.0]]);
    $cart = carrello([[articolo(1.0), 1]], [
        'billing_country' => 'FR', 'shipping_country' => 'IT', 'shipping_province' => 'MI', 'shipping_city' => 'Milano',
    ]);

    return nomi(Shipping::options($cart)) === ['Standard'];
}));

check('senza paese si assume IT', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $standard = metodo('Standard');
    listino($standard, $it, [[5, 8.0]]);
    $cart = carrello([[articolo(1.0), 1]], [
        'billing_country' => '', 'shipping_country' => '', 'shipping_province' => '', 'shipping_city' => '',
    ]);

    return nomi(Shipping::options($cart)) === ['Standard'];
}));

check('line dà nome, importo, aliquota e gratuità del metodo scelto', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $standard = metodo('Standard');
    listino($standard, $it, [[5, 8.0]]);
    $prodotto = articolo(2.0, 10.0);
    $cart = carrello([[$prodotto, 1]], ['fulfillment_type' => 'shipping', 'shipping_method_id' => $standard]);
    $computed = [['type' => 'product', 'product_id' => $prodotto, 'quantity' => 1.0, 'line_total' => '10.00']];

    $riga = Shipping::line(Order::findById($cart), $computed);
    $aliquota = (int) (Setting::current()['shipping_tax_id'] ?? 0);

    return $riga === ['name' => 'Standard', 'amount' => '8.00', 'tax_id' => $aliquota, 'free' => false]
        && Shipping::dropped(Order::findById($cart), $computed) === '';
}));

check('line usa il totale dei prodotti delle righe passate per la soglia', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $standard = metodo('Standard');
    listino($standard, $it, [[5, 8.0]], ['free_over_amount' => '50.00']);
    $prodotto = articolo(2.0, 10.0);
    $cart = carrello([[$prodotto, 1]], ['fulfillment_type' => 'shipping', 'shipping_method_id' => $standard]);
    $computed = [['type' => 'product', 'product_id' => $prodotto, 'quantity' => 1.0, 'line_total' => '60.00']];

    $riga = Shipping::line(Order::findById($cart), $computed);

    return ($riga['amount'] ?? '') === '0.00' && ($riga['free'] ?? false) === true;
}));

check('line è vuota con ritiro, senza metodo o con la funzionalità spenta', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $standard = metodo('Standard');
    listino($standard, $it, [[5, 8.0]]);
    $prodotto = articolo(2.0, 10.0);
    $cart = carrello([[$prodotto, 1]], ['fulfillment_type' => 'shipping', 'shipping_method_id' => $standard]);
    $computed = [['type' => 'product', 'product_id' => $prodotto, 'quantity' => 1.0, 'line_total' => '10.00']];

    Order::update(['fulfillment_type' => 'pickup'], $cart);
    $ritiro = Shipping::line(Order::findById($cart), $computed);
    Order::update(['fulfillment_type' => 'shipping', 'shipping_method_id' => 0], $cart);
    $senzaMetodo = Shipping::line(Order::findById($cart), $computed);
    Order::update(['shipping_method_id' => $standard], $cart);
    spegniFunzionalita(['shipping']);
    $spenta = Shipping::line(Order::findById($cart), $computed);

    return $ritiro === null && $senzaMetodo === null && $spenta === null;
}));

check('line è vuota e dropped dà il motivo se il metodo non copre più la destinazione', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    zona('Germania', [['DE', '']]);
    $standard = metodo('Standard');
    listino($standard, $it, [[5, 8.0]]);
    $prodotto = articolo(2.0, 10.0);
    $cart = carrello([[$prodotto, 1]], [
        'fulfillment_type' => 'shipping', 'shipping_method_id' => $standard,
        'shipping_country' => 'DE', 'shipping_province' => '', 'shipping_city' => 'Berlino',
    ]);
    $computed = [['type' => 'product', 'product_id' => $prodotto, 'quantity' => 1.0, 'line_total' => '10.00']];
    $ordine = Order::findById($cart);

    return Shipping::line($ordine, $computed) === null
        && str_contains(Shipping::dropped($ordine, $computed), 'Standard');
}));

check('line non conta le righe che non richiedono spedizione né le non prodotto', fn () => prova(static function (): bool {
    $it = zona('Italia', [['IT', '']]);
    $standard = metodo('Standard');
    listino($standard, $it, [[1, 5.0], [10, 9.0]]);
    $pesante = articolo(6.0, 10.0, [0, 0, 0], 'false');
    $leggero = articolo(0.5, 10.0);
    $cart = carrello([[$leggero, 1]], ['fulfillment_type' => 'shipping', 'shipping_method_id' => $standard]);
    $computed = [
        ['type' => 'product', 'product_id' => $leggero, 'quantity' => 1.0, 'line_total' => '10.00'],
        ['type' => 'product', 'product_id' => $pesante, 'quantity' => 1.0, 'line_total' => '10.00'],
        ['type' => 'custom', 'product_id' => 0, 'quantity' => 1.0, 'line_total' => '10.00'],
    ];

    return (Shipping::line(Order::findById($cart), $computed)['amount'] ?? '') === '5.00';
}));

summary();
