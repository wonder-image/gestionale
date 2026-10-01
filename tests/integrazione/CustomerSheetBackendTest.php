<?php
/** php tests/integrazione/CustomerSheetBackendTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Sales\CustomerOrderTableResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderItemTableResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
}

/** Accende o spegne «orders» solo dentro la transazione di prova. */
function ordini(bool $acceso): void
{
    sqlModify(Feature::$table, ['enabled' => $acceso ? 'true' : 'false'], 'feature_key', 'orders');
    Gestionale::reset();
}

function clienteDiProva(): int
{
    $creato = Contact::create([
        'type' => 'private', 'name' => 'Anna', 'surname' => 'Prova', 'country' => 'IT',
        'email' => 'anna.prova@example.com', 'is_customer' => 'true', 'active' => 'true',
    ]);

    return (int) ($creato->insert_id ?? 0);
}

/** Tutto l'HTML della scheda, titoli compresi, per cercarci dentro. */
function schedaClienteHtml(int $cliente): string
{
    $layout = CustomerResource::showLayoutSchema((array) Contact::findById($cliente));
    $testo = static function (object $c) use (&$testo): string {
        $html = '';

        foreach (['text', 'title', 'label'] as $p) {
            if (property_exists($c, $p)) {
                $html .= (string) (new ReflectionProperty($c, $p))->getValue($c).' ';
            }
        }

        foreach ((array) ($c->components ?? []) as $figlio) {
            $html .= $testo($figlio);
        }

        return $html;
    };

    return $testo($layout);
}

check('la scheda cliente ha statistiche, ordini, carrello, coupon e dati', function () {
    return prova(static function (): bool {
        ordini(true);
        $html = schedaClienteHtml(clienteDiProva());

        foreach (['Statistiche', 'Ordini', 'Prodotti nel carrello', 'Coupon assegnati', 'Tutti i suoi dati', 'anna.prova@example.com'] as $voce) {
            if (!str_contains($html, $voce)) {
                return false;
            }
        }

        return str_contains($html, 'Nessun ordine') && str_contains($html, 'Nessun prodotto nel carrello');
    });
});

check('con le vendite spente la scheda ha solo coupon e dati', function () {
    return prova(static function (): bool {
        ordini(false);
        $html = schedaClienteHtml(clienteDiProva());

        return str_contains($html, 'Coupon assegnati') && str_contains($html, 'Tutti i suoi dati')
            && !str_contains($html, 'Statistiche') && !str_contains($html, 'Prodotti nel carrello');
    });
});

check('gli ordini del cliente sono quelli suoi, non i carrelli né quelli di altri', function () {
    return prova(static function (): bool {
        ordini(true);
        $cliente = clienteDiProva();
        $mio = ordineDiProva(10.0);
        $altro = ordineDiProva(20.0);
        $carrello = Order::create(['stage' => 'cart', 'status' => 'draft', 'payment_status' => 'unpaid', 'total' => '5.00', 'customer_id' => $cliente]);
        Order::update(['customer_id' => $cliente], $mio);

        $condizione = CustomerOrderTableResource::condition($cliente, 0);
        $ids = array_map(static fn ($r): int => (int) $r['id'], (array) sqlSelect(Order::$table, "({$condizione}) AND deleted = 'false'")->row);

        return in_array($mio, $ids, true) && !in_array($altro, $ids, true)
            && !in_array((int) ($carrello->insert_id ?? 0), $ids, true);
    });
});

check('un cliente senza ordini mostra la frase, non una tabella vuota', function () {
    return prova(static function (): bool {
        ordini(true);
        $html = CustomerOrderTableResource::embedForCustomer(clienteDiProva());

        return str_contains($html, 'Nessun ordine') && !str_contains($html, '<table');
    });
});

check('con le vendite spente le tabelle incorporate restano la frase', function () {
    return prova(static function (): bool {
        ordini(false);

        return str_contains(CustomerOrderTableResource::embedForCustomer(1), 'Nessun ordine')
            && str_contains(OrderItemTableResource::embedMany([1, 2], 'Nessun prodotto nel carrello.'), 'Nessun prodotto nel carrello.');
    });
});

check('i carrelli: lista vuota o id non validi sono la frase', function () {
    return prova(static function (): bool {
        ordini(true);

        return str_contains(OrderItemTableResource::embedMany([], 'Vuoto qui'), 'Vuoto qui')
            && str_contains(OrderItemTableResource::embedMany([0, -3], 'Vuoto qui'), 'Vuoto qui');
    });
});

check('il carrello aperto del cliente conta nelle statistiche', function () {
    return prova(static function (): bool {
        ordini(true);
        $cliente = clienteDiProva();
        $carrello = Order::create(['stage' => 'cart', 'status' => 'draft', 'payment_status' => 'unpaid', 'total' => '30.00', 'customer_id' => $cliente]);
        $carrelloId = (int) ($carrello->insert_id ?? 0);
        OrderItem::create([
            'order_id' => $carrelloId, 'type' => 'custom', 'position' => 1, 'name' => 'Maglia',
            'quantity' => '3.000', 'unit_price' => '10.00', 'line_total' => '30.00',
        ]);
        $html = schedaClienteHtml($cliente);

        return str_contains($html, '30,00 €') && str_contains($html, '3 pezzi');
    });
});

check('nella scheda ordine il cliente è un link alla scheda cliente', function () {
    return prova(static function (): bool {
        $cliente = clienteDiProva();
        $ordine = ordineDiProva(10.0);
        Order::update(['customer_id' => $cliente], $ordine);
        $url = OrderResource::customerUrl((array) Order::findById($ordine));

        return str_contains($url, '/clienti/'.$cliente.'/') && !str_contains($url, '/edit');
    });
});

check('un ordine senza cliente non ha il link', function () {
    return prova(static function (): bool {
        return OrderResource::customerUrl((array) Order::findById(ordineDiProva(10.0))) === '';
    });
});

summary();
