<?php
/** php tests/integrazione/ExpiryTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Expiry;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
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

/** Conta le email che partono dentro il corpo. */
function conPosta(callable $corpo): array
{
    $partite = [];
    Mailer::useTransport(static function (string $to) use (&$partite): bool {
        $partite[] = $to;

        return true;
    });

    try {
        $esito = $corpo();
    } finally {
        Mailer::useTransport(null);
    }

    return [$esito, $partite];
}

/**
 * Un ordine in attesa, prenotato, ordinato nel momento che si vuole.
 *
 * @return array{0: int, 1: int}
 */
function ordineInAttesa(string $orderedAt, ?string $expiresAt): array
{
    $prodotto = articoloConGiacenza(5, 'TST-SCAD-'.substr((string) microtime(true), -6));
    $ordine = ordineDiProva(40.0);
    Order::update([
        'email' => 'cliente@example.com',
        'order_number' => date('Y').'/09'.substr((string) microtime(true), -4),
        'ordered_at' => $orderedAt,
    ], $ordine);
    $riga = OrderItem::create([
        'order_id' => $ordine,
        'type' => 'product',
        'product_id' => $prodotto,
        'position' => 1,
        'name' => 'Crema da prova',
        'quantity' => '2.000',
        'unit_price' => '20.00',
        'line_total' => '40.00',
    ]);

    Allocation::reserve([
        'product_id' => $prodotto,
        'quantity' => 2,
        'order_id' => $ordine,
        'order_item_id' => (int) ($riga->insert_id ?? 0),
        'expires_at' => $expiresAt ?? '',
    ]);

    return [$ordine, $prodotto];
}

check('la prenotazione scaduta libera la merce', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordineInAttesa(
            date('Y-m-d H:i:s', strtotime('-2 hours')),
            date('Y-m-d H:i:s', strtotime('-1 hour'))
        );
        $prima = Levels::of($prodotto)['available'];

        [$esito] = conPosta(static fn (): array => Expiry::run());

        // Scaduta, non conta già più: il disponibile non cambia, cambia la riga.
        return $esito['released'] >= 1
            && Levels::of($prodotto)['available'] === $prima
            && (int) sqlCount(StockReservation::$table, "order_id = {$ordine} AND released_at IS NULL AND deleted = 'false'") === 0;
    });
});

check('la prenotazione ancora buona non si tocca', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordineInAttesa(
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', strtotime('+1 hour'))
        );
        $prima = Levels::of($prodotto)['available'];

        conPosta(static fn (): array => Expiry::run());

        return Levels::of($prodotto)['available'] === $prima
            && (int) sqlCount(StockReservation::$table, "order_id = {$ordine} AND released_at IS NULL AND deleted = 'false'") === 1;
    });
});

check('la prenotazione senza scadenza non si libera mai', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordineInAttesa(date('Y-m-d H:i:s'), null);
        $prima = Levels::of($prodotto)['available'];

        conPosta(static fn (): array => Expiry::run());

        return Levels::of($prodotto)['available'] === $prima
            && (int) sqlCount(StockReservation::$table, "order_id = {$ordine} AND released_at IS NULL AND deleted = 'false'") === 1;
    });
});

check('a metà strada parte il promemoria, una volta sola', function () {
    return prova(static function (): bool {
        $giorni = (int) (Wonder\Plugin\Gestionale\Models\System\Setting::current()['order_payment_wait_days'] ?? 7);
        [$ordine] = ordineInAttesa(
            date('Y-m-d H:i:s', strtotime('-'.max(1, (int) ceil($giorni / 2)).' days')),
            date('Y-m-d H:i:s', strtotime('+1 day'))
        );

        [$primo, $partite] = conPosta(static fn (): array => Expiry::run());
        [$secondo] = conPosta(static fn (): array => Expiry::run());

        return $primo['reminded'] === 1
            && $partite === ['cliente@example.com']
            && $secondo['reminded'] === 0
            && (int) sqlCount(
                OrderStatusLog::$table,
                "order_id = {$ordine} AND field = 'payment_reminder' AND deleted = 'false'"
            ) === 1;
    });
});

check('scaduto il tempo l\'ordine si annulla e la merce torna libera', function () {
    return prova(static function (): bool {
        $giorni = (int) (Wonder\Plugin\Gestionale\Models\System\Setting::current()['order_payment_wait_days'] ?? 7);
        [$ordine, $prodotto] = ordineInAttesa(
            date('Y-m-d H:i:s', strtotime('-'.($giorni + 1).' days')),
            date('Y-m-d H:i:s', strtotime('-1 hour'))
        );
        $prima = Levels::of($prodotto);

        [$esito, $partite] = conPosta(static fn (): array => Expiry::run());
        $riga = Order::findById($ordine);
        $dopo = Levels::of($prodotto);

        return $esito['cancelled'] === 1
            && in_array($ordine, $esito['orders'], true)
            && $riga['status'] === 'cancelled'
            && $dopo['quantity'] === $prima['quantity']
            && $dopo['available'] === $prima['quantity']
            // Una al cliente, una al commerciante.
            && count($partite) >= 1;
    });
});

check('l\'ordine confermato non lo tocca nessuno', function () {
    return prova(static function (): bool {
        $giorni = (int) (Wonder\Plugin\Gestionale\Models\System\Setting::current()['order_payment_wait_days'] ?? 7);
        [$ordine] = ordineInAttesa(
            date('Y-m-d H:i:s', strtotime('-'.($giorni + 1).' days')),
            date('Y-m-d H:i:s', strtotime('-1 hour'))
        );

        Mailer::useTransport(static fn (): bool => true);

        try {
            Lifecycle::confirm($ordine, ['payment' => false]);
            $esito = Expiry::run();
        } finally {
            Mailer::useTransport(null);
        }

        return $esito['cancelled'] === 0 && Order::findById($ordine)['status'] === 'confirmed';
    });
});

check('l\'ordine già annullato non si annulla di nuovo e non riscrive email', function () {
    return prova(static function (): bool {
        $giorni = (int) (Wonder\Plugin\Gestionale\Models\System\Setting::current()['order_payment_wait_days'] ?? 7);
        [$ordine] = ordineInAttesa(
            date('Y-m-d H:i:s', strtotime('-'.($giorni + 1).' days')),
            date('Y-m-d H:i:s', strtotime('-1 hour'))
        );

        conPosta(static fn (): array => Expiry::run());
        [$secondo, $partite] = conPosta(static fn (): array => Expiry::run());

        return $secondo['cancelled'] === 0
            && $partite === []
            && (string) Order::findById($ordine)['status'] === 'cancelled';
    });
});

/**
 * Un'estensione che, quando il primo pagamento aperto decade, fa risultare
 * incassato il secondo: annullare quell'ordine cade su «già pagato».
 */
final class IncassaIlSecondo extends Wonder\Plugin\Gestionale\Extensions\GestionaleExtension
{
    public static int $secondo = 0;

    public function onStatusChanged(string $entity, int $entityId, string $field, string $from, string $to): void
    {
        if ($entity === Wonder\Plugin\Gestionale\Models\Payments\Payment::$table && $to === 'failed' && self::$secondo > 0) {
            Wonder\Plugin\Gestionale\Models\Payments\Payment::update(['status' => 'paid'], self::$secondo);
        }
    }
}

check('un ordine che non si riesce ad annullare non ferma gli altri', function () {
    return prova(static function (): bool {
        $giorni = (int) (Wonder\Plugin\Gestionale\Models\System\Setting::current()['order_payment_wait_days'] ?? 7);
        $scaduto = date('Y-m-d H:i:s', strtotime('-'.($giorni + 1).' days'));

        [$guasto] = ordineInAttesa($scaduto, date('Y-m-d H:i:s', strtotime('-1 hour')));
        $primo = Wonder\Plugin\Gestionale\Support\Payments\Ledger::open(['order_id' => $guasto, 'amount' => 20.0]);
        $secondo = Wonder\Plugin\Gestionale\Support\Payments\Ledger::open(['order_id' => $guasto, 'amount' => 20.0]);
        IncassaIlSecondo::$secondo = $secondo['payment_id'];
        [$buono] = ordineInAttesa($scaduto, date('Y-m-d H:i:s', strtotime('-1 hour')));

        Wonder\Plugin\Gestionale\Extensions\Extensions::use([IncassaIlSecondo::class]);

        try {
            [$esito] = conPosta(static fn (): array => Expiry::run());
        } finally {
            Wonder\Plugin\Gestionale\Extensions\Extensions::use(null);
            IncassaIlSecondo::$secondo = 0;
        }

        return $primo['payment_id'] > 0
            && in_array($buono, $esito['orders'], true)
            && (string) Order::findById($buono)['status'] === 'cancelled'
            && !in_array($guasto, $esito['orders'], true)
            && (string) Order::findById($guasto)['status'] === 'pending';
    });
});

summary();
