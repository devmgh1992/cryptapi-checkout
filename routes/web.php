<?php

use Illuminate\Support\Facades\Route;
use Nous\CryptApiCheckout\Http\Controllers\CheckoutController;

Route::middleware(config('cryptapi-checkout.middleware', ['web']))
    ->prefix(trim((string) config('cryptapi-checkout.prefix', 'pay/crypto'), '/'))
    ->group(function () {
        Route::get('/{reference}', [CheckoutController::class, 'show'])->name('cryptapi-checkout.show');
        Route::get('/{reference}/coin/{ticker}', [CheckoutController::class, 'coin'])->name('cryptapi-checkout.coin');
        Route::get('/{reference}/status', [CheckoutController::class, 'status'])->name('cryptapi-checkout.status');
        Route::post('/{reference}/txid', [CheckoutController::class, 'txid'])->name('cryptapi-checkout.txid');
        Route::post('/{reference}/renew', [CheckoutController::class, 'renew'])->name('cryptapi-checkout.renew');

        // CryptAPI IPN
        Route::get('/callback/{reference}/{token}', [CheckoutController::class, 'callback'])->name('cryptapi-checkout.callback');
    });
