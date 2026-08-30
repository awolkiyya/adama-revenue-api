<?php

use Illuminate\Support\Facades\Route;

use App\Modules\Payment\Controllers\PaymentController;

/*
|--------------------------------------------------------------------------
| Payment API Routes
|--------------------------------------------------------------------------
|
| These routes are protected by auth:sanctum from routes/api.php.
|
*/

Route::prefix('payments')->group(function () {

    // ============================================================
    // GENERAL PAYMENTS
    // ============================================================

    Route::get('/', [
        PaymentController::class,
        'index',
    ]);

    Route::get('/{payment}', [
        PaymentController::class,
        'show',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Download Payment Receipt
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/payments/{payment}/receipt
    |
    | Generates/downloads the official municipal receipt.
    |
    */

    Route::get('/{payment}/receipt', [
        PaymentController::class,
        'receipt',
    ]);


    // ============================================================
    // CHAPA
    // ============================================================

    Route::prefix('chapa')->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Initialize Chapa Payment
        |--------------------------------------------------------------------------
        |
        | POST /api/v1/payments/chapa/initialize
        |
        */

        Route::post('/initialize', [
            PaymentController::class,
            'initialize',
        ]);


        /*
        |--------------------------------------------------------------------------
        | Check Chapa Payment Status
        |--------------------------------------------------------------------------
        |
        | GET /api/v1/payments/chapa/{payment}/status
        |
        */

        Route::get('/{payment}/status', [
            PaymentController::class,
            'status',
        ]);
    });
});