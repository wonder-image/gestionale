<?php
/** php tests/integrazione/CartCampaignsTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaign;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
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

/** Un articolo con prezzo, il suo modello e un carrello vuoto. */
function scenario(string $prezzo, string $scontato = '0.00'): array
{
    accendiFunzionalita(['orders', 'discount_campaigns', 'bundles']);
    $prodotto = articoloConGiacenza(50, 'CC-'.substr(uniqid(), -7));
    Product::update(['price' => $prezzo, 'sale_price' => $scontato], $prodotto);

    return [
        'product' => $prodotto,
        'model' => modelloDi($prodotto),
        'cart' => (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'],
    ];
}

/** Una campagna in corso adesso (da ieri a domani) sul modello dato. */
function campagnaSu(int $modello, array $valori = []): int
{
    $id = (int) (DiscountCampaign::create($valori + [
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

/** La riga madre del carrello com'è scritta in tabella. */
function rigaDi(int $carrello, int $prodotto): array
{
    foreach (OrderItem::find(['order_id' => $carrello, 'deleted' => 'false']) ?: [] as $riga) {
        if (is_array($riga) && (int) $riga['product_id'] === $prodotto) {
            return $riga;
        }
    }

    return [];
}

check('una campagna in corso abbassa il prezzo della riga e lascia il listino', function () {
    $x = prova(static function (): array {
        $s = scenario('50.00');
        $campagna = campagnaSu($s['model']);
        $esito = Cart::add($s['cart'], ['product_id' => $s['product'], 'quantity' => 2]);

        return $s + ['campagna' => $campagna, 'riga' => rigaDi($s['cart'], $s['product']), 'esito' => $esito];
    });
    $riga = $x['riga'];

    return (string) $riga['price_source'] === 'campaign'
        && (string) $riga['unit_price'] === '40.00'
        && (string) $riga['list_price'] === '50.00'
        && (int) $riga['discount_campaign_id'] === $x['campagna']
        && (string) $riga['line_total'] === '80.00'
        && (string) $x['esito']['order']['products_total'] === '80.00';
});

check('campagna scaduta o funzionalità spenta: si torna al prezzo base e l\'id si azzera', function () {
    $x = prova(static function (): array {
        $s = scenario('50.00');
        $campagna = campagnaSu($s['model']);
        Cart::add($s['cart'], ['product_id' => $s['product']]);
        $con = rigaDi($s['cart'], $s['product']);

        DiscountCampaign::update(['ends_at' => date('Y-m-d H:i:s', time() - 60)], $campagna);
        Cart::recalculate($s['cart']);
        $scaduta = rigaDi($s['cart'], $s['product']);

        DiscountCampaign::update(['ends_at' => date('Y-m-d H:i:s', time() + 86400)], $campagna);
        Cart::recalculate($s['cart']);
        $tornata = rigaDi($s['cart'], $s['product']);

        spegniFunzionalita(['discount_campaigns']);
        Cart::recalculate($s['cart']);
        $spenta = rigaDi($s['cart'], $s['product']);

        return compact('con', 'scaduta', 'tornata', 'spenta');
    });

    return (string) $x['con']['unit_price'] === '40.00'
        && (string) $x['scaduta']['unit_price'] === '50.00' && (string) $x['scaduta']['price_source'] === 'base'
        && (int) $x['scaduta']['discount_campaign_id'] === 0
        && (string) $x['tornata']['price_source'] === 'campaign'
        && (string) $x['spenta']['unit_price'] === '50.00' && (int) $x['spenta']['discount_campaign_id'] === 0;
});

check('con il prezzo scontato la campagna vince, salvo exclude_sale_products', function () {
    $x = prova(static function (): array {
        $s = scenario('50.00', '45.00');
        $campagna = campagnaSu($s['model']);
        Cart::add($s['cart'], ['product_id' => $s['product']]);
        $vince = rigaDi($s['cart'], $s['product']);

        DiscountCampaign::update(['exclude_sale_products' => 'true'], $campagna);
        Cart::recalculate($s['cart']);
        $esclusa = rigaDi($s['cart'], $s['product']);

        return compact('vince', 'esclusa');
    });

    return (string) $x['vince']['unit_price'] === '40.00' && (string) $x['vince']['price_source'] === 'campaign'
        && (string) $x['esclusa']['unit_price'] === '45.00' && (string) $x['esclusa']['price_source'] === 'sale_price'
        && (int) $x['esclusa']['discount_campaign_id'] === 0;
});

check('lo sconto di riga scritto a mano vince sulla campagna', function () {
    $riga = prova(static function (): array {
        $s = scenario('50.00');
        campagnaSu($s['model']);
        Cart::add($s['cart'], ['product_id' => $s['product']]);
        $id = (int) rigaDi($s['cart'], $s['product'])['id'];
        OrderItem::update(['discount_type' => 'percent', 'discount_value' => '10.00'], $id);
        Cart::recalculate($s['cart']);

        return rigaDi($s['cart'], $s['product']);
    });

    return (string) $riga['price_source'] === 'manual'
        && (string) $riga['unit_price'] === '36.00'
        && (int) $riga['discount_campaign_id'] === 0;
});

check('il prezzo scritto a mano non è sovrascritto dalla campagna', function () {
    $riga = prova(static function (): array {
        $s = scenario('50.00');
        campagnaSu($s['model']);
        Cart::add($s['cart'], ['product_id' => $s['product']]);
        $id = (int) rigaDi($s['cart'], $s['product'])['id'];
        OrderItem::update(['price_source' => 'manual', 'unit_price' => '30.00'], $id);
        Cart::recalculate($s['cart']);

        return rigaDi($s['cart'], $s['product']);
    });

    return (string) $riga['price_source'] === 'manual'
        && (string) $riga['unit_price'] === '30.00'
        && (int) $riga['discount_campaign_id'] === 0;
});

check('una campagna al 100% fa una riga a zero e resta campagna', function () {
    $riga = prova(static function (): array {
        $s = scenario('50.00');
        campagnaSu($s['model'], ['discount_value' => '100.00']);
        Cart::add($s['cart'], ['product_id' => $s['product']]);

        return rigaDi($s['cart'], $s['product']);
    });

    return (string) $riga['unit_price'] === '0.00' && (string) $riga['price_source'] === 'campaign';
});

check('confezione: la campagna sconta il prezzo, il sovrapprezzo resta pieno e le figlie a zero', function () {
    $x = prova(static function (): array {
        accendiFunzionalita(['orders', 'discount_campaigns', 'bundles']);
        $a = articoloConGiacenza(50, 'CCB-A'.substr(uniqid(), -6));
        $c = articoloConGiacenza(50, 'CCB-C'.substr(uniqid(), -6));
        $bundle = multiprodottoDiProva('mixed', [['product_id' => $a, 'quantity' => 2]], [
            ['name' => 'Colore', 'min' => 1, 'max' => 1, 'options' => [['product_id' => $c, 'surcharge' => 5]]],
        ]);
        Product::update(['price' => '30.00'], $bundle);
        campagnaSu(modelloDi($bundle));
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $opzione = \Wonder\Plugin\Gestionale\Support\Catalog\Bundles::forModel(modelloDi($bundle))['groups'][0]['options'][0]['id'];
        $esito = Cart::add($carrello, ['product_id' => $bundle, 'choices' => [$opzione]]);

        return ['madre' => $esito['items'][0], 'riga' => rigaDi($carrello, $bundle)];
    });
    $figlie = $x['madre']['children'] ?? [];

    // 30.00 scontato del 20 % = 24.00, più 5.00 di opzione non scontati.
    return (string) $x['riga']['unit_price'] === '29.00'
        && (string) $x['riga']['price_source'] === 'campaign'
        && count($figlie) > 0
        && (string) $figlie[0]['unit_price'] === '0.00';
});

check('un ordine già fatto non cambia quando la campagna finisce', function () {
    $x = prova(static function (): array {
        $s = scenario('50.00');
        $campagna = campagnaSu($s['model']);
        Cart::add($s['cart'], ['product_id' => $s['product']]);
        $prima = rigaDi($s['cart'], $s['product']);
        DiscountCampaign::update(['active' => 'false'], $campagna);

        // Un ordine non più in carrello non si riprezza: lo stage lo dice.
        \Wonder\Plugin\Gestionale\Models\Sales\Order::update(['stage' => 'order'], $s['cart']);
        try {
            Cart::recalculate($s['cart']);
        } catch (\Wonder\Plugin\Gestionale\Support\Errors\UserError) {
            // Voluto: un ordine fatto non si riprezza.
        }

        $dopo = rigaDi($s['cart'], $s['product']);

        return compact('prima', 'dopo');
    });

    return (string) $x['prima']['unit_price'] === (string) $x['dopo']['unit_price']
        && (int) $x['dopo']['discount_campaign_id'] === (int) $x['prima']['discount_campaign_id'];
});

summary();
