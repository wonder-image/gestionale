<?php
/** php tests/integrazione/OrdersDemoTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';
require __DIR__ . '/supporto/compra.php';

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
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Seeding\CatalogDemo;
use Wonder\Plugin\Gestionale\Seeding\ContactsDemo;
use Wonder\Plugin\Gestionale\Seeding\Demo;
use Wonder\Plugin\Gestionale\Seeding\DemoCode;
use Wonder\Plugin\Gestionale\Seeding\OrdersDemo;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
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
        accendiFunzionalita(['orders']);
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

summary();
