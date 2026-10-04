<?php

use Illuminate\Support\Facades\Route;

use App\Modules\Payment\Controllers\PaymentController;
use App\Modules\Payment\Controllers\PaymentManagementController;

Route::prefix('payments')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Administrative Payment List
    |--------------------------------------------------------------------------
    */

    Route::get('/', [
        PaymentManagementController::class,
        'index',
    ])->name('payments.index');


    /*
    |--------------------------------------------------------------------------
    | Payment Details
    |--------------------------------------------------------------------------
    */

    Route::get('/{payment}', [
        PaymentController::class,
        'show',
    ])
        ->whereUuid('payment')
        ->name('payments.show');


    /*
    |--------------------------------------------------------------------------
    | Payment Receipt
    |--------------------------------------------------------------------------
    */

    Route::get('/{payment}/receipt', [
        PaymentController::class,
        'receipt',
    ])
        ->whereUuid('payment')
        ->name('payments.receipt');
});


/*
|--------------------------------------------------------------------------
| Channel-Specific Routes
|--------------------------------------------------------------------------
*/

require __DIR__ . '/online-payment.php';

require __DIR__ . '/cash-payment.php';

require __DIR__ . '/bank-transfer.php';