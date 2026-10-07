<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Taxpayer\Controllers\TaxpayerDashboardController;
use App\Modules\Taxpayer\Controllers\TaxpayerInvoiceController;
use App\Modules\Taxpayer\Controllers\TaxpayerPaymentController;
use App\Modules\Taxpayer\Controllers\TaxpayerNotificationController;


/*
|--------------------------------------------------------------------------
| Taxpayer Routes
|--------------------------------------------------------------------------
|
| These routes are for authenticated taxpayers only.
|
| Authentication is handled by the application's existing Auth module.
|
| Base URL:
|
|     /api/v1/taxpayer
|
*/


Route::prefix('taxpayer')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Dashboard
        |--------------------------------------------------------------------------
        |
        | GET /api/v1/taxpayer/dashboard
        |
        */

        Route::get(
            'dashboard',
            [TaxpayerDashboardController::class, 'index']
        )->name('taxpayer.dashboard');


        /*
        |--------------------------------------------------------------------------
        | Invoices
        |--------------------------------------------------------------------------
        */

        Route::prefix('invoices')
            ->group(function () {

                /*
                | GET /api/v1/taxpayer/invoices
                */

                Route::get(
                    '/',
                    [TaxpayerInvoiceController::class, 'index']
                )->name('taxpayer.invoices.index');


                /*
                | GET /api/v1/taxpayer/invoices/{invoice}
                */

                Route::get(
                    '{invoice}',
                    [TaxpayerInvoiceController::class, 'show']
                )->name('taxpayer.invoices.show');
            });




        /*
        |--------------------------------------------------------------------------
        | Payments
        |--------------------------------------------------------------------------
        */

        Route::prefix('payments')
            ->group(function () {

                /*
                | GET /api/v1/taxpayer/payments
                */

                Route::get(
                    '/',
                    [TaxpayerPaymentController::class, 'index']
                )->name('taxpayer.payments.index');


                /*
                | GET /api/v1/taxpayer/payments/{payment}
                */

                Route::get(
                    '{payment}',
                    [TaxpayerPaymentController::class, 'show']
                )->name('taxpayer.payments.show');


                /*
                | GET /api/v1/taxpayer/payments/{payment}/receipt
                */

                Route::get(
                    '{payment}/receipt',
                    [TaxpayerPaymentController::class, 'receipt']
                )->name('taxpayer.payments.receipt');
            });


        /*
        |--------------------------------------------------------------------------
        | Notifications
        |--------------------------------------------------------------------------
        */

        Route::prefix('notifications')
            ->group(function () {

                /*
                | GET /api/v1/taxpayer/notifications
                */

                Route::get(
                    '/',
                    [TaxpayerNotificationController::class, 'index']
                )->name('taxpayer.notifications.index');


                /*
                | POST /api/v1/taxpayer/notifications/read-all
                */

                Route::post(
                    'read-all',
                    [TaxpayerNotificationController::class, 'markAllAsRead']
                )->name('taxpayer.notifications.read-all');


                /*
                | POST /api/v1/taxpayer/notifications/{notification}/read
                */

                Route::post(
                    '{notification}/read',
                    [TaxpayerNotificationController::class, 'markAsRead']
                )->name('taxpayer.notifications.read');
            });   
 });