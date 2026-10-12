<?php

use App\Modules\Agent\Controllers\AgentInvoiceController;
use App\Modules\Agent\Controllers\AgentPaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AGENT PORTAL
|--------------------------------------------------------------------------
|
| auth:sanctum is applied by routes/Api/v1/api.php.
|
| agent.portal_access
|     Determines whether the authenticated user can access the portal.
|
| Feature permissions
|     Control individual operations inside the portal.
|
| Roles bundle permissions; permissions remain the authorization
| boundary.
|
*/

Route::middleware('permission:agent.portal_access')
    ->prefix('agent')
    ->name('agent.')
    ->group(function (): void {

        // ========================================================
        // DASHBOARD
        // ========================================================

        Route::get('/payments/dashboard', [
                AgentPaymentController::class,
                'dashboard',
            ])
            ->name('payments.dashboard');


        // ========================================================
        // TAXPAYERS
        // ========================================================

        // Register taxpayer routes here when implemented.
        // Example permission: citizens.view


        // ========================================================
        // INVOICES
        // ========================================================

        Route::get('/invoices/pending', [
                AgentInvoiceController::class,
                'pending',
            ])
            ->name('invoices.pending');


        // ========================================================
        // PAYMENT HISTORY
        // ========================================================

        Route::get('/payments', [
                AgentPaymentController::class,
                'index',
            ])
            ->name('payments.index');


        // ========================================================
        // PAYMENT SUMMARY
        // ========================================================

        Route::get('/payments/summary', [
                AgentPaymentController::class,
                'summary',
            ])
            ->name('payments.summary');


        // ========================================================
        // TODAY'S PAYMENT SUMMARY
        // ========================================================

        Route::get('/payments/today-summary', [
                AgentPaymentController::class,
                'todaySummary',
            ])
            ->name('payments.today-summary');


        // ========================================================
        // PAYMENT CREATION
        // ========================================================

        // Register when the agent payment creation action exists.
        //
        // Route::middleware('permission:payments.create')
        //     ->post('/payments', [
        //         AgentPaymentController::class,
        //         'store',
        //     ])
        //     ->name('payments.store');


        // ========================================================
        // RECEIPTS
        // ========================================================

        // Register when AgentReceiptController is implemented.
        //
        // Route::middleware('permission:payments.view')
        //     ->get('/receipts/{payment}', [
        //         AgentReceiptController::class,
        //         'show',
        //     ])
        //     ->name('receipts.show');
    });