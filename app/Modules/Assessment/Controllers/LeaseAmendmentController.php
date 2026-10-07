<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeaseAmendment\ApplyLeaseAmendmentRequest;
use App\Http\Requests\LeaseAmendment\RejectLeaseAmendmentRequest;
use App\Http\Requests\LeaseAmendment\StoreLeaseAmendmentRequest;
use App\Http\Requests\LeaseAmendment\UpdateLeaseAmendmentRequest;
use App\Http\Resources\LeaseAmendmentResource;
use App\Models\LeaseAmendment;
use App\Services\LeaseAmendmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class LeaseAmendmentController extends Controller
{
    public function __construct(
        private readonly LeaseAmendmentService $leaseAmendmentService,
    ) {
    }

    /**
     * List lease amendments.
     */
    public function index(Request $request): JsonResponse
    {
        $amendments = $this->leaseAmendmentService->paginate(
            $request->validated()
        );

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
            report($e);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
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
            report($e);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
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
            report($e);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
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
            report($e);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
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
            $amendment = $this->leaseAmendmentService->reject(
                $leaseAmendment,
                $request->validated()['reason'],
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Lease amendment rejected successfully.',
                'data' => new LeaseAmendmentResource($amendment),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Apply an approved amendment.
     *
     * IMPORTANT:
     * This does not recalculate or modify the old assessment.
     * It marks the amendment as applied after the independent
     * replacement assessment has been created.
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
            report($e);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
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
            report($e);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}