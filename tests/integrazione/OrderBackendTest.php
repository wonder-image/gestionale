<?php
/** php tests/integrazione/OrderBackendTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderActionResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
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

check('l\'elenco prende l\'ordine e lascia il carrello', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(10.0);
        $carrello = Order::create(['stage' => 'cart', 'status' => 'draft', 'payment_status' => 'unpaid', 'total' => '5.00']);
        $carrelloId = (int) ($carrello->insert_id ?? 0);
        $condizione = (string) OrderResource::querySchema()['condition'];

        return (int) sqlCount(Order::$table, "({$condizione}) AND id = {$ordine}") === 1
            && (int) sqlCount(Order::$table, "({$condizione}) AND id = {$carrelloId}") === 0;
    });
});

check('un ordine senza nomi si riconosce dall\'email', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(10.0);
        Order::update(['email' => 'solo.email@example.com'], $ordine);

        return OrderResource::customerName((array) Order::findById($ordine)) === 'solo.email@example.com';
    });
});

/** Tutto l'HTML della scheda, per cercarci dentro. */
function schedaHtml(int $ordine): string
{
    $layout = OrderResource::showLayoutSchema((array) Order::findById($ordine));
    $html = '';

    foreach ($layout->components as $card) {
        foreach ($card->components as $c) {
            $html .= (string) (new ReflectionProperty($c, 'text'))->getValue($c).' ';
        }
    }

    return $html;
}

check('la scheda mostra numero, cliente, totale e riepilogo IVA; il nome è escapato', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(122.0);
        Order::update([
            'order_number' => '2025/777', 'billing_name' => '<b>x</b>', 'billing_surname' => 'Rossi',
            'taxable_total' => '100.00', 'tax_total' => '22.00', 'products_total' => '100.00',
        ], $ordine);
        OrderTaxSummary::create(['order_id' => $ordine, 'rate' => '22.00', 'taxable' => '100.00', 'tax' => '22.00', 'total' => '122.00']);
        $html = schedaHtml($ordine);

        return str_contains($html, '2025/777') && str_contains($html, '122,00 €')
            && str_contains($html, '22%') && str_contains($html, '&lt;b&gt;x&lt;/b&gt;')
            && !str_contains($html, '<b>x</b>');
    });
});

check('un ordine con la sola spedizione si disegna senza avvisi', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(5.0);
        OrderItem::create([
            'order_id' => $ordine, 'type' => 'shipping', 'position' => 1, 'name' => 'Spedizione',
            'quantity' => '1.000', 'unit_price' => '5.00', 'line_total' => '5.00',
        ]);
        $avvisi = [];
        set_error_handler(static function (int $n, string $m) use (&$avvisi): bool {
            $avvisi[] = $m;

            return true;
        });

        try {
            $html = schedaHtml($ordine);
        } finally {
            restore_error_handler();
        }

        return $avvisi === [] && str_contains($html, 'Spedizione');
    });
});

check('una riga cancellata non compare nella scheda', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(5.0);
        $riga = OrderItem::create([
            'order_id' => $ordine, 'type' => 'custom', 'position' => 1, 'name' => 'Riga da dimenticare',
            'quantity' => '1.000', 'unit_price' => '5.00', 'line_total' => '5.00',
        ]);
        OrderItem::query()->Update(OrderItem::$table, ['deleted' => 'true'], 'id', (int) ($riga->insert_id ?? 0));

        return !str_contains(schedaHtml($ordine), 'Riga da dimenticare');
    });
});

/** Esegue il corpo con la posta chiusa: le azioni scrivono al cliente. */
function senzaPosta(callable $corpo): mixed
{
    Mailer::useTransport(static fn (): bool => true);

    try {
        return $corpo();
    } finally {
        Mailer::useTransport(null);
    }
}

/**
 * Un ordine in attesa con due pezzi prenotati.
 *
 * @return array{0: int, 1: int}
 */
function ordineConRiga(): array
{
    $prodotto = articoloConGiacenza(5, 'TST-AZ-'.substr((string) microtime(true), -6));
    $ordine = ordineDiProva(40.0);
    Order::update(['email' => 'cliente@example.com'], $ordine);
    $riga = OrderItem::create([
        'order_id' => $ordine, 'type' => 'product', 'product_id' => $prodotto, 'position' => 1,
        'name' => 'Crema', 'quantity' => '2.000', 'unit_price' => '20.00', 'line_total' => '40.00',
    ]);
    Allocation::reserve([
        'product_id' => $prodotto, 'quantity' => 2, 'order_id' => $ordine,
        'order_item_id' => (int) ($riga->insert_id ?? 0), 'expires_at' => '',
    ]);

    return [$ordine, $prodotto];
}

check('Conferma: l\'ordine si conferma e la merce esce', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordineConRiga();

        $esito = senzaPosta(static fn (): array => OrderActionResource::run('confirm', $ordine, 7));

        return $esito['ok'] === true
            && (string) Order::findById($ordine)['status'] === 'confirmed'
            && Levels::of($prodotto)['quantity'] === 3.0
            && str_contains($esito['message'], 'confermato');
    });
});

check('Conferma dal backend non inventa un incasso', function () {
    return prova(static function (): bool {
        [$ordine] = ordineConRiga();

        senzaPosta(static fn (): array => OrderActionResource::run('confirm', $ordine, 7));

        return (string) Order::findById($ordine)['payment_status'] === 'unpaid'
            && (int) sqlCount(Wonder\Plugin\Gestionale\Models\Payments\Payment::$table, "order_id = {$ordine} AND status = 'paid' AND deleted = 'false'") === 0;
    });
});

check('Conferma due volte: la seconda lo dice e non scarica ancora', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordineConRiga();

        senzaPosta(static fn (): array => OrderActionResource::run('confirm', $ordine, 7));
        $secondo = senzaPosta(static fn (): array => OrderActionResource::run('confirm', $ordine, 7));

        return $secondo['ok'] === false
            && str_contains($secondo['message'], 'già')
            && Levels::of($prodotto)['quantity'] === 3.0;
    });
});

check('Annulla: la merce prenotata torna libera', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordineConRiga();
        $prima = Levels::of($prodotto)['available'];

        $esito = senzaPosta(static fn (): array => OrderActionResource::run('cancel', $ordine, 7));

        return $esito['ok'] === true
            && (string) Order::findById($ordine)['status'] === 'cancelled'
            && Levels::of($prodotto)['available'] === $prima + 2.0;
    });
});

check('Annulla un ordine già annullato: una frase, niente scritto', function () {
    return prova(static function (): bool {
        [$ordine] = ordineConRiga();
        senzaPosta(static fn (): array => OrderActionResource::run('cancel', $ordine, 7));
        $righe = (int) sqlCount(OrderStatusLog::$table, "order_id = {$ordine} AND deleted = 'false'");

        $secondo = senzaPosta(static fn (): array => OrderActionResource::run('cancel', $ordine, 7));

        return $secondo['ok'] === false
            && str_contains($secondo['message'], 'già')
            && (int) sqlCount(OrderStatusLog::$table, "order_id = {$ordine} AND deleted = 'false'") === $righe;
    });
});

check('Segna evaso su un ordine non confermato: il rifiuto è una frase', function () {
    return prova(static function (): bool {
        [$ordine] = ordineConRiga();

        $esito = senzaPosta(static fn (): array => OrderActionResource::run('fulfill', $ordine, 7));

        return $esito['ok'] === false
            && $esito['message'] !== ''
            && (string) Order::findById($ordine)['status'] === 'pending'
            && (string) Order::findById($ordine)['fulfillment_status'] === 'unfulfilled';
    });
});

check('Segna evaso su un ordine confermato lo porta a evaso', function () {
    return prova(static function (): bool {
        [$ordine] = ordineConRiga();
        senzaPosta(static fn (): array => OrderActionResource::run('confirm', $ordine, 7));

        $esito = senzaPosta(static fn (): array => OrderActionResource::run('fulfill', $ordine, 7));

        return $esito['ok'] === true
            && (string) Order::findById($ordine)['fulfillment_status'] === 'fulfilled';
    });
});

check('l\'azione del backend lascia scritto chi l\'ha fatta', function () {
    return prova(static function (): bool {
        [$ordine] = ordineConRiga();

        senzaPosta(static fn (): array => OrderActionResource::run('confirm', $ordine, 7));

        return (int) sqlCount(
            OrderStatusLog::$table,
            "order_id = {$ordine} AND field = 'status' AND to_value = 'confirmed' AND source = 'user' AND user_id = 7 AND deleted = 'false'"
        ) === 1;
    });
});

check('un carrello, un id che non c\'è e un\'azione sconosciuta non sollevano', function () {
    return prova(static function (): bool {
        $carrello = Order::create(['stage' => 'cart', 'status' => 'draft', 'payment_status' => 'unpaid', 'total' => '5.00']);
        $carrelloId = (int) ($carrello->insert_id ?? 0);
        [$ordine] = ordineConRiga();

        $a = OrderActionResource::run('confirm', $carrelloId, 7);
        $b = OrderActionResource::run('confirm', 999999999, 7);
        $c = OrderActionResource::run('rimborsa', $ordine, 7);

        return $a['ok'] === false && $b['ok'] === false && $c['ok'] === false
            && $a['message'] !== '' && $b['message'] !== '' && $c['message'] !== ''
            && (string) Order::findById($carrelloId)['status'] === 'draft'
            && (string) Order::findById($ordine)['status'] === 'pending';
    });
});

summary();
