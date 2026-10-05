<?php
/** php tests/integrazione/ShipmentsPickupTest.php */

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
use Wonder\App\Models\Config\SocietyLocation;
use Wonder\App\Models\Config\SocietyLocationHour;
use Wonder\App\Support\SocietyLocations;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;
use Wonder\Plugin\Gestionale\Models\Shipping\ShipmentItem;
use Wonder\Plugin\Gestionale\Models\Shipping\ShipmentStatusLog;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Plugin\Gestionale\Support\Orders\PaymentTiming;
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

/**
 * Una sede di prova: la riga del core, i suoi orari (sempre aperta o mai) e la
 * riga del gestionale. Si butta con la transazione della prova.
 *
 * @return int l'id della riga di `gst_locations`
 */
function sede(bool $ritiro = true, bool $aperta = true, string $attiva = 'true'): int
{
    $core = (int) (SocietyLocation::create([
        'label' => 'Prova ritiro',
        'slug' => 'prova-ritiro-'.uniqid(),
        'position' => 9002,
        'visible' => 'true',
    ])->insert_id ?? 0);

    if ($aperta) {
        // Un orario con l'apertura e senza chiusura vale «sempre aperto».
        SocietyLocationHour::create([
            'society_location_id' => $core,
            'hours_type' => 'regular',
            'open_day' => 'Mon',
            'open_time' => '00:00',
            'close_time' => '',
            'position' => 1,
        ]);
    }

    SocietyLocations::reset();

    return (int) (Location::create([
        'society_location_id' => $core,
        'has_stock' => 'true',
        'is_pickup_point' => $ritiro ? 'true' : 'false',
        'active' => $attiva,
    ])->insert_id ?? 0);
}

/**
 * Un ordine confermato da ritirare, con le righe date ([articolo, quantità]).
 *
 * @return array{0: int, 1: list<int>}
 */
function ordineDaRitirare(array $righeOrdine, ?int $sedeId = null, array $ordine = []): array
{
    [$id, $righeId] = ordineDaSpedire($righeOrdine, $ordine + [
        'fulfillment_type' => 'pickup',
        'location_id' => $sedeId ?? sede(),
    ]);

    return [$id, $righeId];
}

check('un ritiro in una sede di ritiro aperta nasce in attesa con tutto il residuo e una riga di storico', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    $sede = sede();
    [$ordine, [$a, $b]] = ordineDaRitirare([[articolo(1.0), 3], [articolo(2.0), 1]], $sede);
    $id = Shipments::createPickup($ordine, ['source' => 'user', 'user_id' => 5]);
    $s = righe(Shipment::class, 'id = '.$id)[0];
    $voci = righe(ShipmentItem::class, 'shipment_id = '.$id);
    $quantita = [];

    foreach ($voci as $voce) {
        $quantita[(int) $voce['order_item_id']] = (float) $voce['quantity'];
    }

    $storico = storicoSpedizione($id);

    return $s['type'] === 'pickup' && $s['status'] === 'pending'
        && (int) $s['location_id'] === $sede && (int) $s['carrier_id'] === 0
        && $quantita === [$a => 3.0, $b => 1.0]
        && count($storico) === 1 && $storico[0]['from_value'] === null && $storico[0]['to_value'] === 'pending'
        && (int) $storico[0]['user_id'] === 5
        && statoOrdine($ordine)['fulfillment_status'] === 'unfulfilled';
}));

check('un ritiro prende solo quello che resta, e senza righe da ritirare non c\'è niente da fare', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine] = ordineDaRitirare([[articolo(1.0, 10.0, [0, 0, 0], 'false'), 2]]);

    return rifiuto(fn () => Shipments::createPickup($ordine)) === 'shipment.nothing_to_ship'
        && righe(Shipment::class, 'order_id = '.$ordine) === [];
}));

check('una sede che non è di ritiro, spenta o assente si rifiuta e non scrive niente', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    $esiti = [];

    foreach ([sede(false), sede(true, true, 'false'), 0] as $sedeId) {
        [$ordine] = ordineDaRitirare([[articolo(1.0), 1]], $sedeId);
        $esiti[] = rifiuto(fn () => Shipments::createPickup($ordine));
        $esiti[] = righe(Shipment::class, 'order_id = '.$ordine) === [];
    }

    return $esiti === ['shipment.not_pickup_point', true, 'shipment.not_pickup_point', true, 'shipment.not_pickup_point', true];
}));

check('una sede di ritiro chiusa si rifiuta', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine] = ordineDaRitirare([[articolo(1.0), 1]], sede(true, false));

    return rifiuto(fn () => Shipments::createPickup($ordine)) === 'shipment.location_closed'
        && righe(Shipment::class, 'order_id = '.$ordine) === [];
}));

check('un ordine con la consegna non ha un ritiro, e un ordine da ritirare non ha una consegna', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$consegna, [$rigaConsegna]] = ordineDaSpedire([[articolo(1.0), 1]], ['location_id' => sede()]);
    [$ritiro, [$rigaRitiro]] = ordineDaRitirare([[articolo(1.0), 1]]);

    return rifiuto(fn () => Shipments::createPickup($consegna)) === 'shipment.order_not_open'
        && rifiuto(fn () => Shipments::create($ritiro, [$rigaRitiro => 1])) === 'shipment.order_not_open';
}));

check('un ordine annullato o in bozza non ha un ritiro', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    $esiti = [];

    foreach (['cancelled', 'draft'] as $stato) {
        [$ordine] = ordineDaRitirare([[articolo(1.0), 1]], null, ['status' => $stato]);
        $esiti[] = rifiuto(fn () => Shipments::createPickup($ordine));
    }

    return $esiti === ['shipment.order_not_open', 'shipment.order_not_open'];
}));

check('un solo ritiro vivo per ordine: dopo l\'annullamento se ne può fare un altro', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine] = ordineDaRitirare([[articolo(1.0), 2]]);
    $primo = Shipments::createPickup($ordine);
    $doppio = rifiuto(fn () => Shipments::createPickup($ordine));
    $quanti = count(righe(Shipment::class, 'order_id = '.$ordine));
    Shipments::cancel($primo);
    $secondo = Shipments::createPickup($ordine);

    return $doppio === 'shipment.pickup_exists' && $quanti === 1 && $secondo !== $primo
        && count(righe(Shipment::class, 'order_id = '.$ordine)) === 2;
}));

check('ready porta l\'ordine a pronto per il ritiro, pickedUp lo evade; pagato si chiude', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine] = ordineDaRitirare([[articolo(1.0), 2]], null, ['payment_status' => 'paid']);
    $id = Shipments::createPickup($ordine);
    Shipments::ready($id, ['source' => 'user', 'user_id' => 3]);
    $pronto = statoOrdine($ordine);
    $statoPronto = righe(Shipment::class, 'id = '.$id)[0]['status'];
    Shipments::pickedUp($id, ['source' => 'user', 'user_id' => 3]);
    $fine = statoOrdine($ordine);
    $log = array_map(static fn (array $r): string => (string) $r['to_value'], storicoSpedizione($id));
    $logOrdine = array_map(static fn (array $r): string => (string) $r['to_value'], storicoOrdine($ordine, 'fulfillment_status'));

    return $statoPronto === 'ready_for_pickup'
        && $pronto['fulfillment_status'] === 'ready_for_pickup' && $pronto['status'] === 'confirmed'
        && righe(Shipment::class, 'id = '.$id)[0]['status'] === 'picked_up'
        && $fine['fulfillment_status'] === 'fulfilled' && $fine['status'] === 'completed'
        && $log === ['pending', 'ready_for_pickup', 'picked_up']
        && $logOrdine === ['ready_for_pickup', 'fulfilled'];
}));

check('pickedUp senza ready si rifiuta; doppio ready o doppio pickedUp scrivono una sola riga', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine] = ordineDaRitirare([[articolo(1.0), 1]]);
    $id = Shipments::createPickup($ordine);
    $senza = rifiuto(fn () => Shipments::pickedUp($id));
    $dopoRifiuto = count(storicoSpedizione($id));
    Shipments::ready($id);
    Shipments::ready($id);
    $dopoReady = count(storicoSpedizione($id));
    Shipments::pickedUp($id);
    Shipments::pickedUp($id);

    return $senza === 'shipment.bad_transition' && $dopoRifiuto === 1 && $dopoReady === 2
        && count(storicoSpedizione($id)) === 3
        && count(storicoOrdine($ordine, 'fulfillment_status')) === 2;
}));

check('ready e pickedUp valgono solo per i ritiri, ship solo per le consegne', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaSpedire([[articolo(1.0), 2]]);
    $consegna = Shipments::create($ordine, [$riga => 2]);
    [$ritiro] = ordineDaRitirare([[articolo(1.0), 1]]);
    $ritiroId = Shipments::createPickup($ritiro);

    return rifiuto(fn () => Shipments::ready($consegna)) === 'shipment.bad_transition'
        && rifiuto(fn () => Shipments::pickedUp($consegna)) === 'shipment.bad_transition'
        && rifiuto(fn () => Shipments::ship($ritiroId)) === 'shipment.bad_transition'
        && rifiuto(fn () => Shipments::advance($ritiroId, 'in_transit')) === 'shipment.bad_transition'
        && righe(Shipment::class, 'id = '.$ritiroId)[0]['status'] === 'pending';
}));

check('annullare un ritiro pronto libera tutto e riporta l\'ordine a da evadere', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine, [$riga]] = ordineDaRitirare([[articolo(1.0), 2]]);
    $id = Shipments::createPickup($ordine);
    Shipments::ready($id);
    Shipments::cancel($id);
    $dopo = statoOrdine($ordine);
    $log = array_map(static fn (array $r): string => (string) $r['to_value'], storicoOrdine($ordine, 'fulfillment_status'));

    return righe(Shipment::class, 'id = '.$id)[0]['status'] === 'cancelled'
        && $dopo['fulfillment_status'] === 'unfulfilled' && $log === ['ready_for_pickup', 'unfulfilled']
        && Shipments::remaining($ordine) === [$riga => 2.0];
}));

check('un ritiro già ritirato non si annulla', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine] = ordineDaRitirare([[articolo(1.0), 1]]);
    $id = Shipments::createPickup($ordine);
    Shipments::ready($id);
    Shipments::pickedUp($id);

    return rifiuto(fn () => Shipments::cancel($id)) === 'shipment.bad_transition'
        && statoOrdine($ordine)['fulfillment_status'] === 'fulfilled';
}));

check('con la funzionalità spenta il ritiro non si crea', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    [$ordine] = ordineDaRitirare([[articolo(1.0), 1]]);
    spegniFunzionalita(['shipping']);

    return rifiuto(fn () => Shipments::createPickup($ordine)) === 'shipment.feature_off';
}));

/** Un listino con il contrassegno a 3,50 € e un carrello che lo sceglie. */
function carrelloConContrassegno(string $modo, int $sedeId): int
{
    accendiFunzionalita(['orders', 'shipping']);
    $it = zona('Italia', [['IT', '']]);
    $metodo = metodo('Standard');
    listino($metodo, $it, [[5, 8.0], [20, 15.0]], ['cod_fee' => '3.50']);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    Order::update(['shipping_method_id' => $metodo, 'fulfillment_type' => $modo, 'location_id' => $sedeId], $cart);

    return $cart;
}

/** Il checkout di un carrello col pagamento alla consegna, senza spedire posta. */
function contrassegno(int $cart, string $modo, int $sedeId): int
{
    $pagamento = (int) (PaymentMethod::create([
        'code' => 'tst_'.uniqid(),
        'name' => 'Contrassegno di prova',
        'provider' => 'manual',
        'timing' => PaymentTiming::ON_DELIVERY,
        'fee_type' => 'none',
        'fee_value' => '0.00',
        'available_for' => 'all',
        'active' => 'true',
        'position' => 1,
        'instructions' => 'Istruzioni di prova',
    ])->insert_id ?? 0);

    Mailer::useTransport(static fn (): bool => true);

    try {
        return (int) Checkout::place($cart, [
            'email' => 'cliente@example.com',
            'payment_method_id' => $pagamento,
            'fulfillment_type' => $modo,
            'location_id' => $sedeId,
            'shipping_method_id' => (int) Order::findById($cart)['shipping_method_id'],
            'billing' => [
                'country' => 'IT', 'province' => 'MI', 'city' => 'Milano', 'cap' => '20100',
                'street' => 'Via Prova', 'number' => '1', 'name' => 'Mario', 'surname' => 'Rossi',
            ],
        ])['order_id'];
    } finally {
        Mailer::useTransport(null);
    }
}

check('con la consegna il contrassegno porta la commissione del listino, con il ritiro no e senza riga di spedizione', fn () => prova(static function (): bool {
    // Il checkout non sceglie la sede del ritiro: la merce esce dalla principale.
    $sedeId = 0;
    $consegna = contrassegno(carrelloConContrassegno('shipping', $sedeId), 'shipping', $sedeId);
    $ritiro = contrassegno(carrelloConContrassegno('pickup', $sedeId), 'pickup', $sedeId);
    $commissioni = static fn (int $ordine, string $tipo): array => righe(OrderItem::class, "order_id = {$ordine} AND type = '{$tipo}' AND deleted = 'false'");

    return count($commissioni($consegna, 'fee')) === 1
        && (string) $commissioni($consegna, 'fee')[0]['line_total'] === '3.50'
        && $commissioni($ritiro, 'fee') === []
        && $commissioni($ritiro, 'shipping') === []
        && (string) statoOrdine($ritiro)['shipping_total'] === '0.00';
}));

summary();
