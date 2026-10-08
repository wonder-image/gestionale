<?php
/** php tests/integrazione/ShipmentEmailTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/spedizioni.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Shipping\Shipments;
use Wonder\Sql\Transaction;

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

function righe(string $model, string $dove): array
{
    $rows = $model::find($dove);

    if (!is_array($rows) || $rows === []) {
        return [];
    }

    return array_key_exists('id', $rows) ? [$rows] : array_values(array_filter($rows, 'is_array'));
}

function vettore(string $modello = 'https://tracking.esempio.it/?codice={tracking}'): int
{
    return (int) (Carrier::create([
        'code' => 'car_mail-'.uniqid(),
        'name' => 'Corriere <di> prova',
        'tracking_url_template' => $modello,
        'active' => 'true',
    ])->insert_id ?? 0);
}

/**
 * Esegue il corpo con un trasporto che raccoglie le email, o che rifiuta tutto.
 *
 * @return array{0: mixed, 1: list<array{to: string, subject: string, body: string}>}
 */
function conPosta(callable $corpo, bool $parte = true): array
{
    $partite = [];
    Mailer::useTransport(static function (string $to, string $subject, string $body) use (&$partite, $parte): bool {
        $partite[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

        return $parte;
    });

    try {
        $esito = $corpo();
    } finally {
        Mailer::useTransport(null);
    }

    return [$esito, $partite];
}

/** Un ordine con una riga da 1 pezzo e la spedizione in attesa. */
function spedizioneInAttesa(string $email = 'cliente@example.com'): array
{
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    // Separato: un indirizzo vuoto in un aggiornamento con altri campi non passa.
    Order::update(['email' => $email], $ordine);
    Order::update(['order_number' => date('Y').'/77'], $ordine);

    return [$ordine, Shipments::create($ordine, [$riga => 1])];
}

check('ship manda al cliente l\'email di spedito con tracking, vettore e link', fn () => prova(static function (): bool {
    [, $id] = spedizioneInAttesa();
    [, $mail] = conPosta(static fn () => Shipments::ship($id, ['carrier_id' => vettore(), 'tracking_number' => 'AB 12']));

    return count($mail) === 1
        && $mail[0]['to'] === 'cliente@example.com'
        && str_contains($mail[0]['subject'], date('Y').'/77')
        && str_contains($mail[0]['body'], 'AB 12')
        && str_contains($mail[0]['body'], 'Corriere &lt;di&gt; prova')
        && str_contains($mail[0]['body'], 'https://tracking.esempio.it/?codice=AB%2012');
}));

check('due ship di fila sulla stessa spedizione mandano una sola email', fn () => prova(static function (): bool {
    [, $id] = spedizioneInAttesa();
    $v = vettore('');
    [, $mail] = conPosta(static function () use ($id, $v): void {
        Shipments::ship($id, ['carrier_id' => $v]);
        Shipments::ship($id, ['carrier_id' => $v]);
        Shipments::advance($id, 'in_transit');
    });

    return count($mail) === 1;
}));

check('advance da in attesa verso la consegna passa da ship e manda l\'email una volta', fn () => prova(static function (): bool {
    [, $id] = spedizioneInAttesa();
    [, $mail] = conPosta(static fn () => Shipments::advance($id, 'out_for_delivery', ['carrier_id' => vettore('')]));

    return count($mail) === 1 && Shipment::findById($id)['status'] === 'out_for_delivery';
}));

check('i passaggi dopo la partenza e l\'annullamento non scrivono al cliente', fn () => prova(static function (): bool {
    [, $id] = spedizioneInAttesa();
    Shipments::ship($id, ['carrier_id' => vettore('')]);
    [, $mail] = conPosta(static function () use ($id): void {
        Shipments::advance($id, 'out_for_delivery');
        Shipments::advance($id, 'delivered');
    });
    [, $altra] = spedizioneInAttesa();
    [, $annullata] = conPosta(static fn () => Shipments::cancel($altra));

    return $mail === [] && $annullata === [];
}));

check('ready manda l\'email di pronto per il ritiro con nome e indirizzo della sede', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    $sede = sede(true, true, 'true', ['street' => 'Via Roma', 'number' => '1', 'cap' => '24100', 'city' => 'Bergamo']);
    [$ordine] = ordineDaRitirare([[articolo(1.0), 1]], $sede, ['email' => 'cliente@example.com']);
    $id = Shipments::createPickup($ordine);
    [, $mail] = conPosta(static fn () => Shipments::ready($id));
    return count($mail) === 1
        && str_contains($mail[0]['body'], 'Prova ritiro')
        && str_contains($mail[0]['body'], 'Via Roma')
        && str_contains($mail[0]['body'], 'Bergamo')
        && Shipment::findById($id)['status'] === 'ready_for_pickup';
}));

check('advance verso ready_for_pickup e un secondo ready: una sola email', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine] = ordineDaRitirare([[articolo(1.0), 1]], null, ['email' => 'cliente@example.com']);
    $id = Shipments::createPickup($ordine);
    [, $mail] = conPosta(static function () use ($id): void {
        Shipments::advance($id, 'ready_for_pickup');
        Shipments::ready($id);
    });
    [, $ritirato] = conPosta(static fn () => Shipments::pickedUp($id));

    return count($mail) === 1 && $ritirato === [];
}));

check('cliente senza email: l\'operazione riesce lo stesso e nessuna email parte', fn () => prova(static function (): bool {
    [$ordine, $id] = spedizioneInAttesa('');
    [, $mail] = conPosta(static fn () => Shipments::ship($id, ['carrier_id' => vettore('')]));

    return $mail === [] && Shipment::findById($id)['status'] === 'in_transit'
        && Order::findById($ordine)['fulfillment_status'] === 'fulfilled';
}));

check('posta che rifiuta: la spedizione resta in viaggio', fn () => prova(static function (): bool {
    [$ordine, $id] = spedizioneInAttesa();
    [, $mail] = conPosta(static fn () => Shipments::ship($id, ['carrier_id' => vettore('')]), false);

    return count($mail) === 1 && Shipment::findById($id)['status'] === 'in_transit'
        && Order::findById($ordine)['fulfillment_status'] === 'fulfilled';
}));

check('un\'eccezione della posta non fa saltare la spedizione', fn () => prova(static function (): bool {
    [, $id] = spedizioneInAttesa();
    Mailer::useTransport(static function (): bool {
        throw new RuntimeException('smtp giù');
    });

    try {
        Shipments::ship($id, ['carrier_id' => vettore('')]);
    } finally {
        Mailer::useTransport(null);
    }

    return Shipment::findById($id)['status'] === 'in_transit';
}));

check('una spedizione rifiutata non manda niente', fn () => prova(static function (): bool {
    [, $id] = spedizioneInAttesa();
    [$esito, $mail] = conPosta(static function () use ($id): ?string {
        try {
            Shipments::ship($id, ['carrier_id' => vettore(), 'tracking_number' => '']);
        } catch (UserError $errore) {
            return $errore->key();
        }

        return null;
    });

    return $esito === 'shipment.tracking_required' && $mail === [];
}));

summary();
