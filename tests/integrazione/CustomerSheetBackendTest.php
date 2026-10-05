<?php
/** php tests/integrazione/CustomerSheetBackendTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/layout.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Resources\Contacts\ContactAddressResource;
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

/** Tutto l'HTML della scheda, come lo vedrebbe il browser. */
function schedaClienteHtml(int $cliente): string
{
    return layoutHtml(CustomerResource::showLayoutSchema((array) Contact::findById($cliente)));
}

function indirizzoValido(array $extra = []): array
{
    return [...['label' => 'Magazzino', 'street' => 'Via delle Industrie', 'number' => '8', 'cap' => '20096', 'city' => 'Pioltello', 'province' => 'MI', 'country' => 'it'], ...$extra];
}

/** Gli indirizzi non eliminati di un cliente, per id. */
function indirizziDi(int $cliente): array
{
    return ContactAddressResource::addressesOf($cliente);
}

check('la scheda cliente ha statistiche, ordini, carrello, coupon e dati', function () {
    return prova(static function (): bool {
        ordini(true);
        $html = schedaClienteHtml(clienteDiProva());

        foreach (['Statistiche', 'Ordini', 'Prodotti nel carrello', 'Coupon assegnati', 'Tutti i suoi dati', 'Dati di fatturazione', 'Indirizzi di consegna', 'anna.prova@example.com'] as $voce) {
            if (!str_contains($html, $voce)) {
                return false;
            }
        }

        return str_contains($html, 'Nessun ordine') && str_contains($html, 'Nessun prodotto nel carrello')
            && !str_contains($html, 'Chi è') && str_contains($html, 'Nessun indirizzo di consegna');
    });
});

check('con le vendite spente la scheda ha coupon, dati, fatturazione e indirizzi', function () {
    return prova(static function (): bool {
        ordini(false);
        $html = schedaClienteHtml(clienteDiProva());

        return str_contains($html, 'Coupon assegnati') && str_contains($html, 'Tutti i suoi dati') && str_contains($html, 'Indirizzi di consegna')
            && !str_contains($html, 'Statistiche') && !str_contains($html, 'Prodotti nel carrello');
    });
});

check('i coupon stanno in fondo, dopo i dati e gli indirizzi', function () {
    return prova(static function (): bool {
        ordini(true);
        $html = schedaClienteHtml(clienteDiProva());
        $coupon = strpos($html, 'Coupon assegnati');

        return $coupon !== false && $coupon > strpos($html, 'Tutti i suoi dati') && $coupon > strpos($html, 'Indirizzi di consegna')
            && $coupon > strpos($html, 'Dati di fatturazione');
    });
});

check('«Tutti i suoi dati» non è più un accordion: i dati sono sempre visibili', function () {
    return prova(static function (): bool {
        ordini(true);
        $html = schedaClienteHtml(clienteDiProva());
        $titolo = strpos($html, 'Tutti i suoi dati');
        $accordion = strpos($html, 'accordion-button');

        // l'unico accordion che resta è quello dei coupon, dopo i dati
        return $titolo !== false && ($accordion === false || $accordion > $titolo)
            && !preg_match('/accordion-button[^>]*>\s*Tutti i suoi dati/', $html);
    });
});

check('gli indirizzi sono card, una per indirizzo, con i pulsanti e le finestre', function () {
    return prova(static function (): bool {
        ordini(true);
        $cliente = clienteDiProva();
        ContactAddressResource::run('save', $cliente, 0, indirizzoValido());
        ContactAddressResource::run('save', $cliente, 0, indirizzoValido(['label' => 'Ufficio', 'street' => 'Corso Italia']));
        $html = schedaClienteHtml($cliente);

        return str_contains($html, 'Magazzino') && str_contains($html, 'Ufficio') && str_contains($html, 'Via delle Industrie 8')
            && str_contains($html, 'data-bs-target="#'.ContactAddressResource::MODAL_ID.'"')
            && str_contains($html, 'data-wi-confirm-title="Elimina indirizzo"')
            && !str_contains($html, 'Nessun indirizzo di consegna');
    });
});

check('un indirizzo nuovo si salva, il paese va in maiuscolo e il primo diventa il predefinito', function () {
    return prova(static function (): bool {
        $cliente = clienteDiProva();
        $esito = ContactAddressResource::run('save', $cliente, 0, indirizzoValido());
        $righe = indirizziDi($cliente);

        return $esito['ok'] === true && count($righe) === 1 && $righe[0]['country'] === 'IT'
            && $righe[0]['is_default'] === 'true' && (int) $righe[0]['contact_id'] === $cliente;
    });
});

check('con più indirizzi uno solo è il predefinito, e il predefinito si sposta', function () {
    return prova(static function (): bool {
        $cliente = clienteDiProva();
        ContactAddressResource::run('save', $cliente, 0, indirizzoValido());
        ContactAddressResource::run('save', $cliente, 0, indirizzoValido(['label' => 'Ufficio', 'is_default' => 'true']));
        $righe = indirizziDi($cliente);
        $predefiniti = array_filter($righe, static fn (array $r): bool => $r['is_default'] === 'true');

        if (count($righe) !== 2 || count($predefiniti) !== 1 || reset($predefiniti)['label'] !== 'Ufficio') {
            return false;
        }

        $magazzino = (int) array_values(array_filter($righe, static fn (array $r): bool => $r['label'] === 'Magazzino'))[0]['id'];
        $esito = ContactAddressResource::run('default', $cliente, $magazzino);
        $righe = indirizziDi($cliente);
        $predefiniti = array_filter($righe, static fn (array $r): bool => $r['is_default'] === 'true');

        return $esito['ok'] === true && count($predefiniti) === 1 && reset($predefiniti)['label'] === 'Magazzino';
    });
});

check('un indirizzo si modifica, e il predefinito resta il suo', function () {
    return prova(static function (): bool {
        $cliente = clienteDiProva();
        ContactAddressResource::run('save', $cliente, 0, indirizzoValido());
        $id = (int) indirizziDi($cliente)[0]['id'];
        $esito = ContactAddressResource::run('save', $cliente, $id, indirizzoValido(['street' => 'Via Nuova', 'city' => 'Segrate']));
        $righe = indirizziDi($cliente);

        return $esito['ok'] === true && count($righe) === 1 && $righe[0]['street'] === 'Via Nuova'
            && $righe[0]['city'] === 'Segrate' && $righe[0]['is_default'] === 'true';
    });
});

check('eliminare un indirizzo lo toglie dalla scheda ma non dal database, e un altro predefinito prende il suo posto', function () {
    return prova(static function (): bool {
        $cliente = clienteDiProva();
        ContactAddressResource::run('save', $cliente, 0, indirizzoValido());
        ContactAddressResource::run('save', $cliente, 0, indirizzoValido(['label' => 'Ufficio']));
        $predefinito = (int) indirizziDi($cliente)[0]['id'];
        $esito = ContactAddressResource::run('delete', $cliente, $predefinito);
        $righe = indirizziDi($cliente);
        $cancellati = ContactAddress::find(['id' => $predefinito, 'deleted' => 'true']);

        return $esito['ok'] === true && count($righe) === 1 && $righe[0]['label'] === 'Ufficio'
            && $righe[0]['is_default'] === 'true' && !empty($cancellati);
    });
});

check('un indirizzo non valido si rifiuta con la frase e non si salva', function () {
    return prova(static function (): bool {
        $cliente = clienteDiProva();
        $esito = ContactAddressResource::run('save', $cliente, 0, ['label' => 'Vuoto']);

        return $esito['ok'] === false && str_contains($esito['message'], 'la via') && indirizziDi($cliente) === [];
    });
});

check('l\'indirizzo di un\'altra scheda non si tocca; una scheda che non c\'è si rifiuta', function () {
    return prova(static function (): bool {
        $mio = clienteDiProva();
        $altro = clienteDiProva();
        ContactAddressResource::run('save', $altro, 0, indirizzoValido());
        $suo = (int) indirizziDi($altro)[0]['id'];

        $modifica = ContactAddressResource::run('save', $mio, $suo, indirizzoValido(['street' => 'Via Rubata']));
        $elimina = ContactAddressResource::run('delete', $mio, $suo);
        $predefinito = ContactAddressResource::run('default', $mio, $suo);
        $nessuna = ContactAddressResource::run('save', 99999999, 0, indirizzoValido());
        $strana = ContactAddressResource::run('boh', $mio, 0, indirizzoValido());

        return $modifica['ok'] === false && $elimina['ok'] === false && $predefinito['ok'] === false
            && $nessuna['ok'] === false && $strana['ok'] === false
            && indirizziDi($altro)[0]['street'] === 'Via delle Industrie' && indirizziDi($mio) === [];
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
