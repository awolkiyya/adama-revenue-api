<?php

use App\Modules\Audit\Controllers\SystemLogController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')
    ->prefix('audit-logs')
    ->group(function () {

        Route::get('/', [
            SystemLogController::class,
            'index'
        ]);

        Route::get('/{systemLog}', [
            SystemLogController::class,
            'show'
        ]);
    });