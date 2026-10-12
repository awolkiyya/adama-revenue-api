<?php

namespace App\Modules\CashReconciliation\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CashReconciliation;
use App\Modules\CashReconciliation\Requests\IndexCashReconciliationRequest;
use App\Modules\CashReconciliation\Requests\ReviewCashReconciliationRequest;
use App\Modules\CashReconciliation\Requests\StoreCashReconciliationRequest;
use App\Modules\CashReconciliation\Requests\SubmitCashReconciliationRequest;
use App\Modules\CashReconciliation\Requests\UpdateCashReconciliationRequest;
use App\Modules\CashReconciliation\Resources\CashReconciliationResource;
use App\Modules\CashReconciliation\Services\CashReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashReconciliationController extends Controller
{
    public function __construct(
        protected CashReconciliationService $service
    ) {}

    /**
     * GET /api/v1/cash-reconciliations
     *
     * List cash reconciliations visible to the authenticated user.
     *
     * Users with cash_reconciliations.view_all can access all
     * reconciliations. Other authorized users are restricted to
     * their own reconciliations by the service.
     */
    public function index(
        IndexCashReconciliationRequest $request
    ): AnonymousResourceCollection {
        $this->authorize('viewAny', CashReconciliation::class);

        $reconciliations = $this->service->paginate(
            $request->user(),
            $request->validated()
        );

        return CashReconciliationResource::collection(
            $reconciliations
        );
    }

    /**
     * POST /api/v1/cash-reconciliations
     *
     * Create a draft cash reconciliation.
     *
     * The service assigns collector_id from the authenticated user.
     * Clients cannot choose another collector or submit authoritative
     * financial totals through this endpoint.
     */
    public function store(
        StoreCashReconciliationRequest $request
    ): JsonResponse {
        $this->authorize('create', CashReconciliation::class);

        $reconciliation = $this->service->createDraft(
            $request->user(),
            $request->validated()
        );

        $reconciliation->load(['collector', 'reviewer']);

        return (new CashReconciliationResource($reconciliation))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/v1/cash-reconciliations/{cashReconciliation}
     *
     * Retrieve one reconciliation.
     */
    public function show(
        Request $request,
        CashReconciliation $cashReconciliation
    ): CashReconciliationResource {
        $this->authorize('view', $cashReconciliation);

        $reconciliation = $this->service->findForUser(
            $request->user(),
            $cashReconciliation->getKey()
        );

        $reconciliation->loadMissing(['collector', 'reviewer']);

        return new CashReconciliationResource($reconciliation);
    }

    /**
     * PUT/PATCH /api/v1/cash-reconciliations/{cashReconciliation}
     *
     * Update an editable draft or returned reconciliation.
     *
     * Authorization and state-transition checks are enforced by both
     * the policy and the service.
     */
    public function update(
        UpdateCashReconciliationRequest $request,
        CashReconciliation $cashReconciliation
    ): CashReconciliationResource {
        $this->authorize('update', $cashReconciliation);

        $reconciliation = $this->service->updateDraft(
            $request->user(),
            $cashReconciliation,
            $request->validated()
        );

        $reconciliation->loadMissing(['collector', 'reviewer']);

        return new CashReconciliationResource($reconciliation);
    }

    /**
     * POST /api/v1/cash-reconciliations/{cashReconciliation}/submit
     *
     * Submit the physical cash count for review.
     *
     * The service calculates expected cash from eligible completed
     * cash payments, calculates the difference, and changes the status
     * to PENDING_REVIEW.
     */
    public function submit(
        SubmitCashReconciliationRequest $request,
        CashReconciliation $cashReconciliation
    ): CashReconciliationResource {
        $this->authorize('submit', $cashReconciliation);

        $reconciliation = $this->service->submit(
            $request->user(),
            $cashReconciliation,
            $request->validated()
        );

        $reconciliation->loadMissing(['collector', 'reviewer']);

        return new CashReconciliationResource($reconciliation);
    }

    /**
     * POST /api/v1/cash-reconciliations/{cashReconciliation}/review
     *
     * Review a submitted reconciliation.
     *
     * Supported decisions:
     * - RECONCILED: expected and actual cash match.
     * - DISCREPANCY: a shortage or overage exists.
     * - RETURNED: return the reconciliation to the collector.
     *
     * The service prevents a collector from reviewing their own
     * reconciliation and validates the decision against the amounts.
     */
    public function review(
        ReviewCashReconciliationRequest $request,
        CashReconciliation $cashReconciliation
    ): CashReconciliationResource {
        $this->authorize('review', $cashReconciliation);

        $reconciliation = $this->service->review(
            $request->user(),
            $cashReconciliation,
            $request->validated()
        );

        $reconciliation->loadMissing(['collector', 'reviewer']);

        return new CashReconciliationResource($reconciliation);
    }

    /**
     * POST /api/v1/cash-reconciliations/{cashReconciliation}/cancel
     *
     * Cancel an editable draft or returned reconciliation.
     *
     * Submitted reconciliations cannot be cancelled through this
     * endpoint unless the service's business rules explicitly change.
     */
    public function cancel(
        Request $request,
        CashReconciliation $cashReconciliation
    ): CashReconciliationResource {
        $this->authorize('cancel', $cashReconciliation);

        $reconciliation = $this->service->cancel(
            $request->user(),
            $cashReconciliation
        );

        $reconciliation->loadMissing(['collector', 'reviewer']);

        return new CashReconciliationResource($reconciliation);
    }
}
