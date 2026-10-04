<?php

use Illuminate\Support\Facades\Route;

use App\Modules\Payment\Controllers\BankTransferController;

/*
|--------------------------------------------------------------------------
| Bank Transfer Payment Routes
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
| Workflow:
|
|     Taxpayer
|         ↓
|     Bank transfer
|         ↓
|     Submit transfer
|         ↓
|     PENDING_VERIFICATION
|         ↓
|     Revenue Officer
|         ↓
|     Verify bank evidence
|         ↓
|     VERIFIED
|         ↓
|     POSTED
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
    | Creates a bank-transfer payment with:
    |
    |     status = PENDING_VERIFICATION
    |
    | The payment is not officially collected until it has been
    | verified and posted.
    |
    */
    Route::post('/', [
        BankTransferController::class,
        'store',
    ])->name('bank-transfers.store');


    // ============================================================
    // PENDING BANK TRANSFERS
    // ============================================================

    /*
    |--------------------------------------------------------------------------
    | List Pending Bank Transfers
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/bank-transfers/pending
    |
    | Returns bank-transfer payments that are waiting for
    | verification by an authorized revenue officer.
    |
    | This is kept here because it is specific to the bank-transfer
    | operational workflow.
    |
    */
    Route::get('/pending', [
        BankTransferController::class,
        'pending',
    ])->name('bank-transfers.pending');


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
    | The authorized revenue officer verifies:
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
    |     PENDING_VERIFICATION
    |             ↓
    |         VERIFIED
    |             ↓
    |          POSTED
    |
    | Verification and posting are handled as one controlled
    | business operation inside BankTransferService.
    |
    | There is intentionally no separate:
    |
    |     POST /bank-transfers/{payment}/post
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
    | Used when the submitted bank transfer cannot be verified.
    |
    | A rejection reason is required.
    |
    | The payment is not deleted. The rejection remains part of the
    | payment audit history.
    |
    */
    Route::post('/{payment}/reject', [
        BankTransferController::class,
        'reject',
    ])
        ->whereUuid('payment')
        ->name('bank-transfers.reject');
});