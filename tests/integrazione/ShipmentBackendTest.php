<?php
/** php tests/integrazione/ShipmentBackendTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/spedizioni.php';

use Wonder\Plugin\Gestionale\Backend\Widgets\ShipmentsToCheckWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\ShippedNotDeliveredWidget;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;
use Wonder\Plugin\Gestionale\Models\Shipping\ShipmentItem;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderActionResource;
use Wonder\Plugin\Gestionale\Resources\Shipping\ShipmentResource;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Shipping\ShipmentAlerts;
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

function righe(string $model, string|array $dove): array
{
    $rows = $model::find($dove);

    if (!is_array($rows) || $rows === []) {
        return [];
    }

    return array_key_exists('id', $rows) ? [$rows] : array_values(array_filter($rows, 'is_array'));
}

function vettore(): int
{
    return (int) (Carrier::create([
        'code' => 'car_bk-'.uniqid(),
        'name' => 'Corriere prova',
        'tracking_url_template' => 'https://tracking.esempio.it/?codice={tracking}',
        'active' => 'true',
    ])->insert_id ?? 0);
}

/** Esegue l'azione senza lasciar partire email vere. */
function azione(string $nome, int $ordine, array $valori = []): array
{
    Mailer::useTransport(static fn (): bool => true);

    try {
        return OrderActionResource::run($nome, $ordine, 1, $valori);
    } finally {
        Mailer::useTransport(null);
    }
}

function spedizioniDi(int $ordine): array
{
    return righe(Shipment::class, ['order_id' => $ordine]);
}

check('«Crea spedizione» scrive la spedizione e le sue righe con le quantità date', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$a, $b]] = ordineDaSpedire([[articolo(1.0), 3], [articolo(1.0), 2]]);
    $esito = azione('create_shipment', $ordine, ['qty' => [$a => 2, $b => 0]]);
    $spedizioni = spedizioniDi($ordine);
    $righe = $spedizioni === [] ? [] : righe(ShipmentItem::class, ['shipment_id' => $spedizioni[0]['id']]);

    return $esito['ok'] === true
        && count($spedizioni) === 1
        && $spedizioni[0]['status'] === 'pending'
        && count($righe) === 1
        && (int) $righe[0]['order_item_id'] === $a
        && (int) $righe[0]['quantity'] === 2;
}));

check('«Crea spedizione» con «segna subito spedita» parte con corriere e tracking', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $esito = azione('create_shipment', $ordine, [
        'qty' => [$a => 1], 'carrier_id' => vettore(), 'tracking_number' => 'ZZ 99', 'after' => 'ship',
    ]);
    $s = spedizioniDi($ordine)[0] ?? [];

    return $esito['ok'] === true
        && ($s['status'] ?? '') === 'in_transit'
        && ($s['tracking_number'] ?? '') === 'ZZ 99'
        && Order::findById($ordine)['fulfillment_status'] === 'fulfilled';
}));

check('una quantità oltre quella assegnabile è rifiutata con una frase e non scrive niente', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 2]]);
    $esito = azione('create_shipment', $ordine, ['qty' => [$a => 3]]);

    return $esito['ok'] === false && $esito['message'] !== '' && spedizioniDi($ordine) === [];
}));

check('una riga di un altro ordine è rifiutata', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine] = ordineDaSpedire([[articolo(1.0), 1]]);
    [$altro, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $esito = azione('create_shipment', $ordine, ['qty' => [$riga => 1]]);

    return $esito['ok'] === false && spedizioniDi($ordine) === [] && spedizioniDi($altro) === [];
}));

check('nessuna quantità: niente da spedire, nessuna spedizione', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $esito = azione('create_shipment', $ordine, ['qty' => [$a => 0]]);

    return $esito['ok'] === false && spedizioniDi($ordine) === [];
}));

check('«Segna spedita» mette corriere e tracking; ripetuta non cambia né duplica', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]]);
    azione('create_shipment', $ordine, ['qty' => [$a => 1]]);
    $id = (int) spedizioniDi($ordine)[0]['id'];
    $dati = ['shipment_id' => $id, 'carrier_id' => vettore(), 'tracking_number' => 'AB 12'];
    $prima = azione('ship', $ordine, $dati);
    $dopo = azione('ship', $ordine, $dati);
    $s = Shipment::findById($id);

    return $prima['ok'] === true && $dopo['ok'] === true
        && $s['status'] === 'in_transit' && $s['tracking_number'] === 'AB 12'
        && count(spedizioniDi($ordine)) === 1;
}));

check('«Segna spedita» senza tracking dove il corriere lo vuole dà una frase, non un\'eccezione', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]]);
    azione('create_shipment', $ordine, ['qty' => [$a => 1]]);
    $id = (int) spedizioniDi($ordine)[0]['id'];
    $esito = azione('ship', $ordine, ['shipment_id' => $id, 'carrier_id' => vettore(), 'tracking_number' => '']);

    return $esito['ok'] === false && Shipment::findById($id)['status'] === 'pending';
}));

check('una spedizione di un altro ordine non si tocca da quest\'ordine', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]]);
    [$altro, [$b]] = ordineDaSpedire([[articolo(1.0), 1]]);
    azione('create_shipment', $altro, ['qty' => [$b => 1]]);
    $estranea = (int) spedizioniDi($altro)[0]['id'];

    $esiti = [
        azione('ship', $ordine, ['shipment_id' => $estranea, 'carrier_id' => vettore(), 'tracking_number' => 'X']),
        azione('cancel_shipment', $ordine, ['shipment_id' => $estranea]),
        azione('deliver', $ordine, ['shipment_id' => $estranea]),
    ];

    return array_filter($esiti, static fn (array $e): bool => $e['ok']) === []
        && Shipment::findById($estranea)['status'] === 'pending';
}));

check('«Segna consegnata» chiude la spedizione e «Annulla spedizione» libera le quantità', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 2]]);
    azione('create_shipment', $ordine, ['qty' => [$a => 1]]);
    azione('create_shipment', $ordine, ['qty' => [$a => 1]]);
    [$uno, $due] = array_map(static fn (array $s): int => (int) $s['id'], spedizioniDi($ordine));
    azione('ship', $ordine, ['shipment_id' => $uno, 'carrier_id' => vettore(), 'tracking_number' => 'T1']);
    $consegna = azione('deliver', $ordine, ['shipment_id' => $uno]);
    $annulla = azione('cancel_shipment', $ordine, ['shipment_id' => $due]);

    return $consegna['ok'] === true && $annulla['ok'] === true
        && Shipment::findById($uno)['status'] === 'delivered'
        && Shipment::findById($due)['status'] === 'cancelled';
}));

check('«Segna evaso» è rifiutato su un ordine da spedire quando le spedizioni sono accese', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine] = ordineDaSpedire([[articolo(1.0), 1]]);
    $esito = azione('fulfill', $ordine);

    return $esito['ok'] === false
        && Order::findById($ordine)['fulfillment_status'] === 'unfulfilled';
}));

check('con le spedizioni spente «Segna evaso» funziona ancora e le azioni di spedizione sono rifiutate', fn () => prova(static function (): bool {
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]]);
    spegniFunzionalita(['shipping']);
    $crea = azione('create_shipment', $ordine, ['qty' => [$a => 1]]);
    $evaso = azione('fulfill', $ordine);

    return $crea['ok'] === false && $evaso['ok'] === true
        && Order::findById($ordine)['fulfillment_status'] === 'fulfilled';
}));

check('«Pronto per il ritiro» crea il ritiro e lo segna pronto; «Ritirato» lo chiude', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine] = ordineDaRitirare([[articolo(1.0), 1]]);
    $pronto = azione('ready_for_pickup', $ordine);
    $di = spedizioniDi($ordine);
    $ancora = azione('ready_for_pickup', $ordine);
    $ritirato = azione('picked_up', $ordine);

    return $pronto['ok'] === true
        && count($di) === 1 && $di[0]['type'] === 'pickup'
        && count(spedizioniDi($ordine)) === 1
        && $ancora['ok'] === true
        && $ritirato['ok'] === true
        && Shipment::findById((int) $di[0]['id'])['status'] === 'picked_up'
        && Order::findById($ordine)['fulfillment_status'] === 'fulfilled';
}));

check('un\'azione sconosciuta è una frase, non un errore', fn () => prova(static function (): bool {
    [$ordine] = ordineDaSpedire([[articolo(1.0), 1]]);

    return azione('vola', $ordine)['ok'] === false;
}));

/** Una spedizione con stato e data di partenza dati, su un ordine nuovo. */
function spedizioneCon(string $stato, ?string $partita = null): int
{
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $id = \Wonder\Plugin\Gestionale\Support\Shipping\Shipments::create($ordine, [$riga => 1]);
    $valori = ['status' => $stato];

    if ($partita !== null) {
        $valori['shipped_at'] = $partita;
    }

    Shipment::update($valori, $id);

    return $id;
}

check('i conteggi della bacheca: da controllare = eccezione e consegna fallita, e basta', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    $zero = ShipmentAlerts::toCheck();
    spedizioneCon('exception');
    spedizioneCon('failed_attempt');
    spedizioneCon('in_transit');
    spedizioneCon('delivered');
    spedizioneCon('pending');

    return $zero === 0 && ShipmentAlerts::toCheck() === 2;
}));

check('partite da più di sette giorni e non consegnate: il bordo è stretto', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    $giorni = static fn (int $ore): string => date('Y-m-d H:i:s', time() - $ore * 3600);

    spedizioneCon('in_transit', $giorni(7 * 24 - 2));      // quasi sette giorni: no
    spedizioneCon('in_transit', $giorni(7 * 24 + 2));      // oltre: sì
    spedizioneCon('out_for_delivery', $giorni(10 * 24));   // sì
    spedizioneCon('delivered', $giorni(30 * 24));          // consegnata: no
    spedizioneCon('exception', $giorni(30 * 24));          // finisce nell'altro riquadro: no
    spedizioneCon('pending');                              // mai partita: no

    return ShipmentAlerts::notDelivered() === 2;
}));

check('i riquadri disegnano i conteggi veri e a zero tacciono', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    $vuoto = (new ShipmentsToCheckWidget)->render() === '' && (new ShippedNotDeliveredWidget)->render() === '';
    spedizioneCon('exception');
    spedizioneCon('in_transit', date('Y-m-d H:i:s', time() - 9 * 86400));
    $da = (new ShipmentsToCheckWidget)->render();
    $non = (new ShippedNotDeliveredWidget)->render();
    spegniFunzionalita(['shipping']);

    return $vuoto
        && str_contains($da, '1 spedizione') && str_contains($non, '1 spedizione')
        && (new ShipmentsToCheckWidget)->render() === '';
}));

check('la scheda mostra il tracking come testo e il link con il numero codificato', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]]);
    azione('create_shipment', $ordine, [
        'qty' => [$a => 1], 'carrier_id' => vettore(), 'tracking_number' => '<b>AB 1</b>', 'after' => 'ship',
    ]);
    $html = ShipmentResource::trackingHtml(spedizioniDi($ordine)[0]);

    return str_contains($html, '&lt;b&gt;AB 1&lt;/b&gt;') && !str_contains($html, '<b>')
        && str_contains($html, 'rel="noopener');
}));

check('la finestra «Crea spedizione» ha un campo per riga da spedire, con la quantità già piena', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$a, $b]] = ordineDaSpedire([[articolo(1.0), 3], [articolo(1.0), 2]]);
    azione('create_shipment', $ordine, ['qty' => [$b => 2]]);
    $html = ShipmentResource::createModal(Order::findById($ordine), '');

    return str_contains($html, 'name="qty_'.$a.'"')
        && !str_contains($html, 'name="qty_'.$b.'"')
        && str_contains($html, 'name="carrier_id"')
        && str_contains($html, 'name="after"')
        && str_contains($html, 'value="create_shipment"');
}));

check('una riga tutta assegnata non lascia la finestra di creazione', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]]);
    azione('create_shipment', $ordine, ['qty' => [$a => 1]]);

    return ShipmentResource::createModal(Order::findById($ordine), '') === '';
}));

check('la tabella dell\'ordine e la scheda mostrano la spedizione con codice, stato e tracking in testo', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]]);
    azione('create_shipment', $ordine, ['qty' => [$a => 1], 'carrier_id' => vettore(), 'tracking_number' => '<i>7</i>', 'after' => 'ship']);
    $s = spedizioniDi($ordine)[0];
    // La tabella si riempie dall'API: qui si leggono le celle dai formatter delle colonne.
    $tabella = '';

    foreach (\Wonder\Plugin\Gestionale\Resources\Sales\OrderShipmentTableResource::tableSchema() as $colonna) {
        $tabella .= ($colonna->schema['formatter'])($s);
    }

    $scheda = \Wonder\Backend\Support\ResourceFormLayoutRenderer::renderLayout(ShipmentResource::showLayoutSchema($s));

    return str_contains($tabella, (string) $s['code'])
        && str_contains($tabella, '&lt;i&gt;7&lt;/i&gt;') && !str_contains($tabella, '<i>7</i>')
        && str_contains($scheda, (string) $s['code'])
        && str_contains($scheda, '&lt;i&gt;7&lt;/i&gt;') && !str_contains($scheda, '<i>7</i>');
}));

check('le azioni di una spedizione in viaggio: solo «Segna consegnata»', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]]);
    azione('create_shipment', $ordine, ['qty' => [$a => 1], 'carrier_id' => vettore(), 'tracking_number' => 'K1', 'after' => 'ship']);
    $html = ShipmentResource::actionsHtml(spedizioniDi($ordine)[0], '');

    return str_contains($html, 'Segna consegnata') && !str_contains($html, 'Annulla spedizione');
}));

check('«Modifica spedizione» cambia corriere, tracking e stato dalla porta delle azioni', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]]);
    azione('create_shipment', $ordine, ['qty' => [$a => 1]]);
    $id = (int) spedizioniDi($ordine)[0]['id'];
    $v = vettore();
    $scelto = azione('update_shipment', $ordine, ['shipment_id' => $id, 'carrier_id' => $v, 'tracking_number' => '', 'status' => '']);
    $senza = azione('update_shipment', $ordine, ['shipment_id' => $id, 'carrier_id' => $v, 'tracking_number' => '', 'status' => 'in_transit']);
    $parte = azione('update_shipment', $ordine, ['shipment_id' => $id, 'carrier_id' => $v, 'tracking_number' => 'QQ7', 'status' => 'in_transit']);
    $s = Shipment::findById($id);

    return $scelto['ok'] === true && $senza['ok'] === false && str_contains($senza['message'], 'tracking')
        && $parte['ok'] === true && $s['status'] === 'in_transit' && $s['tracking_number'] === 'QQ7' && (int) $s['carrier_id'] === $v;
}));

check('«Modifica spedizione» non tocca una spedizione di un altro ordine', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]]);
    [$altro] = ordineDaSpedire([[articolo(1.0), 1]]);
    azione('create_shipment', $ordine, ['qty' => [$a => 1]]);
    $id = (int) spedizioniDi($ordine)[0]['id'];
    $esito = azione('update_shipment', $altro, ['shipment_id' => $id, 'carrier_id' => vettore(), 'tracking_number' => 'X1']);

    return $esito['ok'] === false && (int) Shipment::findById($id)['carrier_id'] === 0;
}));

check('la scheda offre «Modifica» e la sua finestra con stato, corriere e tracking', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]]);
    azione('create_shipment', $ordine, ['qty' => [$a => 1], 'carrier_id' => vettore(), 'tracking_number' => 'K1', 'after' => 'ship']);
    $s = spedizioniDi($ordine)[0];
    $pulsanti = ShipmentResource::actionsHtml($s, '');
    $finestra = ShipmentResource::modalsFor($s);

    return str_contains($pulsanti, ShipmentResource::editModalId((int) $s['id']))
        && str_contains($finestra, 'name="status"') && str_contains($finestra, 'name="carrier_id"') && str_contains($finestra, 'name="tracking_number"')
        && str_contains($finestra, 'value="update_shipment"')
        && str_contains($finestra, 'value="K1"');
}));

summary();
