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
use App\Modules\Revenue\Controllers\PenaltyRuleController;
use App\Modules\Revenue\Controllers\RevenueSettingController;


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
    */

    Route::apiResource(
        'services.access-rules',
        ServiceAccessRuleController::class
    )->parameters([
        'access-rules' => 'rule',
    ]);


    /*
    |--------------------------------------------------------------------------
    | Service Access Rule Status
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
    | IMPORTANT:
    | Static routes must come before /{tariffVersion}.
    |
    */


    /*
    |--------------------------------------------------------------------------
    | Tariff Version Dashboard Summary
    |--------------------------------------------------------------------------
    |
    | GET /revenue/tariff-versions/summary
    |
    */

    Route::get(
        'tariff-versions/summary',
        [TariffVersionController::class, 'summary']
    )
        ->name('tariff-versions.summary');


    /*
    |--------------------------------------------------------------------------
    | Activate Tariff Version
    |--------------------------------------------------------------------------
    */

    Route::patch(
        'tariff-versions/{id}/activate',
        [TariffVersionController::class, 'activate']
    )
        ->name('tariff-versions.activate');


    /*
    |--------------------------------------------------------------------------
    | Restore Deleted Tariff Version
    |--------------------------------------------------------------------------
    */

    Route::patch(
        'tariff-versions/{id}/restore',
        [TariffVersionController::class, 'restore']
    )
        ->name('tariff-versions.restore');


    /*
    |--------------------------------------------------------------------------
    | Tariff Version CRUD
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'tariff-versions',
        TariffVersionController::class
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
    */

    Route::apiResource(
        'tariff-versions.tariff-rules',
        TariffRuleController::class
    )->parameters([
        'tariff-rules' => 'tariffRule',
    ]);


    /*
    |--------------------------------------------------------------------------
    | Tariff Formula Variables
    |--------------------------------------------------------------------------
    |
    | Tariff Rule
    |      |
    |      └── Formula Variables
    |
    */

    Route::prefix('tariff-rules/{tariffRule}')
        ->group(function () {

            Route::apiResource(
                'formula-variables',
                TariffFormulaVariableController::class
            );

        });


    /*
    |--------------------------------------------------------------------------
    | Penalty Rules
    |--------------------------------------------------------------------------
    |
    | Penalty Rule
    |      |
    |      ├── Global / Default Rule
    |      |
    |      └── Revenue Service Specific Rule
    |
    | A NULL revenue_service_id represents a global/default
    | penalty rule.
    |
    */

    Route::apiResource(
        'penalty-rules',
        PenaltyRuleController::class
    )
        ->only([
            'index',
            'store',
            'show',
            'update',
        ]);


    /*
    |--------------------------------------------------------------------------
    | Penalty Rule Status
    |--------------------------------------------------------------------------
    |
    | PATCH /revenue/penalty-rules/{penaltyRule}/activate
    | PATCH /revenue/penalty-rules/{penaltyRule}/deactivate
    |
    */

    Route::patch(
        'penalty-rules/{penaltyRule}/activate',
        [PenaltyRuleController::class, 'activate']
    )
        ->name('penalty-rules.activate');


    Route::patch(
        'penalty-rules/{penaltyRule}/deactivate',
        [PenaltyRuleController::class, 'deactivate']
    )
        ->name('penalty-rules.deactivate');


    /*
    |--------------------------------------------------------------------------
    | Penalty Rule History
    |--------------------------------------------------------------------------
    |
    | GET /revenue/penalty-rules/{penaltyRule}/history
    |
    */

    Route::get(
        'penalty-rules/{penaltyRule}/history',
        [PenaltyRuleController::class, 'history']
    )
        ->name('penalty-rules.history');

    /*
    |--------------------------------------------------------------------------
    | Global Revenue Settings
    |--------------------------------------------------------------------------
    |
    | Global singleton configuration for Revenue Management.
    |
    | GET /revenue/settings
    |     Retrieve the active revenue settings.
    |
    | PUT /revenue/settings/{revenueSetting}
    |     Update the active revenue settings.
    |
    | Revenue settings intentionally do not expose:
    |
    | - POST
    | - DELETE
    | - activate
    | - deactivate
    |
    */
    Route::get(
        'settings',
        [RevenueSettingController::class, 'show']
    )
        ->name('revenue-settings.show');


    Route::put(
        'settings/{revenueSetting}',
        [RevenueSettingController::class, 'save']
    )
        ->name('revenue-settings.update');

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