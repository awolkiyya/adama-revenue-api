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
    */

    public function index(
        Request $request
    ): JsonResponse {

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
    */

    public function show(
        string $assessment
    ): JsonResponse {

        $model =
            $this->assessmentService
                ->findOrFail(
                    $assessment
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

        $model =
            $this->assessmentService
                ->update(
                    $assessment,
                    $request
                );

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
    | The service determines whether deletion is allowed
    | for the current assessment state.
    |
    */

    public function destroy(
        string $assessment
    ): JsonResponse {

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
    | approval workflow:
    |
    | - validate assessment state
    | - approve assessment
    | - record approved_by
    | - record approved_at
    | - create invoice
    | - create invoice items
    | - aggregate invoice totals
    | - issue invoice
    | - record issued_by
    | - record issued_at
    | - trigger taxpayer notification
    |
    | The controller only delegates the operation.
    |
    */

    public function approve(
        string $assessment
    ): JsonResponse {

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

    public function returnAssessment(
        ReturnAssessmentRequest $request,
        string $assessment
    ): JsonResponse {

        $model =
            $this->assessmentService
                ->returnAssessment(
                    $assessment,
                    $request->validated('reason')
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
    */

    public function summary(
        Request $request
    ): JsonResponse {

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
}