<?php
/** php tests/integrazione/McpServerTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\System\McpLog;
use Wonder\Plugin\Gestionale\Support\Mcp\Caller;
use Wonder\Plugin\Gestionale\Support\Mcp\Server;
use Wonder\Plugin\Gestionale\Support\Mcp\Tools;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Tax\TaxCategories;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$admin = new Caller(0, 0, ['administrator', 'api_internal_user'], '127.0.0.1');
$soloApi = new Caller(0, 0, ['api_internal_user'], '127.0.0.1');

$chiama = static function (string $tool, array $arguments, Caller $caller) use (&$id): array {
    return Server::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], $caller);
};
$dati = static function (array $response): mixed {
    return json_decode($response['result']['content'][0]['text'] ?? 'null', true);
};
$ultimoLog = static function (): array {
    $row = McpLog::find(null, 1, 'id', 'DESC');

    return is_array($row) ? (isset($row['id']) ? $row : (array) reset($row)) : [];
};

// Una versione vendibile qualunque, con il suo articolo.
$prodotto = Product::find("`active` = 'true' AND `product_model_id` > 0", 1, 'id', 'DESC');
$prodotto = is_array($prodotto) && isset($prodotto['id']) ? $prodotto : null;

echo "Server MCP\n";

check('ogni strumento ha ruoli non vuoti', static function (): bool {
    foreach (Tools::all() as $tool) {
        if (Tools::roles($tool['permission']) === []) {
            return false;
        }
    }

    return true;
});

check('gli schemi senza proprietà restano oggetti JSON', static function (): bool {
    $json = Server::json(Tools::all()['categories_list']['schema']);

    return str_contains($json, '"properties":{}');
});

check('initialize risponde con gli strumenti', static function () use ($admin): bool {
    $r = Server::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']], $admin);

    return $r['result']['protocolVersion'] === '2025-03-26' && isset($r['result']['capabilities']['tools']);
});

check('le notifiche non hanno risposta', static fn (): bool => Server::handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $admin) === null);

check('un batch è una richiesta non valida', static fn (): bool => (Server::handle([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']], $admin)['error']['code'] ?? 0) === Server::INVALID_REQUEST);

check('metodo sconosciuto: -32601', static fn (): bool => (Server::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list'], $admin)['error']['code'] ?? 0) === Server::METHOD_NOT_FOUND);

check('senza ruoli del backend non si vede né si usa niente', static function () use ($soloApi, $chiama, $prodotto): bool {
    $list = Server::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $soloApi);
    $call = $chiama('product_get', ['id' => (int) ($prodotto['id'] ?? 1)], $soloApi);

    return $list['result']['tools'] === [] && ($call['error']['code'] ?? 0) === Server::INVALID_PARAMS;
});

check('l\'amministratore vede tutti gli strumenti', static function () use ($admin): bool {
    $list = Server::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $admin);

    return count($list['result']['tools']) === count(Tools::all());
});

if ($prodotto === null) {
    echo "  · nessun prodotto attivo: salto le prove sul catalogo\n";
    summary();
}

$pid = (int) $prodotto['id'];

check('product_get legge prezzi, giacenza e articolo', static function () use ($chiama, $dati, $admin, $pid): bool {
    $r = $dati($chiama('product_get', ['id' => $pid], $admin));

    return $r['id'] === $pid && array_key_exists('price', $r) && isset($r['stock']['quantity'], $r['model']['id']);
});

check('argomenti sbagliati: errore dello strumento, non del protocollo', static function () use ($chiama, $admin): bool {
    $r = $chiama('product_get', ['id' => 'abc'], $admin);

    return ($r['result']['isError'] ?? false) === true;
});

try {
    Transaction::run(static function () use ($chiama, $dati, $admin, $pid, $prodotto, $ultimoLog): void {
        check('product_update cambia i prezzi e registra prima e dopo', static function () use ($chiama, $dati, $admin, $pid, $ultimoLog): bool {
            $r = $dati($chiama('product_update', ['id' => $pid, 'price' => 123.45, 'sale_price' => 99.9, 'allow_backorder' => true], $admin));
            $row = Product::findById($pid);
            $log = $ultimoLog();

            return $r['price'] === 123.45 && (float) $row['price'] === 123.45 && (float) $row['sale_price'] === 99.9
                && $row['allow_backorder'] === 'true'
                && $log['tool'] === 'product_update' && (int) $log['subject_id'] === $pid && $log['success'] === 'true'
                && (json_decode($log['state_after'], true)['price'] ?? null) === 123.45
                && array_key_exists('price', (array) json_decode($log['state_before'], true));
        });

        check('product_update rifiuta lo sconto non minore del prezzo e lo registra', static function () use ($chiama, $admin, $pid, $ultimoLog): bool {
            $r = $chiama('product_update', ['id' => $pid, 'sale_price' => 500], $admin);
            $log = $ultimoLog();

            return ($r['result']['isError'] ?? false) === true && (float) Product::findById($pid)['sale_price'] === 99.9
                && $log['success'] === 'false' && (int) $log['subject_id'] === $pid;
        });

        check('sale_price null toglie lo sconto', static function () use ($chiama, $dati, $admin, $pid): bool {
            $r = $dati($chiama('product_update', ['id' => $pid, 'sale_price' => null], $admin));

            return $r['sale_price'] === null;
        });

        check('stock_adjust imposta la giacenza', static function () use ($chiama, $dati, $admin, $pid): bool {
            $target = Levels::of($pid)['quantity'] + 7;
            $r = $dati($chiama('stock_adjust', ['product_id' => $pid, 'mode' => 'set', 'quantity' => $target, 'note' => 'test mcp'], $admin));

            return $r['delta'] === 7.0 && Levels::of($pid)['quantity'] === $target;
        });

        check('stock_adjust rifiuta una causale interna', static function () use ($chiama, $admin, $pid): bool {
            return ($chiama('stock_adjust', ['product_id' => $pid, 'mode' => 'add', 'quantity' => 1, 'reason' => 'batch_assignment'], $admin)['result']['isError'] ?? false) === true;
        });

        $categoria = 0;

        check('category_create crea con codice e slug', static function () use ($chiama, $dati, $admin, &$categoria): bool {
            $r = $dati($chiama('category_create', ['name' => 'Prova MCP '.uniqid()], $admin));
            $categoria = (int) ($r['id'] ?? 0);
            $row = Category::findById($categoria);

            return $categoria > 0 && str_starts_with((string) $row['code'], 'cat_') && str_starts_with($r['slug'], 'prova-mcp-');
        });

        check('category_update non può mettere una categoria dentro sé stessa', static function () use ($chiama, $admin, &$categoria): bool {
            return ($chiama('category_update', ['id' => $categoria, 'parent_id' => $categoria], $admin)['result']['isError'] ?? false) === true;
        });

        check('category_update cambia nome e lascia lo slug', static function () use ($chiama, $dati, $admin, &$categoria): bool {
            $prima = Category::findById($categoria)['slug'];
            $r = $dati($chiama('category_update', ['id' => $categoria, 'name' => 'Rinominata MCP', 'visible' => false], $admin));

            // Il nome passa da `sanitizeFirst`, come nel backend.
            return $r['name'] === 'Rinominata Mcp' && $r['slug'] === $prima && $r['visible'] === false;
        });

        check('product_set_categories imposta categorie e principale', static function () use ($chiama, $dati, $admin, $pid, &$categoria): bool {
            $r = $dati($chiama('product_set_categories', ['product_id' => $pid, 'category_ids' => [$categoria]], $admin));

            return count($r['categories']) === 1 && $r['categories'][0]['id'] === $categoria && $r['categories'][0]['main'] === true;
        });

        $nuovo = 0;

        check('product_create crea articolo, opzione e versione con i valori di serie', static function () use ($chiama, $dati, $admin, &$categoria, &$nuovo): bool {
            $r = $dati($chiama('product_create', ['name' => 'Articolo MCP '.uniqid(), 'price' => 12.5, 'sku' => 'MCP-'.uniqid(), 'category_ids' => [$categoria]], $admin));
            $nuovo = (int) ($r['id'] ?? 0);
            $model = ProductModel::findById((int) ($r['model']['id'] ?? 0));

            return $nuovo > 0 && $r['price'] === 12.5 && $r['active'] === true
                && str_starts_with((string) $model['code'], 'mod_') && $model['unit'] === 'pz' && $model['type'] === 'simple'
                && (int) $model['tax_category_id'] === TaxCategories::defaultId()
                && $r['model']['visible'] === true && $r['model']['visible_online'] === true
                && $r['model']['categories'][0]['id'] === $categoria && $r['model']['categories'][0]['main'] === true;
        });

        check('product_create vuole nome e prezzo', static function () use ($chiama, $admin): bool {
            return ($chiama('product_create', ['name' => 'Senza prezzo'], $admin)['result']['isError'] ?? false) === true
                && ($chiama('product_create', ['price' => 3], $admin)['result']['isError'] ?? false) === true;
        });

        check('product_create rifiuta unità, IVA e prezzo scontato sbagliati', static function () use ($chiama, $admin): bool {
            $errore = static fn (array $extra): bool => ($chiama('product_create', ['name' => 'Errata', 'price' => 10] + $extra, $admin)['result']['isError'] ?? false) === true;

            return $errore(['unit' => 'casse']) && $errore(['tax_category_id' => 999999]) && $errore(['sale_price' => 10]);
        });

        check('products_list elenca senza testo e filtra per categoria', static function () use ($chiama, $dati, $admin, &$categoria, &$nuovo): bool {
            $tutti = $dati($chiama('products_list', ['limit' => 5], $admin));
            $inCategoria = $dati($chiama('products_list', ['category_id' => $categoria], $admin));
            $pagina2 = $dati($chiama('products_list', ['limit' => 1, 'offset' => 1], $admin));

            return count($tutti) <= 5 && $tutti[0]['id'] === $nuovo
                && count($inCategoria) >= 1 && in_array($nuovo, array_column($inCategoria, 'id'), true)
                && count($pagina2) === 1 && $pagina2[0]['id'] !== $nuovo;
        });

        check('product_create lascia il registro', static function () use (&$nuovo): bool {
            $log = McpLog::find(['tool' => 'product_create', 'subject_id' => $nuovo], 1, 'id', 'DESC');

            return is_array($log) && $log !== [];
        });

        check('orders_list e order_get leggono gli ordini', static function () use ($chiama, $dati, $admin): bool {
            $list = $dati($chiama('orders_list', ['limit' => 3], $admin));

            if ($list === []) {
                return true;
            }

            $order = $dati($chiama('order_get', ['id' => $list[0]['id']], $admin));

            return count($list) <= 3 && $order['id'] === $list[0]['id'] && is_array($order['items']);
        });

        check('order_action rifiuta un\'azione non disponibile', static function () use ($chiama, $admin): bool {
            $row = Order::find(['stage' => 'order', 'status' => 'cancelled'], 1, 'id', 'DESC');

            if (!is_array($row) || !isset($row['id'])) {
                return true;
            }

            return ($chiama('order_action', ['order_id' => (int) $row['id'], 'action' => 'confirm'], $admin)['result']['isError'] ?? false) === true;
        });

        check('order_action esegue un\'azione disponibile', static function () use ($chiama, $dati, $admin): bool {
            foreach ((array) $dati($chiama('orders_list', ['limit' => 50], $admin)) as $order) {
                $action = $order['available_actions'][0] ?? null;

                if ($action === null) {
                    continue;
                }

                $r = $chiama('order_action', ['order_id' => $order['id'], 'action' => $action], $admin);

                return ($r['result']['isError'] ?? true) === false;
            }

            echo "    · nessun ordine con azioni disponibili\n";

            return true;
        });

        throw new Annulla();
    });
} catch (Annulla) {
    // Tutto torna com'era.
}

check('il database è tornato com\'era', static fn (): bool => (string) Product::findById($pid)['price'] === (string) $prodotto['price']);

summary();
