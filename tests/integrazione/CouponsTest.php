<?php
/** php tests/integrazione/CouponsTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCustomer;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Plugin\Gestionale\Support\Orders\Expiry;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Orders\PaymentTiming;
use Wonder\Plugin\Gestionale\Support\Returns\Returns;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
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

/** Un articolo da 50 € e un carrello con due pezzi (100 €), del cliente dato (0 = ospite). */
function scenario(int $cliente = 0): array
{
    accendiFunzionalita(['orders', 'coupons']);
    $prodotto = articoloConGiacenza(50, 'CP-'.substr(uniqid(), -7));
    Product::update(['price' => '50.00', 'sale_price' => '0.00'], $prodotto);
    $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid(), 'customer_id' => $cliente])['id'];
    Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 2]);

    return ['product' => $prodotto, 'model' => modelloDi($prodotto), 'cart' => $carrello];
}

/** Un coupon del 10 % su tutto il catalogo, valido adesso, con quello che serve in più o in meno. */
function coupon(array $valori = []): array
{
    $codice = 'T'.strtoupper(substr(uniqid(), -8));
    $id = (int) (Coupon::create($valori + [
        'code' => $codice,
        'name' => 'Prova '.$codice,
        'discount_type' => 'percent',
        'discount_value' => '10.00',
        'applies_to_all' => 'true',
        'applies_online' => 'true',
        'active' => 'true',
    ])->insert_id ?? 0);

    return ['id' => $id, 'code' => $valori['code'] ?? $codice];
}

/** Il tipo di errore con cui una funzione si ferma, '' se non si ferma. */
function errore(callable $fai): string
{
    try {
        $fai();
    } catch (UserError $e) {
        return $e->key();
    }

    return '';
}

function riga(int $carrello): array
{
    return Order::findById($carrello);
}

/** Gli usi di un coupon, già consumati. */
function usa(int $coupon, int $cliente, string $email = '', ?string $rilasciato = null): void
{
    CouponRedemption::create([
        'coupon_id' => $coupon,
        'order_id' => ordineDiProva(10.0),
        'customer_id' => $cliente,
        'email' => $email,
        'discount_amount' => '5.00',
        'redeemed_at' => date('Y-m-d H:i:s'),
    ] + ($rilasciato !== null ? ['released_at' => $rilasciato] : []));
}

check('find normalizza il codice e ignora vuoti, inesistenti e cancellati', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'coupons']);
        $c = coupon(['code' => 'SAVE10'.substr(uniqid(), -4)]);
        $codice = $c['code'];
        $cancellato = coupon();
        Coupon::query()->Update(Coupon::$table, ['deleted' => 'true'], 'id', $cancellato['id']);

        return (int) Coupons::find($codice)['id'] === $c['id']
            && (int) Coupons::find(strtolower($codice))['id'] === $c['id']
            && (int) Coupons::find('  '.$codice.' ')['id'] === $c['id']
            && Coupons::find('') === null
            && Coupons::find('   ') === null
            && Coupons::find('NONESISTE-'.uniqid()) === null
            && Coupons::find($cancellato['code']) === null;
    });
});

check('apply scrive il coupon sul carrello e evaluate dice quanto toglie', function () {
    return prova(static function (): bool {
        $s = scenario();
        $c = coupon();
        Coupons::apply($s['cart'], strtolower($c['code']));
        $carrello = riga($s['cart']);
        $valutato = Coupons::evaluate($s['cart'], Coupons::find($c['code']), date('Y-m-d H:i:s'));

        return (int) $carrello['coupon_id'] === $c['id']
            && $carrello['coupon_code'] === $c['code']
            && $valutato['ok'] === true
            && abs($valutato['amount'] - 10.0) < 0.001;
    });
});

check('importo fisso: evaluate toglie l\'importo, mai più della merce', function () {
    return prova(static function (): bool {
        $s = scenario();
        $quindici = coupon(['discount_type' => 'amount', 'discount_value' => '15.00']);
        $enorme = coupon(['discount_type' => 'amount', 'discount_value' => '500.00']);
        $ora = date('Y-m-d H:i:s');

        return abs(Coupons::evaluate($s['cart'], Coupons::find($quindici['code']), $ora)['amount'] - 15.0) < 0.001
            && abs(Coupons::evaluate($s['cart'], Coupons::find($enorme['code']), $ora)['amount'] - 100.0) < 0.001;
    });
});

check('spedizione gratuita si accetta con e senza riga di spedizione', function () {
    return prova(static function (): bool {
        $s = scenario();
        $c = coupon(['discount_type' => 'free_shipping', 'discount_value' => '0.00']);
        $senza = Coupons::apply($s['cart'], $c['code']);

        return (int) riga($s['cart'])['coupon_id'] === $c['id'] && is_array($senza);
    });
});

check('ogni rifiuto raggiungibile da un carrello vero ha il suo motivo', function () {
    return prova(static function (): bool {
        $s = scenario();
        $ieri = date('Y-m-d H:i:s', time() - 86400);
        $domani = date('Y-m-d H:i:s', time() + 86400);
        $casi = [
            'coupon.unknown' => 'NONESISTE-'.uniqid(),
            'coupon.inactive' => coupon(['active' => 'false'])['code'],
            'coupon.channel' => coupon(['applies_online' => 'false'])['code'],
            'coupon.expired' => coupon(['ends_at' => $ieri])['code'],
            'coupon.not_started' => coupon(['starts_at' => $domani])['code'],
            'coupon.min_order' => coupon(['min_order_amount' => '500.00'])['code'],
            'coupon.no_eligible_products' => coupon(['applies_to_all' => 'false'])['code'],
        ];

        foreach ($casi as $chiave => $codice) {
            if (errore(static fn () => Coupons::apply($s['cart'], $codice)) !== $chiave) {
                return false;
            }
        }

        // Il rifiuto non lascia niente scritto sul carrello.
        return (int) riga($s['cart'])['coupon_id'] === 0;
    });
});

check('riservato: un ospite o un altro cliente non lo usano, il cliente giusto sì', function () {
    return prova(static function (): bool {
        $ospite = scenario(0);
        $altro = scenario(701);
        $giusto = scenario(702);
        $c = coupon();
        CouponCustomer::create(['coupon_id' => $c['id'], 'customer_id' => 702]);

        return errore(static fn () => Coupons::apply($ospite['cart'], $c['code'])) === 'coupon.not_yours'
            && errore(static fn () => Coupons::apply($altro['cart'], $c['code'])) === 'coupon.not_yours'
            && errore(static fn () => Coupons::apply($giusto['cart'], $c['code'])) === '';
    });
});

check('limiti d\'uso: esaurito, già usato; un uso rilasciato non conta', function () {
    return prova(static function (): bool {
        $s = scenario(710);
        $esaurito = coupon(['usage_limit' => 1]);
        usa($esaurito['id'], 999);
        $gia = coupon(['usage_limit_per_customer' => 1]);
        usa($gia['id'], 710);
        $rilasciato = coupon(['usage_limit' => 1, 'usage_limit_per_customer' => 1]);
        usa($rilasciato['id'], 710, '', date('Y-m-d H:i:s'));

        return errore(static fn () => Coupons::apply($s['cart'], $esaurito['code'])) === 'coupon.exhausted'
            && errore(static fn () => Coupons::apply($s['cart'], $gia['code'])) === 'coupon.already_used'
            && errore(static fn () => Coupons::apply($s['cart'], $rilasciato['code'])) === '';
    });
});

check('l\'ospite è riconosciuto dall\'email per il limite per cliente', function () {
    return prova(static function (): bool {
        $s = scenario(0);
        Order::update(['email' => 'Ospite@Example.com'], $s['cart']);
        $c = coupon(['usage_limit_per_customer' => 1]);
        usa($c['id'], 0, 'ospite@example.com');

        return errore(static fn () => Coupons::apply($s['cart'], $c['code'])) === 'coupon.already_used';
    });
});

check('il limite per cliente vale anche tra ospite e cliente registrato con la stessa email', function () {
    return prova(static function (): bool {
        $s = scenario(720);
        Order::update(['email' => 'Mario@Example.com'], $s['cart']);
        $c = coupon(['usage_limit_per_customer' => 1]);
        usa($c['id'], 0, 'mario@example.com');

        return errore(static fn () => Coupons::apply($s['cart'], $c['code'])) === 'coupon.already_used';
    });
});

check('solo il primo ordine vale anche tra ospite e cliente registrato con la stessa email', function () {
    return prova(static function (): bool {
        $s = scenario(720);
        Order::update(['email' => 'mario@example.com'], $s['cart']);
        $c = coupon(['first_order_only' => 'true']);
        $ospite = ordineDiProva(10.0);
        Order::update(['customer_id' => 0, 'email' => 'Mario@Example.com'], $ospite);

        return errore(static fn () => Coupons::apply($s['cart'], $c['code'])) === 'coupon.not_first_order';
    });
});

check('solo il primo ordine: chi ha già un ordine si rifiuta, un ordine annullato non conta', function () {
    return prova(static function (): bool {
        $s = scenario(720);
        $c = coupon(['first_order_only' => 'true']);
        $annullato = ordineDiProva(10.0);
        Order::update(['customer_id' => 720, 'status' => 'cancelled'], $annullato);
        $ok = errore(static fn () => Coupons::apply($s['cart'], $c['code'])) === '';
        Cart::recalculate($s['cart']);
        $vero = ordineDiProva(10.0);
        Order::update(['customer_id' => 720], $vero);

        return $ok && errore(static fn () => Coupons::apply($s['cart'], $c['code'])) === 'coupon.not_first_order';
    });
});

check('uno sconto scritto a mano sulla testata impedisce il coupon', function () {
    return prova(static function (): bool {
        $s = scenario();
        Order::update(['manual_discount_type' => 'percent', 'manual_discount_value' => '5.00'], $s['cart']);
        $c = coupon();

        return errore(static fn () => Coupons::apply($s['cart'], $c['code'])) === 'coupon.has_manual_discount';
    });
});

check('remove toglie il coupon dal carrello', function () {
    return prova(static function (): bool {
        $s = scenario();
        $c = coupon();
        Coupons::apply($s['cart'], $c['code']);
        Coupons::remove($s['cart']);
        $carrello = riga($s['cart']);

        return (int) $carrello['coupon_id'] === 0 && (string) $carrello['coupon_code'] === '';
    });
});

check('con la funzionalità spenta apply rifiuta e context ignora un coupon già scritto', function () {
    return prova(static function (): bool {
        $s = scenario();
        $c = coupon();
        Coupons::apply($s['cart'], $c['code']);
        spegniFunzionalita(['coupons']);
        $righe = [['id' => 1, 'type' => 'product', 'product_id' => $s['product'], 'price_source' => 'base', 'line_total' => '100.00']];

        return errore(static fn () => Coupons::apply($s['cart'], $c['code'])) === 'coupon.inactive'
            && Coupons::context($s['cart'], $righe, date('Y-m-d H:i:s')) === []
            && (int) riga($s['cart'])['coupon_id'] === $c['id'];
    });
});

check('context ridà tipo, valore, righe adatte e importo; senza coupon è vuoto', function () {
    return prova(static function (): bool {
        $s = scenario();
        $ora = date('Y-m-d H:i:s');
        $righe = [
            ['id' => 11, 'type' => 'product', 'product_id' => $s['product'], 'price_source' => 'base', 'line_total' => '100.00'],
            ['id' => 12, 'type' => 'shipping', 'product_id' => 0, 'price_source' => 'base', 'line_total' => '7.00'],
        ];
        $vuoto = Coupons::context($s['cart'], $righe, $ora);

        $c = coupon();
        Order::update(['coupon_id' => $c['id'], 'coupon_code' => $c['code']], $s['cart']);
        $contesto = Coupons::context($s['cart'], $righe, $ora);

        $gratis = coupon(['discount_type' => 'free_shipping', 'discount_value' => '0.00']);
        Order::update(['coupon_id' => $gratis['id'], 'coupon_code' => $gratis['code']], $s['cart']);
        $spedizione = Coupons::context($s['cart'], $righe, $ora);

        $fisso = coupon(['discount_type' => 'amount', 'discount_value' => '500.00']);
        Order::update(['coupon_id' => $fisso['id'], 'coupon_code' => $fisso['code']], $s['cart']);
        $importo = Coupons::context($s['cart'], $righe, $ora);

        return $vuoto === []
            && $contesto['discount_type'] === 'percent'
            && abs((float) $contesto['discount_value'] - 10.0) < 0.001
            && $contesto['free_shipping'] === false
            && $contesto['discountable'] === [11 => true, 12 => false]
            && abs($contesto['amount'] - 10.0) < 0.001
            && $spedizione['discount_type'] === 'none'
            && $spedizione['free_shipping'] === true
            && $spedizione['amount'] === 0.0
            && $importo['discount_type'] === 'amount'
            && abs((float) $importo['discount_value'] - 100.0) < 0.001;
    });
});

check('context toglie dal carrello il coupon che non regge più, con il motivo', function () {
    return prova(static function (): bool {
        $s = scenario();
        $c = coupon(['min_order_amount' => '90.00']);
        Coupons::apply($s['cart'], $c['code']);
        // La spesa scende sotto la soglia (una campagna ha abbassato il prezzo).
        $righe = [['id' => 11, 'type' => 'product', 'product_id' => $s['product'], 'price_source' => 'campaign', 'line_total' => '80.00']];
        $contesto = Coupons::context($s['cart'], $righe, date('Y-m-d H:i:s'));
        $carrello = riga($s['cart']);

        return $contesto === ['dropped' => 'min_order']
            && (int) $carrello['coupon_id'] === 0
            && (string) $carrello['coupon_code'] === '';
    });
});

check('un coupon cancellato dopo essere stato applicato esce con il motivo unknown', function () {
    return prova(static function (): bool {
        $s = scenario();
        $c = coupon();
        Coupons::apply($s['cart'], $c['code']);
        Coupon::query()->Update(Coupon::$table, ['deleted' => 'true'], 'id', $c['id']);
        $righe = [['id' => 11, 'type' => 'product', 'product_id' => $s['product'], 'price_source' => 'base', 'line_total' => '100.00']];

        return Coupons::context($s['cart'], $righe, date('Y-m-d H:i:s')) === ['dropped' => 'unknown']
            && (int) riga($s['cart'])['coupon_id'] === 0;
    });
});

check('ogni motivo di rifiuto ha la sua frase in italiano', function () {
    $frasi = json_decode((string) file_get_contents(__DIR__.'/../../lang/it/gestionale.json'), true)['gestionale']['errors']['coupon'] ?? [];
    $motivi = ['unknown', 'inactive', 'not_started', 'expired', 'min_order', 'exhausted', 'already_used', 'not_first_order', 'not_yours', 'no_eligible_products', 'has_manual_discount', 'channel'];

    foreach ($motivi as $motivo) {
        if (!is_string($frasi[$motivo] ?? null) || trim($frasi[$motivo]) === '') {
            return false;
        }
    }

    return true;
});

/* ------------------------------------------------- utilizzi (Task 5) -- */

/** I dati del checkout di un cliente: cliente 0 = ospite, riconosciuto dall'email. */
function datiCheckout(string $email = 'cliente@example.com', int $cliente = 0): array
{
    $metodo = (int) (PaymentMethod::create([
        'code' => 'tst_'.uniqid(),
        'name' => 'Prova',
        'provider' => 'stripe',
        'timing' => PaymentTiming::IMMEDIATE,
        'fee_type' => 'none',
        'fee_value' => '0.00',
        'available_for' => 'all',
        'active' => 'true',
        'position' => 1,
        'instructions' => 'Istruzioni di prova',
    ])->insert_id ?? 0);

    return [
        'email' => $email,
        'customer_id' => $cliente,
        'payment_method_id' => $metodo,
        'fulfillment_type' => 'shipping',
        'billing' => [
            'country' => 'IT', 'province' => 'MI', 'city' => 'Milano', 'cap' => '20100',
            'street' => 'Via Prova', 'number' => '1', 'name' => 'Mario', 'surname' => 'Rossi',
        ],
    ];
}

/** Fa girare il corpo senza posta vera. */
function senzaPosta(callable $corpo): mixed
{
    Mailer::useTransport(static fn (): bool => true);

    try {
        return $corpo();
    } finally {
        Mailer::useTransport(null);
    }
}

/** Il checkout senza posta vera. */
function ordina(int $carrello, array $dati): array
{
    return senzaPosta(static fn (): array => Checkout::place($carrello, $dati));
}

/** Gli utilizzi di un coupon, tutti. */
function utilizzi(int $coupon): array
{
    $trovati = CouponRedemption::find(['coupon_id' => $coupon]);

    return !is_array($trovati) || $trovati === [] ? [] : (isset($trovati['id']) ? [$trovati] : array_values($trovati));
}

/** Un secondo carrello con lo stesso articolo (2 pezzi, 100 €), del cliente dato. */
function altroCarrello(array $s, int $cliente = 0): int
{
    $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid(), 'customer_id' => $cliente])['id'];
    Cart::add($carrello, ['product_id' => $s['product'], 'quantity' => 2]);

    return $carrello;
}

check('place con coupon scrive l\'ordine, l\'utilizzo e lo sconto uguale', function () {
    return prova(static function (): bool {
        $s = scenario();
        $c = coupon();
        Coupons::apply($s['cart'], $c['code']);

        $esito = ordina($s['cart'], datiCheckout());
        $ordine = riga($esito['order_id']);
        $usi = utilizzi($c['id']);

        return $ordine['stage'] === 'order'
            && (int) $ordine['coupon_id'] === $c['id']
            && (string) $ordine['coupon_code'] === $c['code']
            && (float) $ordine['discount_total'] === 10.0
            && count($usi) === 1
            && (int) $usi[0]['order_id'] === $esito['order_id']
            && (float) $usi[0]['discount_amount'] === 10.0
            && trim((string) $usi[0]['email']) === 'cliente@example.com'
            && trim((string) $usi[0]['redeemed_at']) !== ''
            && (string) ($usi[0]['released_at'] ?? '') === '';
    });
});

check('con la spedizione gratuita l\'utilizzo vale il risparmio sulla spedizione', function () {
    return prova(static function (): bool {
        $s = scenario();
        $c = coupon(['discount_type' => 'free_shipping', 'discount_value' => '0.00']);
        OrderItem::create([
            'order_id' => $s['cart'], 'type' => 'shipping', 'position' => 9, 'name' => 'Corriere',
            'quantity' => '1.000', 'list_price' => '7.00', 'unit_price' => '7.00', 'line_total' => '7.00',
        ]);
        Coupons::apply($s['cart'], $c['code']);

        $esito = ordina($s['cart'], datiCheckout());
        $usi = utilizzi($c['id']);

        return count($usi) === 1 && (float) $usi[0]['discount_amount'] === 7.0
            && (int) riga($esito['order_id'])['coupon_id'] === $c['id'];
    });
});

check('un ordine senza coupon non scrive utilizzi', function () {
    return prova(static function (): bool {
        $s = scenario();
        $c = coupon();
        $esito = ordina($s['cart'], datiCheckout());

        return utilizzi($c['id']) === [] && (int) riga($esito['order_id'])['coupon_id'] === 0;
    });
});

check('usage_limit 1: il secondo ordine è rifiutato, il coupon esce dal carrello e non nasce niente', function () {
    return prova(static function (): bool {
        $s = scenario();
        $c = coupon(['usage_limit' => 1]);
        $altro = altroCarrello($s);
        Coupons::apply($s['cart'], $c['code']);
        Coupons::apply($altro, $c['code']);

        ordina($s['cart'], datiCheckout());
        $prime = (int) sqlCount(StockReservation::$table, "product_id = {$s['product']} AND deleted = 'false'");
        $motivo = errore(static fn () => ordina($altro, datiCheckout('altro@example.com')));
        $carrello = riga($altro);

        return $motivo === 'coupon.exhausted'
            && (string) $carrello['stage'] === 'cart'
            && (int) $carrello['coupon_id'] === 0
            && (float) $carrello['discount_total'] === 0.0
            && count(utilizzi($c['id'])) === 1
            && (int) sqlCount(StockReservation::$table, "product_id = {$s['product']} AND deleted = 'false'") === $prime;
    });
});

check('un ospite che ha già usato il coupon con la stessa email è rifiutato al checkout', function () {
    return prova(static function (): bool {
        $s = scenario();
        $c = coupon(['usage_limit_per_customer' => 1]);
        $altro = altroCarrello($s);
        Coupons::apply($s['cart'], $c['code']);
        Coupons::apply($altro, $c['code']);

        ordina($s['cart'], datiCheckout('Ospite@Example.com'));
        $motivo = errore(static fn () => ordina($altro, datiCheckout('ospite@example.com')));

        return $motivo === 'coupon.already_used' && count(utilizzi($c['id'])) === 1
            && (int) riga($altro)['coupon_id'] === 0;
    });
});

check('first_order_only: chi ha già ordinato è rifiutato al checkout anche se all\'applicazione era ignoto', function () {
    return prova(static function (): bool {
        $s = scenario();
        $senzaCoupon = altroCarrello($s);
        ordina($senzaCoupon, datiCheckout('veterano@example.com'));

        $c = coupon(['first_order_only' => 'true']);
        Coupons::apply($s['cart'], $c['code']);
        $motivo = errore(static fn () => ordina($s['cart'], datiCheckout('veterano@example.com')));

        return $motivo === 'coupon.not_first_order'
            && (string) riga($s['cart'])['stage'] === 'cart'
            && utilizzi($c['id']) === [];
    });
});

check('l\'annullo rilascia l\'utilizzo e il coupon torna usabile; annullare di nuovo non cambia niente', function () {
    return prova(static function (): bool {
        $s = scenario();
        $c = coupon(['usage_limit' => 1]);
        $altro = altroCarrello($s);
        Coupons::apply($s['cart'], $c['code']);
        $esito = ordina($s['cart'], datiCheckout());

        $piena = errore(static fn () => Coupons::apply($altro, $c['code'])) === 'coupon.exhausted';

        senzaPosta(static fn () => Lifecycle::cancel($esito['order_id'], ['notify' => false]));
        $primo = utilizzi($c['id']);
        $rilasciato = (string) ($primo[0]['released_at'] ?? '');
        Lifecycle::cancel($esito['order_id'], ['notify' => false]);
        $dopo = utilizzi($c['id']);

        $torna = errore(static fn () => Coupons::apply($altro, $c['code'])) === '';

        return $piena && count($primo) === 1 && $rilasciato !== '' && !str_starts_with($rilasciato, '0000')
            && (string) $dopo[0]['released_at'] === $rilasciato
            && $torna;
    });
});

check('la scadenza dell\'ordine in attesa rilascia l\'utilizzo', function () {
    return prova(static function (): bool {
        $s = scenario();
        $c = coupon();
        Coupons::apply($s['cart'], $c['code']);
        $esito = ordina($s['cart'], datiCheckout());

        senzaPosta(static fn () => Expiry::run(date('Y-m-d H:i:s', strtotime('+60 days'))));
        $usi = utilizzi($c['id']);

        return (string) riga($esito['order_id'])['status'] === 'cancelled'
            && count($usi) === 1 && trim((string) ($usi[0]['released_at'] ?? '')) !== '';
    });
});

check('un reso non rilascia l\'utilizzo', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'coupons', 'returns']);
        $s = scenario();
        $c = coupon();
        Coupons::apply($s['cart'], $c['code']);
        $esito = ordina($s['cart'], datiCheckout());
        Order::update(['status' => 'confirmed'], $esito['order_id']);
        $voce = OrderItem::find(['order_id' => $esito['order_id'], 'type' => 'product', 'deleted' => 'false'], 1);

        Returns::register($esito['order_id'], [[
            'order_item_id' => (int) $voce['id'], 'quantity' => '1', 'reason' => 'changed_mind', 'restock' => null,
        ]]);
        $usi = utilizzi($c['id']);

        return count($usi) === 1 && trim((string) ($usi[0]['released_at'] ?? '')) === '';
    });
});

check('un checkout che fallisce per altro non lascia utilizzi e il coupon resta sul carrello', function () {
    return prova(static function (): bool {
        $s = scenario();
        $c = coupon();
        Coupons::apply($s['cart'], $c['code']);
        $dati = datiCheckout();
        $dati['billing']['city'] = '';

        $motivo = errore(static fn () => ordina($s['cart'], $dati));

        return $motivo !== '' && !str_starts_with($motivo, 'coupon.')
            && utilizzi($c['id']) === []
            && (int) riga($s['cart'])['coupon_id'] === $c['id']
            && (string) riga($s['cart'])['stage'] === 'cart';
    });
});

check('con la funzionalità spenta al checkout il coupon non resta sull\'ordine né si consuma', function () {
    return prova(static function (): bool {
        $s = scenario();
        $c = coupon();
        Coupons::apply($s['cart'], $c['code']);
        spegniFunzionalita(['coupons']);

        $esito = ordina($s['cart'], datiCheckout());
        accendiFunzionalita(['coupons']);

        return utilizzi($c['id']) === []
            && (int) riga($esito['order_id'])['coupon_id'] === 0
            && (float) riga($esito['order_id'])['discount_total'] === 0.0;
    });
});

summary();
