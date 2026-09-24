<?php

use Illuminate\Support\Facades\Route;

use App\Modules\Assessment\Controllers\AssessmentController;
use App\Modules\ExistingAssessment\Controllers\ExistingLizzController;


/*
|--------------------------------------------------------------------------
| Revenue Assessment Routes
|--------------------------------------------------------------------------
|
| Normal assessment lifecycle:
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


/*
|--------------------------------------------------------------------------
| NORMAL ASSESSMENTS
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
        | ASSESSMENT RETURN
        |--------------------------------------------------------------------------
        |
        | PATCH /assessments/{assessment}/return
        |
        | Body:
        |
        | {
        |     "reason": "Incorrect taxpayer information"
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


/*
|--------------------------------------------------------------------------
| EXISTING LIZZ
|--------------------------------------------------------------------------
|
| Existing LIZZ is intentionally outside the `assessments` prefix.
|
| It is a separate business workflow for importing/registering
| historical LIZZ agreements.
|
|--------------------------------------------------------------------------
*/


Route::prefix('existing-lizz')
    ->group(function () {



        /*
        |--------------------------------------------------------------------------
        | CREATE
        |--------------------------------------------------------------------------
        |
        | POST /existing-lizz
        |
        */

        Route::post(
            '',
            [
                ExistingLizzController::class,
                'store',
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | UPDATE
        |--------------------------------------------------------------------------
        |
        | PUT   /existing-lizz/{assessment}
        | PATCH /existing-lizz/{assessment}
        |
        */

        Route::match(
            ['put', 'patch'],
            '{assessment}',
            [
                ExistingLizzController::class,
                'update',
            ]
        );

    });
