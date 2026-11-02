<?php

use Illuminate\Support\Facades\Route;
use App\Modules\RevenueReport\Controllers\PaymentReportController;

/*
|--------------------------------------------------------------------------
| Payment Reports
|--------------------------------------------------------------------------
|
| These routes should be placed inside your existing authenticated
| API version group if that group already provides auth:sanctum.
|
*/

Route::prefix('payment-reports')
    ->name('payment-reports.')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Payment Report Summary
        |--------------------------------------------------------------------------
        */

        Route::get('/summary', [
            PaymentReportController::class,
            'summary',
        ])->name('summary');

        /*
        |--------------------------------------------------------------------------
        | Paginated Payment Report
        |--------------------------------------------------------------------------
        */

        Route::get('/', [
            PaymentReportController::class,
            'index',
        ])->name('index');

        /*
        |--------------------------------------------------------------------------
        | Individual Payment Report
        |--------------------------------------------------------------------------
        */

        Route::get('/{payment}', [
            PaymentReportController::class,
            'show',
        ])
            ->whereNumber('payment')
            ->name('show');
});
