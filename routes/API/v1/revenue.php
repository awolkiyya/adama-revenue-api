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
use App\Modules\Revenue\Controllers\InterestRuleController;
use App\Http\Controllers\PenaltyDiscountRequestController;



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


    /*
    |--------------------------------------------------------------------------
    | Revenue Codes
    |--------------------------------------------------------------------------
    */

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
    | Access is configured per:
    |
    |     Revenue Service
    |          |
    |          └── Sector
    |
    | Each service/sector combination has:
    |
    |     is_active = true
    |         → Sector is allowed to use the service
    |
    |     is_active = false
    |         → Sector is not allowed to use the service
    |
    | The frontend ServiceAccessDialog submits the complete sector
    | configuration in one request.
    |
    */


    /*
    |--------------------------------------------------------------------------
    | Retrieve Service Access Configuration
    |--------------------------------------------------------------------------
    |
    | GET /revenue/services/{service}/access-rules
    |
    | Returns the access configuration for all sectors belonging to
    | the selected revenue service.
    |
    */

    Route::get(
        'services/{service}/access-rules',
        [ServiceAccessRuleController::class, 'index']
    )->name('services.access-rules.index');


    /*
    |--------------------------------------------------------------------------
    | Synchronize Service Access Configuration
    |--------------------------------------------------------------------------
    |
    | PUT /revenue/services/{service}/access-rules
    |
    | Example payload:
    |
    | {
    |     "sectors": [
    |         {
    |             "sectorId": "uuid",
    |             "sectorName": "Sector A",
    |             "isActive": true
    |         },
    |         {
    |             "sectorId": "uuid",
    |             "sectorName": "Sector B",
    |             "isActive": false
    |         }
    |     ]
    | }
    |
    | The backend synchronizes the complete configuration.
    |
    */

    Route::put(
        'services/{service}/access-rules',
        [ServiceAccessRuleController::class, 'sync']
    )->name('services.access-rules.sync');


    /*
    |--------------------------------------------------------------------------
    | Individual Service Access Rule
    |--------------------------------------------------------------------------
    |
    | GET /revenue/services/{service}/access-rules/{rule}
    |
    | Used when an individual service/sector access rule needs to be
    | inspected.
    |
    */

    Route::get(
        'services/{service}/access-rules/{rule}',
        [ServiceAccessRuleController::class, 'show']
    )->name('services.access-rules.show');


    /*
    |--------------------------------------------------------------------------
    | Update Individual Service Access Rule
    |--------------------------------------------------------------------------
    |
    | PATCH /revenue/services/{service}/access-rules/{rule}
    |
    | Updates:
    |
    |     sector_id
    |     is_active
    |
    | No role or actions are involved.
    |
    */

    Route::patch(
        'services/{service}/access-rules/{rule}',
        [ServiceAccessRuleController::class, 'update']
    )->name('services.access-rules.update');


    /*
    |--------------------------------------------------------------------------
    | Service Access Rule Status
    |--------------------------------------------------------------------------
    |
    | PATCH /revenue/services/{service}/access-rules/{rule}/status
    |
    | Changes only the active/inactive state.
    |
    */

    Route::patch(
        'services/{service}/access-rules/{rule}/status',
        [ServiceAccessRuleController::class, 'changeStatus']
    )->name('services.access-rules.status');


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
    )->name('tariff-versions.summary');


    /*
    |--------------------------------------------------------------------------
    | Activate Tariff Version
    |--------------------------------------------------------------------------
    */

    Route::patch(
        'tariff-versions/{id}/activate',
        [TariffVersionController::class, 'activate']
    )->name('tariff-versions.activate');


    /*
    |--------------------------------------------------------------------------
    | Restore Deleted Tariff Version
    |--------------------------------------------------------------------------
    */

    Route::patch(
        'tariff-versions/{id}/restore',
        [TariffVersionController::class, 'restore']
    )->name('tariff-versions.restore');


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
    | Interest Rules
    |--------------------------------------------------------------------------
    |
    | Global interest-rate configuration.
    |
    | Interest rules define the legally applicable annual interest rate
    | and calculation basis for municipal revenue.
    |
    | Interest rules are historical/legal configurations and must not
    | be physically deleted.
    |
    */


    /*
    |--------------------------------------------------------------------------
    | Applicable Interest Rule
    |--------------------------------------------------------------------------
    |
    | GET /revenue/interest-rules/applicable
    |
    */

    Route::get(
        'interest-rules/applicable',
        [InterestRuleController::class, 'applicable']
    )
        ->name('interest-rules.applicable');


    /*
    |--------------------------------------------------------------------------
    | Interest Rule CRUD
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'interest-rules',
        InterestRuleController::class
    )->only([
            'index',
            'store',
            'show',
            'update',
        ]);


    /*
    |--------------------------------------------------------------------------
    | Interest Rule Status
    |--------------------------------------------------------------------------
    */

    Route::patch(
        'interest-rules/{interestRule}/activate',
        [InterestRuleController::class, 'activate']
    )
        ->name('interest-rules.activate');


    Route::patch(
        'interest-rules/{interestRule}/deactivate',
        [InterestRuleController::class, 'deactivate']
    )
        ->name('interest-rules.deactivate');


    /*
    |--------------------------------------------------------------------------
    | Interest Rule History
    |--------------------------------------------------------------------------
    */

    Route::get(
        'interest-rules/{interestRule}/history',
        [InterestRuleController::class, 'history']
    )
        ->name('interest-rules.history');


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


        Route::prefix('penalty-discount-requests')
            ->controller(PenaltyDiscountRequestController::class)
            ->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
        
                Route::get(
                    '/{penaltyDiscountRequest}',
                    'show'
                );
        
                Route::post(
                    '/{penaltyDiscountRequest}/submit',
                    'submit'
                );
        
                Route::post(
                    '/{penaltyDiscountRequest}/decide',
                    'decide'
                );
        
                Route::post(
                    '/{penaltyDiscountRequest}/cancel',
                    'cancel'
                );
        
                Route::get(
                    '/{penaltyDiscountRequest}/history',
                    'history'
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