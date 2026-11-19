<?php

use Illuminate\Support\Facades\Route;
use App\Modules\CashReconciliation\Controllers\CashReconciliationController;

/*
|--------------------------------------------------------------------------
| Cash Reconciliation Routes
|--------------------------------------------------------------------------
|
| Permission-driven access:
| - Revenue collectors manage their own cash closings.
| - Authorized finance officers review submitted closings.
| - Users with view_all permission can view all reconciliations.
|
| These routes should be registered inside the authenticated API
| route group, or protected with auth:sanctum.
|
*/

Route::prefix('cash-reconciliations')
    ->name('cash-reconciliations.')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | List and Create
        |--------------------------------------------------------------------------
        */

        Route::get('/', [
            CashReconciliationController::class,
            'index',
        ])->name('index');

        Route::post('/', [
            CashReconciliationController::class,
            'store',
        ])->name('store');

        /*
        |--------------------------------------------------------------------------
        | Summaries
        |--------------------------------------------------------------------------
        |
        | Expected, counted and difference totals grouped by collector.
        | Collectors without view_all only receive their own figures.
        |
        | Keep this above the '/{cashReconciliation}' routes so that
        | static paths are always matched first.
        |
        */

        Route::get('/expected-by-collector', [
            CashReconciliationController::class,
            'expectedByCollector',
        ])->name('expected-by-collector');

        /*
        |--------------------------------------------------------------------------
        | Retrieve, Update and Delete
        |--------------------------------------------------------------------------
        */

        Route::get('/{cashReconciliation}', [
            CashReconciliationController::class,
            'show',
        ])->whereUuid('cashReconciliation')
            ->name('show');

        Route::match(['put', 'patch'], '/{cashReconciliation}', [
            CashReconciliationController::class,
            'update',
        ])->whereUuid('cashReconciliation')
            ->name('update');

        /*
         * Delete is restricted to DRAFT reconciliations.
         * Ownership and status must also be checked by the policy
         * and CashReconciliationService.
         */
        Route::delete('/{cashReconciliation}', [
            CashReconciliationController::class,
            'destroy',
        ])->whereUuid('cashReconciliation')
            ->name('destroy');

        /*
        |--------------------------------------------------------------------------
        | Submission
        |--------------------------------------------------------------------------
        */

        Route::post('/{cashReconciliation}/submit', [
            CashReconciliationController::class,
            'submit',
        ])->whereUuid('cashReconciliation')
            ->name('submit');

        /*
        |--------------------------------------------------------------------------
        | Review
        |--------------------------------------------------------------------------
        */

        Route::post('/{cashReconciliation}/review', [
            CashReconciliationController::class,
            'review',
        ])->whereUuid('cashReconciliation')
            ->name('review');

        /*
        |--------------------------------------------------------------------------
        | Cancellation
        |--------------------------------------------------------------------------
        */

        Route::post('/{cashReconciliation}/cancel', [
            CashReconciliationController::class,
            'cancel',
        ])->whereUuid('cashReconciliation')
            ->name('cancel');
    });