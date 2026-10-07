<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Modules\Payment\Controllers\PaymentWebhookController;

/*
|--------------------------------------------------------------------------
| Payment Provider Routes
|--------------------------------------------------------------------------
|
| These routes are called by external payment providers or the
| customer's browser after completing the payment.
|
| IMPORTANT:
| Do NOT place these routes inside auth:sanctum.
|
*/

Route::prefix('payments')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | CHAPA SERVER-TO-SERVER WEBHOOK
    |--------------------------------------------------------------------------
    |
    | Used when Chapa sends payment notification to our backend.
    |
    | Method: POST
    | URL:
    | /api/v1/payments/webhooks/chapa
    |
    */

    Route::post('/webhooks/chapa', [
        PaymentWebhookController::class,
        'chapa',
    ]);

    /*
    |--------------------------------------------------------------------------
    | CHAPA BROWSER CALLBACK
    |--------------------------------------------------------------------------
    |
    | Used when the customer/browser is redirected back after checkout.
    |
    | Method: GET
    | URL:
    | /api/v1/payments/callback/chapa
    |
    */

    Route::get('/callback/chapa', [
        PaymentWebhookController::class,
        'chapaCallback',
    ]);

});
