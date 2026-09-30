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

    /*
    |--------------------------------------------------------------------------
    | List Payments
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/payments
    |
    | Returns payments available to the authenticated user according
    | to the application's authorization rules.
    |
    */
    Route::get('/', [
        PaymentController::class,
        'index',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Show Payment
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/payments/{payment}
    |
    */
    Route::get('/{payment}', [
        PaymentController::class,
        'show',
    ])->whereUuid('payment');

    /*
    |--------------------------------------------------------------------------
    | Download Payment Receipt
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/payments/{payment}/receipt
    |
    | Generates/downloads the official municipal payment receipt.
    |
    */
    Route::get('/{payment}/receipt', [
        PaymentController::class,
        'receipt',
    ])->whereUuid('payment');


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
        | The backend:
        |
        | 1. Authenticates the taxpayer.
        | 2. Validates the invoice.
        | 3. Verifies the taxpayer can pay the invoice.
        | 4. Re-checks the CURRENT invoice balance.
        | 5. Validates the requested payment amount.
        | 6. Creates a PENDING payment transaction.
        | 7. Initializes Chapa.
        | 8. Returns the checkout/redirect information.
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
        ])->whereUuid('payment');
    });
});