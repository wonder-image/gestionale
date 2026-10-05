<?php
/** php tests/integrazione/ShipmentsDemoTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';
require __DIR__ . '/supporto/compra.php';
require __DIR__ . '/supporto/dns-fixture.php';

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;
use Wonder\Plugin\Gestionale\Models\Shipping\ShipmentItem;
use Wonder\Plugin\Gestionale\Models\Shipping\ShipmentStatusLog;
use Wonder\Plugin\Gestionale\Seeding\CatalogDemo;
use Wonder\Plugin\Gestionale\Seeding\ContactsDemo;
use Wonder\Plugin\Gestionale\Seeding\Demo;
use Wonder\Plugin\Gestionale\Seeding\DemoCode;
use Wonder\Plugin\Gestionale\Seeding\OrdersDemo;
use Wonder\Plugin\Gestionale\Seeding\PromotionsDemo;
use Wonder\Plugin\Gestionale\Seeding\ShipmentsDemo;
use Wonder\Plugin\Gestionale\Seeding\ShippingDemo;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** @return list<array<string, mixed>> */
$righe = static function (string $model, string|array $dove): array {
    $rows = $model::find($dove);

    return !is_array($rows) || $rows === [] ? [] : (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array')));
};

$ordine = static function (string $ref): array {
    $row = Order::find(['code' => DemoCode::forModel(Order::class, $ref), 'deleted' => 'false'], 1);

    return is_array($row) && isset($row['id']) ? $row : [];
};

// `CatalogDemo` scrive foto sul disco e la transazione non le rimette: l'istantanea sì.
$foto = Istantanea::di(rtrim((string) ($GLOBALS['ROOT'] ?? ''), '/').'/assets/upload'.ProductImages::folder());
$esito = [];

try {
    Transaction::run(static function () use (&$esito, $righe, $ordine): void {
        accendiFunzionalita(['orders', 'shipping']);
        spegniFunzionalita(['bundles', 'coupons']);
        ShipmentsDemo::clear();
        OrdersDemo::clear();
        ShippingDemo::clear();
        CatalogDemo::clear();
        ContactsDemo::clear();
        ContactsDemo::create();
        CatalogDemo::create();
        ShippingDemo::create();
        DemoData::notes();
        OrdersDemo::create();

        $esito['registrato'] = (static function (): array {
            DemoData::reset();
            Demo::registerAll();
            $chiavi = array_keys(DemoData::all());
            DemoData::reset();

            return $chiavi;
        })();

        $esito['create'] = ShipmentsDemo::create();
        $esito['secondo'] = ShipmentsDemo::create();

        $perOrdine = [];

        foreach (['carta-pagata', 'ospite', 'azienda', 'pagamento-parziale', 'coupon-pagato'] as $ref) {
            $o = $ordine($ref);
            $id = (int) ($o['id'] ?? 0);
            $perOrdine[$ref] = [
                'ordine' => $o,
                'spedizioni' => $id > 0 ? $righe(Shipment::class, "order_id = {$id} AND deleted = 'false'") : [],
            ];
        }

        $esito['per_ordine'] = $perOrdine;
        $esito['evaso_sicuro'] = $ordine('evaso');

        $ids = [];

        foreach ($perOrdine as $dati) {
            foreach ($dati['spedizioni'] as $s) {
                $ids[] = (int) $s['id'];
            }
        }

        $esito['ids'] = $ids;
        $esito['righe_spedizione'] = $ids === [] ? 0 : count($righe(ShipmentItem::class, 'shipment_id IN ('.implode(',', $ids).')'));
        $esito['storico'] = $ids === [] ? 0 : count($righe(ShipmentStatusLog::class, 'shipment_id IN ('.implode(',', $ids).')'));

        // La pulizia toglie spedizioni, righe e storico, e due giri non rompono.
        $esito['pulite'] = ShipmentsDemo::clear();
        $esito['dopo'] = $ids === [] ? 0 : count($righe(Shipment::class, 'id IN ('.implode(',', $ids).')'));
        $esito['orfane_righe'] = $ids === [] ? 0 : count($righe(ShipmentItem::class, 'shipment_id IN ('.implode(',', $ids).')'));
        $esito['orfani_storico'] = $ids === [] ? 0 : count($righe(ShipmentStatusLog::class, 'shipment_id IN ('.implode(',', $ids).')'));
        $esito['seconda_pulizia'] = ShipmentsDemo::clear();

        // Con le spedizioni spente non si crea niente e il comando lo dice.
        OrdersDemo::create();
        spegniFunzionalita(['shipping']);
        DemoData::notes();
        $esito['spente'] = ShipmentsDemo::create();
        $esito['nota_spente'] = DemoData::notes();

        throw new Annulla();
    });
} catch (Annulla) {
} finally {
    Wonder\Plugin\Gestionale\Gestionale::reset();
    $foto->ripristina();
}

$p = $esito['per_ordine'] ?? [];
$stato = static fn (string $ref): array => array_map(static fn (array $s): string => (string) $s['status'], $p[$ref]['spedizioni'] ?? []);

check('le spedizioni di prova sono nel registro, dopo gli ordini', function () use ($esito) {
    $chiavi = $esito['registrato'] ?? [];

    return in_array(ShipmentsDemo::KEY, $chiavi, true)
        && array_search(ShipmentsDemo::KEY, $chiavi, true) > array_search(OrdersDemo::KEY, $chiavi, true);
});

check('create fa quattro spedizioni (cinque con i coupon), una per ordine, e un secondo giro non ne duplica', fn () =>
    ($esito['create'] ?? 0) === 4 && ($esito['secondo'] ?? -1) === 0 && count($esito['ids'] ?? []) === 4
);

check('una consegnata, una parziale in viaggio, una in attesa e una con problema', fn () =>
    $stato('carta-pagata') === ['delivered']
    && $stato('ospite') === ['in_transit']
    && $stato('pagamento-parziale') === ['pending']
    && $stato('azienda') === ['exception']
    && $stato('coupon-pagato') === []
);

check('l\'evasione degli ordini è quella ricavata dalle spedizioni', function () use ($p) {
    $evasione = static fn (string $ref): string => (string) ($p[$ref]['ordine']['fulfillment_status'] ?? '');

    return $evasione('carta-pagata') === 'fulfilled'
        && $evasione('azienda') === 'fulfilled'
        && $evasione('pagamento-parziale') === 'unfulfilled'
        && in_array($evasione('ospite'), ['partially_fulfilled', 'fulfilled'], true);
});

check('la spedizione consegnata ha la data di consegna, quella in viaggio il tracking', function () use ($p) {
    $consegnata = $p['carta-pagata']['spedizioni'][0] ?? [];
    $viaggio = $p['ospite']['spedizioni'][0] ?? [];

    return (string) ($consegnata['delivered_at'] ?? '') !== '' && (string) ($consegnata['shipped_at'] ?? '') !== ''
        && str_starts_with((string) ($viaggio['tracking_number'] ?? ''), 'DEMO');
});

check('ogni spedizione ha le sue righe e il suo storico', fn () =>
    ($esito['righe_spedizione'] ?? 0) >= 4 && ($esito['storico'] ?? 0) >= 4
);

check('la pulizia toglie spedizioni, righe e storico senza orfani, e la seconda non trova niente', fn () =>
    ($esito['pulite'] ?? 0) === 4 && ($esito['dopo'] ?? -1) === 0
    && ($esito['orfane_righe'] ?? -1) === 0 && ($esito['orfani_storico'] ?? -1) === 0
    && ($esito['seconda_pulizia'] ?? -1) === 0
);

check('con le spedizioni spente non crea niente e lo dice', fn () =>
    ($esito['spente'] ?? -1) === 0 && ($esito['nota_spente'] ?? []) !== []
);

summary();
