<?php

use App\Modules\Agent\Controllers\AgentInvoiceController;

/*
|--------------------------------------------------------------------------
| AGENT PORTAL
|--------------------------------------------------------------------------
| Portal access is permission-based.
|
| agent.portal_access
|     → Determines whether the authenticated user may enter
|       the Agent portal.
|
| Feature permissions
|     → Determine what the user may do inside the Agent portal.
|
| Roles only bundle permissions.
| They are NOT used as the authorization boundary.
|
| NOTE:
| auth:sanctum is already applied by routes/Api/v1/api.php,
| so it is not repeated here.
|--------------------------------------------------------------------------
*/

Route::middleware('permission:agent.portal_access')
    ->prefix('agent')
    ->group(function () {

        // ========================================================
        // DASHBOARD
        // ========================================================

        // Route::get('/dashboard', ...);


        // ========================================================
        // TAXPAYERS
        // ========================================================

        // Route::middleware('permission:citizens.view')
        //     ->get('/taxpayers', ...);


        // ========================================================
        // INVOICES
        // ========================================================
        //
        // Agents can view invoices that are available for payment.
        // Invoices are NOT assigned to individual agents.
        //
        // The authenticated agent becomes associated with the
        // transaction only when the payment is created.
        //

        Route::middleware('permission:invoices.view')
            ->get('/invoices/pending', [
                AgentInvoiceController::class,
                'pending',
            ])
            ->name('agent.invoices.pending');


        // ========================================================
        // PAYMENTS
        // ========================================================
        //
        // Payment creation will record the authenticated agent
        // as the person who processed the payment.
        //

        // Route::middleware('permission:payments.create')
        //     ->post('/payments', [
        //         AgentPaymentController::class,
        //         'store',
        //     ])
        //     ->name('agent.payments.store');


        // ========================================================
        // PAYMENT HISTORY
        // ========================================================
        //
        // Shows payments processed by the authenticated agent.
        //

        // Route::middleware('permission:payments.view')
        //     ->get('/payments', [
        //         AgentPaymentController::class,
        //         'index',
        //     ])
        //     ->name('agent.payments.index');


        // ========================================================
        // RECEIPTS
        // ========================================================
        //
        // Receipts generated from payments processed by the agent.
        //

        // Route::middleware('permission:payments.view')
        //     ->get('/receipts/{payment}', [
        //         AgentReceiptController::class,
        //         'show',
        //     ])
        //     ->name('agent.receipts.show');
    });