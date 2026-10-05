<?php
/** php tests/integrazione/ShipmentsCreateTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/spedizioni.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
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

check('una spedizione completa nasce pending, con le sue righe e una riga di storico', fn () => prova(static function (): bool {
    [$ordine, [$a, $b]] = ordineDaSpedire([[articolo(1.0), 2], [articolo(0.5), 1]]);
    $id = Shipments::create($ordine, [$a => 2, $b => 1], ['tracking_number' => 'ABC1', 'note' => 'fragile']);
    $spedizione = Shipment::findById($id);
    $voci = righe(ShipmentItem::class, 'shipment_id = '.$id);
    $storico = righe(ShipmentStatusLog::class, 'shipment_id = '.$id);

    return $id > 0
        && (int) $spedizione['order_id'] === $ordine
        && $spedizione['status'] === 'pending'
        && $spedizione['type'] === 'delivery'
        && $spedizione['tracking_number'] === 'ABC1'
        && $spedizione['note'] === 'fragile'
        && str_starts_with((string) $spedizione['code'], 'shp_')
        && count($voci) === 2
        && count($storico) === 1
        && (string) ($storico[0]['from_value'] ?? '') === '' && $storico[0]['to_value'] === 'pending'
        && $storico[0]['field'] === 'status';
}));

check('una spedizione parziale lascia il residuo giusto', fn () => prova(static function (): bool {
    [$ordine, [$a, $b]] = ordineDaSpedire([[articolo(1.0), 5], [articolo(0.5), 2]]);
    Shipments::create($ordine, [$a => 2]);

    return Shipments::remaining($ordine) === [$a => 3.0, $b => 2.0];
}));

check('la seconda spedizione oltre il residuo è rifiutata e non scrive niente', fn () => prova(static function (): bool {
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 3]]);
    Shipments::create($ordine, [$a => 2]);
    $prima = count(righe(Shipment::class, 'order_id = '.$ordine));
    $righePrima = count(righe(ShipmentItem::class, 'order_item_id = '.$a));

    $chiave = rifiuto(fn () => Shipments::create($ordine, [$a => 2]));

    return $chiave === 'shipment.over_quantity'
        && count(righe(Shipment::class, 'order_id = '.$ordine)) === $prima
        && count(righe(ShipmentItem::class, 'order_item_id = '.$a)) === $righePrima
        && Shipments::remaining($ordine) === [$a => 1.0];
}));

check('un errore a metà non lascia spedizioni a metà', fn () => prova(static function (): bool {
    [$ordine, [$a, $b]] = ordineDaSpedire([[articolo(1.0), 1], [articolo(1.0), 1]]);

    $chiave = rifiuto(fn () => Shipments::create($ordine, [$a => 1, $b => 5]));

    return $chiave === 'shipment.over_quantity'
        && righe(Shipment::class, 'order_id = '.$ordine) === []
        && righe(ShipmentStatusLog::class, 'shipment_id NOT IN (SELECT id FROM '.Shipment::$table.')') === [];
}));

check('una riga di un altro ordine è rifiutata', fn () => prova(static function (): bool {
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]]);
    [, [$altra]] = ordineDaSpedire([[articolo(1.0), 1]]);

    return rifiuto(fn () => Shipments::create($ordine, [$a => 1, $altra => 1])) === 'shipment.over_quantity'
        && righe(Shipment::class, 'order_id = '.$ordine) === [];
}));

check('una riga di un articolo che non si spedisce è rifiutata', fn () => prova(static function (): bool {
    [$ordine, [$a, $servizio]] = ordineDaSpedire([[articolo(1.0), 1], [articolo(0.0, 5.0, [0, 0, 0], 'false'), 1]]);

    return rifiuto(fn () => Shipments::create($ordine, [$servizio => 1])) === 'shipment.over_quantity'
        && Shipments::remaining($ordine) === [$a => 1.0];
}));

check('una richiesta vuota o tutta a zero non ha niente da spedire', fn () => prova(static function (): bool {
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]]);

    return rifiuto(fn () => Shipments::create($ordine, [])) === 'shipment.nothing_to_ship'
        && rifiuto(fn () => Shipments::create($ordine, [$a => 0])) === 'shipment.nothing_to_ship'
        && rifiuto(fn () => Shipments::create($ordine, [$a => '0.000'])) === 'shipment.nothing_to_ship';
}));

check('un ordine annullato o in bozza non si spedisce', fn () => prova(static function (): bool {
    [$annullato, [$a]] = ordineDaSpedire([[articolo(1.0), 1]], ['status' => 'cancelled']);
    [$bozza, [$b]] = ordineDaSpedire([[articolo(1.0), 1]], ['status' => 'draft']);
    [$carrello, [$c]] = ordineDaSpedire([[articolo(1.0), 1]], ['stage' => 'cart']);

    return rifiuto(fn () => Shipments::create($annullato, [$a => 1])) === 'shipment.order_not_open'
        && rifiuto(fn () => Shipments::create($bozza, [$b => 1])) === 'shipment.order_not_open'
        && rifiuto(fn () => Shipments::create($carrello, [$c => 1])) === 'shipment.order_not_open';
}));

check('un ordine con ritiro in sede non ha spedizioni di consegna', fn () => prova(static function (): bool {
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]], ['fulfillment_type' => 'pickup']);

    return rifiuto(fn () => Shipments::create($ordine, [$a => 1])) === 'shipment.order_not_open';
}));

check('un ordine che non c\'è non si spedisce', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);

    return rifiuto(fn () => Shipments::create(0, [1 => 1])) === 'order.not_found';
}));

check('con la funzionalità spenta non si crea niente', fn () => prova(static function (): bool {
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 1]]);
    spegniFunzionalita(['shipping']);

    return rifiuto(fn () => Shipments::create($ordine, [$a => 1])) === 'shipment.feature_off'
        && righe(Shipment::class, 'order_id = '.$ordine) === [];
}));

check('le quantità con la virgola e i millesimi si assegnano e si sottraggono', fn () => prova(static function (): bool {
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 2.5]]);
    Shipments::create($ordine, [$a => '0,75']);

    return Shipments::remaining($ordine) === [$a => 1.75];
}));

check('una spedizione annullata o resa libera le quantità, una in viaggio no', fn () => prova(static function (): bool {
    [$ordine, [$a]] = ordineDaSpedire([[articolo(1.0), 3]]);
    $uno = Shipments::create($ordine, [$a => 1]);
    $due = Shipments::create($ordine, [$a => 1]);
    $tre = Shipments::create($ordine, [$a => 1]);
    Shipment::update(['status' => 'cancelled'], $uno);
    Shipment::update(['status' => 'returned'], $due);
    Shipment::update(['status' => 'in_transit'], $tre);

    return Shipments::remaining($ordine) === [$a => 2.0];
}));

check('la creazione blocca ordine, righe e spedizioni prima di contare: se un\'altra connessione le tiene, aspetta', function (): bool {
    // Prova con due connessioni vere, quindi con dati confermati: un ordine
    // con una riga viene scritto fuori da `prova()` e tolto a fine prova.
    // La connessione B tiene con un `FOR UPDATE` una tabella alla volta
    // (ordine, righe, spedizioni, righe di spedizione): `create()` di A, che
    // senza il blocco finirebbe in un rifiuto, deve restare in attesa e
    // scadere (errore 1205). Libero il blocco, A riesce: così si sa che era il
    // blocco a fermarla. (La query di blocco non si legge dal
    // log perché `performance_schema` è negato all'utente del sito.)
    accendiFunzionalita(['orders', 'shipping']);
    $a = Order::query()->mysqli;
    $b = new mysqli(
        (string) ($_ENV['DB_HOSTNAME'] ?? 'localhost'),
        (string) ($_ENV['DB_USERNAME'] ?? ''),
        (string) ($_ENV['DB_PASSWORD'] ?? ''),
        substr(strrchr(':'.(string) ($_ENV['DB_DATABASE'] ?? ''), ':'), 1)
    );
    $a->query('SET SESSION innodb_lock_wait_timeout = 1');

    $ordine = (int) (Order::create([
        'code' => 'ord_lock-'.uniqid(), 'stage' => 'order', 'status' => 'confirmed',
        'payment_status' => 'unpaid', 'fulfillment_type' => 'shipping', 'total' => '10.00',
    ])->insert_id ?? 0);
    $riga = (int) (OrderItem::create([
        'order_id' => $ordine, 'type' => 'product', 'product_id' => 0, 'quantity' => '10.000', 'name' => 'Prova blocco',
    ])->insert_id ?? 0);

    $pulisci = static function (int $salva = 0) use ($a, $ordine, $riga): void {
        $spedizioni = 'SELECT id FROM '.Shipment::$table.' WHERE order_id = '.$ordine.' AND id <> '.$salva;
        $a->query('DELETE FROM '.ShipmentStatusLog::$table.' WHERE shipment_id IN ('.$spedizioni.')');
        $a->query('DELETE FROM '.ShipmentItem::$table.' WHERE shipment_id IN ('.$spedizioni.')');
        $a->query('DELETE FROM '.Shipment::$table.' WHERE order_id = '.$ordine.' AND id <> '.$salva);
    };

    try {
        // Una spedizione già viva, perché ci siano righe di spedizione da bloccare.
        $viva = Shipments::create($ordine, [$riga => 1]);
        $esiti = [];

        foreach ([
            'ordine' => 'SELECT id FROM '.Order::$table.' WHERE id = '.$ordine.' FOR UPDATE',
            'righe' => 'SELECT id FROM '.OrderItem::$table.' WHERE order_id = '.$ordine.' FOR UPDATE',
            'spedizioni' => 'SELECT id FROM '.Shipment::$table.' WHERE order_id = '.$ordine.' FOR UPDATE',
            'voci' => 'SELECT id FROM '.ShipmentItem::$table.' WHERE shipment_id = '.$viva.' FOR UPDATE',
        ] as $nome => $blocco) {
            $b->query('BEGIN');
            $b->query($blocco);

            $fermata = false;

            try {
                // Oltre il residuo: senza attese il rifiuto arriva senza scrivere
                // niente (una scrittura prenderebbe da sé i blocchi della chiave
                // esterna e la prova non direbbe più nulla).
                Shipments::create($ordine, [$riga => 50]);
            } catch (UserError) {
                // Un rifiuto non è un'attesa: non conta.
            } catch (Throwable) {
                $fermata = true;
            }

            $b->query('COMMIT');

            $riuscita = true;

            try {
                Shipments::create($ordine, [$riga => 1]);
                $pulisci($viva);
            } catch (Throwable) {
                $riuscita = false;
            }

            $esiti[$nome] = $fermata && $riuscita;
        }

        return $esiti === ['ordine' => true, 'righe' => true, 'spedizioni' => true, 'voci' => true];
    } finally {
        $b->query('COMMIT');
        $b->close();
        $a->query('SET SESSION innodb_lock_wait_timeout = 50');
        $pulisci();
        $a->query('DELETE FROM '.OrderItem::$table.' WHERE id = '.$riga);
        $a->query('DELETE FROM '.Order::$table.' WHERE id = '.$ordine);
    }
});

summary();
