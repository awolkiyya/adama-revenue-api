<?php

namespace App\Modules\Assessment\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Assessment\Requests\StoreAssessmentRequest;
use App\Modules\Assessment\Requests\UpdateAssessmentRequest;
use App\Modules\Assessment\Requests\ReturnAssessmentRequest;
use App\Modules\Assessment\Requests\CancelAssessmentRequest;

use App\Modules\Assessment\Resources\AssessmentResource;
use App\Modules\Assessment\Resources\AssessmentCollection;
use App\Modules\Assessment\Resources\AssessmentSummaryResource;

use App\Modules\Assessment\Services\AssessmentService;
use App\Modules\Assessment\Services\AssessmentApprovalService;

use App\Services\ApiResponse;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


/*
|--------------------------------------------------------------------------
| Assessment Controller
|--------------------------------------------------------------------------
|
| Thin HTTP controller.
|
| Responsibilities:
|
| - Receive HTTP request
| - Validate through FormRequest
| - Authorize through AssessmentPolicy
| - Delegate business logic
| - Return API resources
|
| This controller MUST NOT:
|
| - Calculate tariffs
| - Calculate assessment amounts
| - Resolve tariff rules
| - Calculate invoice amounts
| - Perform financial decisions
|
|--------------------------------------------------------------------------
|
| Assessment lifecycle:
|
| DRAFT
|     ↓
| PENDING_APPROVAL
|     ↓
| APPROVED
|     ↓
| INVOICE
|     ↓
| ISSUED
|     ↓
| TAXPAYER NOTIFIED
|
| Or:
|
| PENDING_APPROVAL
|     ↓
| RETURNED
|     ↓
| DRAFT / EDIT
|     ↓
| PENDING_APPROVAL
|
| CANCELLED can terminate an assessment
| when the business rules allow it.
|
|--------------------------------------------------------------------------
*/

class AssessmentController extends Controller
{
    public function __construct(
        protected AssessmentService $assessmentService,
        protected AssessmentApprovalService $approvalService,
    ) {
    }


    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/assessments
    |
    | Permission:
    |
    |     assessment.read
    |
    | Policy:
    |
    |     viewAny()
    |
    */

    public function index(
        Request $request
    ): JsonResponse {

        $this->authorize('viewAny', \App\Models\Assessment::class);

        $result =
            $this->assessmentService
                ->paginate(
                    $request
                );

        $summary =
            $this->assessmentService
                ->summary(
                    $request
                );

        return ApiResponse::success(
            data: new AssessmentCollection(
                $result
            ),
            message: 'Assessments retrieved successfully.',
            summary: $summary
        );
    }


    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/assessments
    |
    | Permission:
    |
    |     assessment.create
    |
    | Policy:
    |
    |     create()
    |
    | Supports:
    |
    | - DRAFT
    | - PENDING_APPROVAL
    |
    | Multipart FormData is supported.
    |
    */

    public function store(
        StoreAssessmentRequest $request
    ): JsonResponse {

        $this->authorize('create', \App\Models\Assessment::class);

        $assessment =
            $this->assessmentService
                ->create(
                    $request
                );

        return response()->json([
            'success' => true,

            'message' =>
                $assessment->status === 'DRAFT'
                    ? 'Assessment draft saved successfully.'
                    : 'Assessment submitted successfully.',

            'data' =>
                new AssessmentResource(
                    $assessment
                ),

            'errors' => null,

            'meta' => [],
        ], 201);
    }


    /*
    |--------------------------------------------------------------------------
    | SHOW
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/assessments/{assessment}
    |
    | Permission:
    |
    |     assessment.read
    |
    | Policy:
    |
    |     view()
    |
    */

    public function show(
        string $assessment
    ): JsonResponse {

        $model =
            $this->assessmentService
                ->findOrFail(
                    $assessment
                );

        $this->authorize(
            'view',
            $model
        );

        return response()->json([
            'success' => true,

            'message' =>
                'Assessment retrieved successfully.',

            'data' =>
                new AssessmentResource(
                    $model
                ),

            'errors' => null,

            'meta' => [],
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    |
    | PUT/PATCH /api/v1/assessments/{assessment}
    |
    | Permission:
    |
    |     assessment.update
    |
    | Policy:
    |
    |     update()
    |
    | Used primarily for:
    |
    | - DRAFT
    | - RETURNED
    |
    | The service is responsible for enforcing
    | which statuses are actually editable.
    |
    */

    public function update(
        UpdateAssessmentRequest $request,
        string $assessment
    ): JsonResponse {
    
        /*
        |--------------------------------------------------------------------------
        | Resolve Assessment Model
        |--------------------------------------------------------------------------
        */
    
        $model =
            $this->assessmentService
                ->findOrFail(
                    $assessment
                );
    
    
        /*
        |--------------------------------------------------------------------------
        | Authorization
        |--------------------------------------------------------------------------
        */
    
        $this->authorize(
            'update',
            $model
        );
    
    
        /*
        |--------------------------------------------------------------------------
        | Update Assessment
        |--------------------------------------------------------------------------
        |
        | AssessmentService::update() expects:
        |
        |     Assessment $assessment
        |
        | Therefore we MUST pass $model, not the
        | original UUID string $assessment.
        |
        */
    
        $model =
            $this->assessmentService
                ->update(
                    $model,
                    $request
                );
    
    
        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */
    
        return response()->json([
            'success' => true,
    
            'message' =>
                $model->status === 'DRAFT'
                    ? 'Assessment draft updated successfully.'
                    : 'Assessment updated successfully.',
    
            'data' =>
                new AssessmentResource(
                    $model
                ),
    
            'errors' => null,
    
            'meta' => [],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    |
    | DELETE /api/v1/assessments/{assessment}
    |
    | Permission:
    |
    |     assessment.delete
    |
    | Policy:
    |
    |     delete()
    |
    | The service determines whether deletion is allowed
    | for the current assessment state.
    |
    */

    public function destroy(
        string $assessment
    ): JsonResponse {

        $model =
            $this->assessmentService
                ->findOrFail(
                    $assessment
                );

        $this->authorize(
            'delete',
            $model
        );

        $this->assessmentService
            ->delete(
                $assessment
            );

        return response()->json([
            'success' => true,

            'message' =>
                'Assessment deleted successfully.',

            'data' => null,

            'errors' => null,

            'meta' => [],
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | APPROVE
    |--------------------------------------------------------------------------
    |
    | PATCH /api/v1/assessments/{assessment}/approve
    |
    | Permission:
    |
    |     assessment.approve
    |
    | Policy:
    |
    |     approve()
    |
    | Business transition:
    |
    | PENDING_APPROVAL
    |        ↓
    |     APPROVED
    |        ↓
    |     INVOICE
    |        ↓
    |     ISSUED
    |        ↓
    | TAXPAYER NOTIFIED
    |
    | AssessmentApprovalService is responsible for the complete
    | approval workflow.
    |
    */

    public function approve(
        string $assessment
    ): JsonResponse {

        $model =
            $this->assessmentService
                ->findOrFail(
                    $assessment
                );

        $this->authorize(
            'approve',
            $model
        );

        $model =
            $this->approvalService
                ->approve(
                    $assessment
                );

        return response()->json([
            'success' => true,

            'message' =>
                'Assessment approved and invoice issued successfully.',

            'data' =>
                new AssessmentResource(
                    $model
                ),

            'errors' => null,

            'meta' => [],
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | RETURN
    |--------------------------------------------------------------------------
    |
    | PATCH /api/v1/assessments/{assessment}/return
    |
    | Permission:
    |
    |     assessment.return
    |
    | Policy:
    |
    |     returnAssessment()
    |
    | Body:
    |
    | {
    |     "reason": "Incorrect taxpayer information."
    | }
    |
    | Business transition:
    |
    | PENDING_APPROVAL
    |        ↓
    |     RETURNED
    |
    | The assessment can then be corrected
    | and submitted again.
    |
    */
    public function return(
        ReturnAssessmentRequest $request,
        string $assessment
    ): JsonResponse {
    
        $model =
            $this->assessmentService
                ->findOrFail(
                    $assessment
                );
    
        $this->authorize(
            'returnAssessment',
            $model
        );
    
        $model =
            $this->assessmentService
                ->returnAssessment(
                    $model,
                    $request
                );
    
        return response()->json([
            'success' => true,
    
            'message' =>
                'Assessment returned for correction.',
    
            'data' =>
                new AssessmentResource(
                    $model
                ),
    
            'errors' => null,
    
            'meta' => [],
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | CANCEL
    |--------------------------------------------------------------------------
    |
    | PATCH /api/v1/assessments/{assessment}/cancel
    |
    | Permission:
    |
    |     assessment.cancel
    |
    | Policy:
    |
    |     cancel()
    |
    | Body:
    |
    | {
    |     "reason": "Taxpayer cancelled the request."
    | }
    |
    | Business transition:
    |
    | DRAFT / RETURNED / PENDING_APPROVAL
    |        ↓
    |     CANCELLED
    |
    | Exact allowed transitions must be enforced
    | by the service/domain layer.
    |
    */

    public function cancel(
        CancelAssessmentRequest $request,
        string $assessment
    ): JsonResponse {

        $model =
            $this->assessmentService
                ->findOrFail(
                    $assessment
                );

        $this->authorize(
            'cancel',
            $model
        );

        $model =
            $this->assessmentService
                ->cancel(
                    $assessment,
                    $request->validated('reason')
                );

        return response()->json([
            'success' => true,

            'message' =>
                'Assessment cancelled successfully.',

            'data' =>
                new AssessmentResource(
                    $model
                ),

            'errors' => null,

            'meta' => [],
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | SUMMARY
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/assessments/summary
    |
    | Permission:
    |
    |     assessment.read
    |
    | Policy:
    |
    |     viewAny()
    |
    */

    public function summary(
        Request $request
    ): JsonResponse {

        $this->authorize(
            'viewAny',
            \App\Models\Assessment::class
        );

        $summary =
            $this->assessmentService
                ->summary(
                    $request
                );

        return response()->json([
            'success' => true,

            'message' =>
                'Assessment summary retrieved successfully.',

            'data' => null,

            'errors' => null,

            'meta' => [
                'summary' =>
                    new AssessmentSummaryResource(
                        $summary
                    ),
            ],
        ]);
    }


    /*
|--------------------------------------------------------------------------
| SUBMIT
|--------------------------------------------------------------------------
|
| POST /api/v1/assessments/{assessment}/submit
|
| Permission:
|
|     assessment.submit
|
| Policy:
|
|     submit()
|
| Business transition:
|
| DRAFT
|     ↓
| PENDING_APPROVAL
|
| RETURNED
|     ↓
| PENDING_APPROVAL
|
| During submission:
|
| - Assessment is locked
| - Status changes to PENDING_APPROVAL
| - Financial calculation is performed
| - Due date is resolved
| - Penalty rule is resolved
| - Interest rule is resolved
|
| Everything occurs inside one database transaction.
|
| If calculation fails:
|
|     Entire transaction rolls back.
|
*/
public function submit(
    string $assessment
): JsonResponse {

    $model =
        $this->assessmentService
            ->findOrFail(
                $assessment
            );

    $this->authorize(
        'submit',
        $model
    );

    $model =
        $this->assessmentService
            ->submit(
                $model
            );

    return response()->json([
        'success' => true,

        'message' =>
            'Assessment submitted successfully for approval.',

        'data' =>
            new AssessmentResource(
                $model
            ),

        'errors' => null,

        'meta' => [],
    ]);
}
}
