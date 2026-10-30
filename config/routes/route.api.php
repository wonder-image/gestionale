<?php

use Wonder\App\Permission\Access;
use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;

// Risorsa OAuth (RFC 9728): il framework espone i metadati e accetta i token wio_at_.
// Con la funzionalità `mcp` spenta i metadati non annunciano la risorsa.
// Gli scope (per area, lettura o scrittura) limitano un token sotto i permessi dell'utente.
if (Gestionale::feature('mcp')) {
    \Wonder\OAuth\OAuth::resource('/api/gestionale/mcp', 'Gestionale', \Wonder\Plugin\Gestionale\Support\Mcp\Tools::scopes());
}

// Gli eventi Stripe del modulo, oltre a quelli del core: li legge «Collega webhook».
\Wonder\Plugin\Stripe\Connect::addEvents(\Wonder\Plugin\Gestionale\Support\Payments\PaymentEvents::types());

Route::area('api')->response('json')->frontend()->translatable(false)
    ->name('api.gestionale.')->prefix('/gestionale')->group(function () {
        // Niente sessione e niente CSRF: vale la firma di Stripe sul corpo.
        Route::post('/stripe/webhook/', Gestionale::handlerPath('api/stripe/webhook.php'))
            ->name('stripe.webhook');
        // Server MCP: niente sessione, vale il token di `api_users`; i
        // permessi si controllano strumento per strumento.
        Route::post('/mcp/', Gestionale::handlerPath('api/mcp.php'))->name('mcp');
        // GET risponde 405 con `Allow: POST`: la specifica Streamable HTTP lo
        // chiede a chi non apre lo stream SSE. Senza rotta sarebbe un 404.
        Route::get('/mcp/', Gestionale::handlerPath('api/mcp.php'))->name('mcp.get');
        // API REST: ogni rotta è uno strumento MCP, con gli stessi permessi e
        // scope. Attive con il gestionale, anche con la funzionalità `mcp` spenta.
        foreach (\Wonder\Plugin\Gestionale\Support\Api\Rest::routes() as $name => $route) {
            $definition = Route::{strtolower($route['method'])}(
                \Wonder\Plugin\Gestionale\Support\Api\Rest::PREFIX.$route['path'],
                Gestionale::handlerPath('api/v1.php'),
                ['rest' => $name]
            )->name('v1.'.$name);

            if (isset($route['id'])) {
                $definition->where('id', '[0-9]+');
            }
        }
    });

// Il backend del gestionale: con la sessione e i permessi delle pagine.
// Il CSRF lo controlla il core sulle POST (header `X-WI-CSRF`): senza token, 403.
Route::area('api')->response('json')->backend()->translatable(false)->csrf()
    ->name('api.gestionale.backend.')->prefix('/gestionale/backend')->group(function () {
        Route::get('/magazzino/versioni/', Gestionale::handlerPath('api/stock/versions.php'))
            ->name('stock.versions')->guarded()->permit(Access::roles(Gestionale::area('magazzino'), 'create'));
        // Gli articoli da collegare nella scheda (Articoli collegati).
        Route::get('/catalogo/articoli/', Gestionale::handlerPath('api/catalog/models.php'))
            ->name('catalog.models')->guarded()->permit(Access::roles(Gestionale::area('catalogo'), 'view'));
        // G9: le ricerche della pagina *Nuovo ordine*.
        Route::get('/vendite/versioni/', Gestionale::handlerPath('api/sales/versions.php'))
            ->name('sales.versions')->guarded()->permit(Access::roles(Gestionale::area('vendite'), 'create'));
        Route::get('/vendite/clienti/', Gestionale::handlerPath('api/sales/customers.php'))
            ->name('sales.customers')->guarded()->permit(Access::roles(Gestionale::area('vendite'), 'create'));
        Route::post('/vendite/clienti/salva/', Gestionale::handlerPath('api/sales/customer-save.php'))
            ->name('sales.customer_save')->guarded()->permit(Access::roles(Gestionale::area('vendite'), 'create'));
        Route::post('/vendite/spedizione/', Gestionale::handlerPath('api/sales/shipping-quote.php'))
            ->name('sales.shipping_quote')->guarded()->permit(Access::roles(Gestionale::area('vendite'), 'create'));
    });
