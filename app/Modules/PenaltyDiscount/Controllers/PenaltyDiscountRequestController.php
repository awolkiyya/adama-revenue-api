<?php

namespace App\Modules\PenaltyDiscount\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PenaltyDiscountRequest;
use App\Modules\PenaltyDiscount\Requests\DecidePenaltyDiscountRequest;
use App\Modules\PenaltyDiscount\Requests\StorePenaltyDiscountRequest;
use App\Modules\PenaltyDiscount\Requests\UpdatePenaltyDiscountRequest;
use App\Modules\PenaltyDiscount\Resources\PenaltyDiscountRequestResource;
use App\Modules\PenaltyDiscount\Services\PenaltyDiscountRequestService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class PenaltyDiscountRequestController extends Controller
{
    public function __construct(
        protected PenaltyDiscountRequestService $service,
    ) {
    }

    /**
     * Load all relations required by the API resource.
     */
    private function loadRelations(
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): PenaltyDiscountRequest {
        return $penaltyDiscountRequest->load([
            'invoice',
            'citizen',
            'creator',
            'decider',
            'supportingFiles',
        ]);
    }

    /**
     * List penalty discount requests with pagination and summary.
     */
    public function index(): JsonResponse
    {
        $this->authorize(
            'viewAny',
            PenaltyDiscountRequest::class
        );

        $perPage = min(
            max(request()->integer('per_page', 20), 1),
            100
        );

        $requests = PenaltyDiscountRequest::query()
            ->with([
                'invoice',
                'citizen',
                'creator',
                'decider',
                'supportingFiles',
            ])
            ->latest()
            ->paginate($perPage);

        $summary = $this->service->summary();

        return ApiResponse::success(
            data: PenaltyDiscountRequestResource::collection(
                $requests
            ),
            message: 'Penalty discount requests retrieved successfully.',
            meta: [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
            summary: $summary,
        );
    }

    /**
     * Get the penalty discount request dashboard summary.
     */
    public function summary(): JsonResponse
    {
        $this->authorize(
            'viewAny',
            PenaltyDiscountRequest::class
        );

        return ApiResponse::success(
            data: $this->service->summary(),
            message: 'Penalty discount request summary retrieved successfully.'
        );
    }

    /**
     * Create a penalty discount request.
     */
    public function store(
        StorePenaltyDiscountRequest $request
    ): JsonResponse {
        $supportingFile = $request->file('supporting_file');

        $penaltyDiscountRequest = $this->service->create(
            invoice: $request->string('invoice_id')->toString(),
            requestedAmount: $request->float('requested_amount'),
            reason: $request->string('reason')->toString(),
            createdBy: (string) $request->user()->id,
            supportingFile: $supportingFile instanceof UploadedFile
                ? $supportingFile
                : null,
        );

        return ApiResponse::created(
            data: new PenaltyDiscountRequestResource(
                $this->loadRelations($penaltyDiscountRequest)
            ),
            message: 'Penalty discount request created successfully.'
        );
    }

    /**
     * Show a penalty discount request.
     */
    public function show(
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): JsonResponse {
        $this->authorize(
            'view',
            $penaltyDiscountRequest
        );

        return ApiResponse::success(
            data: new PenaltyDiscountRequestResource(
                $this->loadRelations($penaltyDiscountRequest)
            ),
            message: 'Penalty discount request retrieved successfully.'
        );
    }

    /**
     * Update a draft penalty discount request.
     */
    public function update(
        UpdatePenaltyDiscountRequest $request,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): JsonResponse {
        $supportingFile = $request->file('supporting_file');

        $updatedRequest = $this->service->update(
            request: $penaltyDiscountRequest,
            invoice: $request->string('invoice_id')->toString(),
            requestedAmount: $request->float('requested_amount'),
            reason: $request->string('reason')->toString(),
            supportingFile: $supportingFile instanceof UploadedFile
                ? $supportingFile
                : null,
        );

        return ApiResponse::updated(
            data: new PenaltyDiscountRequestResource(
                $this->loadRelations($updatedRequest)
            ),
            message: 'Penalty discount request updated successfully.'
        );
    }

    /**
     * Submit a draft penalty discount request.
     */
    public function submit(
        Request $request,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): JsonResponse {
        $this->authorize(
            'submit',
            $penaltyDiscountRequest
        );

        $updatedRequest = $this->service->submit(
            $penaltyDiscountRequest
        );

        return ApiResponse::updated(
            data: new PenaltyDiscountRequestResource(
                $this->loadRelations($updatedRequest)
            ),
            message: 'Penalty discount request submitted successfully.'
        );
    }

    /**
     * Approve or reject a submitted penalty discount request.
     */
    public function decide(
        DecidePenaltyDiscountRequest $request,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): JsonResponse {
        $updatedRequest = $this->service->decide(
            request: $penaltyDiscountRequest,
            decision: $request->string('decision')->toString(),
            approvedAmount: $request->filled('approved_amount')
                ? (float) $request->input('approved_amount')
                : null,
            decisionReason: $request->input('decision_reason'),
            decidedBy: (string) $request->user()->id,
        );

        $message = $updatedRequest->decision
            === PenaltyDiscountRequest::DECISION_APPROVED
                ? 'Penalty discount request approved successfully.'
                : 'Penalty discount request rejected successfully.';

        return ApiResponse::updated(
            data: new PenaltyDiscountRequestResource(
                $this->loadRelations($updatedRequest)
            ),
            message: $message
        );
    }

    /**
     * Apply an approved discount to the invoice.
     */
    public function apply(
        Request $request,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): JsonResponse {
        $this->authorize(
            'apply',
            $penaltyDiscountRequest
        );

        $updatedRequest = $this->service->apply(
            $penaltyDiscountRequest,
            (string) $request->user()->id
        );

        return ApiResponse::updated(
            data: new PenaltyDiscountRequestResource(
                $this->loadRelations($updatedRequest)
            ),
            message: 'Approved penalty discount applied to the invoice successfully.'
        );
    }

    /**
     * Cancel a penalty discount request.
     */
    public function cancel(
        Request $request,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): JsonResponse {
        $this->authorize(
            'cancel',
            $penaltyDiscountRequest
        );

        $updatedRequest = $this->service->cancel(
            $penaltyDiscountRequest
        );

        return ApiResponse::updated(
            data: new PenaltyDiscountRequestResource(
                $this->loadRelations($updatedRequest)
            ),
            message: 'Penalty discount request cancelled successfully.'
        );
    }

    /**
     * View a penalty discount request and its history information.
     */
    public function history(
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): JsonResponse {
        $this->authorize(
            'viewHistory',
            $penaltyDiscountRequest
        );

        return ApiResponse::success(
            data: new PenaltyDiscountRequestResource(
                $this->loadRelations($penaltyDiscountRequest)
            ),
            message: 'Penalty discount request history retrieved successfully.'
        );
    }
}
