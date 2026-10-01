<?php
/** php tests/integrazione/StockMovementBackendTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
// I filtri disegnano i menu con `select()` del backend, che fuori dal backend non
// c'è e che non si può caricare (ridefinisce `check()` dell'harness): ne basta una
// che restituisca un segnaposto, qui conta che la tabella si costruisca.
if (!function_exists('Wonder\\Backend\\Filter\\select')) {
    eval('namespace Wonder\\Backend\\Filter; function select(...$args): string { return \'<select></select>\'; }');
}

require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderActionResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
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

    StockMovementResource::reset();

    return $esito;
}

/** Un ordine con due pezzi prenotati e poi confermato: nasce un movimento di vendita. */
function venditaDiProva(): array
{
    $sku = 'TST-MOV-'.substr((string) microtime(true), -6);
    $prodotto = articoloConGiacenza(5, $sku);
    $ordine = ordineDiProva(40.0);
    Order::update(['email' => 'cliente@example.com', 'order_number' => '2026/9'.substr((string) microtime(true), -4)], $ordine);
    $riga = OrderItem::create([
        'order_id' => $ordine, 'type' => 'product', 'product_id' => $prodotto, 'position' => 1,
        'name' => 'Crema', 'quantity' => '2.000', 'unit_price' => '20.00', 'line_total' => '40.00',
    ]);
    Allocation::reserve([
        'product_id' => $prodotto, 'quantity' => 2, 'order_id' => $ordine,
        'order_item_id' => (int) ($riga->insert_id ?? 0), 'expires_at' => '',
    ]);

    Mailer::useTransport(static fn (): bool => true);

    try {
        OrderActionResource::run('confirm', $ordine, 0);
    } finally {
        Mailer::useTransport(null);
    }

    return [$ordine, $prodotto, (string) Order::findById($ordine)['order_number']];
}

/** Il movimento di vendita più recente di una versione. */
function movimentoDi(int $prodotto, string $tipo): array
{
    $righe = StockMovement::find(['product_id' => $prodotto, 'type' => $tipo], null, 'id', 'DESC');

    return isset($righe['id']) ? $righe : (array) ($righe[0] ?? []);
}

/** Le celle di una riga, per nome di colonna. */
function celleDi(array $riga): array
{
    $celle = [];

    foreach (StockMovementResource::tableSchema() as $colonna) {
        $f = $colonna->getSchema('formatter');
        $celle[(string) $colonna->name] = is_callable($f) ? (string) $f($riga) : null;
    }

    return $celle;
}

check('un movimento nato da un ordine mostra il numero dell\'ordine, con il link', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto, $numero] = venditaDiProva();
        $movimento = movimentoDi($prodotto, 'sale');
        $celle = celleDi($movimento);

        return ($movimento['reference_type'] ?? '') === 'order'
            && (int) $movimento['reference_id'] === $ordine
            && str_contains($celle['type'], 'Vendita')
            && str_contains($celle['type'], htmlspecialchars($numero, ENT_QUOTES))
            && str_contains($celle['type'], 'href="'.htmlspecialchars(OrderResource::detailUrl($ordine), ENT_QUOTES).'"');
    });
});

check('la cella della versione dice «Articolo — Versione»', function () {
    return prova(static function (): bool {
        [, $prodotto] = venditaDiProva();
        $celle = celleDi(movimentoDi($prodotto, 'sale'));

        return stripos($celle['product_id'], 'Prova vendite') !== false && $celle['product_id'] !== '—';
    });
});

check('Prima, Pezzi e Dopo raccontano la vendita: 5, -2, 3', function () {
    return prova(static function (): bool {
        [, $prodotto] = venditaDiProva();
        $celle = celleDi(movimentoDi($prodotto, 'sale'));

        return $celle['quantity_before'] === '5' && $celle['quantity'] === '-2' && $celle['quantity_after'] === '3';
    });
});

check('un movimento senza utente dice «Sistema», uno con un utente vero ne dice il nome', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(1, 'TST-MOV-U'.substr((string) microtime(true), -6));
        Stock::apply(['product_id' => $prodotto, 'quantity' => 1, 'type' => 'adjustment', 'reason' => 'inventory', 'source' => 'system']);
        $senza = celleDi(movimentoDi($prodotto, 'adjustment'));

        $utente = Wonder\App\Models\User\User::find([], 1);
        $utente = isset($utente['id']) ? $utente : (array) ($utente[0] ?? []);

        if ($utente === []) {
            return $senza['user_id'] === 'Sistema';
        }

        Stock::apply(['product_id' => $prodotto, 'quantity' => 1, 'type' => 'adjustment', 'reason' => 'inventory', 'user_id' => (int) $utente['id'], 'source' => 'user']);
        $con = celleDi(movimentoDi($prodotto, 'adjustment'));
        $nome = trim(trim((string) ($utente['name'] ?? '')).' '.trim((string) ($utente['surname'] ?? '')));
        $atteso = $nome !== '' ? $nome : trim((string) ($utente['username'] ?? ''));

        return $senza['user_id'] === 'Sistema' && ($atteso === '' || str_contains($con['user_id'], htmlspecialchars($atteso, ENT_QUOTES)));
    });
});

check('un movimento senza documento non ha né numero né link', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(3, 'TST-MOV-D'.substr((string) microtime(true), -6));
        $movimento = movimentoDi($prodotto, 'purchase');
        $celle = celleDi($movimento);

        return ($movimento['reference_type'] ?? '') === '' && $celle['type'] === 'Carico' && !str_contains($celle['type'], '<a');
    });
});

check('un ordine sparito non rompe la cella: resta l\'etichetta', function () {
    return prova(static function (): bool {
        $celle = celleDi(['type' => 'sale', 'reference_type' => 'order', 'reference_id' => 999999999, 'product_id' => 0, 'source' => 'system', 'user_id' => 0]);

        return $celle['type'] === 'Vendita';
    });
});

check('la tabella della scheda della versione si costruisce con le colonne scelte', function () {
    return prova(static function (): bool {
        $html = (string) StockMovementResource::backendTable(ProductResource::stockHistoryColumns())->generate(false);

        return str_contains($html, '<table');
    });
});

check('l\'elenco con ?versione= si costruisce, titolo compreso', function () {
    return prova(static function (): bool {
        [, $prodotto] = venditaDiProva();
        $_GET['versione'] = (string) $prodotto;

        try {
            $titolo = (string) (StockMovementResource::tableLayoutSchema()->toArray()['title']['text'] ?? '');
            $html = (string) StockMovementResource::backendTable()->generate(false);
        } finally {
            unset($_GET['versione']);
        }

        return stripos($titolo, 'Movimenti di Prova vendite') === 0 && str_contains($html, '<table');
    });
});

summary();
