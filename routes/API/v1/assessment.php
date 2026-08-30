<?php

use Illuminate\Support\Facades\Route;

use App\Modules\Assessment\Controllers\AssessmentController;


/*
|--------------------------------------------------------------------------
| Revenue Assessment Routes
|--------------------------------------------------------------------------
|
| Assessment lifecycle:
|
| DRAFT
|     ↓
| PENDING_APPROVAL
|     ↓
| APPROVED
|
| Or:
|
| PENDING_APPROVAL
|     ↓
| RETURNED / REJECTED
|
|--------------------------------------------------------------------------
*/


Route::prefix('assessments')
    ->group(function () {



    /*
    |--------------------------------------------------------------------------
    | ASSESSMENT SUMMARY
    |--------------------------------------------------------------------------
    |
    | GET /assessments/summary
    |
    | IMPORTANT:
    | This route must be declared BEFORE
    | /assessments/{assessment}.
    |
    */

    Route::get(
        'summary',
        [
            AssessmentController::class,
            'summary',
        ]
    );


    /*
    |--------------------------------------------------------------------------
    | ASSESSMENT CRUD
    |--------------------------------------------------------------------------
    |
    | GET    /assessments
    | POST   /assessments
    | GET    /assessments/{assessment}
    | PUT    /assessments/{assessment}
    | PATCH  /assessments/{assessment}
    | DELETE /assessments/{assessment}
    |
    */

    Route::apiResource(
        '',
        AssessmentController::class
    )->parameters([
        '' => 'assessment',
    ]);


    /*
    |--------------------------------------------------------------------------
    | ASSESSMENT APPROVAL
    |--------------------------------------------------------------------------
    |
    | PATCH /assessments/{assessment}/approve
    |
    */

    Route::patch(
        '{assessment}/approve',
        [
            AssessmentController::class,
            'approve',
        ]
    );


    /*
    |--------------------------------------------------------------------------
    | ASSESSMENT REJECTION
    |--------------------------------------------------------------------------
    |
    | PATCH /assessments/{assessment}/reject
    |
    | Body:
    |
    | {
    |     "reason": "Incorrect taxpayer information",  Used when the assessment requires correction.
    | }
    |
    */

    Route::patch(
        '{assessment}/return',
        [
            AssessmentController::class,
            'return',
        ]
    );



    /*
    |--------------------------------------------------------------------------
    | ASSESSMENT HISTORY
    |--------------------------------------------------------------------------
    |
    | GET /assessments/{assessment}/history
    |
    */

    Route::get(
        '{assessment}/history',
        [
            AssessmentController::class,
            'history',
        ]
    );

});