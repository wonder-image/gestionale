<?php
/** php tests/integrazione/OfficeOrdersTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\Installments;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            accendiFunzionalita(['orders', 'office_sales']);
            spegniFunzionalita(['coupons', 'shipping', 'backorders']);
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    } finally {
        Wonder\Plugin\Gestionale\Gestionale::reset();
    }

    return $esito;
}

/** Un articolo da 10 € con quei pezzi. */
function articolo(float $pezzi, float $prezzo = 10.0): int
{
    $id = articoloConGiacenza($pezzi, 'UFF-'.strtoupper(substr(uniqid(), -6)));
    Product::update(['price' => number_format($prezzo, 2, '.', ''), 'active' => 'true'], $id);

    return $id;
}

function cliente(): int
{
    $creato = Contact::create([
        'type' => 'private',
        'name' => 'Mario',
        'surname' => 'Ufficio',
        'country' => 'IT',
        'city' => 'Milano',
        'street' => 'Via Torino',
        'number' => '1',
        'cap' => '20100',
        'email' => 'mario.ufficio.'.uniqid().'@example.com',
        'is_customer' => 'true',
        'active' => 'true',
    ]);

    return (int) ($creato->insert_id ?? 0);
}

/** @return list<array<string, mixed>> */
function righe(int $ordine): array
{
    $rows = OrderItem::find(['order_id' => $ordine, 'deleted' => 'false'], null, 'position', 'ASC');

    return is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values($rows)) : [];
}

function rifiuto(callable $f): ?string
{
    try {
        $f();
    } catch (UserError $e) {
        return $e->key();
    }

    return null;
}

/** Una bozza con un prodotto e, se chiesto, una riga libera e una di testo. */
function bozza(int $prodotto, float $pezzi, array $extra = [], array $testata = []): int
{
    return OfficeOrders::save(0, $testata + [
        'customer_id' => cliente(),
        'fulfillment_type' => 'pickup',
        'lines' => array_merge([['type' => 'product', 'product_id' => $prodotto, 'quantity' => (string) $pezzi]], $extra),
    ], 0);
}

check('la bozza nasce senza numero, da ufficio, con gli indirizzi del cliente', function () {
    return prova(static function (): bool {
        $ordine = bozza(articolo(5), 2);
        $o = Order::findById($ordine);

        return (string) $o['status'] === 'draft' && (string) $o['channel'] === 'office'
            && trim((string) ($o['order_number'] ?? '')) === ''
            && (string) $o['billing_city'] === 'Milano' && (string) $o['shipping_street'] === 'Via Torino'
            && round((float) $o['total'], 2) === 20.0;
    });
});

check('prezzo a mano e sconto di riga: lo sconto non si applica due volte', function () {
    return prova(static function (): bool {
        $prodotto = articolo(5);
        $ordine = OfficeOrders::save(0, [
            'customer_id' => cliente(),
            'fulfillment_type' => 'pickup',
            'lines' => [['type' => 'product', 'product_id' => $prodotto, 'quantity' => '2', 'unit_price' => '8,00', 'discount_type' => 'percent', 'discount_value' => '50']],
        ]);
        // Un secondo salvataggio uguale rifà il ricalcolo dalle stesse righe.
        $primo = round((float) Order::findById($ordine)['total'], 2);
        Wonder\Plugin\Gestionale\Support\Orders\Cart::recalculate($ordine);
        $r = righe($ordine)[0];

        return $primo === 8.0
            && round((float) Order::findById($ordine)['total'], 2) === 8.0
            && round((float) $r['unit_price'], 2) === 4.0 && (string) $r['price_source'] === 'manual';
    });
});

check('riga libera e riga di testo: la libera si paga, quella di testo no', function () {
    return prova(static function (): bool {
        $ordine = bozza(articolo(5), 1, [
            ['type' => 'custom', 'name' => 'Montaggio', 'quantity' => '1', 'unit_price' => '25'],
            ['type' => 'text', 'name' => 'Consegna al piano terra'],
        ]);
        $r = righe($ordine);
        $tipi = array_column($r, 'type');

        $iva = Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary::find(['order_id' => $ordine]);

        return $tipi === ['product', 'custom', 'text']
            && is_array($iva) && $iva !== []
            && round((float) Order::findById($ordine)['total'], 2) === 35.0
            && round((float) $r[2]['line_total'], 2) === 0.0;
    });
});

check('lo sconto sul totale e la spedizione a mano', function () {
    return prova(static function (): bool {
        $ordine = bozza(articolo(5), 2, [], ['manual_discount_type' => 'amount', 'manual_discount_value' => '5', 'shipping_amount' => '7,50', 'fulfillment_type' => 'shipping']);
        $o = Order::findById($ordine);

        return round((float) $o['total'], 2) === 22.5 && round((float) $o['shipping_total'], 2) === 7.5;
    });
});

check('salvare di nuovo riscrive le righe e la bozza resta una', function () {
    return prova(static function (): bool {
        $prodotto = articolo(5);
        $ordine = bozza($prodotto, 1);
        OfficeOrders::save($ordine, ['lines' => [['type' => 'product', 'product_id' => $prodotto, 'quantity' => '3']]]);

        // Indirizzi e consegna non inviati restano com'erano.
        return count(righe($ordine)) === 1 && round((float) righe($ordine)[0]['quantity'], 3) === 3.0
            && (string) Order::findById($ordine)['billing_city'] === 'Milano';
    });
});

check('conferma: numero, merce che esce, rate in attesa', function () {
    return prova(static function (): bool {
        $prodotto = articolo(5);
        $ordine = bozza($prodotto, 2);
        $esito = OfficeOrders::confirm($ordine, ['user_id' => 0]);
        $o = Order::findById($ordine);
        $uscite = StockMovement::find(['reference_type' => 'order', 'reference_id' => $ordine, 'type' => 'sale']);

        return $esito['order_number'] !== '' && (string) $o['status'] === 'confirmed' && (string) $o['order_number'] === $esito['order_number']
            && is_array($uscite) && $uscite !== []
            && Levels::of($prodotto)['quantity'] === 3.0
            && count(Installments::pending($ordine)) === 1
            && (string) $o['payment_status'] === 'pending';
    });
});

check('la merce che manca: rifiuto col nome, e niente cambia', function () {
    return prova(static function (): bool {
        $prodotto = articolo(1);
        $ordine = bozza($prodotto, 3);

        return rifiuto(static fn () => OfficeOrders::confirm($ordine)) === 'office_order.out_of_stock'
            && (string) Order::findById($ordine)['status'] === 'draft'
            && trim((string) (Order::findById($ordine)['order_number'] ?? '')) === ''
            && Levels::of($prodotto)['quantity'] === 1.0;
    });
});

check('con la vendita senza giacenza va sotto zero', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['backorders']);
        $prodotto = articolo(1);
        Product::update(['allow_backorder' => 'true'], $prodotto);
        $ordine = bozza($prodotto, 3);
        OfficeOrders::confirm($ordine);

        return Levels::of($prodotto)['quantity'] === -2.0;
    });
});

check('senza cliente o senza righe non si conferma', function () {
    return prova(static function (): bool {
        $senza = OfficeOrders::save(0, ['fulfillment_type' => 'pickup', 'lines' => [['type' => 'product', 'product_id' => articolo(5), 'quantity' => '1']]]);
        $vuota = OfficeOrders::save(0, ['customer_id' => cliente(), 'fulfillment_type' => 'pickup', 'lines' => [['type' => 'text', 'name' => 'Solo testo']]]);

        return rifiuto(static fn () => OfficeOrders::confirm($senza)) === 'office_order.customer_required'
            && rifiuto(static fn () => OfficeOrders::confirm($vuota)) === 'office_order.no_rows';
    });
});

check('lotto e fornitore scelti restano sulla riga', function () {
    return prova(static function (): bool {
        $ordine = OfficeOrders::save(0, [
            'customer_id' => cliente(),
            'fulfillment_type' => 'pickup',
            'lines' => [['type' => 'product', 'product_id' => articolo(5), 'quantity' => '1', 'batch_id' => '0', 'supplier_id' => '0']],
        ]);

        return (int) righe($ordine)[0]['batch_id'] === 0 && (int) righe($ordine)[0]['supplier_id'] === 0;
    });
});

check('annulla righe: la merce rientra, i totali e le rate si rifanno', function () {
    return prova(static function (): bool {
        $prodotto = articolo(5);
        $ordine = bozza($prodotto, 3, [['type' => 'custom', 'name' => 'Montaggio', 'quantity' => '1', 'unit_price' => '20']]);
        OfficeOrders::confirm($ordine);
        $riga = (int) righe($ordine)[0]['id'];
        OfficeOrders::cancelLines($ordine, [$riga => '2']);
        $r = righe($ordine)[0];
        $rate = Installments::pending($ordine);

        return round((float) $r['quantity'], 3) === 1.0 && round((float) $r['quantity_cancelled'], 3) === 2.0
            && Levels::of($prodotto)['quantity'] === 4.0
            && round((float) Order::findById($ordine)['total'], 2) === 30.0
            && round(array_sum(array_column($rate, 'amount')), 2) === 30.0;
    });
});

check('oltre il rimasto non si annulla; tutto annullato = ordine annullato', function () {
    return prova(static function (): bool {
        $prodotto = articolo(5);
        $ordine = bozza($prodotto, 2);
        OfficeOrders::confirm($ordine);
        $riga = (int) righe($ordine)[0]['id'];
        $troppo = rifiuto(static fn () => OfficeOrders::cancelLines($ordine, [$riga => '3']));
        $esito = OfficeOrders::cancelLines($ordine, [$riga => '2']);

        return $troppo === 'office_order.cancel_too_much'
            && $esito['cancelled_order'] === true
            && (string) Order::findById($ordine)['status'] === 'cancelled'
            && Levels::of($prodotto)['quantity'] === 5.0
            && Installments::pending($ordine) === [];
    });
});

check('la bozza si elimina, un confermato no', function () {
    return prova(static function (): bool {
        $bozza = bozza(articolo(5), 1);
        $confermato = bozza(articolo(5), 1);
        OfficeOrders::confirm($confermato);
        OfficeOrders::delete($bozza);
        $sparita = Order::findById($bozza);

        return (!is_array($sparita) || $sparita === [])
            && righe($bozza) === []
            && rifiuto(static fn () => OfficeOrders::delete($confermato)) === 'office_order.cannot_delete';
    });
});

check('una bozza confermata non si salva più', function () {
    return prova(static function (): bool {
        $ordine = bozza(articolo(5), 1);
        OfficeOrders::confirm($ordine);

        return rifiuto(static fn () => OfficeOrders::save($ordine, ['fulfillment_type' => 'pickup', 'lines' => []])) === 'office_order.not_draft';
    });
});

summary();
