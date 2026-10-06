<?php
/**
 * Aiuti per i test della spedizione: zone, metodi, listini, articoli con peso e
 * un carrello con la destinazione. Vanno usati dentro `prova()`, che annulla
 * tutto quello che scrivono.
 */

use Wonder\App\Models\Config\SocietyLocation;
use Wonder\App\Models\Config\SocietyLocationHour;
use Wonder\App\Support\SocietyLocations;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRate;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRateBracket;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZone;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZoneArea;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;

/** Una zona con le sue aree: ogni area è [paese, provincia]. */
function zona(string $nome, array $aree): int
{
    $id = (int) (ShippingZone::create([
        'code' => Code::make(ShippingZone::class, Codes::SHIPPING_ZONE),
        'name' => $nome,
        'position' => 1,
    ])->insert_id ?? 0);

    foreach ($aree as [$paese, $provincia]) {
        ShippingZoneArea::create(['shipping_zone_id' => $id, 'country' => $paese, 'province' => $provincia]);
    }

    return $id;
}

function metodo(string $nome, array $valori = []): int
{
    return (int) (ShippingMethod::create($valori + [
        'code' => Code::make(ShippingMethod::class, Codes::SHIPPING_METHOD),
        'name' => $nome,
        'description' => 'Da 24 a 48 ore',
        'carrier_id' => 0,
        'applies_online' => 'true',
        'applies_office' => 'true',
        'active' => 'true',
        'position' => 1,
    ])->insert_id ?? 0);
}

/** Un listino a scaglioni: ogni scaglione è [peso massimo, importo]. */
function listino(int $metodo, int $zona, array $scaglioni, array $valori = []): int
{
    $id = (int) (ShippingRate::create($valori + [
        'shipping_method_id' => $metodo,
        'shipping_zone_id' => $zona,
        'excess_mode' => 'total_weight',
        'fuel_surcharge_percent' => '0.00',
        'markup_percent' => '0.00',
        'min_price' => '0.00',
        'cod_fee' => '0.00',
        'active' => 'true',
    ])->insert_id ?? 0);

    foreach ($scaglioni as $posizione => [$massimo, $importo]) {
        ShippingRateBracket::create([
            'shipping_rate_id' => $id,
            'type' => 'price',
            'max_weight' => number_format($massimo, 3, '.', ''),
            'amount' => number_format($importo, 2, '.', ''),
            'position' => $posizione + 1,
        ]);
    }

    return $id;
}

/** Un articolo con peso, misure e prezzo. */
function articolo(float $peso, float $prezzo = 10.0, array $misure = [0, 0, 0], string $spedibile = 'true'): int
{
    $prodotto = articoloConGiacenza(50, 'SPD-'.uniqid());
    Product::update([
        'price' => number_format($prezzo, 2, '.', ''),
        'weight' => number_format($peso, 3, '.', ''),
        'length' => number_format($misure[0], 2, '.', ''),
        'width' => number_format($misure[1], 2, '.', ''),
        'height' => number_format($misure[2], 2, '.', ''),
    ], $prodotto);
    ProductModel::update(['requires_shipping' => $spedibile], modelloDi($prodotto));

    return $prodotto;
}

/** Un carrello con le righe date ([articolo, quantità]) e la destinazione. */
function carrello(array $righe, array $ordine = []): int
{
    accendiFunzionalita(['orders', 'shipping']);
    $cart = (int) Cart::open(['cart_token' => 'tok-'.uniqid(), 'channel' => $ordine['channel'] ?? 'online'])['id'];

    foreach ($righe as [$prodotto, $quantita]) {
        Cart::add($cart, ['product_id' => $prodotto, 'quantity' => $quantita]);
    }

    Order::update($ordine + ['shipping_country' => 'IT', 'shipping_province' => 'MI', 'shipping_city' => 'Milano'], $cart);

    return $cart;
}

/**
 * Un ordine confermato da spedire, con le righe date ([articolo, quantità]).
 *
 * @return array{0: int, 1: list<int>} l'id dell'ordine e gli id delle sue righe, nell'ordine dato
 */
function ordineDaSpedire(array $righe, array $ordine = []): array
{
    $id = carrello($righe);
    Order::update($ordine + [
        'stage' => 'order',
        'status' => 'confirmed',
        'fulfillment_type' => 'shipping',
    ], $id);

    $trovate = OrderItem::find(['order_id' => $id, 'deleted' => 'false']);
    $trovate = !is_array($trovate) || $trovate === [] ? [] : (array_key_exists('id', $trovate) ? [$trovate] : array_values(array_filter($trovate, 'is_array')));
    usort($trovate, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

    return [$id, array_map(static fn (array $riga): int => (int) $riga['id'], $trovate)];
}

/**
 * Una sede di prova: la riga del core, i suoi orari (sempre aperta o mai) e la
 * riga del gestionale. Si butta con la transazione della prova.
 *
 * @param array<string, string> $indirizzo campi di indirizzo della sede (`street`, `number`, `cap`, `city`)
 * @return int l'id della riga di `gst_locations`
 */
function sede(bool $ritiro = true, bool $aperta = true, string $attiva = 'true', array $indirizzo = []): int
{
    // L'indirizzo va dato alla creazione: un aggiornamento parziale non passa la validazione.
    $core = (int) (SocietyLocation::create($indirizzo + [
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
