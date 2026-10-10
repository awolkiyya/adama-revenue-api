<?php

use App\Modules\PenaltyDiscount\Controllers\PenaltyDiscountRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('penalty-discount-requests')
->name('penalty-discount-requests.')
->controller(PenaltyDiscountRequestController::class)
->group(function () {
     // List requests and dashboard summary.
     Route::get('/', 'index')->name('index');
    

    // Create a request.
    Route::post('/', 'store')->name('store');

    // View a specific request.
    Route::get(
        '/{penaltyDiscountRequest}',
        'show'
    )->name('show');

    // Update a draft request.
    Route::put(
        '/{penaltyDiscountRequest}',
        'update'
    )->name('update');

    // Submit a draft request for approval.
    Route::post(
        '/{penaltyDiscountRequest}/submit',
        'submit'
    )->name('submit');

    // Approve or reject a submitted request.
    Route::post(
        '/{penaltyDiscountRequest}/decide',
        'decide'
    )->name('decide');

    // Apply an approved discount to the invoice.
    Route::post(
        '/{penaltyDiscountRequest}/apply',
        'apply'
    )->name('apply');

    // Cancel an eligible request.
    Route::post(
        '/{penaltyDiscountRequest}/cancel',
        'cancel'
    )->name('cancel');

    // View request history/details.
    Route::get(
        '/{penaltyDiscountRequest}/history',
        'history'
    )->name('history');
});

