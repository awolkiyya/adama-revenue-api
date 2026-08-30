<?php

use Illuminate\Support\Facades\Route;

use App\Modules\Revenue\Controllers\RevenueCategoryController;
use App\Modules\Revenue\Controllers\RevenueCodeController;
use App\Modules\Revenue\Controllers\RevenueServiceController;
use App\Modules\Revenue\Controllers\ServiceAccessRuleController;
use App\Modules\Revenue\Controllers\TariffVersionController;
use App\Modules\Revenue\Controllers\MeasurementUnitController;
use App\Modules\Revenue\Controllers\BaseFieldController;
use App\Modules\Revenue\Controllers\TariffRuleController;
use App\Modules\Revenue\Controllers\TariffFormulaVariableController;


/*
|--------------------------------------------------------------------------
| Revenue Management
|--------------------------------------------------------------------------
|
| Handles municipal revenue configuration,
| assessment, billing, collection and reporting.
|
*/

Route::prefix('revenue')
    ->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Revenue Configuration
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'categories',
        RevenueCategoryController::class
    );

    Route::get(
        'codes',
        [RevenueCodeController::class, 'index']
    );


    /*
    |--------------------------------------------------------------------------
    | Revenue Services
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'services',
        RevenueServiceController::class
    );


    /*
    |--------------------------------------------------------------------------
    | Service Access Rules
    |--------------------------------------------------------------------------
    |
    | Revenue Service
    |     └── Access Rules
    |
    | Example:
    |
    | GET    /revenue/services/{service}/access-rules
    | POST   /revenue/services/{service}/access-rules
    | GET    /revenue/services/{service}/access-rules/{rule}
    | PATCH  /revenue/services/{service}/access-rules/{rule}
    | DELETE /revenue/services/{service}/access-rules/{rule}
    |
    */

    Route::apiResource(
        'services.access-rules',
        ServiceAccessRuleController::class
    )->parameters([
        'access-rules' => 'rule',
    ]);


    /*
    |--------------------------------------------------------------------------
    | Optional Status Toggle
    |--------------------------------------------------------------------------
    */

    Route::patch(
        'services/{service}/access-rules/{rule}/status',
        [ServiceAccessRuleController::class, 'changeStatus']
    );

      /*
    |--------------------------------------------------------------------------
    | Tariff Versions
    |--------------------------------------------------------------------------
    |
    | Manage yearly tariff pricing versions.
    |
    | Examples:
    |
    | GET    /revenue/tariff-versions
    | POST   /revenue/tariff-versions
    | GET    /revenue/tariff-versions/{id}
    | PUT    /revenue/tariff-versions/{id}
    | DELETE /revenue/tariff-versions/{id}
    |
    */


    Route::apiResource(
        'tariff-versions',
        TariffVersionController::class
    );





    /*
    |--------------------------------------------------------------------------
    | Tariff Version Dashboard Summary
    |--------------------------------------------------------------------------
    |
    | Returns:
    |
    | {
    |   current_active_tariff:{
    |       year:2026,
    |       message:"2026 is the currently active tariff..."
    |   }
    | }
    |
    */


    Route::get(
        'tariff-versions-summary',
        [TariffVersionController::class, 'summary']
    );





    /*
    |--------------------------------------------------------------------------
    | Activate Tariff Version
    |--------------------------------------------------------------------------
    |
    | Only one tariff version can be active.
    |
    */

    Route::patch(
        'tariff-versions/{id}/activate',
        [TariffVersionController::class, 'activate']
    );





    /*
    |--------------------------------------------------------------------------
    | Restore Deleted Tariff Version
    |--------------------------------------------------------------------------
    */

    Route::patch(
        'tariff-versions/{id}/restore',
        [TariffVersionController::class, 'restore']
    );


    /*
    |--------------------------------------------------------------------------
    | Measurement Units
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'measurement-units',
        MeasurementUnitController::class
    );

    Route::patch(
        'measurement-units/{id}/restore',
        [MeasurementUnitController::class, 'restore']
    );

    Route::patch(
        'measurement-units/{id}/status',
        [MeasurementUnitController::class, 'changeStatus']
    );


    /*
    |--------------------------------------------------------------------------
    | Base Fields
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'base-fields',
        BaseFieldController::class
    );

    Route::patch(
        'base-fields/{id}/restore',
        [BaseFieldController::class, 'restore']
    );

    Route::patch(
        'base-fields/{id}/status',
        [BaseFieldController::class, 'changeStatus']
    );


    /*
    |--------------------------------------------------------------------------
    | Tariff Rules
    |--------------------------------------------------------------------------
    |
    | Tariff Version
    |      |
    |      └── Rules
    |
    | Examples:
    |
    | GET    /revenue/tariff-versions/{tariffVersion}/rules
    | POST   /revenue/tariff-versions/{tariffVersion}/rules
    |
    | GET    /revenue/tariff-rules/{rule}
    | PUT    /revenue/tariff-rules/{rule}
    | DELETE /revenue/tariff-rules/{rule}
    |
    */


    Route::apiResource(
        'tariff-versions.tariff-rules',
        TariffRuleController::class
    )->parameters([
        'tariff-rules' => 'tariffRule',
    ]);

    Route::prefix('tariff-rules/{tariffRule}')
    ->group(function () {

        Route::apiResource(
            'formula-variables',
            TariffFormulaVariableController::class
        );
    });

});





    /*
    |--------------------------------------------------------------------------
    | Tariff Management
    |--------------------------------------------------------------------------
    |
    | Versioned tariffs
    |
    | Example:
    |
    | Property Tax 2026
    | Market Rent 2026
    |
    */

    // Route::apiResource(
    //     'tariff-versions',
    //     TariffVersionController::class
    // );



    /**
     * Tariff Rules
     *
     * Example:
     *
     * Property value:
     * 0 - 100,000 = 0.5%
     * 100,001 - 500,000 = 1%
     *
     */
    // Route::apiResource(
    //     'tariff-rules',
    //     TariffRuleController::class
    // );



    // Route::apiResource(
    //     'service-access-rules',
    //     ServiceAccessRuleController::class
    // );


    // Route::prefix('service-access-rules')
    //     ->group(function () {


    //         Route::get(
    //             'service/{service}',
    //             [
    //                 ServiceAccessRuleController::class,
    //                 'byService'
    //             ]
    //         );


    //         Route::get(
    //             'sector/{sector}',
    //             [
    //                 ServiceAccessRuleController::class,
    //                 'bySector'
    //             ]
    //         );


    //         Route::get(
    //             'check',
    //             [
    //                 ServiceAccessRuleController::class,
    //                 'check'
    //             ]
    //         );


    //     });



    
    
    
    
    
    /*
    |--------------------------------------------------------------------------
    | Revenue Assessment
    |--------------------------------------------------------------------------
    |
    | Calculate taxpayer obligation
    |
    */


    // Route::apiResource(
    //     'assessments',
    //     AssessmentController::class
    // );



    /*
    |--------------------------------------------------------------------------
    | Invoice Management
    |--------------------------------------------------------------------------
    |
    | Generated revenue bills
    |
    */


    // Route::apiResource(
    //     'invoices',
    //     InvoiceController::class
    // );



    /*
    |--------------------------------------------------------------------------
    | Payment Collection
    |--------------------------------------------------------------------------
    |
    | Receive taxpayer payments
    |
    */


    // Route::apiResource(
    //     'payments',
    //     PaymentController::class
    // );



    /*
    |--------------------------------------------------------------------------
    | Receipts
    |--------------------------------------------------------------------------
    |
    | Official payment documents
    |
    */


    // Route::get(
    //     'payments/{payment}/receipt',
    //     [
    //         ReceiptController::class,
    //         'show'
    //     ]
    // );



    /*
    |--------------------------------------------------------------------------
    | Revenue Reports
    |--------------------------------------------------------------------------
    */


    // Route::prefix('reports')
    //     ->group(function(){


    //         /**
    //          * Daily collection report
    //          */
    //         Route::get(
    //             'daily',
    //             [
    //                 RevenueReportController::class,
    //                 'daily'
    //             ]
    //         );


    //         /**
    //          * Monthly collection report
    //          */
    //         Route::get(
    //             'monthly',
    //             [
    //                 RevenueReportController::class,
    //                 'monthly'
    //             ]
    //         );


    //         /**
    //          * Revenue by category
    //          */
    //         Route::get(
    //             'categories',
    //             [
    //                 RevenueReportController::class,
    //                 'byCategory'
    //             ]
    //         );


    //         /**
    //          * Revenue by sector
    //          */
    //         Route::get(
    //             'sectors',
    //             [
    //                 RevenueReportController::class,
    //                 'bySector'
    //             ]
    //         );


    //     });