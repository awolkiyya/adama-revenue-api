<?php

declare(strict_types=1);

namespace App\Modules\CashReconciliation\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\CashReconciliation;
use App\Models\Payment;
use App\Models\User;
use App\Services\Authorization\AdministrativeScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashReconciliationService
{
    public function __construct(
        protected AdministrativeScopeService $administrativeScopeService,
        protected CashReconciliationEvidenceService $evidenceService,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Authorization Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Reconciliations belonging to collectors within the authenticated
     * user's administrative scope.
     *
     * Higher-level administrative users can access subordinate units
     * according to AdministrativeScopeService.
     */
    protected function administrativelyScopedReconciliationQuery(
        User $user
    ): Builder {
        return CashReconciliation::query()
            ->whereHas('collector', function (Builder $collectorQuery) use ($user): void {
                $this->administrativeScopeService->applyAdministrativeScope(
                    $collectorQuery,
                    $user,
                    'users.administrative_unit_id'
                );
            });
    }

    /**
     * Reconciliation visibility:
     *
     * - Collector-only: own reconciliations.
     * - Without view_all: own reconciliations.
     * - With view_all: all reconciliations within administrative scope.
     */
    protected function authorizedReconciliationQuery(
        User $user,
        bool $collectorOnly = false
    ): Builder {
        $query = $this->administrativelyScopedReconciliationQuery($user);
    
        // Revenue collectors can ONLY access their own reconciliations.
        if ($user->hasRole('REVENUE_COLLECTOR')) {
            return $query->where(
                'cash_reconciliations.collector_id',
                $user->getKey()
            );
        }
    
        // Explicit collector-only operations.
        if ($collectorOnly) {
            return $query->where(
                'cash_reconciliations.collector_id',
                $user->getKey()
            );
        }
    
        // Other users need view-all permission to access
        // reconciliations belonging to other collectors.
        if (! $user->can('cash_reconciliations.view_all')) {
            $query->where(
                'cash_reconciliations.collector_id',
                $user->getKey()
            );
        }
    
        return $query;
    }

    /**
     * Resolve a reconciliation within the appropriate authorization scope.
     */
    protected function resolveAuthorizedReconciliation(
        User $user,
        CashReconciliation $reconciliation,
        bool $collectorOnly = false,
        bool $lock = false,
        bool $administrativeScopeOnly = false
    ): CashReconciliation {
        $query = $administrativeScopeOnly
            ? $this->administrativelyScopedReconciliationQuery($user)
            : $this->authorizedReconciliationQuery($user, $collectorOnly);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query
            ->with(['collector', 'reviewer'])
            ->findOrFail($reconciliation->getKey());
    }

    /**
     * Payments belonging to invoices within the authenticated user's
     * administrative scope.
     */
    protected function authorizedPaymentQuery(User $user): Builder
    {
        return Payment::query()
            ->whereHas('invoice', function (Builder $invoiceQuery) use ($user): void {
                $this->administrativeScopeService->applyAdministrativeScope(
                    $invoiceQuery,
                    $user,
                    'invoices.administrative_unit_id'
                );
            });
    }

    /**
     * Determine whether the user can view all expected-cash collectors
     * within their administrative scope.
     */
    protected function canViewAllExpectedCash(User $user): bool
    {
        return $user->can(
            'cash_reconciliations.view_all_expected_cash'
        ) || $user->can(
            'cash_reconciliations.view_all'
        );
    }

    /**
     * Build a collector query constrained by administrative scope.
     */
    protected function administrativelyScopedCollectorQuery(
        User $user
    ): Builder {
        $query = User::query();

        $this->administrativeScopeService->applyAdministrativeScope(
            $query,
            $user,
            'users.administrative_unit_id'
        );

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    public function paginate(
        User $user,
        array $filters = []
    ): LengthAwarePaginator {
        $query = $this->authorizedReconciliationQuery($user)
            ->with(['collector', 'reviewer']);

        if (! empty($filters['status'])) {
            $query->where(
                'cash_reconciliations.status',
                $filters['status']
            );
        }

        if (! empty($filters['collector_id'])) {
            $query->where(
                'cash_reconciliations.collector_id',
                $filters['collector_id']
            );
        }

        if (! empty($filters['period_start'])) {
            $query->whereDate(
                'cash_reconciliations.period_start',
                '>=',
                $filters['period_start']
            );
        }

        if (! empty($filters['period_end'])) {
            $query->whereDate(
                'cash_reconciliations.period_end',
                '<=',
                $filters['period_end']
            );
        }

        if (! empty($filters['from'])) {
            $query->whereDate(
                'cash_reconciliations.created_at',
                '>=',
                $filters['from']
            );
        }

        if (! empty($filters['to'])) {
            $query->whereDate(
                'cash_reconciliations.created_at',
                '<=',
                $filters['to']
            );
        }

        $sortableColumns = [
            'created_at',
            'period_start',
            'period_end',
            'expected_amount',
            'actual_amount',
            'difference_amount',
            'submitted_at',
            'reviewed_at',
            'status',
        ];

        $sortBy = $filters['sort_by'] ?? 'created_at';

        if (! in_array($sortBy, $sortableColumns, true)) {
            $sortBy = 'created_at';
        }

        $sortDirection = strtolower(
            (string) ($filters['sort_direction'] ?? 'desc')
        );

        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        $perPage = max(
            1,
            min(100, (int) ($filters['per_page'] ?? 15))
        );

        return $query
            ->orderBy(
                'cash_reconciliations.' . $sortBy,
                $sortDirection
            )
            ->paginate($perPage)
            ->withQueryString();
    }

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    */

    /**
     * Retrieve a reconciliation using its UUID.
     */
    public function findForUser(
        User $user,
        string $id
    ): CashReconciliation {
        return $this->authorizedReconciliationQuery($user)
            ->with(['collector', 'reviewer'])
            ->findOrFail($id);
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    /**
     * Create a reconciliation draft and attach its initial evidence.
     *
     * @param array<int, UploadedFile> $evidenceFiles
     */
    public function createDraft(
        User $user,
        array $data,
        array $evidenceFiles = []
    ): CashReconciliation {
        $this->assertPermission(
            $user,
            'cash_reconciliations.create'
        );

        $this->validatePeriod(
            $data['period_start'] ?? null,
            $data['period_end'] ?? null
        );

        return DB::transaction(function () use (
            $user,
            $data,
            $evidenceFiles
        ): CashReconciliation {
            $reconciliation = CashReconciliation::create([
                'collector_id' => $user->getKey(),

                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],

                'expected_amount' => '0.00',
                'actual_amount' => null,
                'difference_amount' => null,

                'collector_notes' => $data['collector_notes'] ?? null,

                'submitted_at' => null,

                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_notes' => null,

                'status' => CashReconciliation::STATUS_DRAFT,
            ]);

            /*
             * Calculate the expected cash immediately so the collector
             * can see the current expected amount while the record is
             * still a draft.
             *
             * The amount is recalculated again during submission.
             */
            $reconciliation->expected_amount = $this->calculateExpectedCash(
                $user,
                $reconciliation
            );

            $reconciliation->difference_amount = null;
            $reconciliation->save();

            if ($evidenceFiles !== []) {
                $this->evidenceService->attach(
                    $reconciliation,
                    $evidenceFiles
                );
            }

            return $this->resolveAuthorizedReconciliation(
                $user,
                $reconciliation,
                collectorOnly: true
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    /**
     * Update an editable reconciliation and manage its evidence.
     *
     * @param array<int, UploadedFile> $evidenceFiles
     * @param array<int, string> $removeEvidenceIds
     */
    public function updateDraft(
        User $user,
        CashReconciliation $reconciliation,
        array $data,
        array $evidenceFiles = [],
        array $removeEvidenceIds = []
    ): CashReconciliation {
        $this->assertPermission(
            $user,
            'cash_reconciliations.update'
        );

        return DB::transaction(function () use (
            $user,
            $reconciliation,
            $data,
            $evidenceFiles,
            $removeEvidenceIds
        ): CashReconciliation {
            $reconciliation = $this->resolveAuthorizedReconciliation(
                $user,
                $reconciliation,
                collectorOnly: true,
                lock: true
            );

            $this->assertEditable($reconciliation);

            $start = $data['period_start']
                ?? $reconciliation->period_start;

            $end = $data['period_end']
                ?? $reconciliation->period_end;

            $this->validatePeriod($start, $end);

            $reconciliation->period_start = $start;
            $reconciliation->period_end = $end;

            if (array_key_exists('actual_amount', $data)) {
                $reconciliation->actual_amount =
                    $data['actual_amount'] === null
                        ? null
                        : $this->formatMoney(
                            $this->moneyToCents(
                                $data['actual_amount']
                            )
                        );
            }

            $reconciliation->expected_amount = $this->calculateExpectedCash(
                $user,
                $reconciliation
            );

            $reconciliation->difference_amount = null;

            if (array_key_exists('collector_notes', $data)) {
                $reconciliation->collector_notes =
                    $data['collector_notes'];
            }

            $reconciliation->save();

            if ($removeEvidenceIds !== []) {
                $this->evidenceService->remove(
                    $reconciliation,
                    $removeEvidenceIds
                );
            }

            if ($evidenceFiles !== []) {
                $this->evidenceService->attach(
                    $reconciliation,
                    $evidenceFiles
                );
            }

            return $reconciliation->fresh([
                'collector',
                'reviewer',
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Submit
    |--------------------------------------------------------------------------
    */

    public function submit(
        User $user,
        CashReconciliation $reconciliation,
        array $data
    ): CashReconciliation {
        $this->assertPermission(
            $user,
            'cash_reconciliations.submit'
        );

        return DB::transaction(function () use (
            $user,
            $reconciliation,
            $data
        ): CashReconciliation {
            $reconciliation = $this->resolveAuthorizedReconciliation(
                $user,
                $reconciliation,
                collectorOnly: true,
                lock: true
            );

            $this->assertEditable($reconciliation);

            $this->validatePeriod(
                $reconciliation->period_start,
                $reconciliation->period_end
            );

            /*
             * Serialize submissions for the same collector.
             */
            User::query()
                ->whereKey($reconciliation->collector_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertNoOverlappingSubmittedPeriod(
                $user,
                $reconciliation
            );

            $expectedCents = $this->moneyToCents(
                $this->calculateExpectedCash($user, $reconciliation)
            );

            $actualCents = $this->moneyToCents(
                $data['actual_amount'] ?? null
            );

            $differenceCents = $actualCents - $expectedCents;

            $reconciliation->expected_amount =
                $this->formatMoney($expectedCents);

            $reconciliation->actual_amount =
                $this->formatMoney($actualCents);

            $reconciliation->difference_amount =
                $this->formatMoney($differenceCents);

            if (array_key_exists('collector_notes', $data)) {
                $reconciliation->collector_notes =
                    $data['collector_notes'];
            }

            $reconciliation->submitted_at = now();

            $reconciliation->reviewed_by = null;
            $reconciliation->reviewed_at = null;
            $reconciliation->review_notes = null;

            $reconciliation->status =
                CashReconciliation::STATUS_PENDING_REVIEW;

            $reconciliation->save();

            return $reconciliation->fresh([
                'collector',
                'reviewer',
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Review
    |--------------------------------------------------------------------------
    */

    public function review(
        User $user,
        CashReconciliation $reconciliation,
        array $data
    ): CashReconciliation {
        $this->assertPermission(
            $user,
            'cash_reconciliations.review'
        );

        return DB::transaction(function () use (
            $user,
            $reconciliation,
            $data
        ): CashReconciliation {
            $reconciliation = $this->resolveAuthorizedReconciliation(
                $user,
                $reconciliation,
                lock: true,
                administrativeScopeOnly: true
            );

            if (
                (string) $reconciliation->collector_id
                === (string) $user->getKey()
            ) {
                throw ValidationException::withMessages([
                    'reconciliation' =>
                        'You cannot review your own cash reconciliation.',
                ]);
            }

            if (
                ! in_array(
                    $reconciliation->status,
                    [
                        CashReconciliation::STATUS_PENDING_REVIEW,
                    ],
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'status' =>
                        'Only pending-review reconciliations can be reviewed.',
                ]);
            }

            $decision = strtoupper(
                trim((string) ($data['decision'] ?? ''))
            );

            $allowedDecisions = [
                CashReconciliation::STATUS_RECONCILED,
                CashReconciliation::STATUS_DISCREPANCY,
                CashReconciliation::STATUS_RETURNED,
            ];

            if (! in_array($decision, $allowedDecisions, true)) {
                throw ValidationException::withMessages([
                    'decision' =>
                        'The selected review decision is invalid.',
                ]);
            }

            $notes = trim(
                (string) ($data['review_notes'] ?? '')
            );

            if (
                in_array(
                    $decision,
                    [
                        CashReconciliation::STATUS_DISCREPANCY,
                        CashReconciliation::STATUS_RETURNED,
                    ],
                    true
                )
                && $notes === ''
            ) {
                throw ValidationException::withMessages([
                    'review_notes' =>
                        'Review notes are required when flagging a discrepancy or returning a reconciliation.',
                ]);
            }

            if (
                $reconciliation->actual_amount === null
                || $reconciliation->difference_amount === null
            ) {
                throw ValidationException::withMessages([
                    'decision' =>
                        'The reconciliation must have recorded financial amounts before review.',
                ]);
            }

            $differenceCents = $this->moneyToCents(
                $reconciliation->difference_amount
            );

            if (
                $decision === CashReconciliation::STATUS_RECONCILED
                && $differenceCents !== 0
            ) {
                throw ValidationException::withMessages([
                    'decision' =>
                        'A reconciliation with a cash difference cannot be finalized as reconciled.',
                ]);
            }

            if (
                $decision === CashReconciliation::STATUS_DISCREPANCY
                && $differenceCents === 0
            ) {
                throw ValidationException::withMessages([
                    'decision' =>
                        'A balanced closing cannot be flagged as a cash discrepancy.',
                ]);
            }

            $reconciliation->reviewed_by = $user->getKey();
            $reconciliation->reviewed_at = now();
            $reconciliation->review_notes =
                $notes !== '' ? $notes : null;

            $reconciliation->status = $decision;

            $reconciliation->save();

            return $reconciliation->fresh([
                'collector',
                'reviewer',
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Cancel
    |--------------------------------------------------------------------------
    */

    public function cancel(
        User $user,
        CashReconciliation $reconciliation
    ): CashReconciliation {
        $this->assertPermission(
            $user,
            'cash_reconciliations.cancel'
        );

        return DB::transaction(function () use (
            $user,
            $reconciliation
        ): CashReconciliation {
            $reconciliation = $this->resolveAuthorizedReconciliation(
                $user,
                $reconciliation,
                collectorOnly: true,
                lock: true
            );

            $this->assertEditable($reconciliation);

            $reconciliation->status =
                CashReconciliation::STATUS_CANCELLED;

            $reconciliation->save();

            return $reconciliation->fresh([
                'collector',
                'reviewer',
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Draft
    |--------------------------------------------------------------------------
    */

    /**
     * Permanently delete an unsubmitted draft reconciliation.
     *
     * Only the collector who owns the reconciliation may delete it.
     * Only DRAFT records can be deleted.
     */
    public function deleteDraft(
        User $user,
        CashReconciliation $reconciliation
    ): void {
        DB::transaction(function () use (
            $user,
            $reconciliation
        ): void {
            $reconciliation = $this->resolveAuthorizedReconciliation(
                $user,
                $reconciliation,
                collectorOnly: true,
                lock: true
            );

            if (
                $reconciliation->status
                !== CashReconciliation::STATUS_DRAFT
            ) {
                throw ValidationException::withMessages([
                    'status' =>
                        'Only draft cash reconciliations can be permanently deleted. Submitted, returned, reviewed, and cancelled reconciliations cannot be deleted.',
                ]);
            }

            $reconciliation->delete();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Expected Cash Calculation
    |--------------------------------------------------------------------------
    */

    protected function calculateExpectedCash(
        User $user,
        CashReconciliation $reconciliation
    ): string {
        $total = $this->authorizedPaymentQuery($user)
            ->where(
                'payments.processed_by',
                $reconciliation->collector_id
            )
            ->where(
                'payments.payment_method',
                PaymentMethod::CASH->value
            )
            ->where(
                'payments.status',
                PaymentStatus::COMPLETED->value
            )
            ->whereBetween('payments.created_at', [
                $reconciliation->period_start,
                $reconciliation->period_end,
            ])
            ->sum('payments.amount');

        return $this->formatMoney(
            $this->moneyToCents($total)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Period Validation
    |--------------------------------------------------------------------------
    */

    protected function validatePeriod(
        mixed $start,
        mixed $end
    ): void {
        if (
            ! $start
            || ! $end
            || strtotime((string) $end) < strtotime((string) $start)
        ) {
            throw ValidationException::withMessages([
                'period_end' =>
                    'The closing period end must be on or after its start.',
            ]);
        }
    }

    protected function assertNoOverlappingSubmittedPeriod(
        User $user,
        CashReconciliation $reconciliation
    ): void {
        $overlapExists = $this->authorizedReconciliationQuery(
            $user,
            collectorOnly: true
        )
            ->where(
                'cash_reconciliations.id',
                '<>',
                $reconciliation->getKey()
            )
            ->whereNotIn('cash_reconciliations.status', [
                CashReconciliation::STATUS_DRAFT,
                CashReconciliation::STATUS_CANCELLED,
            ])
            ->where(
                'cash_reconciliations.period_start',
                '<=',
                $reconciliation->period_end
            )
            ->where(
                'cash_reconciliations.period_end',
                '>=',
                $reconciliation->period_start
            )
            ->exists();

        if ($overlapExists) {
            throw ValidationException::withMessages([
                'period_start' =>
                    'This period overlaps another submitted cash reconciliation.',
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization and Workflow Guards
    |--------------------------------------------------------------------------
    */

    protected function assertPermission(
        User $user,
        string $permission
    ): void {
        if (! $user->can($permission)) {
            abort(403, 'You are not authorized to perform this action.');
        }
    }

    protected function assertEditable(
        CashReconciliation $reconciliation
    ): void {
        if (! $reconciliation->isEditable()) {
            throw ValidationException::withMessages([
                'status' =>
                    'Only draft or returned reconciliations can be edited, submitted, or cancelled.',
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Monetary Precision
    |--------------------------------------------------------------------------
    */

    protected function moneyToCents(mixed $amount): int
    {
        if ($amount === null || $amount === '') {
            throw ValidationException::withMessages([
                'actual_amount' =>
                    'A valid monetary amount is required.',
            ]);
        }

        $value = trim((string) $amount);

        if (! preg_match(
            '/^(-?)(\d+)(?:\.(\d{1,2}))?$/',
            $value,
            $matches
        )) {
            throw ValidationException::withMessages([
                'actual_amount' =>
                    'The monetary amount must have no more than two decimal places.',
            ]);
        }

        $sign = ($matches[1] ?? '') === '-' ? -1 : 1;
        $whole = (int) $matches[2];

        $fraction = str_pad(
            $matches[3] ?? '',
            2,
            '0',
            STR_PAD_RIGHT
        );

        $cents = ($whole * 100) + (int) $fraction;

        return $sign * $cents;
    }

    protected function formatMoney(int $cents): string
    {
        $absolute = abs($cents);

        $whole = intdiv($absolute, 100);

        $fraction = str_pad(
            (string) ($absolute % 100),
            2,
            '0',
            STR_PAD_LEFT
        );

        return ($cents < 0 ? '-' : '')
            . $whole
            . '.'
            . $fraction;
    }

    /*
    |--------------------------------------------------------------------------
    | Expected Cash By Collector
    |--------------------------------------------------------------------------
    */

    /**
     * Get expected, reconciled, and outstanding cash grouped by collector.
     *
     * Collector identification is based on payments.processed_by
     * for CASH payments, independently of the selected date range.
     *
     * Expected cash:
     *     COMPLETED CASH payments in the selected payment date range.
     *
     * Reconciled cash:
     *     Actual amounts from RECONCILED records whose periods are
     *     fully contained within the selected date range.
     *
     * Visibility:
     *     - Users without all-collector access see only their own record.
     *     - Users with all-collector access see collectors in their
     *       administrative scope.
     *     - Higher-level users can see subordinate units according to
     *       AdministrativeScopeService.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function expectedByCollector(
        User $user,
        array $filters,
        int $perPage = 50
    ): array {
        /*
        |--------------------------------------------------------------------------
        | 1. Determine Collector Visibility
        |--------------------------------------------------------------------------
        */

        $canViewAll = $this->canViewAllExpectedCash($user);

        /*
        |--------------------------------------------------------------------------
        | 2. Normalize Filters
        |--------------------------------------------------------------------------
        */

        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $search = trim(
            (string) ($filters['search'] ?? '')
        );

        $perPage = max(1, min($perPage, 100));

        $sortDirection = strtolower(
            (string) ($filters['sort_dir'] ?? 'desc')
        ) === 'asc' ? 'asc' : 'desc';

        $sortColumn = match ($filters['sort_by'] ?? 'expected') {
            'reconciled' => 'reconciled_total',
            'outstanding' => 'outstanding_total',
            default => 'expected_total',
        };

        /*
        |--------------------------------------------------------------------------
        | 3. Identify Cash Collectors
        |--------------------------------------------------------------------------
        |
        | A collector must have processed at least one CASH payment.
        |
        | The selected date range is deliberately not applied here.
        |
        */

        $collectorIds = Payment::query()
            ->select('processed_by')
            ->where(
                'payment_method',
                PaymentMethod::CASH->value
            )
            ->whereNotNull('processed_by')
            ->distinct();

        /*
        |--------------------------------------------------------------------------
        | 4. Calculate Expected Cash
        |--------------------------------------------------------------------------
        */

        $paymentTotals = Payment::query()
            ->select('processed_by as collector_id')
            ->selectRaw('
                COUNT(*) AS payments_count,
                COALESCE(SUM(amount), 0) AS expected_total
            ')
            ->where(
                'payment_method',
                PaymentMethod::CASH->value
            )
            ->where(
                'status',
                PaymentStatus::COMPLETED->value
            )
            ->whereNotNull('processed_by')
            ->when($dateFrom, function ($query) use ($dateFrom): void {
                $query->whereDate(
                    'created_at',
                    '>=',
                    $dateFrom
                );
            })
            ->when($dateTo, function ($query) use ($dateTo): void {
                $query->whereDate(
                    'created_at',
                    '<=',
                    $dateTo
                );
            })
            ->groupBy('processed_by');

        /*
        |--------------------------------------------------------------------------
        | 5. Calculate Reconciled Cash
        |--------------------------------------------------------------------------
        */

        $reconciliationTotals = CashReconciliation::query()
            ->select('collector_id')
            ->selectRaw('
                COUNT(*) AS reconciliations_count,
                COALESCE(SUM(actual_amount), 0) AS reconciled_total
            ')
            ->where(
                'status',
                CashReconciliation::STATUS_RECONCILED
            )
            ->when($dateFrom, function ($query) use ($dateFrom): void {
                $query->whereDate(
                    'period_start',
                    '>=',
                    $dateFrom
                );
            })
            ->when($dateTo, function ($query) use ($dateTo): void {
                $query->whereDate(
                    'period_end',
                    '<=',
                    $dateTo
                );
            })
            ->groupBy('collector_id');

        /*
        |--------------------------------------------------------------------------
        | 6. Build Collector Query
        |--------------------------------------------------------------------------
        */

        $base = User::query()
            ->select([
                'users.id',
                'users.name',
                'users.email',
            ])
            ->whereIn(
                'users.id',
                $collectorIds
            );

        /*
        |--------------------------------------------------------------------------
        | 7. Apply Administrative Scope
        |--------------------------------------------------------------------------
        |
        | Administrative scope is always applied.
        |
        | Higher-level administrators receive the subordinate-unit
        | visibility implemented by AdministrativeScopeService.
        |
        | Lower-level users cannot access parent or unrelated units.
        |
        | Users without all-collector permission are additionally
        | restricted to their own collector record.
        |
        */

        $this->administrativeScopeService->applyAdministrativeScope(
            $base,
            $user,
            'users.administrative_unit_id'
        );

        if (! $canViewAll) {
            $base->where(
                'users.id',
                $user->getKey()
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 8. Join Payment and Reconciliation Aggregates
        |--------------------------------------------------------------------------
        */

        $base
            ->leftJoinSub(
                $paymentTotals,
                'payment_totals',
                function ($join): void {
                    $join->on(
                        'payment_totals.collector_id',
                        '=',
                        'users.id'
                    );
                }
            )
            ->leftJoinSub(
                $reconciliationTotals,
                'reconciliation_totals',
                function ($join): void {
                    $join->on(
                        'reconciliation_totals.collector_id',
                        '=',
                        'users.id'
                    );
                }
            )
            ->selectRaw('
                COALESCE(
                    payment_totals.payments_count,
                    0
                ) AS payments_count,

                COALESCE(
                    payment_totals.expected_total,
                    0
                ) AS expected_total,

                COALESCE(
                    reconciliation_totals.reconciliations_count,
                    0
                ) AS reconciliations_count,

                COALESCE(
                    reconciliation_totals.reconciled_total,
                    0
                ) AS reconciled_total,

                (
                    COALESCE(
                        payment_totals.expected_total,
                        0
                    )
                    -
                    COALESCE(
                        reconciliation_totals.reconciled_total,
                        0
                    )
                ) AS outstanding_total
            ');

        /*
        |--------------------------------------------------------------------------
        | 9. Search Collectors
        |--------------------------------------------------------------------------
        */

        if ($search !== '') {
            $term = '%' . $search . '%';

            $base->where(function ($query) use ($term): void {
                $query
                    ->where('users.name', 'like', $term)
                    ->orWhere('users.email', 'like', $term);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 10. Calculate Totals Across Matching Collectors
        |--------------------------------------------------------------------------
        */

        $totals = (clone $base)
            ->reorder()
            ->select([])
            ->selectRaw('
                COUNT(*) AS collectors_count,

                COALESCE(
                    SUM(
                        COALESCE(payment_totals.expected_total, 0)
                    ),
                    0
                ) AS expected_total,

                COALESCE(
                    SUM(
                        COALESCE(reconciliation_totals.reconciled_total, 0)
                    ),
                    0
                ) AS reconciled_total,

                COALESCE(
                    SUM(
                        COALESCE(payment_totals.expected_total, 0)
                        -
                        COALESCE(reconciliation_totals.reconciled_total, 0)
                    ),
                    0
                ) AS outstanding_total,

                COALESCE(
                    SUM(
                        COALESCE(payment_totals.payments_count, 0)
                    ),
                    0
                ) AS payments_count,

                COALESCE(
                    SUM(
                        COALESCE(reconciliation_totals.reconciliations_count, 0)
                    ),
                    0
                ) AS reconciliations_count
            ')
            ->first();

        /*
        |--------------------------------------------------------------------------
        | 11. Paginate Collectors
        |--------------------------------------------------------------------------
        */

        $paginator = (clone $base)
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('users.name')
            ->paginate($perPage)
            ->withQueryString();

        /*
        |--------------------------------------------------------------------------
        | 12. Format Collector Results
        |--------------------------------------------------------------------------
        */

        $rows = $paginator->getCollection()
            ->map(function ($row): array {
                return [
                    'collector_id' => $row->id,

                    'collector' => [
                        'id' => $row->id,
                        'name' => $row->name,
                        'email' => $row->email,
                    ],

                    'payments_count' => (int) $row->payments_count,

                    'expected_total' => (string) $row->expected_total,

                    'reconciliations_count' => (int) $row->reconciliations_count,

                    'reconciled_total' => (string) $row->reconciled_total,

                    'outstanding_total' => (string) $row->outstanding_total,
                ];
            })
            ->values();

        /*
        |--------------------------------------------------------------------------
        | 13. Return Response
        |--------------------------------------------------------------------------
        */

        return [
            'data' => $rows,

            'totals' => [
                'collectors' => (int) ($totals->collectors_count ?? 0),

                'payments_count' => (int) ($totals->payments_count ?? 0),

                'expected_total' => (string) ($totals->expected_total ?? 0),

                'reconciliations_count' => (int) ($totals->reconciliations_count ?? 0),

                'reconciled_total' => (string) ($totals->reconciled_total ?? 0),

                'outstanding_total' => (string) ($totals->outstanding_total ?? 0),
            ],

            'meta' => [
                'current_page' => $paginator->currentPage(),

                'last_page' => $paginator->lastPage(),

                'per_page' => $paginator->perPage(),

                'total' => $paginator->total(),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Collector Outstanding Cash For Notification
    |--------------------------------------------------------------------------
    */

    /**
     * Retrieve a collector's outstanding cash.
     *
     * Authorization to send notifications is handled by the controller's
     * policy. This method independently enforces administrative scope
     * when resolving the target collector.
     *
     * Every successful request may create a new notification.
     *
     * @return array<string, mixed>
     */
    public function getCollectorOutstandingCash(
        User $user,
        string $collectorId,
        array $filters = []
    ): array {
        /*
        |--------------------------------------------------------------------------
        | 1. Normalize Filters
        |--------------------------------------------------------------------------
        */

        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        /*
        |--------------------------------------------------------------------------
        | 2. Resolve Collector Within Administrative Scope
        |--------------------------------------------------------------------------
        |
        | The collector must:
        | - Exist.
        | - Have processed at least one CASH payment.
        | - Belong to the administrator's authorized administrative scope.
        |
        | The controller policy is responsible for permission to perform
        | the notification action.
        |
        */

        $collectorIds = Payment::query()
            ->select('processed_by')
            ->where(
                'payment_method',
                PaymentMethod::CASH->value
            )
            ->whereNotNull('processed_by')
            ->distinct();

        $collector = $this->administrativelyScopedCollectorQuery($user)
            ->whereKey($collectorId)
            ->whereIn('users.id', $collectorIds)
            ->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | 3. Calculate Expected Cash
        |--------------------------------------------------------------------------
        |
        | Use the same calculation rules as expectedByCollector().
        |
        */

        $paymentQuery = Payment::query()
            ->where(
                'processed_by',
                $collector->getKey()
            )
            ->where(
                'payment_method',
                PaymentMethod::CASH->value
            )
            ->where(
                'status',
                PaymentStatus::COMPLETED->value
            )
            ->when($dateFrom, function ($query) use ($dateFrom): void {
                $query->whereDate(
                    'created_at',
                    '>=',
                    $dateFrom
                );
            })
            ->when($dateTo, function ($query) use ($dateTo): void {
                $query->whereDate(
                    'created_at',
                    '<=',
                    $dateTo
                );
            });

        $paymentsCount = (clone $paymentQuery)->count();

        $expectedTotal = (clone $paymentQuery)
            ->sum('amount');

        /*
        |--------------------------------------------------------------------------
        | 4. Calculate Reconciled Cash
        |--------------------------------------------------------------------------
        |
        | Only STATUS_RECONCILED contributes to reconciled cash.
        |
        | Period containment matches expectedByCollector().
        |
        */

        $reconciliationQuery = CashReconciliation::query()
            ->where(
                'collector_id',
                $collector->getKey()
            )
            ->where(
                'status',
                CashReconciliation::STATUS_RECONCILED
            )
            ->when($dateFrom, function ($query) use ($dateFrom): void {
                $query->whereDate(
                    'period_start',
                    '>=',
                    $dateFrom
                );
            })
            ->when($dateTo, function ($query) use ($dateTo): void {
                $query->whereDate(
                    'period_end',
                    '<=',
                    $dateTo
                );
            });

        $reconciliationsCount = (clone $reconciliationQuery)
            ->count();

        $reconciledTotal = (clone $reconciliationQuery)
            ->sum('actual_amount');

        /*
        |--------------------------------------------------------------------------
        | 5. Calculate Outstanding Cash
        |--------------------------------------------------------------------------
        */

        $expectedCents = $this->moneyToCents(
            (string) $expectedTotal
        );

        $reconciledCents = $this->moneyToCents(
            (string) $reconciledTotal
        );

        $outstandingCents = $expectedCents - $reconciledCents;

        /*
        |--------------------------------------------------------------------------
        | 6. Prevent Notifications Without Outstanding Cash
        |--------------------------------------------------------------------------
        */

        if ($outstandingCents <= 0) {
            throw ValidationException::withMessages([
                'collector_id' =>
                    'The selected collector has no outstanding cash for the selected reporting period.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 7. Return Verified Financial Details
        |--------------------------------------------------------------------------
        */

        return [
            'collector' => $collector,

            'collector_id' => (string) $collector->getKey(),

            'collector_name' => $collector->name,

            'date_from' => $dateFrom,

            'date_to' => $dateTo,

            'payments_count' => $paymentsCount,

            'expected_total' => $this->formatMoney($expectedCents),

            'reconciliations_count' => $reconciliationsCount,

            'reconciled_total' => $this->formatMoney($reconciledCents),

            'outstanding_total' => $this->formatMoney($outstandingCents),
        ];
    }
}
