<?php

use App\Modules\DirectCollection\Controllers\DirectCollectionController;
use App\Modules\DirectCollection\Controllers\DirectCollectionDashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Direct Collection Routes
|--------------------------------------------------------------------------
|
| Direct Collection flow:
|
|   Taxpayer
|      ↓
|   Revenue Service
|      ↓
|   Dynamic Fields
|      ↓
|   Calculate
|      ↓
|   Create Invoice
|      ↓
|   Issue Invoice
|
|   Existing ISSUED Invoice
|      ↓
|   Edit Fields
|      ↓
|   Recalculate
|      ↓
|   Update Invoice
|
|--------------------------------------------------------------------------
*/

Route::prefix('direct-collections')
    ->name('direct-collections.')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Dashboard
        |--------------------------------------------------------------------------
        |
        | Returns the authenticated cash collector's dashboard:
        |
        | - Today's invoice summary
        | - Today's cash payment summary
        | - Today's cash collected
        | - Expected cash
        | - Reconciled cash
        | - Outstanding cash
        | - Accessible revenue services
        |
        | GET /api/v1/direct-collections/dashboard
        |
        */

        Route::get(
            '/dashboard',
            [DirectCollectionDashboardController::class, 'index']
        )->name('dashboard');

        /*
        |--------------------------------------------------------------------------
        | Calculate
        |--------------------------------------------------------------------------
        |
        | Calculates the amount without creating an invoice.
        |
        | POST /api/v1/direct-collections/calculate
        |
        */

        Route::post(
            '/calculate',
            [DirectCollectionController::class, 'calculate']
        )->name('calculate');

        /*
        |--------------------------------------------------------------------------
        | Create
        |--------------------------------------------------------------------------
        |
        | Recalculates the amount on the server, creates the invoice,
        | and issues it through InvoiceIssuanceService.
        |
        | POST /api/v1/direct-collections
        |
        */

        Route::post(
            '/',
            [DirectCollectionController::class, 'store']
        )->name('store');

        /*
        |--------------------------------------------------------------------------
        | List
        |--------------------------------------------------------------------------
        |
        | Returns paginated direct collection invoices.
        |
        | GET /api/v1/direct-collections
        |
        */

        Route::get(
            '/',
            [DirectCollectionController::class, 'index']
        )->name('index');

        /*
        |--------------------------------------------------------------------------
        | Update
        |--------------------------------------------------------------------------
        |
        | Updates an existing direct collection.
        |
        | ONLY ISSUED / PENDING-PAYMENT invoices can be edited.
        |
        | PARTIALLY_PAID → blocked
        | PAID           → blocked
        | CANCELLED      → blocked
        |
        | The amount is recalculated server-side.
        |
        | PUT /api/v1/direct-collections/{invoice}
        |
        */

        Route::put(
            '/{invoice}',
            [DirectCollectionController::class, 'update']
        )->name('update');

        /*
        |--------------------------------------------------------------------------
        | Show
        |--------------------------------------------------------------------------
        |
        | Returns one direct collection invoice.
        |
        | GET /api/v1/direct-collections/{invoice}
        |
        */

        Route::get(
            '/{invoice}',
            [DirectCollectionController::class, 'show']
        )->name('show');
    });