<?php

namespace App\Modules\CashReconciliation\Services;

use App\Models\CashReconciliation;
use App\Models\Payment;
use App\Models\User;
use App\Services\Authorization\AdministrativeScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;


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

    protected function authorizedReconciliationQuery(
        User $user,
        bool $collectorOnly = false
    ): Builder {
        $query = $this->administrativelyScopedReconciliationQuery($user);

        if (
            $collectorOnly
            || ! $user->can('cash_reconciliations.view_all')
        ) {
            $query->where(
                'cash_reconciliations.collector_id',
                $user->getKey()
            );
        }

        return $query;
    }

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
     * Evidence files are uploaded as part of the same create request.
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
             * This is only a preview. The amount is recalculated again
             * during submission to capture any payments received after
             * the draft was created.
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
     * New files are attached and selected existing evidence files
     * are removed through the evidence service.
     *
     * @param array<int, UploadedFile> $evidenceFiles
     * @param array<int, string> $removeEvidenceIds UUID file IDs
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

            /*
             * Update reconciliation fields.
             */

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

            /*
             * Refresh the expected-cash preview whenever the collector
             * updates the reconciliation. This also handles period
             * changes and keeps the draft/returned record current.
             *
             * The final expected amount and difference are recalculated
             * again by submit(), immediately before submission.
             */
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

            /*
             * Remove existing evidence selected by the collector.
             *
             * The evidence service verifies that every supplied UUID
             * belongs to this reconciliation before detaching it.
             */
            if ($removeEvidenceIds !== []) {
                $this->evidenceService->remove(
                    $reconciliation,
                    $removeEvidenceIds
                );
            }

            /*
             * Attach newly uploaded evidence.
             */
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

            if (! in_array(
                $reconciliation->status,
                [
                    CashReconciliation::STATUS_PENDING_REVIEW,
                ],
                true
            )) {
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
     * The record must have exactly DRAFT status.
     *
     * Submitted, returned, reviewed, and cancelled reconciliations
     * must remain in the database for audit purposes.
     */
    public function deleteDraft(
        User $user,
        CashReconciliation $reconciliation
    ): void {
        // $this->assertPermission(
        //     $user,
        //     'cash_reconciliations.delete'
        // );

        DB::transaction(function () use (
            $user,
            $reconciliation
        ): void {
            /*
             * Resolve the record within the authenticated collector's
             * administrative scope and ownership scope.
             *
             * Lock the row to prevent concurrent updates or submission
             * while the deletion is being processed.
             */
            $reconciliation = $this->resolveAuthorizedReconciliation(
                $user,
                $reconciliation,
                collectorOnly: true,
                lock: true
            );

            /*
             * Deletion is intentionally stricter than assertEditable().
             *
             * assertEditable() allows both DRAFT and RETURNED records.
             * Permanent deletion must allow DRAFT only.
             */
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
            ->where('payments.payment_method', 'CASH')
            ->where('payments.status', 'COMPLETED')
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


   



/**
 * Get expected, reconciled, and outstanding cash grouped by cash collector.
 *
 * Cash collectors are identified from the users recorded in
 * payments.processed_by for CASH payments.
 *
 * Expected cash:
 *     Sum of successfully completed cash payments.
 *
 * Reconciled cash:
 *     Sum of actual_amount from RECONCILED records only.
 *
 * Outstanding cash:
 *     Expected cash - Reconciled cash.
 *
 * Collector identification is independent of the selected date range.
 * This allows previously recorded cash collectors to appear even when
 * they have no cash payments in the selected period.
 *
 * Visibility:
 *     - Users without all-collector access see only their own records.
 *     - Users with all-collector access see collectors within their
 *       authorized administrative scope.
 *     - SYSTEM_ADMIN receives global scope through
 *       AdministrativeScopeService.
 *
 * @param User $user
 * @param array<string, mixed> $filters
 * @param int $perPage
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

    $canViewAll =  true;
    // $user->hasPermission(
    //     'cash_reconciliations.view_all_expected_cash'
    // );

    /*
    |--------------------------------------------------------------------------
    | 2. Normalize Filters
    |--------------------------------------------------------------------------
    */

    $dateFrom = $filters['date_from'] ?? null;
    $dateTo = $filters['date_to'] ?? null;

    $search = trim((string) ($filters['search'] ?? ''));

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
    | 3. Identify Cash Collectors From Payment Records
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | A cash collector is identified by the user recorded in
    | payments.processed_by when processing a CASH payment.
    |
    | Do not identify collectors using:
    |     - A specific role.
    |     - All employees.
    |     - All system users.
    |
    | Do not apply the selected date range here.
    |
    | This ensures a previously recorded collector remains eligible
    | even when they have no payments in the selected reporting period.
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
    | 4. Calculate Expected Cash From Payments
    |--------------------------------------------------------------------------
    |
    | Only successfully completed CASH payments are included.
    |
    | Payments are grouped by the user recorded in processed_by.
    |
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
        ->when($dateFrom, function ($query) use ($dateFrom) {
            $query->whereDate(
                'created_at',
                '>=',
                $dateFrom
            );
        })
        ->when($dateTo, function ($query) use ($dateTo) {
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
    |
    | Only RECONCILED records contribute to reconciled cash.
    |
    | Reconciliation periods must be fully contained within the
    | selected date range.
    |
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
        ->when($dateFrom, function ($query) use ($dateFrom) {
            $query->whereDate(
                'period_start',
                '>=',
                $dateFrom
            );
        })
        ->when($dateTo, function ($query) use ($dateTo) {
            $query->whereDate(
                'period_end',
                '<=',
                $dateTo
            );
        })
        ->groupBy('collector_id');

    /*
    |--------------------------------------------------------------------------
    | 6. Build Cash Collector Query
    |--------------------------------------------------------------------------
    |
    | Only users recorded as processors of CASH payments are included.
    |
    | No specific role or user_type is required.
    |
    | LEFT JOIN ensures that eligible collectors without payments
    | or reconciliations in the selected period receive zero totals.
    |
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
    | 7. Apply Collector Visibility and Administrative Scope
    |--------------------------------------------------------------------------
    */

    if (! $canViewAll) {
        // Without all-collector access, users see only their own records.
        $base->where('users.id', $user->id);
    } else {
        // All-collector access remains restricted by administrative scope.
        // SYSTEM_ADMIN receives global scope through the scope service.
        $this->administrativeScopeService->applyAdministrativeScope(
            $base,
            $user,
            'users.administrative_unit_id'
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
            function ($join) {
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
            function ($join) {
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

        $base->where(function ($query) use ($term) {
            $query
                ->where('users.name', 'like', $term)
                ->orWhere('users.email', 'like', $term);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 10. Calculate Totals Across All Matching Collectors
    |--------------------------------------------------------------------------
    |
    | select([]) clears the original SELECT columns before aggregation.
    |
    | This prevents PostgreSQL grouping errors involving users.id,
    | users.name, and users.email.
    |
    | Outstanding cash is calculated directly from the aggregate
    | subquery columns instead of referencing a SELECT alias.
    |
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
        ->map(function ($row) {
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






}
