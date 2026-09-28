<?php
use App\Modules\PaymentSchedule\Controllers\PaymentScheduleController;
use Illuminate\Support\Facades\Route;


Route::prefix('payment-schedules')
    ->group(function () {

        Route::get(
            '/{assessmentServiceId}',
            [PaymentScheduleController::class, 'show']
        );

        Route::post(
            '/{assessmentServiceId}/invoice',
            [PaymentScheduleController::class, 'createInvoice']
        );
    });