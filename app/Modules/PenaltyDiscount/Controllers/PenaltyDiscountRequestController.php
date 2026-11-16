<?php

declare(strict_types=1);

namespace App\Modules\PenaltyDiscount\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PenaltyDiscountRequest;
use App\Models\User;
use App\Modules\PenaltyDiscount\Requests\DecidePenaltyDiscountRequest;
use App\Modules\PenaltyDiscount\Requests\StorePenaltyDiscountRequest;
use App\Modules\PenaltyDiscount\Requests\UpdatePenaltyDiscountRequest;
use App\Modules\PenaltyDiscount\Resources\PenaltyDiscountRequestResource;
use App\Modules\PenaltyDiscount\Services\PenaltyDiscountRequestService;
use App\Services\ApiResponse;
use App\Services\Authorization\AdministrativeScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class PenaltyDiscountRequestController extends Controller
{
    public function __construct(
        protected PenaltyDiscountRequestService $service,
        protected AdministrativeScopeService $administrativeScopeService,
    ) {
    }

    /**
     * Build a penalty-discount request query restricted to the
     * authenticated user's administrative-unit scope.
     *
     * Access is determined through the related invoice.
     */
    protected function authorizedRequestQuery(User $user): Builder
    {
        return PenaltyDiscountRequest::query()
            ->whereHas(
                'invoice',
                function (Builder $invoiceQuery) use ($user): void {
                    $this->administrativeScopeService
                        ->applyAdministrativeScope(
                            $invoiceQuery,
                            $user,
                            'invoices.administrative_unit_id'
                        );
                }
            );
    }

    /**
     * Build an invoice query restricted to the authenticated user's
     * administrative-unit scope.
     */
    protected function authorizedInvoiceQuery(User $user): Builder
    {
        $query = Invoice::query();

        $this->administrativeScopeService
            ->applyAdministrativeScope(
                $query,
                $user,
                'invoices.administrative_unit_id'
            );

        return $query;
    }

    /**
     * Resolve a route-bound penalty-discount request through the
     * authenticated user's administrative scope.
     *
     * Route model binding alone does not enforce this scope.
     */
    protected function resolveAuthorizedRequest(
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): PenaltyDiscountRequest {
        $user = request()->user();

        abort_unless($user instanceof User, 401);

        return $this->authorizedRequestQuery($user)
            ->whereKey($penaltyDiscountRequest->getKey())
            ->firstOrFail();
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

        $user = request()->user();

        abort_unless($user instanceof User, 401);

        $perPage = min(
            max(request()->integer('per_page', 20), 1),
            100
        );

        $query = $this->authorizedRequestQuery($user);

        $requests = (clone $query)
            ->with([
                'invoice',
                'citizen',
                'creator',
                'decider',
                'supportingFiles',
            ])
            ->latest()
            ->paginate($perPage);

        /*
        |--------------------------------------------------------------------------
        | Administrative-scope-aware summary
        |--------------------------------------------------------------------------
        |
        | Do not call the original unscoped service summary here.
        | Aggregate only requests whose invoices are within the
        | authenticated user's administrative scope.
        |
        */

        $summary = $this->buildScopedSummary($query);

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
     * Build summary counts from the authorized request query.
     *
     * @return array<string,mixed>
     */
    protected function buildScopedSummary(Builder $query): array
    {
        $total = (clone $query)->count();

        $byStatus = (clone $query)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->toArray();

        return [
            'total' => $total,
            'by_status' => $byStatus,
        ];
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

        $user = request()->user();

        abort_unless($user instanceof User, 401);

        $summary = $this->buildScopedSummary(
            $this->authorizedRequestQuery($user)
        );

        return ApiResponse::success(
            data: $summary,
            message: 'Penalty discount request summary retrieved successfully.'
        );
    }

    /**
     * Create a penalty discount request.
     *
     * The invoice must belong to the authenticated user's
     * administrative-unit scope.
     */
    public function store(
        StorePenaltyDiscountRequest $request
    ): JsonResponse {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $invoiceId = $request->string('invoice_id')->toString();

        /*
        |--------------------------------------------------------------------------
        | Verify invoice administrative scope
        |--------------------------------------------------------------------------
        */

        $invoice = $this->authorizedInvoiceQuery($user)
            ->whereKey($invoiceId)
            ->firstOrFail();

        $supportingFile = $request->file('supporting_file');

        $penaltyDiscountRequest = $this->service->create(
            invoice: (string) $invoice->id,
            requestedAmount: $request->float('requested_amount'),
            reason: $request->string('reason')->toString(),
            createdBy: (string) $user->id,
            supportingFile: $supportingFile instanceof UploadedFile
                ? $supportingFile
                : null,
        );

        /*
        |--------------------------------------------------------------------------
        | Reload through authorized query
        |--------------------------------------------------------------------------
        */

        $penaltyDiscountRequest = $this->authorizedRequestQuery($user)
            ->whereKey($penaltyDiscountRequest->getKey())
            ->firstOrFail();

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
        $penaltyDiscountRequest = $this->resolveAuthorizedRequest(
            $penaltyDiscountRequest
        );

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
     *
     * Both the existing request and the replacement invoice, if
     * supplied, must be within the authenticated user's scope.
     */
    public function update(
        UpdatePenaltyDiscountRequest $request,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): JsonResponse {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        /*
        |--------------------------------------------------------------------------
        | Verify existing request scope
        |--------------------------------------------------------------------------
        */

        $penaltyDiscountRequest = $this->resolveAuthorizedRequest(
            $penaltyDiscountRequest
        );

        /*
        |--------------------------------------------------------------------------
        | Verify replacement invoice scope
        |--------------------------------------------------------------------------
        */

        $invoiceId = $request->string('invoice_id')->toString();

        $invoice = $this->authorizedInvoiceQuery($user)
            ->whereKey($invoiceId)
            ->firstOrFail();

        $supportingFile = $request->file('supporting_file');

        $updatedRequest = $this->service->update(
            request: $penaltyDiscountRequest,
            invoice: (string) $invoice->id,
            requestedAmount: $request->float('requested_amount'),
            reason: $request->string('reason')->toString(),
            supportingFile: $supportingFile instanceof UploadedFile
                ? $supportingFile
                : null,
        );

        /*
        |--------------------------------------------------------------------------
        | Reload through authorized query
        |--------------------------------------------------------------------------
        */

        $updatedRequest = $this->authorizedRequestQuery($user)
            ->whereKey($updatedRequest->getKey())
            ->firstOrFail();

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
        $penaltyDiscountRequest = $this->resolveAuthorizedRequest(
            $penaltyDiscountRequest
        );

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
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $penaltyDiscountRequest = $this->resolveAuthorizedRequest(
            $penaltyDiscountRequest
        );

        $this->authorize(
            'decide',
            $penaltyDiscountRequest
        );

        $updatedRequest = $this->service->decide(
            request: $penaltyDiscountRequest,
            decision: $request->string('decision')->toString(),
            approvedAmount: $request->filled('approved_amount')
                ? (float) $request->input('approved_amount')
                : null,
            decisionReason: $request->input('decision_reason'),
            decidedBy: (string) $user->id,
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
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $penaltyDiscountRequest = $this->resolveAuthorizedRequest(
            $penaltyDiscountRequest
        );

        $this->authorize(
            'apply',
            $penaltyDiscountRequest
        );

        $updatedRequest = $this->service->apply(
            $penaltyDiscountRequest,
            (string) $user->id
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
        $penaltyDiscountRequest = $this->resolveAuthorizedRequest(
            $penaltyDiscountRequest
        );

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
        $penaltyDiscountRequest = $this->resolveAuthorizedRequest(
            $penaltyDiscountRequest
        );

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
