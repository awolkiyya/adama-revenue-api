<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Dashboard\Controllers\DashboardController;

/*
|--------------------------------------------------------------------------
| Dashboard
|--------------------------------------------------------------------------
|
| Dashboard summary and system-wide statistics.
| Register this file within your existing API v1 route group.
|
*/

Route::prefix('dashboard')
    ->middleware([
        'permission:dashboard.view',
        'permission:analytics.view',
    ])
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Dashboard Summary
        |--------------------------------------------------------------------------
        |
        | GET /api/v1/dashboard/summary
        | Optional query parameters: from=YYYY-MM-DD&to=YYYY-MM-DD
        |
        */

        Route::get('/summary', [
            DashboardController::class,
            'summary',
        ])->name('dashboard.summary');
});