<?php

use Illuminate\Support\Facades\Route;

use App\Modules\Payment\Controllers\CashPaymentController;

/*
|--------------------------------------------------------------------------
| Cash Payment API Routes
|--------------------------------------------------------------------------
|
| These routes are protected by auth:sanctum from routes/api.php.
|
| This file contains ONLY cash-payment-specific operations.
|
| Common payment operations such as:
|
| - listing payments
| - showing a payment
| - downloading a receipt
|
| are handled by payment.php.
|
| Cash workflow:
|
|     Invoice
|         ↓
|     Taxpayer pays cash
|         ↓
|     Collector records payment
|         ↓
|     RECORDED
|         ↓
|     Cash confirmation / operational control
|         ↓
|     POSTED
|         ↓
|     Invoice updated
|         ↓
|     Receipt generated
|
*/

Route::prefix('cash-payments')->group(function () {

    // ============================================================
    // RECORD CASH PAYMENT
    // ============================================================

    /*
    |--------------------------------------------------------------------------
    | Record Cash Payment
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/cash-payments
    |
    | Records a cash payment against an invoice.
    |
    | The backend determines:
    |
    | - authenticated collector
    | - payment reference
    | - payment status
    | - recorded_by_user
    |
    | The frontend must NOT submit these audit fields.
    |
    */
    Route::post('/', [
        CashPaymentController::class,
        'store',
    ])->name('cash-payments.store');


    // ============================================================
    // POST / CONFIRM CASH PAYMENT
    // ============================================================

    /*
    |--------------------------------------------------------------------------
    | Post Cash Payment
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/cash-payments/{payment}/post
    |
    | Confirms the recorded cash payment and makes it an official
    | posted financial transaction.
    |
    | Typical workflow:
    |
    |     RECORDED
    |         ↓
    |     Physical cash confirmed
    |         ↓
    |     POST
    |         ↓
    |     POSTED
    |
    | Depending on segregation-of-duties policy, this operation may
    | be performed by the collector, cashier, or another authorized
    | revenue officer.
    |
    */
    Route::post('/{payment}/post', [
        CashPaymentController::class,
        'post',
    ])
        ->whereUuid('payment')
        ->name('cash-payments.post');
});