<?php

use Illuminate\Support\Facades\Route;

use App\Modules\Payment\Controllers\OnlinePaymentController;

/*
|--------------------------------------------------------------------------
| Online Payment API Routes
|--------------------------------------------------------------------------
|
| These routes are protected by auth:sanctum from routes/api.php.
|
| Online payments are transactions handled through external payment
| providers such as:
|
| - Chapa
| - Telebirr
|
| Common payment operations such as listing payments, showing a
| payment, and downloading a receipt belong to payment.php.
|
| Cash payments belong to cash-payment.php.
|
| Manual bank transfers belong to bank-transfer.php.
|
*/


Route::prefix('online-payments')->group(function () {

    // ============================================================
    // INITIALIZE ONLINE PAYMENT
    // ============================================================

    /*
    |--------------------------------------------------------------------------
    | Initialize Online Payment
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/online-payments/initialize
    |
    | Workflow:
    |
    |     Invoice
    |         ↓
    |     Customer selects online payment
    |         ↓
    |     Backend validates invoice
    |         ↓
    |     Backend validates amount
    |         ↓
    |     Backend validates payment provider
    |         ↓
    |     Create local payment
    |         ↓
    |     Call Chapa / Telebirr
    |         ↓
    |     Return checkout URL
    |
    | The payment is NOT considered officially collected at this
    | point.
    |
    */
    Route::post('/initialize', [
        OnlinePaymentController::class,
        'initialize',
    ])->name('online-payments.initialize');


    // ============================================================
    // CHECK ONLINE PAYMENT STATUS
    // ============================================================

    /*
    |--------------------------------------------------------------------------
    | Check Online Payment Status
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/online-payments/{payment}/status
    |
    | This checks the current state of an online payment.
    |
    | The backend may:
    |
    | - inspect the local payment
    | - verify the transaction with the provider
    | - update the payment status
    | - return the current payment state
    |
    | The browser return URL must NOT be treated as proof of payment.
    |
    */
    Route::get('/{payment}/status', [
        OnlinePaymentController::class,
        'status',
    ])
        ->whereUuid('payment')
        ->name('online-payments.status');
});