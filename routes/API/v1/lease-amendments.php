<?php

use App\Http\Controllers\Api\V1\LeaseAmendmentController;

Route::middleware('auth:sanctum')
    ->prefix('lease-amendments')
    ->group(function () {

        Route::get(
            '/',
            [LeaseAmendmentController::class, 'index']
        );

        Route::post(
            '/',
            [LeaseAmendmentController::class, 'store']
        );

        Route::get(
            '/{leaseAmendment}',
            [LeaseAmendmentController::class, 'show']
        );

        Route::put(
            '/{leaseAmendment}',
            [LeaseAmendmentController::class, 'update']
        );

        Route::post(
            '/{leaseAmendment}/submit',
            [LeaseAmendmentController::class, 'submit']
        );

        Route::post(
            '/{leaseAmendment}/approve',
            [LeaseAmendmentController::class, 'approve']
        );

        Route::post(
            '/{leaseAmendment}/reject',
            [LeaseAmendmentController::class, 'reject']
        );

        Route::post(
            '/{leaseAmendment}/apply',
            [LeaseAmendmentController::class, 'apply']
        );

        Route::post(
            '/{leaseAmendment}/cancel',
            [LeaseAmendmentController::class, 'cancel']
        );
    });