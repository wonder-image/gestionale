<?php
/** php tests/integrazione/ShippingDemoTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/spedizioni.php';

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRate;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRateBracket;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZone;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZoneArea;
use Wonder\Plugin\Gestionale\Seeding\CatalogDemo;
use Wonder\Plugin\Gestionale\Seeding\ContactsDemo;
use Wonder\Plugin\Gestionale\Seeding\Demo;
use Wonder\Plugin\Gestionale\Seeding\ShippingDemo;
use Wonder\Plugin\Gestionale\Support\Shipping\Shipping;
use Wonder\Sql\Transaction;

/** Serve a buttare via tutto quello che la prova scrive. */
final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            // Zone e metodi veri del sito (per esempio «Italia» e «Spedizione
            // Standard») si nascondono: la transazione poi li rimette.
            ShippingZone::query()->Update(ShippingZone::$table, ['deleted' => 'true'], 'deleted', 'false');
            ShippingMethod::query()->Update(ShippingMethod::$table, ['deleted' => 'true'], 'deleted', 'false');
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

/** Gli id delle righe di prova di un Model (un `WHERE` dentro la condizione la farebbe leggere male al core). */
function idDiProva(string $model, string $prefisso): string
{
    $id = array_map(
        static fn (array $riga): int => (int) $riga['id'],
        righe($model, "code LIKE '".$prefisso."\\_demo-%' AND deleted = 'false'")
    );

    return $id === [] ? '0' : implode(',', $id);
}

/** Zone, corriere, metodi, listini e aree di prova, contati. */
function contaSpedizioni(): array
{
    $conta = static fn (string $model, string $dove): int => count(righe($model, $dove));
    $zone = idDiProva(ShippingZone::class, 'shz');
    $metodi = idDiProva(ShippingMethod::class, 'shm');

    return [
        'zone' => $conta(ShippingZone::class, 'id IN ('.$zone.")"),
        'corrieri' => $conta(Carrier::class, 'id IN ('.idDiProva(Carrier::class, 'car').')'),
        'metodi' => $conta(ShippingMethod::class, 'id IN ('.$metodi.')'),
        'listini' => $conta(ShippingRate::class, 'shipping_method_id IN ('.$metodi.')'),
        'aree' => $conta(ShippingZoneArea::class, 'shipping_zone_id IN ('.$zone.')'),
    ];
}

/** Righe dei figli che non hanno più il padre. */
function orfane(): int
{
    return count(righe(ShippingRateBracket::class, 'shipping_rate_id NOT IN (SELECT id FROM '.ShippingRate::$table.')'))
        + count(righe(ShippingRate::class, 'shipping_method_id NOT IN (SELECT id FROM '.ShippingMethod::$table.') OR shipping_zone_id NOT IN (SELECT id FROM '.ShippingZone::$table.')'))
        + count(righe(ShippingZoneArea::class, 'shipping_zone_id NOT IN (SELECT id FROM '.ShippingZone::$table.')'));
}

/** Un carrello con un articolo da 2 kg e la destinazione data. */
function carrelloVerso(string $paese, string $provincia = '', float $prezzo = 20.0): int
{
    $cart = carrello([[articolo(2.0, $prezzo), 1]]);
    Order::update(['shipping_country' => $paese, 'shipping_province' => $provincia, 'fulfillment_type' => 'shipping'], $cart);

    return $cart;
}

/** I prezzi dei metodi proposti, per nome. */
function prezzi(int $cart): array
{
    $trovati = [];

    foreach (Shipping::options($cart) as $opzione) {
        $trovati[$opzione['name']] = (float) $opzione['price'];
    }

    ksort($trovati);

    return $trovati;
}

check('le spedizioni di prova sono nel registro dei dati di prova', function () {
    DemoData::reset();
    Demo::registerAll();
    $registro = DemoData::all();
    DemoData::reset();

    return array_key_exists(ShippingDemo::KEY, $registro);
});

check('create fa tre zone, un corriere, due metodi e i loro listini', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    ShippingDemo::clear();
    ShippingDemo::create();

    return contaSpedizioni() === ['zone' => 3, 'corrieri' => 1, 'metodi' => 2, 'listini' => 5, 'aree' => 13];
}));

check('il corriere ha il link di tracking col segnaposto', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    ShippingDemo::clear();
    ShippingDemo::create();
    $corriere = righe(Carrier::class, "code LIKE 'car\\_demo-%'")[0] ?? [];

    return str_contains((string) ($corriere['tracking_url_template'] ?? ''), '{tracking}');
}));

check('in Italia si propongono Standard ed Espresso, sulle isole il prezzo delle Isole, fuori zona niente', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    ShippingDemo::clear();
    ShippingDemo::create();

    return prezzi(carrelloVerso('IT', 'MI')) === ['Espresso' => round(9.90 * 1.05, 2), 'Standard' => 6.90]
        && prezzi(carrelloVerso('IT', 'CA')) === ['Espresso' => round(12.90 * 1.05, 2), 'Standard' => 9.90]
        && prezzi(carrelloVerso('FR')) === ['Standard' => 14.90]
        && prezzi(carrelloVerso('US')) === [];
}));

check('l\'Espresso è gratis sopra i 100 euro di prodotti', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    ShippingDemo::clear();
    ShippingDemo::create();

    return (prezzi(carrelloVerso('IT', 'MI', 120.0))['Espresso'] ?? -1.0) === 0.0;
}));

check('un listino ha la commissione del contrassegno', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    ShippingDemo::clear();
    ShippingDemo::create();

    return count(righe(ShippingRate::class, 'cod_fee > 0 AND shipping_method_id IN ('.idDiProva(ShippingMethod::class, 'shm').')')) === 1;
}));

check('due create di fila non duplicano niente', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    ShippingDemo::clear();
    $primo = ShippingDemo::create();
    $dopo = contaSpedizioni();
    $secondo = ShippingDemo::create();

    return $primo > 0 && $secondo === 0 && contaSpedizioni() === $dopo;
}));

check('la pulizia toglie tutto, senza ponti orfani, e si può rifare', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    ShippingDemo::clear();
    $prima = orfane();
    ShippingDemo::create();
    $tolte = ShippingDemo::clear();
    $dopo = contaSpedizioni();
    ShippingDemo::create();

    return $tolte > 0
        && array_sum($dopo) === 0
        && orfane() === $prima
        && contaSpedizioni()['listini'] === 5;
}));

check('una zona cancellata dal backend si rimette in vita senza morire sul codice unico', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    ShippingDemo::clear();
    ShippingDemo::create();
    $zona = righe(ShippingZone::class, "code LIKE 'shz\\_demo-%' AND deleted = 'false'")[0];
    ShippingZone::query()->Update(ShippingZone::$table, ['deleted' => 'true'], 'id', (int) $zona['id']);

    ShippingDemo::create();

    return contaSpedizioni()['zone'] === 3;
}));

check('un metodo di prova già su un ordine resta, e la pulizia lo dice', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    ShippingDemo::clear();
    ShippingDemo::create();
    $metodo = righe(ShippingMethod::class, "code LIKE 'shm\\_demo-%' AND name = 'Standard' AND deleted = 'false'")[0];
    $cart = carrelloVerso('IT', 'MI');
    Order::update(['shipping_method_id' => (int) $metodo['id']], $cart);

    ShippingDemo::clear();
    $dopo = contaSpedizioni();

    return $dopo['metodi'] === 1 && $dopo['zone'] === 3 && $dopo['corrieri'] === 1;
}));

check('una zona con il listino di un metodo vero resta', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    ShippingDemo::clear();
    ShippingDemo::create();
    $zona = righe(ShippingZone::class, "code LIKE 'shz\\_demo-%' AND name = 'Italia' AND deleted = 'false'")[0];
    $vero = metodo('Mio metodo');
    listino($vero, (int) $zona['id'], [[5, 7.0]]);

    ShippingDemo::clear();

    return count(righe(ShippingZone::class, "code LIKE 'shz\\_demo-%' AND deleted = 'false'")) === 1
        && count(righe(ShippingMethod::class, "id = {$vero} AND deleted = 'false'")) === 1;
}));

check('gli articoli di prova da spedire senza peso lo prendono, gli altri no', fn () => prova(static function (): bool {
    accendiFunzionalita(['orders', 'shipping']);
    ContactsDemo::create();
    CatalogDemo::create();

    $modelli = idDiProva(ProductModel::class, 'mod');
    $senza = static fn (): int => count(righe(Product::class, "deleted = 'false' AND (weight IS NULL OR weight = 0) AND product_model_id IN (".$modelli.')'));

    // Il sito di prova può avere già i pesi dati da una demo: si parte da articoli senza peso.
    $articoli = righe(Product::class, "deleted = 'false' AND product_model_id IN (".$modelli.')');
    foreach ($articoli as $articolo) {
        Product::update(['weight' => null], (int) $articolo['id']);
    }
    $pesato = (int) $articoli[0]['id'];
    Product::update(['weight' => '2.500'], $pesato);

    $primo = $senza();
    ShippingDemo::create();
    $rimasto = righe(Product::class, 'id = '.$pesato)[0]['weight'];

    return $primo > 0 && $senza() === 0 && abs((float) $rimasto - 2.5) < 0.0001;
}));

summary();
