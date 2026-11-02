<?php

use Illuminate\Support\Facades\Route;

use App\Modules\Payment\Controllers\PaymentManagementController;

Route::prefix('payments')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Administrative Payment List
    |--------------------------------------------------------------------------
    |
    | Lists payments for authorized municipal staff.
    |
    */

    Route::get('/', [
        PaymentManagementController::class,
        'index',
    ])->name('payments.index');


    /*
    |--------------------------------------------------------------------------
    | Payment Details
    |--------------------------------------------------------------------------
    |
    | Returns the complete administrative view of a payment.
    |
    */

    Route::get('/{payment}', [
        PaymentManagementController::class,
        'show',
    ])
        ->whereUuid('payment')
        ->name('payments.show');


    /*
    |--------------------------------------------------------------------------
    | Official Payment Receipt
    |--------------------------------------------------------------------------
    |
    | Returns the official receipt record belonging to a
    | completed payment.
    |
    | This endpoint does not create a receipt.
    |
    */

    Route::get('/{payment}/receipt', [
        PaymentManagementController::class,
        'receipt',
    ])
        ->whereUuid('payment')
        ->name('payments.receipt');


    /*
    |--------------------------------------------------------------------------
    | Payment Receipt PDF Download
    |--------------------------------------------------------------------------
    |
    | Downloads the existing official receipt as an A4 PDF.
    |
    */

    Route::get('/{payment}/receipt/pdf', [
        PaymentManagementController::class,
        'receiptPdf',
    ])
        ->whereUuid('payment')
        ->name('payments.receipt.pdf');


    /*
    |--------------------------------------------------------------------------
    | Payment Receipt PDF Stream
    |--------------------------------------------------------------------------
    |
    | Opens the existing official receipt PDF in the browser.
    |
    */

    Route::get('/{payment}/receipt/pdf/stream', [
        PaymentManagementController::class,
        'receiptPdfStream',
    ])
        ->whereUuid('payment')
        ->name('payments.receipt.pdf.stream');
});


/*
|--------------------------------------------------------------------------
| Channel-Specific Payment Routes
|--------------------------------------------------------------------------
|
| Each payment channel owns its own transaction workflow.
|
*/

require __DIR__ . '/online-payment.php';

require __DIR__ . '/cash-payment.php';

require __DIR__ . '/bank-transfer.php';