<?php
/** php tests/integrazione/CartCouponsTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponProductModel;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaign;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
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
            // Le righe di spedizione di questi test le scrive la prova: il modulo delle spedizioni,
            // se è acceso sul sito, le toglierebbe a ogni ricalcolo.
            spegniFunzionalita(['shipping']);
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    }

    return $esito;
}

/** Un articolo al prezzo dato, e il suo modello. */
function articolo(string $prezzo): array
{
    $prodotto = articoloConGiacenza(50, 'CK-'.substr(uniqid(), -7));
    Product::update(['price' => $prezzo, 'sale_price' => '0.00'], $prodotto);

    return [$prodotto, modelloDi($prodotto)];
}

/** Un carrello con le righe date (prezzo, quantità), tutto a prezzo base. */
function carrello(array $righe): array
{
    accendiFunzionalita(['orders', 'coupons', 'discount_campaigns', 'bundles']);
    $cart = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
    $prodotti = [];

    foreach ($righe as [$prezzo, $quantita]) {
        [$prodotto, $modello] = articolo($prezzo);
        Cart::add($cart, ['product_id' => $prodotto, 'quantity' => $quantita]);
        $prodotti[] = ['product' => $prodotto, 'model' => $modello];
    }

    return ['cart' => $cart, 'p' => $prodotti];
}

function coupon(array $valori = []): array
{
    $codice = 'K'.strtoupper(substr(uniqid(), -8));
    $id = (int) (Coupon::create($valori + [
        'code' => $codice,
        'name' => 'Prova '.$codice,
        'discount_type' => 'percent',
        'discount_value' => '10.00',
        'applies_to_all' => 'true',
        'applies_online' => 'true',
        'active' => 'true',
    ])->insert_id ?? 0);

    return ['id' => $id, 'code' => $codice];
}

function campagnaSu(int $modello): int
{
    $id = (int) (DiscountCampaign::create([
        'code' => Code::make(DiscountCampaign::class, Codes::DISCOUNT_CAMPAIGN),
        'name' => 'Prova '.uniqid(),
        'discount_type' => 'percent',
        'discount_value' => '20.00',
        'starts_at' => date('Y-m-d H:i:s', time() - 86400),
        'ends_at' => date('Y-m-d H:i:s', time() + 86400),
        'active' => 'true',
        'applies_to_all' => 'false',
        'exclude_sale_products' => 'false',
        'applies_online' => 'true',
        'applies_office' => 'false',
        'applies_pos' => 'false',
        'note' => '',
    ])->insert_id ?? 0);
    DiscountCampaignProductModel::create(['discount_campaign_id' => $id, 'product_model_id' => $modello, 'is_excluded' => 'false']);

    return $id;
}

function rigaDi(int $carrello, int $prodotto): array
{
    foreach (OrderItem::find(['order_id' => $carrello, 'deleted' => 'false']) ?: [] as $riga) {
        if (is_array($riga) && (int) $riga['product_id'] === $prodotto) {
            return $riga;
        }
    }

    return [];
}

function spedizione(int $carrello, string $prezzo): void
{
    OrderItem::create([
        'order_id' => $carrello,
        'type' => 'shipping',
        'name' => 'Spedizione',
        'list_price' => $prezzo,
        'unit_price' => $prezzo,
        'quantity' => '1.000',
        'line_total' => $prezzo,
        'position' => 99,
    ]);
}

check('un coupon in percentuale sconta la merce e le quote delle righe tornano', function () {
    return prova(static function (): bool {
        $s = carrello([['50.00', 2], ['30.00', 1]]);
        $c = coupon();
        $esito = Coupons::apply($s['cart'], $c['code']);
        $ordine = $esito['order'];
        $quote = array_sum(array_map(static fn (array $r): float => (float) $r['order_discount_amount'], $esito['items']));

        return (string) $ordine['discount_total'] === '13.00'
            && (string) $ordine['total'] === '117.00'
            && round($quote, 2) === 13.0
            && (string) rigaDi($s['cart'], $s['p'][0]['product'])['order_discount_amount'] === '10.00'
            && (string) rigaDi($s['cart'], $s['p'][1]['product'])['order_discount_amount'] === '3.00';
    });
});

check('un coupon a importo sconta quella cifra, e mai più della merce', function () {
    return prova(static function (): bool {
        $s = carrello([['50.00', 2]]);
        $c = coupon(['discount_type' => 'amount', 'discount_value' => '15.00']);
        $esito = Coupons::apply($s['cart'], $c['code']);

        $grande = carrello([['10.00', 1]]);
        $g = coupon(['discount_type' => 'amount', 'discount_value' => '50.00']);
        $tetto = Coupons::apply($grande['cart'], $g['code']);

        return (string) $esito['order']['discount_total'] === '15.00'
            && (string) $esito['order']['total'] === '85.00'
            && (string) $tetto['order']['discount_total'] === '10.00'
            && (string) $tetto['order']['total'] === '0.00';
    });
});

check('la spedizione gratuita azzera la riga di spedizione e non sconta la merce', function () {
    return prova(static function (): bool {
        $s = carrello([['50.00', 2]]);
        spedizione($s['cart'], '7.00');
        $prima = Cart::recalculate($s['cart']);
        $c = coupon(['discount_type' => 'free_shipping', 'discount_value' => '0.00']);
        $dopo = Coupons::apply($s['cart'], $c['code']);

        $senza = carrello([['50.00', 2]]);
        $solo = Coupons::apply($senza['cart'], coupon(['discount_type' => 'free_shipping', 'discount_value' => '0.00'])['code']);

        return (string) $prima['order']['shipping_total'] === '7.00'
            && (string) $prima['order']['total'] === '107.00'
            && (string) $dopo['order']['shipping_total'] === '0.00'
            && (string) $dopo['order']['discount_total'] === '0.00'
            && (string) $dopo['order']['total'] === '100.00'
            && (string) $solo['order']['total'] === '100.00';
    });
});

check('coupon e campagna insieme: il coupon sconta il prezzo già ridotto', function () {
    return prova(static function (): bool {
        $s = carrello([['50.00', 2]]);
        campagnaSu($s['p'][0]['model']);
        $esito = Coupons::apply($s['cart'], coupon()['code']);

        return (string) rigaDi($s['cart'], $s['p'][0]['product'])['line_total'] === '80.00'
            && (string) $esito['order']['discount_total'] === '8.00'
            && (string) $esito['order']['total'] === '72.00';
    });
});

check('un coupon che esclude i prodotti già scontati non conta la riga in campagna', function () {
    return prova(static function (): bool {
        $s = carrello([['50.00', 1], ['30.00', 1]]);
        campagnaSu($s['p'][0]['model']);
        $esito = Coupons::apply($s['cart'], coupon(['exclude_discounted_products' => 'true'])['code']);

        return (string) $esito['order']['discount_total'] === '3.00'
            && (string) rigaDi($s['cart'], $s['p'][0]['product'])['order_discount_amount'] === '0.00'
            && (string) rigaDi($s['cart'], $s['p'][1]['product'])['order_discount_amount'] === '3.00';
    });
});

check('un coupon sul selettore sconta solo le righe adatte', function () {
    return prova(static function (): bool {
        $s = carrello([['50.00', 1], ['30.00', 1]]);
        $c = coupon(['applies_to_all' => 'false']);
        CouponProductModel::create(['coupon_id' => $c['id'], 'product_model_id' => $s['p'][0]['model'], 'is_excluded' => 'false']);
        $esito = Coupons::apply($s['cart'], $c['code']);

        return (string) $esito['order']['discount_total'] === '5.00'
            && (string) rigaDi($s['cart'], $s['p'][0]['product'])['order_discount_amount'] === '5.00'
            && (string) rigaDi($s['cart'], $s['p'][1]['product'])['order_discount_amount'] === '0.00';
    });
});

check('una riga a prezzo manuale resta com\'è e non conta come prezzo base', function () {
    return prova(static function (): bool {
        $s = carrello([['50.00', 1], ['30.00', 1]]);
        $manuale = rigaDi($s['cart'], $s['p'][0]['product']);
        OrderItem::update(['price_source' => 'manual', 'unit_price' => '45.00'], (int) $manuale['id']);
        $c = coupon(['exclude_discounted_products' => 'true']);
        $esito = Coupons::apply($s['cart'], $c['code']);
        $riga = rigaDi($s['cart'], $s['p'][0]['product']);

        return (string) $riga['price_source'] === 'manual'
            && (string) $riga['unit_price'] === '45.00'
            && (string) $riga['order_discount_amount'] === '0.00'
            && (string) $esito['order']['discount_total'] === '3.00';
    });
});

check('le figlie di una confezione non ricevono quote di sconto', function () {
    return prova(static function (): bool {
        $s = carrello([['50.00', 1]]);
        [$a] = articolo('10.00');
        [$c] = articolo('4.00');
        $bundle = multiprodottoDiProva('mixed', [['product_id' => $a, 'quantity' => 2]], [['name' => 'Colore', 'min' => 1, 'max' => 1, 'options' => [['product_id' => $c, 'surcharge' => 5]]]]);
        $opzione = Bundles::forModel(modelloDi($bundle))['groups'][0]['options'][0]['id'];
        Cart::add($s['cart'], ['product_id' => $bundle, 'choices' => [$opzione]]);
        $esito = Coupons::apply($s['cart'], coupon()['code']);

        $figlie = 0;

        foreach ($esito['items'] as $voce) {
            foreach ($voce['children'] as $figlia) {
                $figlie++;

                if ((float) $figlia['order_discount_amount'] !== 0.0) {
                    return false;
                }
            }
        }

        $quote = array_sum(array_map(static fn (array $r): float => (float) $r['order_discount_amount'], $esito['items']));

        return $figlie > 0 && round($quote, 2) === (float) $esito['order']['discount_total'] && (float) $esito['order']['discount_total'] > 0;
    });
});

check('un coupon che non vale più al ricalcolo esce dal carrello e lo dice', function () {
    return prova(static function (): bool {
        $s = carrello([['50.00', 2]]);
        $c = coupon();
        Coupons::apply($s['cart'], $c['code']);
        Coupon::update(['ends_at' => date('Y-m-d H:i:s', time() - 3600)], $c['id']);
        $esito = Cart::recalculate($s['cart']);

        return (int) $esito['order']['coupon_id'] === 0
            && (string) $esito['order']['coupon_code'] === ''
            && (string) $esito['order']['discount_total'] === '0.00'
            && (string) $esito['order']['total'] === '100.00'
            && $esito['coupon_dropped'] === 'expired';
    });
});

check('uno sconto scritto a mano sulla testata vince: il coupon esce con il suo motivo', function () {
    return prova(static function (): bool {
        $s = carrello([['50.00', 2]]);
        $c = coupon();
        Coupons::apply($s['cart'], $c['code']);
        Order::update(['manual_discount_type' => 'percent', 'manual_discount_value' => '5.00'], $s['cart']);
        $esito = Cart::recalculate($s['cart']);

        return (int) $esito['order']['coupon_id'] === 0
            && $esito['coupon_dropped'] === 'has_manual_discount'
            && (string) $esito['order']['discount_total'] === '5.00';
    });
});

check('senza coupon il ricalcolo non dice niente e lo sconto a mano funziona come prima', function () {
    return prova(static function (): bool {
        $s = carrello([['50.00', 2]]);
        $liscio = Cart::recalculate($s['cart']);
        Order::update(['manual_discount_type' => 'amount', 'manual_discount_value' => '12.00'], $s['cart']);
        $manuale = Cart::recalculate($s['cart']);

        return $liscio['coupon_dropped'] === ''
            && (string) $liscio['order']['discount_total'] === '0.00'
            && $manuale['coupon_dropped'] === ''
            && (string) $manuale['order']['discount_total'] === '12.00';
    });
});

check('con la funzionalità spenta il coupon scritto non si applica e non si stacca', function () {
    return prova(static function (): bool {
        $s = carrello([['50.00', 2]]);
        $c = coupon();
        Coupons::apply($s['cart'], $c['code']);
        spegniFunzionalita(['coupons']);
        $esito = Cart::recalculate($s['cart']);

        return (string) $esito['order']['discount_total'] === '0.00'
            && (string) $esito['order']['total'] === '100.00'
            && (int) $esito['order']['coupon_id'] === $c['id']
            && $esito['coupon_dropped'] === '';
    });
});

check('due ricalcoli di fila danno gli stessi totali', function () {
    return prova(static function (): bool {
        $s = carrello([['33.33', 3]]);
        Coupons::apply($s['cart'], coupon(['discount_type' => 'amount', 'discount_value' => '10.00'])['code']);
        $uno = Cart::recalculate($s['cart']);
        $due = Cart::recalculate($s['cart']);

        return $uno['order']['total'] === $due['order']['total']
            && $uno['order']['discount_total'] === $due['order']['discount_total']
            && (string) $due['order']['discount_total'] === '10.00';
    });
});

summary();
