<?php

namespace App\Modules\Assessment\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LeaseAmendment;
use App\Modules\Assessment\Requests\ApplyLeaseAmendmentRequest;
use App\Modules\Assessment\Requests\RejectLeaseAmendmentRequest;
use App\Modules\Assessment\Requests\StoreLeaseAmendmentRequest;
use App\Modules\Assessment\Requests\UpdateLeaseAmendmentRequest;
use App\Modules\Assessment\Resources\LeaseAmendmentResource;
use App\Modules\Assessment\Services\LeaseAmendmentService;
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
     * List lease amendments with validated filters and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
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
            ],
            'per_page' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $amendments = $this->leaseAmendmentService->paginate($filters);

        return response()->json([
            'success' => true,
            'message' => 'Lease amendments retrieved successfully.',
            'data' => LeaseAmendmentResource::collection($amendments),
            'meta' => [
                'current_page' => $amendments->currentPage(),
                'last_page' => $amendments->lastPage(),
                'per_page' => $amendments->perPage(),
                'total' => $amendments->total(),
            ],
        ]);
    }

    /**
     * Show a single amendment.
     */
    public function show(LeaseAmendment $leaseAmendment): JsonResponse
    {
        $amendment = $this->leaseAmendmentService->find(
            $leaseAmendment
        );

        return response()->json([
            'success' => true,
            'message' => 'Lease amendment retrieved successfully.',
            'data' => new LeaseAmendmentResource($amendment),
        ]);
    }

    /**
     * Create a new amendment.
     */
    public function store(
        StoreLeaseAmendmentRequest $request
    ): JsonResponse {
        try {
            $amendment = $this->leaseAmendmentService->create(
                $request->validated(),
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Lease amendment created successfully.',
                'data' => new LeaseAmendmentResource($amendment),
            ], 201);
        } catch (Throwable $e) {
            return $this->handleActionException(
                $e,
                'Unable to create the lease amendment.'
            );
        }
    }

    /**
     * Update a draft amendment.
     */
    public function update(
        UpdateLeaseAmendmentRequest $request,
        LeaseAmendment $leaseAmendment
    ): JsonResponse {
        try {
            $amendment = $this->leaseAmendmentService->update(
                $leaseAmendment,
                $request->validated(),
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Lease amendment updated successfully.',
                'data' => new LeaseAmendmentResource($amendment),
            ]);
        } catch (Throwable $e) {
            return $this->handleActionException(
                $e,
                'Unable to update the lease amendment.'
            );
        }
    }

    /**
     * Submit an amendment for approval.
     */
    public function submit(
        LeaseAmendment $leaseAmendment
    ): JsonResponse {
        try {
            $amendment = $this->leaseAmendmentService->submit(
                $leaseAmendment,
                request()->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Lease amendment submitted for approval.',
                'data' => new LeaseAmendmentResource($amendment),
            ]);
        } catch (Throwable $e) {
            return $this->handleActionException(
                $e,
                'Unable to submit the lease amendment.'
            );
        }
    }

    /**
     * Approve an amendment.
     */
    public function approve(
        LeaseAmendment $leaseAmendment
    ): JsonResponse {
        try {
            $amendment = $this->leaseAmendmentService->approve(
                $leaseAmendment,
                request()->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Lease amendment approved successfully.',
                'data' => new LeaseAmendmentResource($amendment),
            ]);
        } catch (Throwable $e) {
            return $this->handleActionException(
                $e,
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
        try {
            $validated = $request->validated();

            $amendment = $this->leaseAmendmentService->reject(
                $leaseAmendment,
                $validated['reason'],
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Lease amendment rejected successfully.',
                'data' => new LeaseAmendmentResource($amendment),
            ]);
        } catch (Throwable $e) {
            return $this->handleActionException(
                $e,
                'Unable to reject the lease amendment.'
            );
        }
    }

    /**
     * Apply an approved amendment.
     *
     * The service must verify that the replacement assessment is valid.
     * Applying an amendment must not silently modify the previous
     * assessment, its payments, or its payment schedule.
     */
    public function apply(
        ApplyLeaseAmendmentRequest $request,
        LeaseAmendment $leaseAmendment
    ): JsonResponse {
        try {
            $amendment = $this->leaseAmendmentService->apply(
                $leaseAmendment,
                $request->validated(),
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Lease amendment applied successfully.',
                'data' => new LeaseAmendmentResource($amendment),
            ]);
        } catch (Throwable $e) {
            return $this->handleActionException(
                $e,
                'Unable to apply the lease amendment.'
            );
        }
    }

    /**
     * Cancel an amendment.
     */
    public function cancel(
        LeaseAmendment $leaseAmendment
    ): JsonResponse {
        try {
            $amendment = $this->leaseAmendmentService->cancel(
                $leaseAmendment,
                request()->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Lease amendment cancelled successfully.',
                'data' => new LeaseAmendmentResource($amendment),
            ]);
        } catch (Throwable $e) {
            return $this->handleActionException(
                $e,
                'Unable to cancel the lease amendment.'
            );
        }
    }

    /**
     * Handle expected action failures without exposing internal
     * exception messages or stack traces to API clients.
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