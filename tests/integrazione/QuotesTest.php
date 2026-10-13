<?php
/** php tests/integrazione/QuotesTest.php */

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
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentFiles;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders;
use Wonder\Plugin\Gestionale\Support\Sales\Quotes;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$posta = [];

function prova(callable $corpo): mixed
{
    global $posta;

    $esito = null;
    $posta = [];
    $cartella = sys_get_temp_dir().'/gst-quotes-'.bin2hex(random_bytes(4));
    DocumentFiles::useBase($cartella);
    Mailer::useTransport(static function (string $to, string $subject, string $body, array $files = []) use (&$posta): bool {
        $posta[] = ['to' => $to, 'subject' => $subject, 'files' => $files];

        return true;
    });

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            accendiFunzionalita(['orders', 'office_sales', 'quotes']);
            spegniFunzionalita(['coupons', 'shipping', 'backorders']);
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
    $id = articoloConGiacenza($pezzi, 'PRV-'.strtoupper(substr(uniqid(), -6)));
    Product::update(['price' => '20.00', 'active' => 'true'], $id);

    return $id;
}

function cliente(): int
{
    return (int) (Contact::create([
        'type' => 'private', 'name' => 'Giulia', 'surname' => 'Preventivo', 'country' => 'IT', 'city' => 'Torino',
        'street' => 'Via Po', 'number' => '3', 'cap' => '10100',
        'email' => 'giulia.preventivo.'.uniqid().'@example.com', 'is_customer' => 'true', 'active' => 'true',
    ])->insert_id ?? 0);
}

function preventivo(int $prodotto, string $pezzi = '2'): int
{
    return OfficeOrders::save(0, [
        'customer_id' => cliente(),
        'fulfillment_type' => 'shipping',
        'quote_expires_at' => '2026-12-31',
        'lines' => [['type' => 'product', 'product_id' => $prodotto, 'quantity' => $pezzi]],
    ], 0, 'quote');
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

check('il preventivo nasce bozza, senza numero, con la sua validità', function () {
    return prova(static function (): bool {
        $id = preventivo(articolo(5));
        $o = Order::findById($id);

        return (string) $o['stage'] === 'quote' && (string) $o['status'] === 'draft'
            && trim((string) ($o['quote_number'] ?? '')) === ''
            && str_starts_with((string) $o['quote_expires_at'], '2026-12-31')
            && round((float) $o['total'], 2) === 40.0;
    });
});

check('l\'invio: numero, revisione 1, PDF archiviato, email con l\'allegato', function () {
    return prova(static function (): bool {
        global $posta;

        $id = preventivo(articolo(5));
        $esito = Quotes::send($id, ['user_id' => 0]);
        $o = Order::findById($id);
        $revisioni = Quotes::revisions($id);
        $pdf = DocumentFiles::path((string) $revisioni[0]['pdf_file']);

        return $esito['revision'] === 1 && $esito['number'] !== ''
            && (string) $o['status'] === 'sent' && (int) $o['quote_revision'] === 1
            && count($revisioni) === 1 && $pdf !== null && str_starts_with((string) file_get_contents($pdf), '%PDF')
            && count($posta) === 1 && str_contains($posta[0]['subject'], $esito['number'])
            && array_values($posta[0]['files']) === ['Preventivo-'.preg_replace('/[^A-Za-z0-9-]+/', '-', $esito['number']).'.pdf']
            && (string) Quotes::revisions($id)[0]['sent_to'] === $posta[0]['to'];
    });
});

check('modificato e rimandato: revisione 2, due PDF, stesso numero', function () {
    return prova(static function (): bool {
        $prodotto = articolo(5);
        $id = preventivo($prodotto);
        $primo = Quotes::send($id);
        OfficeOrders::save($id, ['lines' => [['type' => 'product', 'product_id' => $prodotto, 'quantity' => '3']]], 0, 'quote');
        $dopo = (string) Order::findById($id)['status'];
        $secondo = Quotes::send($id);

        return $dopo === 'draft' && $secondo['revision'] === 2 && $secondo['number'] === $primo['number']
            && count(Quotes::revisions($id)) === 2
            && $secondo['file'] !== $primo['file'];
    });
});

check('rimandare senza cambiare non fa un\'altra revisione', function () {
    return prova(static function (): bool {
        $id = preventivo(articolo(5));
        Quotes::send($id);
        $di_nuovo = Quotes::send($id);

        return $di_nuovo['revision'] === 1 && count(Quotes::revisions($id)) === 1;
    });
});

check('accettato: lo stesso documento è una bozza d\'ordine con le stesse righe', function () {
    return prova(static function (): bool {
        $id = preventivo(articolo(5));
        $numero = Quotes::send($id)['number'];
        $righe = count(OrderItem::find(['order_id' => $id, 'deleted' => 'false']) ?: []);
        Quotes::accept($id);
        $o = Order::findById($id);

        return (string) $o['stage'] === 'order' && (string) $o['status'] === 'draft'
            && (string) $o['quote_number'] === $numero
            && count(OrderItem::find(['order_id' => $id, 'deleted' => 'false']) ?: []) === $righe
            && OfficeOrders::confirm($id)['order_number'] !== '';
    });
});

check('rifiutato e scaduto; un rifiutato non si invia più', function () {
    return prova(static function (): bool {
        $rifiutato = preventivo(articolo(5));
        Quotes::send($rifiutato);
        Quotes::reject($rifiutato);

        $scaduto = preventivo(articolo(5));
        Quotes::send($scaduto);
        Order::update(['quote_expires_at' => '2020-01-01 00:00:00'], $scaduto);
        $quanti = Quotes::expireDue(new DateTimeImmutable('2026-10-13'));

        return (string) Order::findById($rifiutato)['status'] === 'rejected'
            && rifiuto(static fn () => Quotes::send($rifiutato)) === 'quote.cannot_send'
            && $quanti >= 1 && (string) Order::findById($scaduto)['status'] === 'expired';
    });
});

check('mai inviato si elimina, inviato no', function () {
    return prova(static function (): bool {
        $bozza = preventivo(articolo(5));
        $inviato = preventivo(articolo(5));
        Quotes::send($inviato);
        Quotes::delete($bozza);
        $sparito = Order::findById($bozza);

        return (!is_array($sparito) || $sparito === [])
            && rifiuto(static fn () => Quotes::delete($inviato)) === 'quote.cannot_delete';
    });
});

check('un preventivo non tocca il magazzino', function () {
    return prova(static function (): bool {
        $prodotto = articolo(5);
        $id = preventivo($prodotto, '4');
        Quotes::send($id);

        return (StockMovement::find(['reference_type' => 'order', 'reference_id' => $id]) ?: []) === []
            && (StockReservation::find(['order_id' => $id]) ?: []) === []
            && Wonder\Plugin\Gestionale\Support\Stock\Levels::of($prodotto)['quantity'] === 5.0;
    });
});

check('la conferma da ufficio con l\'email porta il PDF', function () {
    return prova(static function (): bool {
        global $posta;

        $ordine = OfficeOrders::save(0, ['customer_id' => cliente(), 'fulfillment_type' => 'pickup', 'lines' => [['type' => 'product', 'product_id' => articolo(5), 'quantity' => '1']]]);
        $numero = OfficeOrders::confirm($ordine, ['notify' => true])['order_number'];
        $file = (string) array_key_first($posta[0]['files'] ?? []);

        return count($posta) === 1 && str_contains($posta[0]['subject'], $numero)
            && is_file($file) && str_starts_with((string) file_get_contents($file), '%PDF');
    });
});

summary();
