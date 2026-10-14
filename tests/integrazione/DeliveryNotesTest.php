<?php
/** php tests/integrazione/DeliveryNotesTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Sales\DeliveryNote;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentFiles;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Sales\DeliveryNotes;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders;
use Wonder\Plugin\Gestionale\Support\Shipping\Shipments;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

function prova(callable $corpo, array $accese = []): mixed
{
    $esito = null;
    DocumentFiles::useBase(sys_get_temp_dir().'/gst-ddt-'.bin2hex(random_bytes(4)));
    Mailer::useTransport(static fn (): bool => true);

    try {
        Transaction::run(static function () use ($corpo, $accese, &$esito): void {
            accendiFunzionalita(['orders', 'office_sales', 'delivery_notes', ...$accese]);
            spegniFunzionalita(array_values(array_diff(['coupons', 'shipping', 'backorders', 'batch_tracking'], $accese)));
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    } finally {
        Wonder\Plugin\Gestionale\Gestionale::reset();
        DocumentFiles::useBase(null);
        Mailer::useTransport(null);
    }

    return $esito;
}

function articolo(float $pezzi): int
{
    $id = articoloConGiacenza($pezzi, 'DDT-'.strtoupper(substr(uniqid(), -6)));
    Product::update(['price' => '10.00', 'active' => 'true'], $id);

    return $id;
}

/** Un ordine da ufficio confermato con quelle righe: `[[prodotto, pezzi], ...]`. */
function ordineConfermato(array $righe, string $consegna = 'pickup'): int
{
    $cliente = (int) (Contact::create([
        'type' => 'private', 'name' => 'Paolo', 'surname' => 'Trasporto', 'country' => 'IT', 'city' => 'Bergamo',
        'street' => 'Via XX Settembre', 'number' => '5', 'cap' => '24100',
        'email' => 'paolo.ddt.'.uniqid().'@example.com', 'is_customer' => 'true', 'active' => 'true',
    ])->insert_id ?? 0);
    $ordine = OfficeOrders::save(0, [
        'customer_id' => $cliente,
        'fulfillment_type' => $consegna,
        'lines' => array_map(static fn (array $r): array => ['type' => 'product', 'product_id' => $r[0], 'quantity' => (string) $r[1]], $righe),
    ]);
    OfficeOrders::confirm($ordine);

    return $ordine;
}

/** @return list<array<string, mixed>> */
function righeOrdine(int $ordine): array
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

check('la bozza propone le righe da consegnare, con i dati dell\'ordine', function () {
    return prova(static function (): bool {
        $ordine = ordineConfermato([[articolo(5), 2], [articolo(5), 1]]);
        $ddt = DeliveryNotes::createFromOrder($ordine);
        $nota = DeliveryNote::findById($ddt);
        $righe = DeliveryNotes::items($ddt);

        return (string) $nota['status'] === 'draft' && trim((string) ($nota['number'] ?? '')) === ''
            && (int) $nota['order_id'] === $ordine && (string) $nota['billing_city'] === 'Bergamo'
            && count($righe) === 2 && round((float) $righe[0]['quantity'], 3) === 2.0;
    });
});

check('emesso: numero, PDF, ordine evaso; un DDT non muove il magazzino', function () {
    return prova(static function (): bool {
        $prodotto = articolo(5);
        $ordine = ordineConfermato([[$prodotto, 2]]);
        $prima = Wonder\Plugin\Gestionale\Support\Stock\Levels::of($prodotto)['quantity'];
        $ddt = DeliveryNotes::createFromOrder($ordine);
        $esito = DeliveryNotes::issue($ddt);

        return $esito['number'] !== ''
            && DocumentFiles::path($esito['file']) !== null
            && (string) DeliveryNote::findById($ddt)['status'] === 'issued'
            && (string) Order::findById($ordine)['fulfillment_status'] === 'fulfilled'
            && Wonder\Plugin\Gestionale\Support\Stock\Levels::of($prodotto)['quantity'] === $prima;
    });
});

check('una parte sola: evaso in parte, e il secondo DDT propone il resto', function () {
    return prova(static function (): bool {
        $ordine = ordineConfermato([[articolo(5), 3]]);
        $primo = DeliveryNotes::createFromOrder($ordine);
        $riga = (int) DeliveryNotes::items($primo)[0]['id'];
        DeliveryNotes::saveDraft($primo, ['packages_count' => '1', 'goods_appearance' => 'Scatola'], [$riga => '1']);
        DeliveryNotes::issue($primo);
        $parziale = (string) Order::findById($ordine)['fulfillment_status'];
        $secondo = DeliveryNotes::createFromOrder($ordine);

        return $parziale === 'partially_fulfilled'
            && round((float) DeliveryNotes::items($secondo)[0]['quantity'], 3) === 2.0
            && (int) DeliveryNote::findById($primo)['packages_count'] === 1;
    });
});

check('oltre il resto non si emette; con tutto consegnato non nasce un altro DDT', function () {
    return prova(static function (): bool {
        $ordine = ordineConfermato([[articolo(5), 2]]);
        $primo = DeliveryNotes::createFromOrder($ordine);
        $secondo = DeliveryNotes::createFromOrder($ordine);
        DeliveryNotes::issue($primo);

        return rifiuto(static fn () => DeliveryNotes::issue($secondo)) === 'delivery_note.too_much'
            && rifiuto(static fn () => DeliveryNotes::createFromOrder($ordine)) === 'delivery_note.nothing_to_deliver';
    });
});

check('annullato: le righe tornano da consegnare e l\'evasione torna indietro', function () {
    return prova(static function (): bool {
        $ordine = ordineConfermato([[articolo(5), 2]]);
        $ddt = DeliveryNotes::createFromOrder($ordine);
        DeliveryNotes::issue($ddt);
        DeliveryNotes::cancel($ddt);

        return (string) DeliveryNote::findById($ddt)['status'] === 'cancelled'
            && (string) Order::findById($ordine)['fulfillment_status'] === 'unfulfilled'
            && DeliveryNotes::inNotes($ordine) === []
            && count(DeliveryNotes::proposal($ordine)) === 1;
    });
});

check('la bozza si elimina, l\'emesso no; un ordine in bozza non fa DDT', function () {
    return prova(static function (): bool {
        $ordine = ordineConfermato([[articolo(5), 1]]);
        $bozza = DeliveryNotes::createFromOrder($ordine);
        DeliveryNotes::delete($bozza);
        $emesso = DeliveryNotes::createFromOrder($ordine);
        DeliveryNotes::issue($emesso);
        $daConfermare = OfficeOrders::save(0, ['fulfillment_type' => 'pickup', 'lines' => [['type' => 'product', 'product_id' => articolo(5), 'quantity' => '1']]]);

        $sparita = DeliveryNote::findById($bozza);

        return (!is_array($sparita) || $sparita === [])
            && rifiuto(static fn () => DeliveryNotes::delete($emesso)) === 'delivery_note.not_draft'
            && rifiuto(static fn () => DeliveryNotes::createFromOrder($daConfermare)) === 'delivery_note.order_not_ready';
    });
});

check('DDT e spedizione della stessa merce: contata una volta, non si rispedisce', function () {
    return prova(static function (): bool {
        $ordine = ordineConfermato([[articolo(5), 2]], 'shipping');
        Order::update(['shipping_street' => 'Via Roma', 'shipping_city' => 'Milano', 'shipping_country' => 'IT'], $ordine);
        $riga = (int) righeOrdine($ordine)[0]['id'];
        $ddt = DeliveryNotes::createFromOrder($ordine);
        DeliveryNotes::issue($ddt);

        return (Shipments::remaining($ordine)[$riga] ?? -1.0) === 0.0
            && rifiuto(static fn () => Shipments::create($ordine, [$riga => '1'])) === 'shipment.over_quantity'
            && (string) Order::findById($ordine)['fulfillment_status'] === 'fulfilled';
    }, ['shipping']);
});

check('annulla righe di un ordine da ufficio: quello nel DDT emesso non si annulla', function () {
    return prova(static function (): bool {
        $ordine = ordineConfermato([[articolo(5), 3]]);
        $ddt = DeliveryNotes::createFromOrder($ordine);
        $riga = (int) DeliveryNotes::items($ddt)[0]['id'];
        DeliveryNotes::saveDraft($ddt, [], [$riga => '2']);
        DeliveryNotes::issue($ddt);
        $annullabili = OfficeOrders::cancellableQuantities(Order::findById($ordine), righeOrdine($ordine));

        return array_values($annullabili) === [1.0];
    });
});

summary();
