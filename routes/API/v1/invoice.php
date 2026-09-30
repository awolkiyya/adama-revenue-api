<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Invoice\Controllers\InvoiceController;

Route::prefix('invoices')->group(function () {

    // List invoices
    Route::get('/', [InvoiceController::class, 'index']);

    // View invoice
    Route::get('/{invoice}', [InvoiceController::class, 'show']);

    // Cancel invoice
    Route::post('/{invoice}/cancel', [InvoiceController::class, 'cancel']);

    // Download/view invoice document
    Route::get('/{invoice}/pdf', [InvoiceController::class, 'pdf']);
});