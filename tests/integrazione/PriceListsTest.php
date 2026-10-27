<?php
/** php tests/integrazione/PriceListsTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceList;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceListItem;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaign;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            accendiFunzionalita(['orders', 'office_sales', 'customer_price_lists']);
            spegniFunzionalita(['coupons', 'shipping', 'backorders']);
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    } finally {
        Wonder\Plugin\Gestionale\Gestionale::reset();
    }

    return $esito;
}

function articolo(float $prezzo = 10.0): int
{
    $id = articoloConGiacenza(100, 'PRL-'.strtoupper(substr(uniqid(), -6)));
    Product::update(['price' => number_format($prezzo, 2, '.', ''), 'sale_price' => '0.00', 'active' => 'true'], $id);

    return $id;
}

/**
 * Un listino in corso con le sue righe, nella stessa base IVA dei prezzi di
 * catalogo del sito, così il prezzo atteso è quello scritto.
 *
 * @param list<array{0: int, 1: float, 2: float}> $righe prodotto, quantità minima, prezzo
 */
function listino(array $righe, array $valori = []): int
{
    $id = (int) (PriceList::create($valori + [
        'code' => Code::make(PriceList::class, Codes::PRICE_LIST),
        'name' => 'Prova '.uniqid(),
        'currency' => 'EUR',
        'prices_include_tax' => (string) (Setting::current()['catalog_prices_include_tax'] ?? 'true'),
        'active' => 'true',
    ])->insert_id ?? 0);

    foreach ($righe as $i => [$prodotto, $minimo, $prezzo]) {
        PriceListItem::create(['price_list_id' => $id, 'product_id' => $prodotto, 'min_quantity' => $minimo, 'price' => $prezzo, 'position' => $i + 1]);
    }

    return $id;
}

function cliente(int $listino = 0): int
{
    $creato = Contact::create([
        'type' => 'private',
        'name' => 'Lia',
        'surname' => 'Listino',
        'country' => 'IT',
        'city' => 'Milano',
        'street' => 'Via Torino',
        'number' => '1',
        'cap' => '20100',
        'email' => 'lia.listino.'.uniqid().'@example.com',
        'is_customer' => 'true',
        'active' => 'true',
    ]);
    $id = (int) ($creato->insert_id ?? 0);

    if ($listino > 0) {
        Contact::update(['price_list_id' => $listino], $id);
    }

    return $id;
}

function bozza(int $cliente, int $prodotto, float $pezzi): int
{
    return OfficeOrders::save(0, [
        'customer_id' => $cliente,
        'fulfillment_type' => 'pickup',
        'lines' => [['type' => 'product', 'product_id' => $prodotto, 'quantity' => (string) $pezzi]],
    ], 0);
}

/** La riga del prodotto. @return array<string, mixed> */
function riga(int $ordine, int $prodotto): array
{
    foreach (OrderItem::find(['order_id' => $ordine, 'deleted' => 'false']) ?: [] as $r) {
        if (is_array($r) && (int) $r['product_id'] === $prodotto) {
            return $r;
        }
    }

    return [];
}

function prezzo(array $riga): float
{
    return round((float) ($riga['unit_price'] ?? 0), 2);
}

check('ufficio: il cliente col listino paga il prezzo dello scaglione giusto', function () {
    return prova(static function (): bool {
        $p = articolo(10);
        $l = listino([[$p, 1, 9.0], [$p, 10, 8.0]]);
        $c = cliente($l);
        $poco = riga(bozza($c, $p, 2), $p);
        $tanto = bozza($c, $p, 12);

        return (string) $poco['price_source'] === 'price_list' && prezzo($poco) === 9.0
            && (int) $poco['price_list_id'] === $l
            && prezzo(riga($tanto, $p)) === 8.0
            && (int) Order::findById($tanto)['price_list_id'] === $l;
    });
});

check('ufficio: un prodotto fuori listino resta al prezzo di catalogo', function () {
    return prova(static function (): bool {
        $dentro = articolo(10);
        $fuori = articolo(15);
        $r = riga(bozza(cliente(listino([[$dentro, 1, 9.0]])), $fuori, 1), $fuori);

        return (string) $r['price_source'] !== 'price_list' && prezzo($r) === 15.0;
    });
});

check('ufficio: un listino scaduto non vale più', function () {
    return prova(static function (): bool {
        $p = articolo(10);
        $l = listino([[$p, 1, 9.0]], ['valid_to' => date('Y-m-d', time() - 86400 * 2)]);
        $r = riga(bozza(cliente($l), $p, 1), $p);

        return (string) $r['price_source'] !== 'price_list' && prezzo($r) === 10.0;
    });
});

check('ufficio: un listino spento non vale', function () {
    return prova(static function (): bool {
        $p = articolo(10);
        $r = riga(bozza(cliente(listino([[$p, 1, 9.0]], ['active' => 'false'])), $p, 1), $p);

        return prezzo($r) === 10.0;
    });
});

check('ufficio: il cliente senza listino paga il prezzo di catalogo', function () {
    return prova(static function (): bool {
        $p = articolo(10);
        listino([[$p, 1, 9.0]]);
        $ordine = bozza(cliente(), $p, 1);

        return prezzo(riga($ordine, $p)) === 10.0 && (int) (Order::findById($ordine)['price_list_id'] ?? 0) === 0;
    });
});

check('ufficio: il listino della bozza si cambia, e i prezzi lo seguono', function () {
    return prova(static function (): bool {
        $p = articolo(10);
        $c = cliente(listino([[$p, 1, 9.0]]));
        $altro = listino([[$p, 1, 7.5]]);
        $ordine = bozza($c, $p, 1);
        OfficeOrders::save($ordine, ['customer_id' => $c, 'price_list_id' => $altro, 'lines' => [['type' => 'product', 'product_id' => $p, 'quantity' => '1']]], 0);
        $dopo = prezzo(riga($ordine, $p));
        OfficeOrders::save($ordine, ['customer_id' => $c, 'price_list_id' => '0', 'lines' => [['type' => 'product', 'product_id' => $p, 'quantity' => '1']]], 0);

        return $dopo === 7.5 && prezzo(riga($ordine, $p)) === 10.0;
    });
});

check('ufficio: l\'ordine confermato non cambia se poi cambia il listino', function () {
    return prova(static function (): bool {
        $p = articolo(10);
        $l = listino([[$p, 1, 9.0]]);
        $ordine = bozza(cliente($l), $p, 2);
        OfficeOrders::confirm($ordine, ['user_id' => 0]);
        $prima = round((float) Order::findById($ordine)['total'], 2);
        $voce = PriceListItem::find(['price_list_id' => $l], 1);
        PriceListItem::update(['price' => '5.00'], (int) $voce['id']);

        try {
            Cart::recalculate($ordine);
        } catch (Throwable) {
            // Un ordine confermato non si ricalcola: il rifiuto va bene.
        }

        return $prima === 18.0 && prezzo(riga($ordine, $p)) === 9.0
            && round((float) Order::findById($ordine)['total'], 2) === 18.0;
    });
});

check('online: il cliente col listino lo paga nel carrello', function () {
    return prova(static function (): bool {
        $p = articolo(10);
        $l = listino([[$p, 1, 9.0], [$p, 5, 8.5]]);
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid(), 'customer_id' => cliente($l)])['id'];
        Cart::add($carrello, ['product_id' => $p, 'quantity' => 5]);
        $r = riga($carrello, $p);

        return (string) $r['price_source'] === 'price_list' && prezzo($r) === 8.5
            && (int) Order::findById($carrello)['price_list_id'] === $l;
    });
});

check('online: l\'ospite paga il prezzo di catalogo', function () {
    return prova(static function (): bool {
        $p = articolo(10);
        listino([[$p, 1, 9.0]]);
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($carrello, ['product_id' => $p, 'quantity' => 1]);

        return prezzo(riga($carrello, $p)) === 10.0;
    });
});

check('listino e campagna non si sommano: vale il listino', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['discount_campaigns']);
        $p = articolo(10);
        $campagna = (int) (DiscountCampaign::create([
            'code' => Code::make(DiscountCampaign::class, Codes::DISCOUNT_CAMPAIGN),
            'name' => 'Prova '.uniqid(),
            'discount_type' => 'percent',
            'discount_value' => '50.00',
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
        DiscountCampaignProductModel::create(['discount_campaign_id' => $campagna, 'product_model_id' => modelloDi($p), 'is_excluded' => 'false']);
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid(), 'customer_id' => cliente(listino([[$p, 1, 9.0]]))])['id'];
        Cart::add($carrello, ['product_id' => $p, 'quantity' => 1]);
        $r = riga($carrello, $p);

        return (string) $r['price_source'] === 'price_list' && prezzo($r) === 9.0;
    });
});

summary();
