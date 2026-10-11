
<?php

use App\Modules\RevenueReport\Controllers\RevenueCollectionReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Revenue Reports
|--------------------------------------------------------------------------
|
| Register this file inside your existing auth:sanctum route group.
| Access is restricted to users with the revenue_reports.view permission.
|
*/

Route::prefix('revenue-reports')
    ->name('revenue-reports.')
    ->group(function () {
        Route::get(
            '/collection',
            [RevenueCollectionReportController::class, 'collection']
        )->name('collection');
    });