<?php

use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;

Route::area('api')->response('json')->frontend()->translatable(false)
    ->name('api.gestionale.')->prefix('/gestionale')->group(function () {
        // Niente sessione e niente CSRF: vale la firma di Stripe sul corpo.
        Route::post('/stripe/webhook/', Gestionale::handlerPath('api/stripe/webhook.php'))
            ->name('stripe.webhook');
    });
