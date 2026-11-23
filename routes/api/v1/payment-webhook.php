
<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Payment\Controllers\PaymentWebhookController;
use App\Modules\Payment\Controllers\OnlinePaymentController;

/*
|--------------------------------------------------------------------------
| External Payment Provider Routes
|--------------------------------------------------------------------------
|
| These endpoints are public because payment providers must be able
| to communicate with our application without Sanctum authentication.
|
*/

Route::prefix('payments')->name('payments.')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Chapa
    |--------------------------------------------------------------------------
    */

    Route::prefix('webhooks')->name('webhooks.')->group(function () {

        Route::post('/chapa', [
            PaymentWebhookController::class,
            'chapa',
        ])->name('chapa');

        Route::post('/telebirr', [
            PaymentWebhookController::class,
            'telebirr',
        ])->name('telebirr');

    });

    Route::prefix('callback')->name('callback.')->group(function () {

        Route::get('/chapa', [
            PaymentWebhookController::class,
            'chapaCallback',
        ])->name('chapa');

        Route::get('/telebirr', [
            PaymentWebhookController::class,
            'telebirrCallback',
        ])->name('telebirr');

    });

    /*
    |--------------------------------------------------------------------------
    | Payment Result
    |--------------------------------------------------------------------------
    |
    | Shared by Chapa and Telebirr.
    | Returns the payment status stored in our database.
    |
    */

    Route::get('/{payment}/result', [
        OnlinePaymentController::class,
        'result',
    ])
        ->whereNumber('payment')
        ->name('result');

});
