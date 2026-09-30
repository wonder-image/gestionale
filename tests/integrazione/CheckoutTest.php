<?php
/** php tests/integrazione/CheckoutTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Plugin\Gestionale\Support\Orders\PaymentTiming;
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

/** Un metodo di pagamento di prova, col modo e la commissione che servono. */
function metodoDiProva(string $timing, string $feeType = 'none', float $feeValue = 0): int
{
    $metodo = PaymentMethod::create([
        'code' => 'tst_'.uniqid(),
        'name' => 'Prova '.$timing,
        'provider' => $timing === PaymentTiming::IMMEDIATE ? 'stripe' : 'manual',
        'timing' => $timing,
        'fee_type' => $feeType,
        'fee_value' => number_format($feeValue, 2, '.', ''),
        'available_for' => 'all',
        'active' => 'true',
        'position' => 1,
        'instructions' => 'Istruzioni di prova',
    ]);

    return (int) ($metodo->insert_id ?? 0);
}

/** Un carrello con dentro un articolo da 20 €. */
function carrelloPronto(float $giacenza = 10, float $quantita = 2): array
{
    $prodotto = articoloConGiacenza($giacenza, 'TST-CHK-'.substr((string) microtime(true), -6));
    Wonder\Plugin\Gestionale\Models\Catalog\Product::update(['price' => '20.00'], $prodotto);

    $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
    Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => $quantita]);

    return [$carrello, $prodotto];
}

/** @return array<string, mixed> */
function datiCheckout(int $metodo): array
{
    return [
        'email' => 'cliente@example.com',
        'payment_method_id' => $metodo,
        'fulfillment_type' => 'shipping',
        'billing' => [
            'country' => 'IT',
            'province' => 'MI',
            'city' => 'Milano',
            'cap' => '20100',
            'street' => 'Via Prova',
            'number' => '1',
            'name' => 'Mario',
            'surname' => 'Rossi',
        ],
    ];
}

function senzaPosta(callable $corpo): mixed
{
    Mailer::useTransport(static fn (): bool => true);

    try {
        return $corpo();
    } finally {
        Mailer::useTransport(null);
    }
}

check('il carrello diventa un ordine numerato, con la merce impegnata', function () {
    return prova(static function (): bool {
        [$carrello, $prodotto] = carrelloPronto();
        $prima = Levels::of($prodotto);

        $esito = senzaPosta(static fn (): array => Checkout::place(
            $carrello,
            datiCheckout(metodoDiProva(PaymentTiming::IMMEDIATE))
        ));

        $ordine = Order::findById($esito['order_id']);
        $dopo = Levels::of($prodotto);

        return $esito['order_id'] === $carrello
            && preg_match('#^\d{4}/\d{2}\d{4}$#', $esito['order_number']) === 1
            && $ordine['stage'] === 'order'
            && $ordine['status'] === 'pending'
            && trim((string) $ordine['ordered_at']) !== ''
            && $ordine['billing_city'] === 'Milano'
            && $esito['reserved'] === 1
            && $dopo['quantity'] === $prima['quantity']
            && $dopo['available'] === round($prima['available'] - 2, 3);
    });
});

check('il pagamento nasce in attesa per l\'importo dell\'ordine', function () {
    return prova(static function (): bool {
        [$carrello] = carrelloPronto();
        $esito = senzaPosta(static fn (): array => Checkout::place(
            $carrello,
            datiCheckout(metodoDiProva(PaymentTiming::IMMEDIATE))
        ));

        $pagamento = Payment::findById($esito['payment_id']);
        $ordine = Order::findById($esito['order_id']);

        return $pagamento['status'] === 'pending'
            && (string) $pagamento['amount'] === (string) $ordine['total']
            && $ordine['payment_status'] === 'pending';
    });
});

check('i riepiloghi IVA restano scritti sull\'ordine', function () {
    return prova(static function (): bool {
        [$carrello] = carrelloPronto();
        $esito = senzaPosta(static fn (): array => Checkout::place(
            $carrello,
            datiCheckout(metodoDiProva(PaymentTiming::IMMEDIATE))
        ));

        return (int) sqlCount(OrderTaxSummary::$table, 'order_id = '.$esito['order_id']) > 0;
    });
});

check('la commissione del metodo diventa una riga', function () {
    return prova(static function (): bool {
        [$carrello] = carrelloPronto();
        $esito = senzaPosta(static fn (): array => Checkout::place(
            $carrello,
            datiCheckout(metodoDiProva(PaymentTiming::DEFERRED, 'amount', 2.50))
        ));

        $contenuto = Cart::contents($esito['order_id']);
        $commissioni = array_values(array_filter(
            $contenuto['items'],
            static fn (array $riga): bool => (string) $riga['type'] === 'fee'
        ));

        return count($commissioni) === 1
            && (string) $commissioni[0]['line_total'] === '2.50'
            && (string) $contenuto['order']['fees_total'] === '2.50';
    });
});

check('la carta impegna per mezz\'ora, il bonifico per giorni, il contrassegno non scade', function () {
    return prova(static function (): bool {
        $scadenze = [];

        foreach ([PaymentTiming::IMMEDIATE, PaymentTiming::DEFERRED, PaymentTiming::ON_DELIVERY] as $modo) {
            [$carrello] = carrelloPronto();
            $esito = senzaPosta(static fn (): array => Checkout::place(
                $carrello,
                datiCheckout(metodoDiProva($modo))
            ));
            $riga = StockReservation::find(['order_id' => $esito['order_id'], 'deleted' => 'false'], 1);
            $scadenze[$modo] = trim((string) ($riga['expires_at'] ?? ''));
        }

        $subito = strtotime($scadenze[PaymentTiming::IMMEDIATE]) - time();
        $dopo = strtotime($scadenze[PaymentTiming::DEFERRED]) - time();

        return $subito > 0 && $subito <= 1800
            && $dopo > 86400
            && ($scadenze[PaymentTiming::ON_DELIVERY] === ''
                || str_starts_with($scadenze[PaymentTiming::ON_DELIVERY], '0000-00-00'));
    });
});

check('col contrassegno l\'ordine è già confermato e la merce è uscita', function () {
    return prova(static function (): bool {
        [$carrello, $prodotto] = carrelloPronto();
        $prima = Levels::of($prodotto)['quantity'];

        $esito = senzaPosta(static fn (): array => Checkout::place(
            $carrello,
            datiCheckout(metodoDiProva(PaymentTiming::ON_DELIVERY))
        ));

        return $esito['status'] === 'confirmed'
            && Levels::of($prodotto)['quantity'] === round($prima - 2, 3);
    });
});

check('il carrello vuoto non diventa un ordine', function () {
    return prova(static function (): bool {
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];

        try {
            senzaPosta(static fn (): array => Checkout::place(
                $carrello,
                datiCheckout(metodoDiProva(PaymentTiming::IMMEDIATE))
            ));
        } catch (UserError) {
            $riga = Order::findById($carrello);

            return $riga['stage'] === 'cart' && trim((string) $riga['order_number']) === '';
        }

        return false;
    });
});

check('un metodo di pagamento spento ferma tutto, e il carrello resta intero', function () {
    return prova(static function (): bool {
        [$carrello, $prodotto] = carrelloPronto();
        $metodo = metodoDiProva(PaymentTiming::IMMEDIATE);
        PaymentMethod::update(['active' => 'false'], $metodo);
        $prima = Levels::of($prodotto)['available'];

        try {
            senzaPosta(static fn (): array => Checkout::place($carrello, datiCheckout($metodo)));
        } catch (UserError) {
            $riga = Order::findById($carrello);

            return $riga['stage'] === 'cart'
                && Levels::of($prodotto)['available'] === $prima
                && (int) sqlCount(StockReservation::$table, "order_id = {$carrello} AND deleted = 'false'") === 0;
        }

        return false;
    });
});

check('il metodo che non esiste è rifiutato', function () {
    return prova(static function (): bool {
        [$carrello] = carrelloPronto();

        try {
            senzaPosta(static fn (): array => Checkout::place($carrello, datiCheckout(999999)));
        } catch (UserError) {
            return true;
        }

        return false;
    });
});

check('la merce finita fra il carrello e il checkout ferma l\'ordine', function () {
    return prova(static function (): bool {
        [$carrello, $prodotto] = carrelloPronto(2, 2);

        // Qualcuno l'ha comprata prima: al checkout non c'è più niente.
        $altro = ordineDiProva();
        Wonder\Plugin\Gestionale\Support\Stock\Allocation::reserve([
            'product_id' => $prodotto,
            'quantity' => 2,
            'order_id' => $altro,
        ]);

        try {
            senzaPosta(static fn (): array => Checkout::place(
                $carrello,
                datiCheckout(metodoDiProva(PaymentTiming::IMMEDIATE))
            ));
        } catch (UserError) {
            return (string) Order::findById($carrello)['stage'] === 'cart';
        }

        return false;
    });
});

check('un carrello con la sola riga a quantità zero non diventa un ordine', function () {
    return prova(static function (): bool {
        [$carrello, $prodotto] = carrelloPronto();
        $riga = Wonder\Plugin\Gestionale\Models\Sales\OrderItem::find(['order_id' => $carrello, 'deleted' => 'false'], 1);
        Wonder\Plugin\Gestionale\Models\Sales\OrderItem::update(['quantity' => '0.000'], (int) $riga['id']);
        $prima = Levels::of($prodotto)['available'];

        try {
            senzaPosta(static fn (): array => Checkout::place(
                $carrello,
                datiCheckout(metodoDiProva(PaymentTiming::IMMEDIATE))
            ));
        } catch (UserError) {
            return (string) Order::findById($carrello)['stage'] === 'cart'
                && Levels::of($prodotto)['available'] === $prima;
        }

        return false;
    });
});

summary();
