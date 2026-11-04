<?php
/** php tests/EndpointsTest.php — le convenzioni degli endpoint (#79). */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Http\Exceptions\NotFoundHttpException;

$root = dirname(__DIR__);
$rotte = (string) file_get_contents($root.'/config/routes/route.api.php');

check('il gruppo /gestionale/backend dichiara ->csrf()', function () use ($rotte) {
    return preg_match("/->csrf\(\)\s*->name\('api\.gestionale\.backend\.'\)/", $rotte) === 1;
});

check('GET /mcp/ resta registrata (405 con Allow: POST)', function () use ($root, $rotte) {
    $mcp = (string) file_get_contents($root.'/http/api/mcp.php');

    return str_contains($rotte, "Route::get('/mcp/'")
        && str_contains($mcp, "header('Allow: POST')")
        && str_contains($mcp, '$respond(405');
});

$json = array_merge(
    glob($root.'/http/api/sales/*.php') ?: [],
    glob($root.'/http/api/stock/*.php') ?: [],
    glob($root.'/http/api/catalog/*.php') ?: []
);

check('gli endpoint JSON del backend rispondono solo con la busta di ApiRequest', function () use ($json) {
    $fuori = [];

    foreach ($json as $file) {
        $codice = (string) file_get_contents($file);

        if (preg_match('/json_encode|http_response_code|\bCsrf::|\$_POST/', $codice) === 1
            || !str_contains($codice, 'ApiRequest::ok(')) {
            $fuori[] = basename(dirname($file)).'/'.basename($file);
        }
    }

    if ($fuori !== []) {
        echo '    '.implode("\n    ", $fuori)."\n";
    }

    return $json !== [] && $fuori === [];
});

check('clienti/salva e spedizione leggono anche il corpo JSON', function () use ($root) {
    foreach (['customer-save.php', 'shipping-quote.php'] as $file) {
        if (!str_contains((string) file_get_contents($root.'/http/api/sales/'.$file), 'ApiRequest::body()')) {
            return false;
        }
    }

    return true;
});

$scaricamenti = array_merge(
    glob($root.'/http/backend/sales/*pdf.php') ?: [],
    [$root.'/http/backend/sales/quote-print.php', $root.'/http/backend/stock/batch-customers.php', $root.'/http/backend/pricing/price-list-items.php']
);

check('PDF e CSV non trovati passano dalla pagina 404 del core', function () use ($scaricamenti) {
    $fuori = [];

    foreach ($scaricamenti as $file) {
        $codice = (string) file_get_contents($file);

        if (str_contains($codice, 'http_response_code(404)') || !str_contains($codice, 'throw new NotFoundHttpException()')) {
            $fuori[] = basename($file);
        }
    }

    if ($fuori !== []) {
        echo '    '.implode("\n    ", $fuori)."\n";
    }

    return $fuori === [];
});

check('il PDF di un preventivo senza id lancia NotFoundHttpException', function () use ($root) {
    $ROUTE_PARAMETERS = ['id' => 0, 'revision' => 0];

    try {
        include $root.'/http/backend/sales/quote-pdf.php';
    } catch (NotFoundHttpException $e) {
        return $e->statusCode() === 404;
    }

    return false;
});

summary();
