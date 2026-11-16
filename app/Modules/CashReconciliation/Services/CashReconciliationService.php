<?php

namespace App\Modules\CashReconciliation\Services;

use App\Models\CashReconciliation;
use App\Models\Payment;
use App\Models\User;
use App\Services\Authorization\AdministrativeScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashReconciliationService
{
    public function __construct(
        protected AdministrativeScopeService $administrativeScopeService
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Authorization Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Restrict reconciliations through their collector's administrative unit.
     *
     * IMPORTANT:
     * cash_reconciliations does not have administrative_unit_id.
     * Therefore, administrative scope is applied through the collector
     * relationship and the users table.
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
     * Apply both administrative scope and collector ownership rules.
     *
     * Users with view_all can see all collectors within their
     * administrative scope.
     *
     * Other users can only see their own reconciliations.
     */
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

    /**
     * Retrieve a reconciliation through its authorized scope.
     *
     * administrativeScopeOnly is used for operations such as review,
     * where the user is authorized to act on another collector's record
     * within their administrative scope.
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
     * Apply administrative scope to payments through their invoice.
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

    public function findForUser(
        User $user,
        int|string $id
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

    public function createDraft(
        User $user,
        array $data
    ): CashReconciliation {
        $this->assertPermission(
            $user,
            'cash_reconciliations.create'
        );

        $this->validatePeriod(
            $data['period_start'] ?? null,
            $data['period_end'] ?? null
        );

        /*
         * Do not accept collector_id or administrative_unit_id
         * from the request. The authenticated user is the collector.
         *
         * Administrative visibility is derived through the collector
         * relationship when the record is retrieved.
         */
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

        return $this->resolveAuthorizedReconciliation(
            $user,
            $reconciliation,
            collectorOnly: true
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    public function updateDraft(
        User $user,
        CashReconciliation $reconciliation,
        array $data
    ): CashReconciliation {
        $this->assertPermission(
            $user,
            'cash_reconciliations.update'
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
                            $this->moneyToCents($data['actual_amount'])
                        );

                $reconciliation->expected_amount = '0.00';
                $reconciliation->difference_amount = null;
            }

            if (array_key_exists('collector_notes', $data)) {
                $reconciliation->collector_notes =
                    $data['collector_notes'];
            }

            $reconciliation->save();

            return $reconciliation->fresh(['collector', 'reviewer']);
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

            return $reconciliation->fresh(['collector', 'reviewer']);
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
            /*
             * Reviewers may review another collector's reconciliation
             * inside their administrative scope.
             *
             * This intentionally does not require view_all permission.
             */
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
                    CashReconciliation::STATUS_DISCREPANCY,
                ],
                true
            )) {
                throw ValidationException::withMessages([
                    'status' =>
                        'Only pending-review or discrepancy reconciliations can be reviewed.',
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
                    'decision' => 'The selected review decision is invalid.',
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

            return $reconciliation->fresh(['collector', 'reviewer']);
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

            return $reconciliation->fresh(['collector', 'reviewer']);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Expected Cash Calculation
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate completed cash payments for this collector and period.
     *
     * Payments are restricted to invoices within the user's
     * administrative scope.
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

    /**
     * Prevent overlapping submitted periods for the same collector.
     *
     * Draft and cancelled records do not reserve a period.
     */
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

    /**
     * Convert a monetary value to integer cents.
     *
     * Values must have no more than two decimal places.
     */
    protected function moneyToCents(mixed $amount): int
    {
        if ($amount === null || $amount === '') {
            throw ValidationException::withMessages([
                'actual_amount' => 'A valid monetary amount is required.',
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

    /**
     * Format integer cents as a two-decimal monetary string.
     */
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
}
