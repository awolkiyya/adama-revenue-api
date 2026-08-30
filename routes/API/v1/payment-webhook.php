<?php

use Illuminate\Support\Facades\Route;

use App\Modules\Payment\Controllers\PaymentWebhookController;

/*
|--------------------------------------------------------------------------
| Payment Webhook Routes
|--------------------------------------------------------------------------
|
| These routes are called by external payment providers.
|
| IMPORTANT:
| Do NOT place these routes inside auth:sanctum.
|
*/

Route::prefix('payments/webhooks')->group(function () {

    // ============================================================
    // CHAPA
    // ============================================================

    Route::post('/chapa', [
        PaymentWebhookController::class,
        'chapa',
    ]);

});