<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Assessment\Controllers\LeaseAmendmentController;

Route::prefix('lease-amendments')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        // List lease amendments
        Route::get('/', [
            LeaseAmendmentController::class,
            'index',
        ]);

        // Create a lease amendment
        Route::post('/', [
            LeaseAmendmentController::class,
            'store',
        ]);

        // View a specific lease amendment
        Route::get('/{leaseAmendment}', [
            LeaseAmendmentController::class,
            'show',
        ])->whereUuid('leaseAmendment');

        // Update a draft lease amendment
        Route::put('/{leaseAmendment}', [
            LeaseAmendmentController::class,
            'update',
        ])->whereUuid('leaseAmendment');

        // Submit for approval
        Route::post('/{leaseAmendment}/submit', [
            LeaseAmendmentController::class,
            'submit',
        ])->whereUuid('leaseAmendment');

        // Approve
        Route::post('/{leaseAmendment}/approve', [
            LeaseAmendmentController::class,
            'approve',
        ])->whereUuid('leaseAmendment');

        // Reject
        Route::post('/{leaseAmendment}/reject', [
            LeaseAmendmentController::class,
            'reject',
        ])->whereUuid('leaseAmendment');

        // Apply an approved amendment
        Route::post('/{leaseAmendment}/apply', [
            LeaseAmendmentController::class,
            'apply',
        ])->whereUuid('leaseAmendment');

        // Cancel an amendment
        Route::post('/{leaseAmendment}/cancel', [
            LeaseAmendmentController::class,
            'cancel',
        ])->whereUuid('leaseAmendment');
    });
