<?php
/** php tests/integrazione/ShipmentsFlowTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

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
use Wonder\Plugin\Gestionale\Models\Shipping\ShipmentItem;
use Wonder\Plugin\Gestionale\Models\Shipping\ShipmentStatusLog;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Shipping\Shipments;
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

/** Le righe di un Model che rispondono alla condizione, in elenco. */
function righe(string $model, string $dove): array
{
    $rows = $model::find($dove);

    if (!is_array($rows) || $rows === []) {
        return [];
    }

    return array_key_exists('id', $rows) ? [$rows] : array_values(array_filter($rows, 'is_array'));
}

/** La chiave del rifiuto atteso, o null se non c'è stato. */
function rifiuto(callable $fn): ?string
{
    try {
        $fn();
    } catch (UserError $errore) {
        return $errore->key();
    }

    return null;
}

/** Un vettore di prova, con o senza modello per il link di tracking. */
function vettore(string $modello = 'https://tracking.esempio.it/?codice={tracking}'): int
{
    return (int) (Carrier::create([
        'code' => 'car_flow-'.uniqid(),
        'name' => 'Corriere di prova',
        'tracking_url_template' => $modello,
        'active' => 'true',
    ])->insert_id ?? 0);
}

/** Lo stato dell'ordine. */
function statoOrdine(int $ordine): array
{
    return righe(Order::class, 'id = '.$ordine)[0];
}

/** Le righe di storico dell'ordine su un campo. */
function storicoOrdine(int $ordine, string $campo): array
{
    return righe(OrderStatusLog::class, "order_id = {$ordine} AND field = '{$campo}'");
}

/** Le righe di storico di una spedizione, dalla più vecchia. */
function storicoSpedizione(int $spedizione): array
{
    return righe(ShipmentStatusLog::class, 'shipment_id = '.$spedizione);
}

check('ship con vettore e tracking porta in viaggio, costruisce il link e evade l\'ordine in parte', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 3]]);
    $id = Shipments::create($ordine, [$riga => 2]);
    Shipments::ship($id, ['carrier_id' => vettore(), 'tracking_number' => 'AB 12/é', 'shipped_at' => '2026-09-30']);
    $s = Shipment::findById($id);

    return $s['status'] === 'in_transit'
        && $s['tracking_number'] === 'AB 12/é'
        && $s['tracking_url'] === 'https://tracking.esempio.it/?codice=AB%2012%2F%C3%A9'
        && str_starts_with((string) $s['shipped_at'], '2026-09-30')
        && statoOrdine($ordine)['fulfillment_status'] === 'partially_fulfilled';
}));

check('ship senza data parte oggi', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $id = Shipments::create($ordine, [$riga => 1]);
    Shipments::ship($id, ['carrier_id' => vettore(''), 'tracking_number' => '']);

    return str_starts_with((string) Shipment::findById($id)['shipped_at'], date('Y-m-d'));
}));

check('una data che non è una data si rifiuta e non scrive niente', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $id = Shipments::create($ordine, [$riga => 1]);

    return rifiuto(fn () => Shipments::ship($id, ['carrier_id' => vettore(''), 'shipped_at' => '31/09/2026'])) === 'shipment.bad_date'
        && Shipment::findById($id)['status'] === 'pending'
        && count(storicoSpedizione($id)) === 1;
}));

check('spedita anche la seconda l\'ordine è evaso e, se pagato, si chiude da solo', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 3]], ['payment_status' => 'paid']);
    $v = vettore('');
    $uno = Shipments::create($ordine, [$riga => 2]);
    $due = Shipments::create($ordine, [$riga => 1]);
    Shipments::ship($uno, ['carrier_id' => $v]);
    $dopoLaPrima = statoOrdine($ordine);
    Shipments::ship($due, ['carrier_id' => $v]);
    $fine = statoOrdine($ordine);

    return $dopoLaPrima['fulfillment_status'] === 'partially_fulfilled' && $dopoLaPrima['status'] === 'confirmed'
        && $fine['fulfillment_status'] === 'fulfilled' && $fine['status'] === 'completed';
}));

check('una spedizione in attesa non evade niente: finché non parte l\'ordine resta da evadere', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 3]]);
    Shipments::create($ordine, [$riga => 3]);

    return statoOrdine($ordine)['fulfillment_status'] === 'unfulfilled'
        && storicoOrdine($ordine, 'fulfillment_status') === [];
}));

check('annullare una spedizione in attesa libera le quantità e l\'evasione non cambia', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 3]]);
    $uno = Shipments::create($ordine, [$riga => 2]);
    $due = Shipments::create($ordine, [$riga => 1]);
    Shipments::ship($uno, ['carrier_id' => vettore('')]);
    Shipments::cancel($due);
    $log = storicoSpedizione($due);

    return Shipment::findById($due)['status'] === 'cancelled'
        && Shipments::remaining($ordine) === [$riga => 1.0]
        && statoOrdine($ordine)['fulfillment_status'] === 'partially_fulfilled'
        && count($log) === 2 && $log[1]['from_value'] === 'pending' && $log[1]['to_value'] === 'cancelled';
}));

check('una spedizione già in viaggio non si annulla', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $id = Shipments::create($ordine, [$riga => 1]);
    Shipments::ship($id, ['carrier_id' => vettore('')]);

    return rifiuto(fn () => Shipments::cancel($id)) === 'shipment.bad_transition'
        && Shipment::findById($id)['status'] === 'in_transit';
}));

check('con un vettore che ha il link il tracking è obbligatorio; senza link no', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 2]]);
    $uno = Shipments::create($ordine, [$riga => 1]);
    $due = Shipments::create($ordine, [$riga => 1]);
    $con = vettore();
    $senza = vettore('');

    $primo = rifiuto(fn () => Shipments::ship($uno, ['carrier_id' => $con, 'tracking_number' => '  ']));
    $ferma = Shipment::findById($uno)['status'] === 'pending' && count(storicoSpedizione($uno)) === 1;
    Shipments::ship($due, ['carrier_id' => $senza, 'tracking_number' => '']);
    $s = Shipment::findById($due);

    return $primo === 'shipment.tracking_required' && $ferma
        && $s['status'] === 'in_transit' && (string) $s['tracking_url'] === '';
}));

check('un vettore già scelto alla creazione basta, non va riscritto alla spedizione', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $id = Shipments::create($ordine, [$riga => 1], ['carrier_id' => vettore(), 'tracking_number' => 'XY99']);
    Shipments::ship($id, []);
    $s = Shipment::findById($id);

    return $s['status'] === 'in_transit' && str_ends_with((string) $s['tracking_url'], 'XY99');
}));

check('una spedizione resa libera le quantità e riporta l\'ordine indietro', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 3]]);
    $v = vettore('');
    $uno = Shipments::create($ordine, [$riga => 2]);
    $due = Shipments::create($ordine, [$riga => 1]);
    Shipments::ship($uno, ['carrier_id' => $v]);
    Shipments::ship($due, ['carrier_id' => $v]);
    $evaso = statoOrdine($ordine)['fulfillment_status'] === 'fulfilled';

    Shipments::advance($due, 'returned');
    $parziale = statoOrdine($ordine)['fulfillment_status'] === 'partially_fulfilled' && Shipments::remaining($ordine) === [$riga => 1.0];

    Shipments::advance($uno, 'returned');

    return $evaso && $parziale
        && statoOrdine($ordine)['fulfillment_status'] === 'unfulfilled'
        && Shipments::remaining($ordine) === [$riga => 3.0];
}));

check('due ship di fila sulla stessa spedizione scrivono una sola riga di storico', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $id = Shipments::create($ordine, [$riga => 1]);
    $v = vettore('');
    Shipments::ship($id, ['carrier_id' => $v]);
    Shipments::ship($id, ['carrier_id' => $v]);

    return count(storicoSpedizione($id)) === 2
        && count(storicoOrdine($ordine, 'fulfillment_status')) === 1;
}));

check('lo stesso stato due volte con advance non scrive niente', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $id = Shipments::create($ordine, [$riga => 1]);
    Shipments::ship($id, ['carrier_id' => vettore('')]);
    Shipments::advance($id, 'out_for_delivery');
    Shipments::advance($id, 'out_for_delivery');

    return count(storicoSpedizione($id)) === 3;
}));

check('delivered scrive la data di consegna e tiene l\'evasione', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $id = Shipments::create($ordine, [$riga => 1]);
    Shipments::ship($id, ['carrier_id' => vettore('')]);
    Shipments::advance($id, 'delivered');
    $s = Shipment::findById($id);

    return $s['status'] === 'delivered'
        && str_starts_with((string) $s['delivered_at'], date('Y-m-d'))
        && statoOrdine($ordine)['fulfillment_status'] === 'fulfilled';
}));

check('i passaggi non ammessi si rifiutano e non scrivono: indietro, da consegnata, da in attesa a resa', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 2]]);
    $uno = Shipments::create($ordine, [$riga => 1]);
    $due = Shipments::create($ordine, [$riga => 1]);
    Shipments::ship($uno, ['carrier_id' => vettore('')]);
    Shipments::advance($uno, 'delivered');

    $scritte = count(storicoSpedizione($uno)) + count(storicoSpedizione($due));

    return rifiuto(fn () => Shipments::advance($uno, 'in_transit')) === 'shipment.bad_transition'
        && rifiuto(fn () => Shipments::advance($uno, 'out_for_delivery')) === 'shipment.bad_transition'
        && rifiuto(fn () => Shipments::advance($due, 'returned')) === 'shipment.bad_transition'
        && rifiuto(fn () => Shipments::advance($due, 'picked_up')) === 'shipment.bad_transition'
        && rifiuto(fn () => Shipments::advance($due, 'inventato')) === 'shipment.bad_transition'
        && Shipment::findById($uno)['status'] === 'delivered'
        && count(storicoSpedizione($uno)) + count(storicoSpedizione($due)) === $scritte;
}));

check('da in attesa a consegnata si passa dalla spedizione: serve il tracking e restano due righe di storico', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $id = Shipments::create($ordine, [$riga => 1]);
    $v = vettore();

    $senza = rifiuto(fn () => Shipments::advance($id, 'delivered', ['carrier_id' => $v]));
    $ferma = Shipment::findById($id)['status'] === 'pending';
    Shipments::advance($id, 'delivered', ['carrier_id' => $v, 'tracking_number' => 'Z1']);
    $log = storicoSpedizione($id);

    return $senza === 'shipment.tracking_required' && $ferma
        && Shipment::findById($id)['status'] === 'delivered'
        && array_column($log, 'to_value') === ['pending', 'in_transit', 'delivered']
        && statoOrdine($ordine)['fulfillment_status'] === 'fulfilled';
}));

check('annullare con advance è come cancel', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $id = Shipments::create($ordine, [$riga => 1]);
    Shipments::advance($id, 'cancelled');

    return Shipment::findById($id)['status'] === 'cancelled' && Shipments::remaining($ordine) === [$riga => 1.0];
}));

check('con l\'ordine annullato dopo la creazione la spedizione non parte, ma si può annullare', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $id = Shipments::create($ordine, [$riga => 1]);
    Order::update(['status' => 'cancelled'], $ordine);

    $rifiutata = rifiuto(fn () => Shipments::ship($id, ['carrier_id' => vettore('')])) === 'shipment.order_not_open'
        && Shipment::findById($id)['status'] === 'pending'
        && statoOrdine($ordine)['fulfillment_status'] === 'unfulfilled';
    Shipments::cancel($id);

    return $rifiutata && Shipment::findById($id)['status'] === 'cancelled';
}));

check('con la funzionalità spenta non si spedisce, non si avanza e non si annulla', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 2]]);
    $uno = Shipments::create($ordine, [$riga => 1]);
    $due = Shipments::create($ordine, [$riga => 1]);
    $v = vettore('');
    Shipments::ship($due, ['carrier_id' => $v]);
    spegniFunzionalita(['shipping']);

    $esiti = [
        rifiuto(fn () => Shipments::ship($uno, ['carrier_id' => $v])),
        rifiuto(fn () => Shipments::advance($due, 'delivered')),
        rifiuto(fn () => Shipments::cancel($uno)),
    ];
    accendiFunzionalita(['shipping']);

    return $esiti === ['shipment.feature_off', 'shipment.feature_off', 'shipment.feature_off']
        && Shipment::findById($uno)['status'] === 'pending'
        && Shipment::findById($due)['status'] === 'in_transit';
}));

check('una spedizione che non c\'è si rifiuta', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);

    return rifiuto(fn () => Shipments::ship(0, [])) === 'shipment.not_found'
        && rifiuto(fn () => Shipments::advance(999999999, 'delivered')) === 'shipment.not_found'
        && rifiuto(fn () => Shipments::cancel(999999999)) === 'shipment.not_found';
}));

check('lo storico dell\'ordine riceve il passaggio con la fonte e l\'utente di chi l\'ha fatto', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 2]]);
    $id = Shipments::create($ordine, [$riga => 1], ['source' => 'user', 'user_id' => 7]);
    Shipments::ship($id, ['carrier_id' => vettore('')]);
    $storico = storicoOrdine($ordine, 'fulfillment_status');
    $spedizione = storicoSpedizione($id);

    return count($storico) === 1
        && $storico[0]['from_value'] === 'unfulfilled' && $storico[0]['to_value'] === 'partially_fulfilled'
        && $storico[0]['source'] === 'user'
        && (int) $spedizione[0]['user_id'] === 7;
}));

check('se l\'evasione non cambia l\'ordine non riceve una riga di storico in più', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 3]]);
    $v = vettore('');
    Shipments::ship(Shipments::create($ordine, [$riga => 1]), ['carrier_id' => $v]);
    Shipments::ship(Shipments::create($ordine, [$riga => 1]), ['carrier_id' => $v]);

    return statoOrdine($ordine)['fulfillment_status'] === 'partially_fulfilled'
        && count(storicoOrdine($ordine, 'fulfillment_status')) === 1;
}));

check('la fonte e l\'utente passati a ship finiscono nello storico dell\'ordine e della spedizione', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $id = Shipments::create($ordine, [$riga => 1]);
    Shipments::ship($id, ['carrier_id' => vettore(''), 'source' => 'system', 'user_id' => 9]);
    $ordineLog = storicoOrdine($ordine, 'fulfillment_status');
    $spedizioneLog = storicoSpedizione($id);

    return $ordineLog[0]['source'] === 'system'
        && (int) $spedizioneLog[1]['user_id'] === 9 && $spedizioneLog[1]['source'] === 'system';
}));

check('il corriere e il tracking si cambiano dopo, senza toccare lo stato né la data di partenza', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $primo = vettore();
    $id = Shipments::create($ordine, [$riga => 1]);
    Shipments::ship($id, ['carrier_id' => $primo, 'tracking_number' => 'AA1', 'shipped_at' => '2026-01-10']);
    $secondo = vettore('https://altro.esempio.it/?n={tracking}');
    Shipments::update($id, ['carrier_id' => $secondo, 'tracking_number' => 'BB2']);
    $s = Shipment::findById($id);

    return (int) $s['carrier_id'] === $secondo && $s['tracking_number'] === 'BB2'
        && $s['tracking_url'] === 'https://altro.esempio.it/?n=BB2'
        && $s['status'] === 'in_transit' && str_starts_with((string) $s['shipped_at'], '2026-01-10')
        && count(storicoSpedizione($id)) === 2;
}));

check('su una spedizione in attesa il tracking si può aggiungere dopo: serve solo per farla partire', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $v = vettore();
    $id = Shipments::create($ordine, [$riga => 1]);
    Shipments::update($id, ['carrier_id' => $v]);
    $scelto = (int) Shipment::findById($id)['carrier_id'];
    $senza = rifiuto(fn () => Shipments::update($id, ['status' => 'in_transit']));
    $ferma = Shipment::findById($id)['status'] === 'pending';
    Shipments::update($id, ['status' => 'in_transit', 'tracking_number' => 'CC3']);
    $s = Shipment::findById($id);

    return $scelto === $v && $senza === 'shipment.tracking_required' && $ferma
        && $s['status'] === 'in_transit' && $s['tracking_url'] === 'https://tracking.esempio.it/?codice=CC3';
}));

check('una spedizione già partita non perde il tracking: con un corriere che ne ha bisogno non si svuota', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $id = Shipments::create($ordine, [$riga => 1]);
    Shipments::ship($id, ['carrier_id' => vettore(), 'tracking_number' => 'AA1']);
    $senza = rifiuto(fn () => Shipments::update($id, ['tracking_number' => '']));

    return $senza === 'shipment.tracking_required' && Shipment::findById($id)['tracking_number'] === 'AA1';
}));

check('update cambia anche lo stato, con le regole di sempre, e sta tutto o niente', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $v = vettore();
    $id = Shipments::create($ordine, [$riga => 1]);
    Shipments::ship($id, ['carrier_id' => $v, 'tracking_number' => 'AA1']);
    $indietro = rifiuto(fn () => Shipments::update($id, ['status' => 'pending', 'tracking_number' => 'ZZ9']));
    $intatto = Shipment::findById($id)['tracking_number'] === 'AA1';
    Shipments::update($id, ['status' => 'delivered', 'tracking_number' => 'AA2']);
    $s = Shipment::findById($id);

    return $indietro === 'shipment.bad_transition' && $intatto
        && $s['status'] === 'delivered' && $s['tracking_number'] === 'AA2'
        && statoOrdine($ordine)['fulfillment_status'] === 'fulfilled';
}));

check('update non cambia il corriere di un ritiro né di una spedizione annullata', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 1]]);
    $id = Shipments::create($ordine, [$riga => 1]);
    Shipments::cancel($id);
    $annullata = rifiuto(fn () => Shipments::update($id, ['carrier_id' => vettore()]));

    return $annullata === 'shipment.bad_transition';
}));

summary();
