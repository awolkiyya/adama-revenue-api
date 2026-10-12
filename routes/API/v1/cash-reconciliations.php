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
| Ensure this route group is registered inside the authenticated
| API route group, or retain the auth:sanctum middleware below.
|
*/

Route::prefix('cash-reconciliations')
    ->name('cash-reconciliations.')
    ->group(function () {

        // List reconciliations
        Route::get('/', [
            CashReconciliationController::class,
            'index',
        ])->name('index');

        // Create a draft
        Route::post('/', [
            CashReconciliationController::class,
            'store',
        ])->name('store');

        // View a reconciliation
        Route::get('/{cashReconciliation}', [
            CashReconciliationController::class,
            'show',
        ])->whereNumber('cashReconciliation')
            ->name('show');

        // Update a draft or returned reconciliation
        Route::match(['put', 'patch'], '/{cashReconciliation}', [
            CashReconciliationController::class,
            'update',
        ])->whereNumber('cashReconciliation')
            ->name('update');

        // Submit a cash closing for review
        Route::post('/{cashReconciliation}/submit', [
            CashReconciliationController::class,
            'submit',
        ])->whereNumber('cashReconciliation')
            ->name('submit');

        // Review a submitted cash closing
        Route::post('/{cashReconciliation}/review', [
            CashReconciliationController::class,
            'review',
        ])->whereNumber('cashReconciliation')
            ->name('review');

        // Cancel a draft or returned reconciliation
        Route::post('/{cashReconciliation}/cancel', [
            CashReconciliationController::class,
            'cancel',
        ])->whereNumber('cashReconciliation')
            ->name('cancel');
});
