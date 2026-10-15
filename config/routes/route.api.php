<?php

use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;

// Risorsa OAuth (RFC 9728): il framework espone i metadati e accetta i token wio_at_.
\Wonder\OAuth\OAuth::resource('/api/gestionale/mcp', 'Gestionale');

Route::area('api')->response('json')->frontend()->translatable(false)
    ->name('api.gestionale.')->prefix('/gestionale')->group(function () {
        // Niente sessione e niente CSRF: vale la firma di Stripe sul corpo.
        Route::post('/stripe/webhook/', Gestionale::handlerPath('api/stripe/webhook.php'))
            ->name('stripe.webhook');
        // Server MCP: niente sessione, vale il token di `api_users`; i
        // permessi si controllano strumento per strumento.
        Route::post('/mcp/', Gestionale::handlerPath('api/mcp.php'))->name('mcp');
        Route::get('/mcp/', Gestionale::handlerPath('api/mcp.php'))->name('mcp.get');
    });

// Il backend del gestionale: con la sessione e i permessi delle pagine.
Route::area('api')->response('json')->backend()->translatable(false)
    ->name('api.gestionale.backend.')->prefix('/gestionale/backend')->group(function () {
        Route::get('/magazzino/versioni/', Gestionale::handlerPath('api/stock/versions.php'))
            ->name('stock.versions')->guarded()->permit(['admin', 'administrator']);
        // G9: le ricerche della pagina *Nuovo ordine*.
        Route::get('/vendite/versioni/', Gestionale::handlerPath('api/sales/versions.php'))
            ->name('sales.versions')->guarded()->permit(['admin', 'administrator']);
        Route::get('/vendite/clienti/', Gestionale::handlerPath('api/sales/customers.php'))
            ->name('sales.customers')->guarded()->permit(['admin', 'administrator']);
        Route::post('/vendite/clienti/salva/', Gestionale::handlerPath('api/sales/customer-save.php'))
            ->name('sales.customer_save')->guarded()->permit(['admin', 'administrator']);
        Route::post('/vendite/spedizione/', Gestionale::handlerPath('api/sales/shipping-quote.php'))
            ->name('sales.shipping_quote')->guarded()->permit(['admin', 'administrator']);
    });
