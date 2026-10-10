<?php

namespace App\Modules\Assessment\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LeaseAmendment;
use App\Modules\Assessment\Requests\RejectLeaseAmendmentRequest;
use App\Modules\Assessment\Requests\StoreLeaseAmendmentRequest;
use App\Modules\Assessment\Requests\UpdateLeaseAmendmentRequest;
use App\Modules\Assessment\Resources\LeaseAmendmentResource;
use App\Modules\Assessment\Services\LeaseAmendmentService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class LeaseAmendmentController extends Controller
{
    public function __construct(
        private readonly LeaseAmendmentService $leaseAmendmentService,
    ) {
    }

    /**
     * List lease amendments with validated filters, pagination,
     * and summary statistics scoped to the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaseAmendment::class);

        $user = $request->user();

        $filters = $request->validate([
            'search' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'status' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
            ],

            'amendment_type' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'previous_assessment_id' => [
                'sometimes',
                'nullable',
                'uuid',
                'exists:assessments,id',
            ],

            'per_page' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        // The service must apply the user's administrative-unit scope.
        $amendments = $this->leaseAmendmentService->paginate(
            $filters,
            $user
        );

        $amendments->getCollection()->each(
            fn (LeaseAmendment $amendment) => $amendment->loadMissing(
                $this->resourceRelationships()
            )
        );

        // Summary statistics must use the same user scope as the list.
        $summary = $this->leaseAmendmentService->summary(
            [
                'previous_assessment_id' =>
                    $filters['previous_assessment_id'] ?? null,

                'amendment_type' =>
                    $filters['amendment_type'] ?? null,
            ],
            $user
        );

        return ApiResponse::success(
            data: LeaseAmendmentResource::collection($amendments),
            message: 'Lease amendments retrieved successfully.',
            meta: [
                'current_page' => $amendments->currentPage(),
                'last_page' => $amendments->lastPage(),
                'per_page' => $amendments->perPage(),
                'total' => $amendments->total(),
            ],
            summary: $summary,
        );
    }

    /**
     * Show a single lease amendment.
     */
    public function show(
        Request $request,
        LeaseAmendment $leaseAmendment
    ): JsonResponse {
        $this->authorize('view', $leaseAmendment);

        $amendment = $this->leaseAmendmentService->find(
            $leaseAmendment,
            $request->user()
        );

        $amendment->loadMissing($this->resourceRelationships());

        return $this->successResponse(
            $amendment,
            'Lease amendment retrieved successfully.'
        );
    }

    /**
     * Create a lease amendment.
     */
    public function store(
        StoreLeaseAmendmentRequest $request
    ): JsonResponse {
        $this->authorize('create', LeaseAmendment::class);

        try {
            $amendment = $this->leaseAmendmentService->create(
                $request->validated(),
                $request->user()
            );

            return $this->successResponse(
                $amendment,
                'Lease amendment created successfully.',
                201
            );
        } catch (Throwable $exception) {
            return $this->handleActionException(
                $exception,
                'Unable to create the lease amendment.'
            );
        }
    }

    /**
     * Update an editable lease amendment.
     */
    public function update(
        UpdateLeaseAmendmentRequest $request,
        LeaseAmendment $leaseAmendment
    ): JsonResponse {
        $this->authorize('update', $leaseAmendment);

        try {
            $amendment = $this->leaseAmendmentService->update(
                $leaseAmendment,
                $request->validated(),
                $request->user()
            );

            return $this->successResponse(
                $amendment,
                'Lease amendment updated successfully.'
            );
        } catch (Throwable $exception) {
            return $this->handleActionException(
                $exception,
                'Unable to update the lease amendment.'
            );
        }
    }

    /**
     * Submit an amendment for approval.
     */
    public function submit(
        Request $request,
        LeaseAmendment $leaseAmendment
    ): JsonResponse {
        $this->authorize('submit', $leaseAmendment);

        try {
            $amendment = $this->leaseAmendmentService->submit(
                $leaseAmendment,
                $request->user()
            );

            return $this->successResponse(
                $amendment,
                'Lease amendment submitted for approval.'
            );
        } catch (Throwable $exception) {
            return $this->handleActionException(
                $exception,
                'Unable to submit the lease amendment.'
            );
        }
    }

    /**
     * Approve an amendment.
     */
    public function approve(
        Request $request,
        LeaseAmendment $leaseAmendment
    ): JsonResponse {
        $this->authorize('approve', $leaseAmendment);

        try {
            $amendment = $this->leaseAmendmentService->approve(
                $leaseAmendment,
                $request->user()
            );

            return $this->successResponse(
                $amendment,
                'Lease amendment approved successfully.'
            );
        } catch (Throwable $exception) {
            return $this->handleActionException(
                $exception,
                'Unable to approve the lease amendment.'
            );
        }
    }

    /**
     * Reject an amendment.
     */
    public function reject(
        RejectLeaseAmendmentRequest $request,
        LeaseAmendment $leaseAmendment
    ): JsonResponse {
        $this->authorize('reject', $leaseAmendment);

        try {
            $amendment = $this->leaseAmendmentService->reject(
                $leaseAmendment,
                $request->validated('reason'),
                $request->user()
            );

            return $this->successResponse(
                $amendment,
                'Lease amendment rejected successfully.'
            );
        } catch (Throwable $exception) {
            return $this->handleActionException(
                $exception,
                'Unable to reject the lease amendment.'
            );
        }
    }

 
    /**
     * Apply an approved amendment.
     */
    public function apply(
        LeaseAmendment $leaseAmendment
    ): JsonResponse {
        $this->authorize('apply', $leaseAmendment);

        try {
            $amendment = $this->leaseAmendmentService->apply(
                $leaseAmendment,
                auth()->user()
            );

            return $this->successResponse(
                $amendment,
                'Lease amendment applied successfully.'
            );
        } catch (Throwable $exception) {
            return $this->handleActionException(
                $exception,
                'Unable to apply the lease amendment.'
            );
        }
    }
    
    /**
     * Cancel an amendment.
     */
    public function cancel(
        Request $request,
        LeaseAmendment $leaseAmendment
    ): JsonResponse {
        $this->authorize('cancel', $leaseAmendment);

        try {
            $amendment = $this->leaseAmendmentService->cancel(
                $leaseAmendment,
                $request->user()
            );

            return $this->successResponse(
                $amendment,
                'Lease amendment cancelled successfully.'
            );
        } catch (Throwable $exception) {
            return $this->handleActionException(
                $exception,
                'Unable to cancel the lease amendment.'
            );
        }
    }

    /**
     * Return all relationships required by LeaseAmendmentResource.
     *
     * These relationship names must exist on the LeaseAmendment model.
     */
    private function resourceRelationships(): array
    {
        return [
            'previousAssessment',
            'newAssessment',
            'changes',
            'createdBy',
            'decidedBy',
            'appliedBy',
            'files',
        ];
    }

    /**
     * Return a consistent success response.
     */
    private function successResponse(
        LeaseAmendment $amendment,
        string $message,
        int $status = 200
    ): JsonResponse {
        $amendment->loadMissing($this->resourceRelationships());

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => new LeaseAmendmentResource($amendment),
        ], $status);
    }

    /**
     * Handle action exceptions without exposing internal details.
     */
    private function handleActionException(
        Throwable $exception,
        string $fallbackMessage
    ): JsonResponse {
        if (
            $exception instanceof ValidationException
            || $exception instanceof HttpExceptionInterface
        ) {
            throw $exception;
        }

        report($exception);

        return response()->json([
            'success' => false,
            'message' => $fallbackMessage,
            'data' => null,
        ], 500);
    }
}