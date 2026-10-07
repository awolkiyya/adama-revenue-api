<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Payment\Controllers\PaymentWebhookController;
use App\Modules\Payment\Controllers\OnlinePaymentController;

/*
|--------------------------------------------------------------------------
| Payment Provider Routes
|--------------------------------------------------------------------------
|
| These routes are called by external payment providers or the
| customer's browser after completing the payment.
|
| IMPORTANT:
| Webhook and browser callback routes must NOT be placed inside
| auth:sanctum because Chapa must be able to reach them.
|
*/

Route::prefix('payments')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | CHAPA SERVER-TO-SERVER WEBHOOK
    |--------------------------------------------------------------------------
    |
    | Chapa sends payment notifications to this endpoint.
    |
    | Method:
    | POST
    |
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
    | The customer's browser is redirected here after checkout.
    |
    | Method:
    | GET
    |
    | URL:
    | /api/v1/payments/callback/chapa
    |
    */

    Route::get('/callback/chapa', [
        PaymentWebhookController::class,
        'chapaCallback',
    ]);


    /*
    |--------------------------------------------------------------------------
    | Online Payment Result
    |--------------------------------------------------------------------------
    |
    | This endpoint is used by the frontend payment-result page to retrieve
    | the authoritative payment status from our database.
    |
    | IMPORTANT:
    | The frontend must NOT decide whether the payment succeeded.
    |
    | Laravel gets the status from the Payment record, which is finalized
    | by PaymentVerificationService after provider verification.
    |
    | Method:
    | GET
    |
    | URL:
    | /api/v1/online-payments/{payment}/result
    |
    | This route is intentionally outside auth:sanctum if the public
    | payment-result page must work after returning from Chapa.
    |
    */

    Route::get('/{payment}/result', [
        OnlinePaymentController::class,
        'result',
    ]);

});

