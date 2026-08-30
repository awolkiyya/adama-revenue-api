<?php

use Illuminate\Support\Facades\Route;

use App\Modules\Citizens\Controllers\CitizenController;
use App\Modules\Citizens\Controllers\CitizenImportController;
use App\Modules\Citizens\Controllers\CitizenExportController;

Route::prefix('citizens')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Citizen Import
        |--------------------------------------------------------------------------
        */

        // Download Excel/CSV template
        Route::get('/import/template', [
            CitizenImportController::class,
            'template'
        ]);

        // Upload Excel/CSV
        Route::post('/import', [
            CitizenImportController::class,
            'import'
        ]);


        /*
        |--------------------------------------------------------------------------
        | Citizen Export
        |--------------------------------------------------------------------------
        */

        // Export filtered citizens
        Route::get('/export', [
            CitizenExportController::class,
            'export'
        ]);


        /*
        |--------------------------------------------------------------------------
        | Citizen Status
        |--------------------------------------------------------------------------
        */

        Route::patch('/{citizen}/status', [
            CitizenController::class,
            'toggleStatus'
        ]);


        /*
        |--------------------------------------------------------------------------
        | Citizens CRUD
        |--------------------------------------------------------------------------
        */

        Route::get('/', [
            CitizenController::class,
            'index'
        ]);

        Route::post('/', [
            CitizenController::class,
            'store'
        ]);

        Route::get('/{citizen}', [
            CitizenController::class,
            'show'
        ]);

        Route::put('/{citizen}', [
            CitizenController::class,
            'update'
        ]);

        Route::delete('/{citizen}', [
            CitizenController::class,
            'destroy'
        ]);

    });