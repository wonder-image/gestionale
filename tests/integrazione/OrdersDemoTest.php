<?php
/** php tests/integrazione/OrdersDemoTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';
require __DIR__ . '/supporto/compra.php';
require __DIR__ . '/supporto/dns-fixture.php';

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnItem;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnStatusLog;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Seeding\CatalogDemo;
use Wonder\Plugin\Gestionale\Seeding\ContactsDemo;
use Wonder\Plugin\Gestionale\Seeding\Demo;
use Wonder\Plugin\Gestionale\Seeding\DemoCode;
use Wonder\Plugin\Gestionale\Seeding\OrdersDemo;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Orders\OrderLines;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Returns\Returns;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Le righe di un Model che rispondono alla condizione, in elenco. */
$righe = static function (string $model, string|array $dove): array {
    $rows = $model::find($dove);

    if (!is_array($rows) || $rows === []) {
        return [];
    }

    return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
};

/** L'ordine di prova con quel riferimento. */
$ordine = static function (string $ref): array {
    $row = Order::find(['code' => DemoCode::forModel(Order::class, $ref), 'deleted' => 'false'], 1);

    return is_array($row) && isset($row['id']) ? $row : [];
};

/** Quante righe di quel Model restano agganciate agli ordini di prova. */
$agganciate = static function (string $model, string $colonna, array $ids) use ($righe): int {
    if ($ids === []) {
        return 0;
    }

    return count($righe($model, $colonna.' IN ('.implode(',', $ids).')'));
};

$sempre = ['bonifico-in-attesa', 'carta-pagata', 'evaso', 'annullato', 'pagamento-parziale', 'ospite', 'azienda'];

// `CatalogDemo` scrive foto sul disco e la transazione non le rimette: l'istantanea sì.
$cartella = rtrim((string) ($GLOBALS['ROOT'] ?? ''), '/').'/assets/upload'.ProductImages::folder();
$foto = Istantanea::di($cartella);

$esito = [];

try {
    Transaction::run(static function () use (&$esito, $righe, $ordine, $agganciate, $sempre): void {
        // Si parte dal pulito: la transazione rimette tutto com'era.
        accendiFunzionalita(['orders', 'returns', 'customizations']);
        // Spenti per davvero: il sito di prova può averli accesi, e gli ordini
        // restano i sette di sempre.
        spegniFunzionalita(['bundles', 'coupons']);
        OrdersDemo::clear();
        CatalogDemo::clear();
        ContactsDemo::clear();
        ContactsDemo::create();
        CatalogDemo::create();
        DemoData::notes();

        $tutti = array_map(static fn (array $p): int => (int) $p['id'], $righe(Product::class, "deleted = 'false'"));
        $esito['prima'] = Levels::forProducts($tutti);

        $esito['registrato'] = array_key_exists(OrdersDemo::KEY, (static function (): array {
            DemoData::reset();
            Demo::registerAll();
            $registro = DemoData::all();
            DemoData::reset();

            return $registro;
        })());

        $esito['create'] = OrdersDemo::create();
        $esito['secondo'] = OrdersDemo::create();

        $esito['ordini'] = [];

        foreach ($sempre as $ref) {
            $esito['ordini'][$ref] = $ordine($ref);
        }

        $esito['numeri'] = array_map(static fn (array $o): string => (string) ($o['order_number'] ?? ''), $esito['ordini']);
        $esito['conteggio'] = count($righe(Order::class, "code LIKE 'ord\\_demo-%' AND deleted = 'false'"));
        $esito['cesti'] = count($righe(Order::class, "code LIKE 'ord\\_demo-cesto%' AND deleted = 'false'"));

        // Le righe con una personalizzazione: cosa ha scritto il cliente e quanto costa.
        $idOrdini = array_values(array_map(static fn (array $o): int => (int) ($o['id'] ?? 0), $esito['ordini']));
        $esito['personalizzate'] = array_values(array_filter(
            $righe(OrderItem::class, 'order_id IN ('.implode(',', $idOrdini).") AND type = 'product' AND deleted = 'false'"),
            static fn (array $riga): bool => Customizations::decode($riga['customization'] ?? '') !== []
        ));

        // Il reso di prova, sull'ordine evaso: com'è finito e cosa ha mosso.
        $evasoId = (int) ($esito['ordini']['evaso']['id'] ?? 0);
        $resi = $righe(SalesReturn::class, "order_id = {$evasoId} AND deleted = 'false'");
        $esito['resi'] = count($resi);
        $esito['reso'] = $resi[0] ?? [];
        $resoId = (int) ($esito['reso']['id'] ?? 0);
        $esito['reso_righe'] = $righe(SalesReturnItem::class, "sales_return_id = {$resoId}");
        $esito['reso_movimenti'] = count($righe(StockMovement::class, "reference_type = 'sales_return' AND reference_id = {$resoId} AND type = 'return'"));
        $esito['resi_altri'] = count($righe(SalesReturn::class, "order_id <> {$evasoId} AND code LIKE 'ret\\_demo-%'"));

        // Un reso fatto a mano dal commerciante sull'ordine di prova, con la merce che rientra:
        // l'ordine se ne va, e il reso con lui, senza giacenza doppia né chiavi esterne.
        $esito['reso_a_mano'] = 0;

        foreach (Returns::lines($evasoId) as $riga) {
            if ($riga['max'] > 0) {
                $esito['reso_a_mano'] = Returns::register($evasoId, [
                    ['order_item_id' => $riga['order_item_id'], 'quantity' => '1', 'reason' => 'changed_mind'],
                ])['return_id'];

                break;
            }
        }

        // Un reso vero, su un ordine vero: la pulizia non lo tocca.
        $ordineVero = ordineDiProva(10.0);
        $esito['reso_vero'] = (int) (SalesReturn::create([
            'code' => 'ret_vero-1', 'number' => 'R-VERO', 'order_id' => $ordineVero, 'status' => 'received', 'location_id' => Locations::mainId(),
        ])->insert_id ?? 0);

        // Cosa ha fatto al magazzino ciascun ordine.
        foreach ($esito['ordini'] as $ref => $o) {
            $id = (int) ($o['id'] ?? 0);
            $esito['vendite'][$ref] = count($righe(StockMovement::class, "reference_type = 'order' AND reference_id = {$id} AND type = 'sale'"));
            $esito['rilasci'][$ref] = count($righe(StockReservation::class, "order_id = {$id} AND released_at IS NULL"));
            $esito['righe'][$ref] = count($righe(OrderItem::class, "order_id = {$id} AND deleted = 'false'"));
        }

        // Un ordine vero, creato prima della pulizia: deve restare.
        $vero = ordineDiProva(10.0);
        $esito['vero_codice'] = (string) (Order::findById($vero)['code'] ?? '');

        $idDemo = array_values(array_map(static fn (array $o): int => (int) ($o['id'] ?? 0), $esito['ordini']));
        $prodotti = [];

        foreach ($righe(OrderItem::class, 'order_id IN ('.implode(',', $idDemo).')') as $riga) {
            $prodotti[(int) $riga['product_id']] = true;
        }

        $esito['prodotti'] = array_keys($prodotti);
        $esito['tolti'] = OrdersDemo::clear();
        $esito['vero_resta'] = Order::findById($vero) !== null && (int) (Order::findById($vero)['id'] ?? 0) === $vero;
        $esito['demo_dopo'] = count($righe(Order::class, "code LIKE 'ord\\_demo-%'"));

        $esito['resti'] = [
            'righe' => $agganciate(OrderItem::class, 'order_id', $idDemo),
            'riepiloghi' => $agganciate(OrderTaxSummary::class, 'order_id', $idDemo),
            'log' => $agganciate(OrderStatusLog::class, 'order_id', $idDemo),
            'pagamenti' => $agganciate(Payment::class, 'order_id', $idDemo),
            'prenotazioni' => $agganciate(StockReservation::class, 'order_id', $idDemo),
            'movimenti' => count($righe(StockMovement::class, "reference_type = 'order' AND reference_id IN (".implode(',', $idDemo).')')),
        ];

        $esito['dopo'] = Levels::forProducts($tutti);
        $esito['resti']['resi'] = count($righe(SalesReturn::class, "order_id IN (".implode(',', $idDemo).')'));
        $esito['resti']['resi_righe'] = $agganciate(SalesReturnItem::class, 'sales_return_id', [$resoId]);
        $esito['resti']['resi_log'] = $agganciate(SalesReturnStatusLog::class, 'sales_return_id', [$resoId]);
        $esito['resti']['resi_movimenti'] = count($righe(StockMovement::class, "reference_type = 'sales_return' AND reference_id = {$resoId}"));
        $esito['resti']['reso_a_mano'] = count($righe(SalesReturn::class, 'id = '.(int) $esito['reso_a_mano']))
            + $agganciate(SalesReturnItem::class, 'sales_return_id', [(int) $esito['reso_a_mano']])
            + count($righe(StockMovement::class, "reference_type = 'sales_return' AND reference_id = ".(int) $esito['reso_a_mano']));
        $esito['reso_vero_resta'] = SalesReturn::findById((int) $esito['reso_vero']) !== null;

        // Il catalogo che segue non trova chiavi esterne.
        try {
            $esito['catalogo'] = CatalogDemo::clear();
            $esito['errore'] = '';
        } catch (Throwable $e) {
            $esito['errore'] = $e->getMessage();
        }

        // Una seconda pulizia non trova più niente e non rompe.
        $esito['secondaPulizia'] = OrdersDemo::clear();

        throw new Annulla();
    });
} catch (Annulla) {
} finally {
    Wonder\Plugin\Gestionale\Gestionale::reset();
    $foto->ripristina();
}

$o = $esito['ordini'] ?? [];

check('i dati di prova degli ordini sono nel registro, dopo catalogo e anagrafiche', fn () => ($esito['registrato'] ?? false) === true);

check('create fa sette ordini e un secondo giro non ne duplica', fn () =>
    ($esito['create'] ?? 0) === 7 && ($esito['secondo'] ?? -1) === 0 && ($esito['conteggio'] ?? 0) === 7
);

check('ogni ordine di prova ha il suo numero, e i numeri sono tutti diversi', fn () =>
    !in_array('', $esito['numeri'] ?? [''], true) && count(array_unique($esito['numeri'] ?? [])) === 7
);

check('il bonifico in attesa è in attesa, da pagare e da evadere', fn () =>
    ($o['bonifico-in-attesa']['status'] ?? '') === 'pending'
    && ($o['bonifico-in-attesa']['payment_status'] ?? '') === 'pending'
    && ($o['bonifico-in-attesa']['fulfillment_status'] ?? '') === 'unfulfilled'
);

check('quello pagato con la carta è confermato e pagato', fn () =>
    ($o['carta-pagata']['status'] ?? '') === 'confirmed' && ($o['carta-pagata']['payment_status'] ?? '') === 'paid'
);

check('l\'evaso è completato: pagato ed evaso', fn () =>
    ($o['evaso']['status'] ?? '') === 'completed'
    && ($o['evaso']['payment_status'] ?? '') === 'paid'
    && ($o['evaso']['fulfillment_status'] ?? '') === 'fulfilled'
);

check('l\'annullato è annullato', fn () => ($o['annullato']['status'] ?? '') === 'cancelled');

check('il pagamento parziale è confermato e pagato a metà', fn () =>
    ($o['pagamento-parziale']['status'] ?? '') === 'confirmed' && ($o['pagamento-parziale']['payment_status'] ?? '') === 'partially_paid'
);

check('l\'ospite non ha un cliente in rubrica', fn () =>
    (int) ($o['ospite']['id'] ?? 0) > 0 && (int) ($o['ospite']['customer_id'] ?? -1) === 0 && trim((string) ($o['ospite']['email'] ?? '')) !== ''
);

check('quello dell\'azienda ha la ragione sociale e un cliente di rubrica', fn () =>
    trim((string) ($o['azienda']['billing_business_name'] ?? '')) !== '' && (int) ($o['azienda']['customer_id'] ?? 0) > 0
);

check('ogni ordine ha delle righe', fn () => min($esito['righe'] ?? [0]) > 0 && count($esito['righe'] ?? []) === 7);

check('il magazzino racconta gli stati: i confermati scaricano, in attesa prenota, annullato non pesa', function () use ($esito) {
    $v = $esito['vendite'] ?? [];
    $r = $esito['rilasci'] ?? [];

    return $v['bonifico-in-attesa'] === 0 && $r['bonifico-in-attesa'] > 0
        && $v['carta-pagata'] > 0 && $v['evaso'] > 0 && $v['pagamento-parziale'] > 0 && $v['ospite'] > 0 && $v['azienda'] > 0
        && $v['annullato'] === 0 && $r['annullato'] === 0;
});

check('clear toglie gli ordini di prova e dice quanti', fn () => ($esito['tolti'] ?? 0) >= 7 && ($esito['demo_dopo'] ?? -1) === 0);

check('clear non tocca un ordine vero', fn () =>
    ($esito['vero_resta'] ?? false) === true && !DemoCode::is((string) ($esito['vero_codice'] ?? 'ord_demo-x'))
);

check('dopo clear non restano righe di prova in nessuna tabella', fn () =>
    array_sum($esito['resti'] ?? ['x' => 1]) === 0
);

check('dopo clear la giacenza di ogni articolo è quella di prima', fn () =>
    ($esito['prima'] ?? null) !== [] && ($esito['prima'] ?? null) == ($esito['dopo'] ?? 'x')
);

check('il catalogo che segue non incontra chiavi esterne', fn () => ($esito['errore'] ?? 'no') === '' && ($esito['catalogo'] ?? 0) > 0);

check('una seconda pulizia non trova niente e non rompe', fn () => ($esito['secondaPulizia'] ?? -1) === 0);

check('l\'ordine evaso ha un reso ricevuto, con il segno della demo', fn () =>
    ($esito['resi'] ?? 0) === 1 && ($esito['reso']['status'] ?? '') === 'received'
    && DemoCode::is((string) ($esito['reso']['code'] ?? ''))
);

check('il reso ha una riga che rientra a magazzino, con il suo movimento', fn () =>
    count($esito['reso_righe'] ?? []) === 1 && ($esito['reso_righe'][0]['restock'] ?? '') === 'true'
    && ($esito['reso_righe'][0]['reason'] ?? '') === 'changed_mind' && ($esito['reso_movimenti'] ?? 0) === 1
);

check('gli altri ordini di prova non hanno resi', fn () => ($esito['resi_altri'] ?? -1) === 0);

check('clear toglie anche il reso: righe, storia e movimenti', fn () =>
    ($esito['resti']['resi'] ?? 1) === 0 && ($esito['resti']['resi_righe'] ?? 1) === 0
    && ($esito['resti']['resi_log'] ?? 1) === 0 && ($esito['resti']['resi_movimenti'] ?? 1) === 0
);

check('un reso fatto a mano su un ordine di prova se ne va con l\'ordine', fn () =>
    ($esito['reso_a_mano'] ?? 0) > 0 && ($esito['resti']['reso_a_mano'] ?? 1) === 0 && ($esito['errore'] ?? 'no') === ''
);

check('con le personalizzazioni accese una riga ha l\'incisione «Auguri» a 5.00', function () use ($esito) {
    $righe = $esito['personalizzate'] ?? [];

    return $righe !== []
        && count(array_filter($righe, static fn (array $r): bool =>
            Customizations::lines($r) === ['Incisione: Auguri'] && $r['customization_surcharge'] === '5.00')) === count($righe);
});

check('clear non tocca il reso di un ordine vero', fn () => ($esito['reso_vero_resta'] ?? false) === true);

check('con i multiprodotti spenti nessun ordine «cesto»', fn () => ($esito['cesti'] ?? -1) === 0);

// Con i multiprodotti accesi: quattro ordini in più, con le figlie, e un reso della confezione.
$cesti = [];

try {
    Transaction::run(static function () use (&$cesti, $righe, $ordine, $agganciate): void {
        accendiFunzionalita(['orders', 'returns', 'bundles']);
        spegniFunzionalita(['coupons']);
        OrdersDemo::clear();
        CatalogDemo::clear();
        ContactsDemo::clear();
        ContactsDemo::create();
        CatalogDemo::create();
        DemoData::notes();

        $tutti = array_map(static fn (array $p): int => (int) $p['id'], $righe(Product::class, "deleted = 'false'"));
        $cesti['prima'] = Levels::forProducts($tutti);
        $cesti['create'] = OrdersDemo::create();
        $cesti['secondo'] = OrdersDemo::create();
        $cesti['ordini'] = [];

        foreach (['cesto-in-attesa', 'cesto-confermato', 'cesto-annullato', 'cesto-evaso'] as $ref) {
            $o = $ordine($ref);
            $id = (int) ($o['id'] ?? 0);
            $righeOrdine = $righe(OrderItem::class, "order_id = {$id} AND deleted = 'false'");
            $madri = array_values(array_filter($righeOrdine, static fn (array $r): bool => OrderLines::children($righeOrdine, (int) $r['id']) !== []));
            $cesti['ordini'][$ref] = [
                'ordine' => $o,
                'madri' => count($madri),
                'figlie' => $madri === [] ? [] : OrderLines::children($righeOrdine, (int) $madri[0]['id']),
                'madre' => $madri[0] ?? [],
                'vendite' => array_map(
                    static fn (array $m): int => (int) $m['product_id'],
                    $righe(StockMovement::class, "reference_type = 'order' AND reference_id = {$id} AND type = 'sale'")
                ),
            ];
        }

        // Il reso della confezione: una riga per la madre e una per componente.
        $evaso = $cesti['ordini']['cesto-evaso'];
        $resi = $righe(SalesReturn::class, 'order_id = '.(int) ($evaso['ordine']['id'] ?? 0)." AND deleted = 'false'");
        $resoId = (int) ($resi[0]['id'] ?? 0);
        $idFiglie = array_map(static fn (array $f): int => (int) $f['id'], $evaso['figlie']);
        $cesti['resi'] = count($resi);
        $cesti['reso_codice'] = (string) ($resi[0]['code'] ?? '');
        $cesti['reso_figlie'] = array_values(array_filter(
            $righe(SalesReturnItem::class, "sales_return_id = {$resoId}"),
            static fn (array $r): bool => in_array((int) $r['order_item_id'], $idFiglie, true)
        ));
        $cesti['reso_madre'] = array_values(array_filter(
            $righe(SalesReturnItem::class, "sales_return_id = {$resoId}"),
            static fn (array $r): bool => (int) $r['order_item_id'] === (int) ($evaso['madre']['id'] ?? 0)
        ));

        $idDemo = array_map(static fn (array $o): int => (int) $o['id'], $righe(Order::class, "code LIKE 'ord\\_demo-%' AND deleted = 'false'"));
        $cesti['tolti'] = OrdersDemo::clear();
        $cesti['resti'] = $agganciate(OrderItem::class, 'order_id', $idDemo)
            + $agganciate(SalesReturnItem::class, 'sales_return_id', [$resoId])
            + count($righe(StockMovement::class, "reference_type = 'sales_return' AND reference_id = {$resoId}"));
        $cesti['dopo'] = Levels::forProducts($tutti);

        try {
            $cesti['catalogo'] = CatalogDemo::clear();
            $cesti['errore'] = '';
        } catch (Throwable $e) {
            $cesti['errore'] = $e->getMessage();
        }

        throw new Annulla();
    });
} catch (Annulla) {
} finally {
    Wonder\Plugin\Gestionale\Gestionale::reset();
    $foto->ripristina();
}

$c = $cesti['ordini'] ?? [];

check('con i multiprodotti accesi nascono quattro ordini in più, e un secondo giro non ne duplica', fn () =>
    ($cesti['create'] ?? 0) === 11 && ($cesti['secondo'] ?? -1) === 0
);

check('ogni ordine «cesto» ha una madre con le figlie, che portano i componenti', fn () =>
    count($c) === 4 && min(array_map(static fn (array $x): int => $x['madri'], $c)) === 1
    && min(array_map(static fn (array $x): int => count($x['figlie']), $c)) >= 2
);

check('gli ordini «cesto» sono in attesa, confermato, annullato ed evaso', fn () =>
    ($c['cesto-in-attesa']['ordine']['status'] ?? '') === 'pending'
    && ($c['cesto-confermato']['ordine']['status'] ?? '') === 'confirmed'
    && ($c['cesto-annullato']['ordine']['status'] ?? '') === 'cancelled'
    && ($c['cesto-evaso']['ordine']['status'] ?? '') === 'completed'
);

check('il confermato scarica i componenti e mai la confezione', function () use ($c) {
    $scaricati = $c['cesto-confermato']['vendite'] ?? [];
    $figlie = array_map(static fn (array $f): int => (int) $f['product_id'], $c['cesto-confermato']['figlie'] ?? []);
    $madre = (int) ($c['cesto-confermato']['madre']['product_id'] ?? 0);

    return $scaricati !== [] && $madre > 0 && !in_array($madre, $scaricati, true)
        && array_diff($scaricati, $figlie) === [];
});

check('l\'ordine a scelta porta le scelte del cliente: figlie con un\'opzione di gruppo', function () use ($c) {
    $figlie = $c['cesto-confermato']['figlie'] ?? [];

    return $figlie !== [] && count(array_filter($figlie, static fn (array $f): bool => (int) $f['bundle_option_id'] > 0)) >= 1;
});

check('il reso della confezione ha una figlia che non rientra («difettoso») e una che rientra', function () use ($cesti) {
    $restock = array_map(static fn (array $r): string => (string) $r['restock'], $cesti['reso_figlie'] ?? []);

    return ($cesti['resi'] ?? 0) === 1 && DemoCode::is((string) ($cesti['reso_codice'] ?? ''))
        && in_array('false', $restock, true) && in_array('true', $restock, true)
        && count($cesti['reso_madre'] ?? []) === 1;
});

check('clear toglie anche gli ordini «cesto»: niente righe né resi, giacenza di prima, nessuna chiave esterna', fn () =>
    ($cesti['tolti'] ?? 0) >= 11 && ($cesti['resti'] ?? 1) === 0
    && ($cesti['prima'] ?? null) !== [] && ($cesti['prima'] ?? null) == ($cesti['dopo'] ?? 'x')
    && ($cesti['errore'] ?? 'no') === '' && ($cesti['catalogo'] ?? 0) > 0
);

// Con «returns» spenta la demo non fa resi.
$senzaResi = null;
$senzaPersonalizzazioni = null;

try {
    Transaction::run(static function () use (&$senzaResi, &$senzaPersonalizzazioni, $righe, $ordine): void {
        accendiFunzionalita(['orders']);
        spegniFunzionalita(['coupons']);
        // Spente per davvero: il sito di prova può averle accese.
        spegniFunzionalita(['customizations']);
        sqlModify(Feature::$table, ['enabled' => 'false'], 'feature_key', 'returns');
        Wonder\Plugin\Gestionale\Gestionale::reset();
        OrdersDemo::clear();
        CatalogDemo::clear();
        ContactsDemo::clear();
        ContactsDemo::create();
        CatalogDemo::create();
        OrdersDemo::create();
        $evaso = (int) ($ordine('evaso')['id'] ?? 0);
        $senzaResi = count($righe(SalesReturn::class, "order_id = {$evaso}"));
        $idDemo = array_map(static fn (array $o): int => (int) $o['id'], $righe(Order::class, "code LIKE 'ord\\_demo-%' AND deleted = 'false'"));
        $senzaPersonalizzazioni = $idDemo === [] ? -1 : count($righe(OrderItem::class, 'order_id IN ('.implode(',', $idDemo).") AND customization <> ''"));

        throw new Annulla();
    });
} catch (Annulla) {
} finally {
    Wonder\Plugin\Gestionale\Gestionale::reset();
    $foto->ripristina();
}

check('con i resi spenti l\'ordine evaso non ne ha', fn () => $senzaResi === 0);

check('con le personalizzazioni spente nessuna riga ne ha una', fn () => $senzaPersonalizzazioni === 0);

summary();
