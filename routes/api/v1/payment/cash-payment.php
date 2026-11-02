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
|     PENDING
|         ↓
|     Cash payment completed
|         ↓
|     COMPLETED
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
    | The payment is initially created as PENDING.
    |
    | The backend determines:
    |
    | - authenticated user
    | - payment number
    | - transaction reference
    | - payment status
    | - processed_by
    | - cash_received_at
    |
    | The frontend must NOT submit these audit/system fields.
    |
    */
    Route::post('/', [
        CashPaymentController::class,
        'store',
    ])->name('cash-payments.store');


    // ============================================================
    // COMPLETE CASH PAYMENT
    // ============================================================

    /*
    |--------------------------------------------------------------------------
    | Complete Cash Payment
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/cash-payments/{payment}/complete
    |
    | Completes a pending cash payment.
    |
    | Workflow:
    |
    |     PENDING
    |         ↓
    |     Complete
    |         ↓
    |     COMPLETED
    |         ↓
    |     Invoice updated
    |         ↓
    |     Receipt generated
    |
    | This endpoint should be idempotent where possible. If the
    | payment is already COMPLETED, the backend can return the
    | existing completed payment rather than creating another
    | financial transaction.
    |
    */
    Route::post('/{payment}/complete', [
        CashPaymentController::class,
        'complete',
    ])
        ->whereUuid('payment')
        ->name('cash-payments.complete');
});