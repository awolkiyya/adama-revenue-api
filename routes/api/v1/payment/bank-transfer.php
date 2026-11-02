<?php

use Illuminate\Support\Facades\Route;

use App\Modules\Payment\Controllers\BankTransferController;

/*
|--------------------------------------------------------------------------
| Bank Transfer Payment API Routes
|--------------------------------------------------------------------------
|
| These routes are protected by auth:sanctum from routes/api.php.
|
| Manual bank transfers are NOT online payment-provider transactions.
|
| This file contains ONLY bank-transfer-specific operations.
|
| Common payment operations such as:
|
| - listing payments
| - showing a payment
| - downloading a receipt
|
| are handled by payment.php.
|
| Bank transfer workflow:
|
|     Invoice
|         ↓
|     Taxpayer makes bank transfer
|         ↓
|     Submit transfer
|         ↓
|     PENDING
|         ↓
|     Revenue Officer reviews bank evidence
|         ↓
|     ┌─────────────────────────────┐
|     │                             │
|   Verify                         Reject
|     │                             │
|     ↓                             ↓
| COMPLETED                       FAILED
|     │
|     ↓
| Invoice updated
|     ↓
| Receipt generated
|
| Payment status is controlled by PaymentStatus:
|
|     PENDING
|     PROCESSING
|     COMPLETED
|     FAILED
|     CANCELLED
|     EXPIRED
|     REVERSED
|
| Bank-specific verification details are handled by the
| BankTransferService and are NOT separate PaymentStatus values.
|
*/

Route::prefix('bank-transfers')->group(function () {

    // ============================================================
    // SUBMIT BANK TRANSFER
    // ============================================================

    /*
    |--------------------------------------------------------------------------
    | Submit Bank Transfer
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/bank-transfers
    |
    | Creates a bank-transfer payment against an invoice.
    |
    | The payment is initially created as:
    |
    |     status = PENDING
    |
    | PENDING means the payment has been recorded but has not yet
    | been financially completed.
    |
    | The backend determines system/audit fields such as:
    |
    | - authenticated user
    | - payment number
    | - transaction reference
    | - payment status
    | - submitted_at
    |
    | The frontend must NOT submit these system-controlled fields.
    |
    */
    Route::post('/', [
        BankTransferController::class,
        'store',
    ])->name('bank-transfers.store');


    // ============================================================
    // VERIFY BANK TRANSFER
    // ============================================================

    /*
    |--------------------------------------------------------------------------
    | Verify Bank Transfer
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/bank-transfers/{payment}/verify
    |
    | Used by an authorized revenue officer to verify a submitted
    | bank transfer.
    |
    | The officer verifies:
    |
    | - invoice
    | - amount
    | - bank
    | - transfer reference
    | - transfer date
    | - payer information
    | - supporting evidence
    | - bank statement / bank confirmation
    |
    | If verification succeeds:
    |
    |     PENDING
    |         ↓
    |     COMPLETED
    |
    | The completed payment is then financially counted against
    | the invoice.
    |
    | The backend can also:
    |
    | - update the invoice
    | - update the invoice balance
    | - generate the receipt
    | - record verified_by
    | - record verified_at
    |
    | Verification and payment completion are handled as one
    | controlled business operation inside BankTransferService.
    |
    | There is intentionally no separate:
    |
    |     POST /bank-transfers/{payment}/complete
    |
    | because bank-transfer completion requires successful
    | verification of the bank evidence.
    |
    */
    Route::post('/{payment}/verify', [
        BankTransferController::class,
        'verify',
    ])
        ->whereUuid('payment')
        ->name('bank-transfers.verify');


    // ============================================================
    // REJECT BANK TRANSFER
    // ============================================================

    /*
    |--------------------------------------------------------------------------
    | Reject Bank Transfer
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/bank-transfers/{payment}/reject
    |
    | Used by an authorized revenue officer when the submitted
    | bank transfer cannot be verified.
    |
    | A rejection reason is required.
    |
    | If the payment cannot be verified:
    |
    |     PENDING
    |         ↓
    |       FAILED
    |
    | The payment is NOT deleted.
    |
    | The rejection remains part of the payment audit history.
    |
    | The backend should record information such as:
    |
    | - rejected_by
    | - rejected_at
    | - rejection_reason
    |
    */
    Route::post('/{payment}/reject', [
        BankTransferController::class,
        'reject',
    ])
        ->whereUuid('payment')
        ->name('bank-transfers.reject');
});
