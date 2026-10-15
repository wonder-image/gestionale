<?php

use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;

Route::area('api')->response('json')->frontend()->translatable(false)
    ->name('api.gestionale.')->prefix('/gestionale')->group(function () {
        // Niente sessione e niente CSRF: vale la firma di Stripe sul corpo.
        Route::post('/stripe/webhook/', Gestionale::handlerPath('api/stripe/webhook.php'))
            ->name('stripe.webhook');
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
    });
