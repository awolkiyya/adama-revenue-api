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
     * List reconciliations according to the authenticated user's
     * permissions and access scope.
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
     * Create a draft cash closing.
     */
    public function store(
        StoreCashReconciliationRequest $request
    ): JsonResponse {
        $this->authorize('create', CashReconciliation::class);

        $reconciliation = $this->service->createDraft(
            $request->user(),
            $request->validated()
        );

        return (new CashReconciliationResource(
            $reconciliation->load(['collector', 'reviewer'])
        ))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/v1/cash-reconciliations/{cashReconciliation}
     *
     * View an individual reconciliation.
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

        return new CashReconciliationResource(
            $reconciliation->loadMissing(['collector', 'reviewer'])
        );
    }

    /**
     * PUT/PATCH /api/v1/cash-reconciliations/{cashReconciliation}
     *
     * Update a draft or returned closing.
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

        return new CashReconciliationResource(
            $reconciliation->loadMissing(['collector', 'reviewer'])
        );
    }

    /**
     * POST /api/v1/cash-reconciliations/{cashReconciliation}/submit
     *
     * Submit the physical cash count for finance review.
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

        return new CashReconciliationResource(
            $reconciliation->loadMissing(['collector', 'reviewer'])
        );
    }

    /**
     * POST /api/v1/cash-reconciliations/{cashReconciliation}/review
     *
     * Review a submitted cash closing.
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

        return new CashReconciliationResource(
            $reconciliation->loadMissing(['collector', 'reviewer'])
        );
    }

    /**
     * POST /api/v1/cash-reconciliations/{cashReconciliation}/cancel
     *
     * Cancel an eligible draft or returned closing.
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

        return new CashReconciliationResource(
            $reconciliation->loadMissing(['collector', 'reviewer'])
        );
    }
}