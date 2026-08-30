<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Invoice\Controllers\InvoiceController;

Route::prefix('invoices')->group(function () {

    // List invoices
    Route::get('/', [InvoiceController::class, 'index']);

    // Create invoice
    Route::post('/', [InvoiceController::class, 'store']);

    // View invoice
    Route::get('/{invoice}', [InvoiceController::class, 'show']);

    // Update invoice
    Route::put('/{invoice}', [InvoiceController::class, 'update']);

    // Delete/cancel invoice
    Route::delete('/{invoice}', [InvoiceController::class, 'destroy']);

    // Issue draft invoice
    Route::post('/{invoice}/issue', [InvoiceController::class, 'issue']);

    // Send invoice
    Route::post('/{invoice}/send', [InvoiceController::class, 'send']);

    // Mark invoice as paid
    Route::post('/{invoice}/pay', [InvoiceController::class, 'markAsPaid']);

    // Cancel invoice
    Route::post('/{invoice}/cancel', [InvoiceController::class, 'cancel']);

    // Download/view invoice document
    Route::get('/{invoice}/pdf', [InvoiceController::class, 'pdf']);
});