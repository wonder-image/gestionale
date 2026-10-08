<?php
/** php tests/integrazione/OrderBackendTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/layout.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderActionResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderNoteResource;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderPaymentResource;
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

/** Un coupon qualunque, perché gli utilizzi hanno la chiave esterna. */
function couponDiProva(): int
{
    return (int) (Coupon::create([
        'code' => 'X'.strtoupper(substr(uniqid(), -8)), 'name' => 'Prova', 'discount_type' => 'percent', 'discount_value' => '10.00',
        'applies_to_all' => 'true', 'applies_online' => 'true', 'active' => 'true',
    ])->insert_id ?? 0);
}

/** Tutto l'HTML della scheda, per cercarci dentro. */
function schedaHtml(int $ordine): string
{
    return layoutHtml(OrderResource::showLayoutSchema((array) Order::findById($ordine)));
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

check('la scheda mostra il coupon con il codice escapato e quanto ha fatto risparmiare; senza coupon la riga non c\'è', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(50.0);
        $senza = schedaHtml($ordine);

        $coupon = couponDiProva();
        Order::update(['coupon_id' => $coupon, 'coupon_code' => '<b>ESTATE</b>', 'discount_total' => '5.00'], $ordine);
        CouponRedemption::create([
            'coupon_id' => $coupon, 'order_id' => $ordine, 'customer_id' => 0, 'email' => 'a@example.com',
            'discount_amount' => '5.00', 'redeemed_at' => date('Y-m-d H:i:s'),
        ]);
        $con = schedaHtml($ordine);

        return !str_contains($senza, 'Coupon')
            && str_contains($con, 'Coupon') && str_contains($con, '&lt;b&gt;ESTATE&lt;/b&gt;')
            && !str_contains($con, '<b>ESTATE</b>') && str_contains($con, '5,00 €');
    });
});

check('la riga del coupon c\'è anche a funzionalità spenta: è storia', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(50.0);
        $coupon = couponDiProva();
        Order::update(['coupon_id' => $coupon, 'coupon_code' => 'ESTATE'], $ordine);
        CouponRedemption::create([
            'coupon_id' => $coupon, 'order_id' => $ordine, 'customer_id' => 0, 'email' => 'a@example.com',
            'discount_amount' => '4.00', 'redeemed_at' => date('Y-m-d H:i:s'),
        ]);
        spegniFunzionalita(['coupons']);
        $html = schedaHtml($ordine);

        return str_contains($html, 'ESTATE') && str_contains($html, '4,00 €');
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

check('le righe della scheda sono una vera tabella con le colonne dell\'ordine; senza righe c\'è la frase', function () {
    return prova(static function (): bool {
        // La tabella esiste solo con la funzionalità «orders» accesa: nel database
        // di sviluppo può essere spenta, quindi la si accende qui (la transazione la rimette).
        sqlModify(Wonder\Plugin\Gestionale\Models\System\Feature::$table, ['enabled' => 'true'], 'feature_key', 'orders');
        Wonder\Plugin\Gestionale\Gestionale::reset();

        $ordine = ordineDiProva(5.0);
        $prima = Wonder\Plugin\Gestionale\Resources\Sales\OrderItemTableResource::embed($ordine);
        OrderItem::create([
            'order_id' => $ordine, 'type' => 'custom', 'position' => 1, 'name' => 'Maglia', 'sku' => 'MG-BLU',
            'quantity' => '2.000', 'unit_price' => '2.50', 'line_total' => '5.00',
        ]);
        $dopo = Wonder\Plugin\Gestionale\Resources\Sales\OrderItemTableResource::embed($ordine);

        // Le celle si disegnano nel primo draw della tabella, che legge da un'altra connessione:
        // dentro la transazione di prova non vede la riga. Il disegno delle celle è provato dai test unitari.
        $esito = !str_contains($prima, '<table') && str_contains($prima, 'Nessuna riga')
            && str_contains($dopo, '<table') && str_contains($dopo, 'gst_order_items__table')
            && str_contains($dopo, '"title":"Foto"') && str_contains($dopo, '"title":"Articolo"');

        // La cache delle funzionalità non deve portarsi dietro lo stato acceso per gli altri check.
        Wonder\Plugin\Gestionale\Gestionale::reset();

        return $esito;
    });
});

check('senza righe l\'accordion dice che non ce ne sono, senza tabella', fn () =>
    prova(static function (): bool {
        $ordine = ordineDiProva(5.0);
        $html = Wonder\Plugin\Gestionale\Resources\Sales\OrderHistoryTableResource::embed($ordine + 100000);

        return str_contains($html, 'Nessun') && !str_contains($html, '<table');
    })
);

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

check('le note si salvano, e quella del cliente non si tocca', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(10.0);
        Order::update(['customer_note' => 'Citofono rotto', 'internal_note' => 'vecchia'], $ordine);
        $esito = OrderNoteResource::run($ordine, ['internal_note' => "  Chiamare Rossi\r\n ", 'document_note' => 'Pagamento a 30 giorni', 'customer_note' => 'cambiata']);
        $dopo = (array) Order::findById($ordine);

        return $esito['ok'] === true
            && $dopo['internal_note'] === 'Chiamare Rossi'
            && $dopo['document_note'] === 'Pagamento a 30 giorni'
            && $dopo['customer_note'] === 'Citofono rotto';
    });
});

check('una nota assente dai valori resta com\'è, e una nota vuota la svuota', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(10.0);
        Order::update(['internal_note' => 'resta', 'document_note' => 'va via'], $ordine);
        OrderNoteResource::run($ordine, ['document_note' => '']);
        $dopo = (array) Order::findById($ordine);

        return $dopo['internal_note'] === 'resta' && (string) ($dopo['document_note'] ?? '') === '';
    });
});

check('una nota troppo lunga si rifiuta con una frase e non cambia niente', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(10.0);
        Order::update(['internal_note' => 'prima'], $ordine);
        $esito = OrderNoteResource::run($ordine, ['internal_note' => str_repeat('a', OrderNoteResource::MAX + 1)]);

        return $esito['ok'] === false && str_contains($esito['message'], 'troppo lunghe')
            && ((array) Order::findById($ordine))['internal_note'] === 'prima';
    });
});

check('le note di un carrello o di un ordine che non c\'è non si scrivono', function () {
    return prova(static function (): bool {
        $carrello = Order::create(['stage' => 'cart', 'status' => 'draft', 'payment_status' => 'unpaid', 'total' => '5.00']);
        $id = (int) ($carrello->insert_id ?? 0);

        return OrderNoteResource::run($id, ['internal_note' => 'x'])['ok'] === false
            && OrderNoteResource::run(0, ['internal_note' => 'x'])['ok'] === false
            && ((array) Order::findById($id))['internal_note'] !== 'x';
    });
});

check('un ordine evaso e pagato ha le note bloccate: niente matita, niente finestra, il salvataggio è rifiutato', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(5.0);
        Order::update(['internal_note' => 'prima', 'payment_status' => 'paid'], $ordine);
        $aperto = schedaHtml($ordine);
        $finestraAperta = OrderNoteResource::modal((array) Order::findById($ordine), '');

        Order::update(['fulfillment_status' => 'fulfilled'], $ordine);
        $chiuso = schedaHtml($ordine);
        $finestraChiusa = OrderNoteResource::modal((array) Order::findById($ordine), '');
        $esito = OrderNoteResource::run($ordine, ['internal_note' => 'dopo']);

        return str_contains($aperto, 'bi-pencil') && !str_contains($aperto, 'bi-lock') && $finestraAperta !== ''
            && str_contains($chiuso, 'bi-lock') && !str_contains($chiuso, 'bi-pencil') && $finestraChiusa === ''
            && $esito['ok'] === false && str_contains($esito['message'], 'evaso e pagato')
            && ((array) Order::findById($ordine))['internal_note'] === 'prima';
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
        // «Segna evaso» a mano vale con le spedizioni spente: accese, si evade spedendo.
        spegniFunzionalita(['shipping']);
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

/** Le righe di denaro dell'ordine, per contarle. */
function righeDenaro(int $ordine): int
{
    return (int) sqlCount(Payment::$table, "order_id = {$ordine} AND deleted = 'false'");
}

/** La prima riga di denaro dell'ordine, qualunque forma abbia il risultato di `find`. */
function primaRigaDenaro(int $ordine): array
{
    $righe = Payment::find(['order_id' => $ordine, 'deleted' => 'false']);
    $righe = isset($righe['id']) ? [$righe] : array_values((array) $righe);

    return (array) ($righe[0] ?? []);
}

check('Registra pagamento: l\'importo intero porta l\'ordine a pagato', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(40.0);

        $esito = OrderPaymentResource::run($ordine, ['amount' => '40,00'], 7);

        return $esito['ok'] === true
            && (string) Order::findById($ordine)['payment_status'] === 'paid'
            && righeDenaro($ordine) === 1;
    });
});

check('Registra pagamento: metà è pagato in parte, l\'altra metà salda', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(40.0);

        OrderPaymentResource::run($ordine, ['amount' => '12,50'], 7);
        $meta = (string) Order::findById($ordine)['payment_status'];
        $riga = primaRigaDenaro($ordine);
        $resto = OrderPaymentResource::run($ordine, ['amount' => '27,50'], 7);

        return $meta === 'partially_paid'
            && (string) $riga['amount'] === '12.50'
            && $resto['ok'] === true
            && (string) Order::findById($ordine)['payment_status'] === 'paid';
    });
});

check('Registra pagamento sull\'ordine già evaso lo chiude', function () {
    return prova(static function (): bool {
        [$ordine] = ordineConRiga();
        spegniFunzionalita(['shipping']);
        senzaPosta(static fn (): array => OrderActionResource::run('confirm', $ordine, 7));
        senzaPosta(static fn (): array => OrderActionResource::run('fulfill', $ordine, 7));

        OrderPaymentResource::run($ordine, ['amount' => '40,00'], 7);

        return (string) Order::findById($ordine)['status'] === 'completed';
    });
});

check('Registra pagamento: zero, negativo e testo non scrivono niente', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(40.0);

        foreach (['0', '-5', 'abc', ''] as $importo) {
            $esito = OrderPaymentResource::run($ordine, ['amount' => $importo], 7);

            if ($esito['ok'] !== false || $esito['message'] === '') {
                return false;
            }
        }

        return righeDenaro($ordine) === 0;
    });
});

check('Registra pagamento: oltre il residuo è rifiutato e dice il residuo', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(40.0);
        OrderPaymentResource::run($ordine, ['amount' => '30'], 7);

        $esito = OrderPaymentResource::run($ordine, ['amount' => '10,01'], 7);

        return $esito['ok'] === false
            && str_contains($esito['message'], '10,00')
            && righeDenaro($ordine) === 1;
    });
});

check('Registra pagamento su un ordine annullato o già saldato è rifiutato', function () {
    return prova(static function (): bool {
        $annullato = ordineDiProva(40.0);
        Order::update(['status' => 'cancelled'], $annullato);
        $saldato = ordineDiProva(40.0);
        OrderPaymentResource::run($saldato, ['amount' => '40'], 7);

        $a = OrderPaymentResource::run($annullato, ['amount' => '10'], 7);
        $b = OrderPaymentResource::run($saldato, ['amount' => '1'], 7);

        return $a['ok'] === false && $b['ok'] === false
            && righeDenaro($annullato) === 0 && righeDenaro($saldato) === 1;
    });
});

check('Registra pagamento: la data si scrive, il futuro no, il formato italiano va bene', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(40.0);
        $tre = strtotime('-3 days');

        $futuro = OrderPaymentResource::run($ordine, ['amount' => '5', 'paid_at' => date('d/m/Y', strtotime('+3 days'))], 7);
        $vera = OrderPaymentResource::run($ordine, ['amount' => '5', 'paid_at' => date('d/m/Y', $tre), 'reference' => 'CRO123'], 7);
        $riga = primaRigaDenaro($ordine);

        return $futuro['ok'] === false
            && $vera['ok'] === true
            && str_starts_with((string) $riga['paid_at'], date('Y-m-d', $tre))
            && (string) $riga['provider_reference'] === 'CRO123';
    });
});

check('Registra pagamento lascia scritto chi l\'ha registrato', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(40.0);

        OrderPaymentResource::run($ordine, ['amount' => '40'], 7);
        $riga = primaRigaDenaro($ordine);

        return (int) sqlCount(
            Wonder\Plugin\Gestionale\Models\Payments\PaymentStatusLog::$table,
            "payment_id = ".(int) $riga['id']." AND to_value = 'paid' AND source = 'user' AND user_id = 7 AND deleted = 'false'"
        ) === 1;
    });
});

check('Registra pagamento: un carrello o un id che non c\'è non sollevano', function () {
    return prova(static function (): bool {
        $carrello = Order::create(['stage' => 'cart', 'status' => 'draft', 'payment_status' => 'unpaid', 'total' => '5.00']);
        $a = OrderPaymentResource::run((int) ($carrello->insert_id ?? 0), ['amount' => '5'], 7);
        $b = OrderPaymentResource::run(999999999, ['amount' => '5'], 7);

        return $a['ok'] === false && $b['ok'] === false;
    });
});

summary();
