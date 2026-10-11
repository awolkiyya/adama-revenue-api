<?php

use Illuminate\Support\Facades\Route;
use App\Modules\CashReconciliation\Controllers\CashReconciliationController;


// ============================================================
// CASH RECONCILIATION
// AUTHENTICATED ROUTES
// ============================================================
//
// Revenue Collector:
// - Create a closing draft
// - View their own reconciliations
// - Update a draft or returned closing
// - Submit the physical cash count
// - Cancel a draft or returned closing
//
// Finance Officer:
// - View reconciliations permitted by their permissions
// - Review submitted closings
// - Record review decisions and notes
//
// Authorization is permission-driven, not role-driven.
// The controller, Form Requests, and service enforce access
// and workflow rules.
// ============================================================


Route::prefix('cash-reconciliations')
    ->name('cash-reconciliations.')
    ->group(function () {

        // ====================================================
        // LIST RECONCILIATIONS
        // GET /api/v1/cash-reconciliations
        // ====================================================

        Route::get('/', [
            CashReconciliationController::class,
            'index',
        ])->name('index');


        // ====================================================
        // CREATE DRAFT
        // POST /api/v1/cash-reconciliations
        // ====================================================

        Route::post('/', [
            CashReconciliationController::class,
            'store',
        ])->name('store');


        // ====================================================
        // VIEW RECONCILIATION
        // GET /api/v1/cash-reconciliations/{cashReconciliation}
        // ====================================================

        Route::get('/{cashReconciliation}', [
            CashReconciliationController::class,
            'show',
        ])->whereNumber('cashReconciliation')
            ->name('show');


        // ====================================================
        // UPDATE DRAFT OR RETURNED CLOSING
        // PUT/PATCH /api/v1/cash-reconciliations/{id}
        // ====================================================

        Route::match(['put', 'patch'], '/{cashReconciliation}', [
            CashReconciliationController::class,
            'update',
        ])->whereNumber('cashReconciliation')
            ->name('update');


        // ====================================================
        // SUBMIT CASH CLOSING
        // POST /api/v1/cash-reconciliations/{id}/submit
        // ====================================================

        Route::post('/{cashReconciliation}/submit', [
            CashReconciliationController::class,
            'submit',
        ])->whereNumber('cashReconciliation')
            ->name('submit');


        // ====================================================
        // FINANCE REVIEW
        // POST /api/v1/cash-reconciliations/{id}/review
        // ====================================================

        Route::post('/{cashReconciliation}/review', [
            CashReconciliationController::class,
            'review',
        ])->whereNumber('cashReconciliation')
            ->name('review');


        // ====================================================
        // CANCEL DRAFT OR RETURNED CLOSING
        // POST /api/v1/cash-reconciliations/{id}/cancel
        // ====================================================

        Route::post('/{cashReconciliation}/cancel', [
            CashReconciliationController::class,
            'cancel',
        ])->whereNumber('cashReconciliation')
            ->name('cancel');
    });