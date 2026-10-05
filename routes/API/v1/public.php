<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PublicReceiptController;


// ============================================================
// PUBLIC RECEIPT VERIFICATION
// ============================================================
//
// No authentication required.
//
// Used by:
// - Public landing page
// - Citizen receipt verification
// - QR code receipt verification
//
// ============================================================

Route::prefix('public')->group(function () {

    Route::prefix('receipts')->group(function () {

        // Search / verify receipt
        Route::get(
            '/verify',
            [PublicReceiptController::class, 'verify']
        )
            ->middleware('throttle:30,1')
            ->name('public.receipts.verify');
    });
});