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
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Modules\CashReconciliation\Requests\ExpectedByCollectorRequest;
use App\Services\ApiResponse;

class CashReconciliationController extends Controller
{
    public function __construct(
        protected CashReconciliationService $service
    ) {}

    /**
     * GET /api/v1/cash-reconciliations
     *
     * List cash reconciliations visible to the authenticated user.
     */
    public function index(
        IndexCashReconciliationRequest $request
    ): AnonymousResourceCollection {
        $user = $request->user();

        Log::info('Cash reconciliation list requested.', [
            'user_id' => $user?->getKey(),
            'filters' => $request->validated(),
        ]);

        try {
            $this->authorize('viewAny', CashReconciliation::class);

            $reconciliations = $this->service->paginate(
                $user,
                $request->validated()
            );

            Log::info('Cash reconciliation list retrieved successfully.', [
                'user_id' => $user?->getKey(),
                'total' => $reconciliations->total(),
                'current_page' => $reconciliations->currentPage(),
            ]);

            return CashReconciliationResource::collection(
                $reconciliations
            );
        } catch (Throwable $exception) {
            Log::error('Failed to retrieve cash reconciliation list.', [
                'user_id' => $user?->getKey(),
                'filters' => $request->validated(),
                'exception' => $exception->getMessage(),
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            throw $exception;
        }
    }

    /**
     * POST /api/v1/cash-reconciliations
     *
     * Create a draft cash reconciliation with optional evidence files.
     */
    public function store(
        StoreCashReconciliationRequest $request
    ): JsonResponse {
        $user = $request->user();

        $evidenceCount = count($request->file('evidence', []));

        Log::info('Cash reconciliation creation requested.', [
            'user_id' => $user?->getKey(),
            'evidence_count' => $evidenceCount,
        ]);

        try {
            $this->authorize('create', CashReconciliation::class);

            $reconciliation = $this->service->createDraft(
                $user,
                $request->safe()->except(['evidence']),
                $request->file('evidence', [])
            );

            $reconciliation->loadMissing([
                'collector',
                'reviewer',
                'evidence',
            ]);

            Log::info('Cash reconciliation draft created successfully.', [
                'user_id' => $user?->getKey(),
                'reconciliation_id' => (string) $reconciliation->getKey(),
                'status' => $reconciliation->status,
                'evidence_count' => $reconciliation->evidence->count(),
            ]);

            return (new CashReconciliationResource($reconciliation))
                ->response()
                ->setStatusCode(201);
        } catch (Throwable $exception) {
            Log::error('Failed to create cash reconciliation draft.', [
                'user_id' => $user?->getKey(),
                'evidence_count' => $evidenceCount,
                'exception' => $exception->getMessage(),
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            throw $exception;
        }
    }

    /**
     * GET /api/v1/cash-reconciliations/{cashReconciliation}
     *
     * Retrieve one reconciliation using its UUID.
     */
    public function show(
        Request $request,
        CashReconciliation $cashReconciliation
    ): CashReconciliationResource {
        $user = $request->user();

        $reconciliationId = (string) $cashReconciliation->getKey();

        Log::info('Cash reconciliation details requested.', [
            'user_id' => $user?->getKey(),
            'reconciliation_id' => $reconciliationId,
        ]);

        try {
            $this->authorize('view', $cashReconciliation);

            $reconciliation = $this->service->findForUser(
                $user,
                $reconciliationId
            );

            $reconciliation->loadMissing([
                'collector',
                'reviewer',
                'evidence',
            ]);

            Log::info('Cash reconciliation details retrieved successfully.', [
                'user_id' => $user?->getKey(),
                'reconciliation_id' => $reconciliationId,
                'status' => $reconciliation->status,
                'evidence_count' => $reconciliation->evidence->count(),
            ]);

            return new CashReconciliationResource($reconciliation);
        } catch (Throwable $exception) {
            Log::error('Failed to retrieve cash reconciliation details.', [
                'user_id' => $user?->getKey(),
                'reconciliation_id' => $reconciliationId,
                'exception' => $exception->getMessage(),
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            throw $exception;
        }
    }

    /**
     * PUT/PATCH /api/v1/cash-reconciliations/{cashReconciliation}
     *
     * Update an editable draft or returned reconciliation.
     */
    public function update(
        UpdateCashReconciliationRequest $request,
        CashReconciliation $cashReconciliation
    ): CashReconciliationResource {
        $user = $request->user();

        $reconciliationId = (string) $cashReconciliation->getKey();

        $validated = $request->validated();

        $evidenceCount = count($request->file('evidence', []));

        $removeEvidenceIds = $validated['remove_evidence_ids'] ?? [];

        Log::info('Cash reconciliation update requested.', [
            'user_id' => $user?->getKey(),
            'reconciliation_id' => $reconciliationId,
            'evidence_upload_count' => $evidenceCount,
            'evidence_removal_count' => count($removeEvidenceIds),
        ]);

        try {
            $this->authorize('update', $cashReconciliation);

            $reconciliation = $this->service->updateDraft(
                $user,
                $cashReconciliation,
                collect($validated)
                    ->except([
                        'evidence',
                        'remove_evidence_ids',
                    ])
                    ->all(),
                $request->file('evidence', []),
                $removeEvidenceIds
            );

            $reconciliation->loadMissing([
                'collector',
                'reviewer',
                'evidence',
            ]);

            Log::info('Cash reconciliation updated successfully.', [
                'user_id' => $user?->getKey(),
                'reconciliation_id' => (string) $reconciliation->getKey(),
                'status' => $reconciliation->status,
                'evidence_upload_count' => $evidenceCount,
                'evidence_removal_count' => count($removeEvidenceIds),
                'remaining_evidence_count' => $reconciliation->evidence->count(),
            ]);

            return new CashReconciliationResource($reconciliation);
        } catch (Throwable $exception) {
            Log::error('Failed to update cash reconciliation.', [
                'user_id' => $user?->getKey(),
                'reconciliation_id' => $reconciliationId,
                'evidence_upload_count' => $evidenceCount,
                'evidence_removal_count' => count($removeEvidenceIds),
                'exception' => $exception->getMessage(),
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            throw $exception;
        }
    }

    /**
     * DELETE /api/v1/cash-reconciliations/{cashReconciliation}
     *
     * Delete an unsubmitted draft reconciliation.
     *
     * Only the owning collector can delete their own DRAFT.
     * Submitted, returned, reconciled, discrepancy and cancelled
     * reconciliations must not be permanently deleted.
     */
    public function destroy(
        Request $request,
        CashReconciliation $cashReconciliation
    ): JsonResponse {
        $user = $request->user();

        $reconciliationId = (string) $cashReconciliation->getKey();

        Log::info('Cash reconciliation draft deletion requested.', [
            'user_id' => $user?->getKey(),
            'reconciliation_id' => $reconciliationId,
            'status' => $cashReconciliation->status,
        ]);

        try {
            // $this->authorize('delete', $cashReconciliation);

            $this->service->deleteDraft(
                $user,
                $cashReconciliation
            );

            Log::info('Cash reconciliation draft deleted successfully.', [
                'user_id' => $user?->getKey(),
                'reconciliation_id' => $reconciliationId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cash reconciliation draft deleted successfully.',
                'data' => null,
                'errors' => null,
            ], 200);
        } catch (Throwable $exception) {
            Log::error('Failed to delete cash reconciliation draft.', [
                'user_id' => $user?->getKey(),
                'reconciliation_id' => $reconciliationId,
                'exception' => $exception->getMessage(),
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            throw $exception;
        }
    }

    /**
     * POST /api/v1/cash-reconciliations/{cashReconciliation}/submit
     *
     * Submit the physical cash count for review.
     */
    public function submit(
        SubmitCashReconciliationRequest $request,
        CashReconciliation $cashReconciliation
    ): CashReconciliationResource {
        $user = $request->user();

        $reconciliationId = (string) $cashReconciliation->getKey();

        Log::info('Cash reconciliation submission requested.', [
            'user_id' => $user?->getKey(),
            'reconciliation_id' => $reconciliationId,
        ]);

        try {
            $this->authorize('submit', $cashReconciliation);

            $reconciliation = $this->service->submit(
                $user,
                $cashReconciliation,
                $request->validated()
            );

            $reconciliation->loadMissing([
                'collector',
                'reviewer',
                'evidence',
            ]);

            Log::info('Cash reconciliation submitted successfully.', [
                'user_id' => $user?->getKey(),
                'reconciliation_id' => (string) $reconciliation->getKey(),
                'status' => $reconciliation->status,
            ]);

            return new CashReconciliationResource($reconciliation);
        } catch (Throwable $exception) {
            Log::error('Failed to submit cash reconciliation.', [
                'user_id' => $user?->getKey(),
                'reconciliation_id' => $reconciliationId,
                'exception' => $exception->getMessage(),
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            throw $exception;
        }
    }

    /**
     * POST /api/v1/cash-reconciliations/{cashReconciliation}/review
     *
     * Review a submitted reconciliation.
     */
    public function review(
        ReviewCashReconciliationRequest $request,
        CashReconciliation $cashReconciliation
    ): CashReconciliationResource {
        $user = $request->user();

        $reconciliationId = (string) $cashReconciliation->getKey();

        $validated = $request->validated();

        Log::info('Cash reconciliation review requested.', [
            'user_id' => $user?->getKey(),
            'reconciliation_id' => $reconciliationId,
            'decision' => $validated['decision'] ?? null,
        ]);

        try {
            $this->authorize('review', $cashReconciliation);

            $reconciliation = $this->service->review(
                $user,
                $cashReconciliation,
                $validated
            );

            $reconciliation->loadMissing([
                'collector',
                'reviewer',
                'evidence',
            ]);

            Log::info('Cash reconciliation reviewed successfully.', [
                'user_id' => $user?->getKey(),
                'reconciliation_id' => (string) $reconciliation->getKey(),
                'status' => $reconciliation->status,
            ]);

            return new CashReconciliationResource($reconciliation);
        } catch (Throwable $exception) {
            Log::error('Failed to review cash reconciliation.', [
                'user_id' => $user?->getKey(),
                'reconciliation_id' => $reconciliationId,
                'decision' => $validated['decision'] ?? null,
                'exception' => $exception->getMessage(),
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            throw $exception;
        }
    }

    /**
     * POST /api/v1/cash-reconciliations/{cashReconciliation}/cancel
     *
     * Cancel an editable draft or returned reconciliation.
     */
    public function cancel(
        Request $request,
        CashReconciliation $cashReconciliation
    ): CashReconciliationResource {
        $user = $request->user();

        $reconciliationId = (string) $cashReconciliation->getKey();

        Log::info('Cash reconciliation cancellation requested.', [
            'user_id' => $user?->getKey(),
            'reconciliation_id' => $reconciliationId,
        ]);

        try {
            $this->authorize('cancel', $cashReconciliation);

            $reconciliation = $this->service->cancel(
                $user,
                $cashReconciliation
            );

            $reconciliation->loadMissing([
                'collector',
                'reviewer',
                'evidence',
            ]);

            Log::info('Cash reconciliation cancelled successfully.', [
                'user_id' => $user?->getKey(),
                'reconciliation_id' => (string) $reconciliation->getKey(),
                'status' => $reconciliation->status,
            ]);

            return new CashReconciliationResource($reconciliation);
        } catch (Throwable $exception) {
            Log::error('Failed to cancel cash reconciliation.', [
                'user_id' => $user?->getKey(),
                'reconciliation_id' => $reconciliationId,
                'exception' => $exception->getMessage(),
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            throw $exception;
        }
    }


  
/*
|--------------------------------------------------------------------------
| Expected Cash By Collector
|--------------------------------------------------------------------------
|
| GET /api/v1/cash-reconciliations/expected-by-collector
|
| Returns expected, reconciled, and outstanding cash grouped
| by revenue collector.
|
| The service enforces collector visibility and administrative scope.
|
*/

public function expectedByCollector(
    ExpectedByCollectorRequest $request
): JsonResponse {
    $user = $request->user();

    $filters = $request->validated();

    $perPage = (int) ($filters['per_page'] ?? 50);

    Log::info('Expected cash by collector requested.', [
        'user_id' => $user?->getKey(),
        'filters' => $filters,
        'per_page' => $perPage,
    ]);

    try {
        /*
        |--------------------------------------------------------------------------
        | 1. Authorize Access
        |--------------------------------------------------------------------------
        |
        | This ability allows:
        | - Collectors with the own expected-cash permission.
        | - Authorized users with the all-collectors permission.
        |
        | Do not use viewAllExpectedCash here because collectors
        | must also be able to access their own expected cash.
        |
        */

        // $this->authorize(
        //     'viewExpectedCash',
        //     CashReconciliation::class
        // );

        /*
        |--------------------------------------------------------------------------
        | 2. Retrieve Expected Cash Summary
        |--------------------------------------------------------------------------
        */

        $result = $this->service->expectedByCollector(
            user: $user,
            filters: $filters,
            perPage: $perPage
        );

        /*
        |--------------------------------------------------------------------------
        | 3. Build Pagination Metadata
        |--------------------------------------------------------------------------
        */

        $meta = [
            'current_page' => $result['meta']['current_page'],
            'last_page' => $result['meta']['last_page'],
            'per_page' => $result['meta']['per_page'],
            'total' => $result['meta']['total'],
        ];

        /*
        |--------------------------------------------------------------------------
        | 4. Log Successful Retrieval
        |--------------------------------------------------------------------------
        */

        Log::info('Expected cash by collector retrieved successfully.', [
            'user_id' => $user?->getKey(),
            'collectors_count' => $result['totals']['collectors'],
            'payments_count' => $result['totals']['payments_count'],
            'expected_total' => $result['totals']['expected_total'],
            'reconciled_total' => $result['totals']['reconciled_total'],
            'outstanding_total' => $result['totals']['outstanding_total'],
            'current_page' => $result['meta']['current_page'],
            'last_page' => $result['meta']['last_page'],
        ]);

        /*
        |--------------------------------------------------------------------------
        | 5. Return Standard API Response
        |--------------------------------------------------------------------------
        |
        | data:
        |     Collector rows.
        |
        | meta:
        |     Pagination information, timestamp, request ID, API version.
        |
        | summary:
        |     Aggregated totals across all matching collectors.
        |
        */

        return ApiResponse::success(
            data: $result['data'],
            message: 'Expected cash by collector retrieved successfully.',
            meta: $meta,
            summary: $result['totals']
        );
    } catch (Throwable $exception) {
        Log::error('Failed to retrieve expected cash by collector.', [
            'user_id' => $user?->getKey(),
            'filters' => $filters,
            'exception' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);

        throw $exception;
    }
}

}
