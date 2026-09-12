<?php

namespace App\Http\Controllers;

use App\Http\Requests\PenaltyDiscount\CancelPenaltyDiscountRequest;
use App\Http\Requests\PenaltyDiscount\DecidePenaltyDiscountRequest;
use App\Http\Requests\PenaltyDiscount\StorePenaltyDiscountRequest;
use App\Http\Requests\PenaltyDiscount\SubmitPenaltyDiscountRequest;
use App\Http\Resources\PenaltyDiscountRequestResource;
use App\Models\PenaltyDiscountRequest;
use App\Modules\PenaltyDiscount\Services\PenaltyDiscountRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PenaltyDiscountRequestController extends Controller
{
    public function __construct(
        protected PenaltyDiscountRequestService $service,
    ) {
    }

    /**
     * Display a listing of penalty discount requests.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize(
            'viewAny',
            PenaltyDiscountRequest::class
        );

        $requests = PenaltyDiscountRequest::query()
            ->with([
                'invoice',
                'citizen',
                'creator',
                'decider',
            ])
            ->latest()
            ->paginate(
                request()->integer('per_page', 20)
            );

        return PenaltyDiscountRequestResource::collection(
            $requests
        );
    }

    /**
     * Store a newly created penalty discount request.
     */
    public function store(
        StorePenaltyDiscountRequest $request
    ): PenaltyDiscountRequestResource {
        $penaltyDiscountRequest = $this->service->create(
            invoice: $request->string('invoice_id')->toString(),
            requestedAmount: $request->float('requested_amount'),
            reason: $request->string('reason')->toString(),
            createdBy: $request->user()->id,
        );

        return new PenaltyDiscountRequestResource(
            $penaltyDiscountRequest->load([
                'invoice',
                'citizen',
                'creator',
                'decider',
            ])
        );
    }

    /**
     * Display the specified request.
     */
    public function show(
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): PenaltyDiscountRequestResource {
        $this->authorize(
            'view',
            $penaltyDiscountRequest
        );

        return new PenaltyDiscountRequestResource(
            $penaltyDiscountRequest->load([
                'invoice',
                'citizen',
                'creator',
                'decider',
            ])
        );
    }

    /**
     * Submit a draft request.
     */
    public function submit(
        SubmitPenaltyDiscountRequest $request,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): PenaltyDiscountRequestResource {
        $updatedRequest = $this->service->submit(
            $penaltyDiscountRequest
        );

        return new PenaltyDiscountRequestResource(
            $updatedRequest
        );
    }

    /**
     * Make an administrative decision.
     */
    public function decide(
        DecidePenaltyDiscountRequest $request,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): PenaltyDiscountRequestResource {
        $updatedRequest = $this->service->decide(
            request: $penaltyDiscountRequest,
            decision: $request->string('decision')->toString(),
            approvedAmount: $request->input('approved_amount'),
            decisionReason: $request->input('decision_reason'),
            decidedBy: $request->user()->id,
        );

        return new PenaltyDiscountRequestResource(
            $updatedRequest
        );
    }

    /**
     * Cancel a draft or submitted request.
     */
    public function cancel(
        CancelPenaltyDiscountRequest $request,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): PenaltyDiscountRequestResource {
        $updatedRequest = $this->service->cancel(
            $penaltyDiscountRequest
        );

        return new PenaltyDiscountRequestResource(
            $updatedRequest
        );
    }

    /**
     * Display request history.
     */
    public function history(
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): PenaltyDiscountRequestResource {
        $this->authorize(
            'viewHistory',
            $penaltyDiscountRequest
        );

        return new PenaltyDiscountRequestResource(
            $penaltyDiscountRequest->load([
                'invoice',
                'citizen',
                'creator',
                'decider',
            ])
        );
    }
}
