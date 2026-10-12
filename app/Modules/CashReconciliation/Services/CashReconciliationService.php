<?php

namespace App\Modules\CashReconciliation\Services;

use App\Models\CashReconciliation;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashReconciliationService
{
    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    /**
     * List reconciliations visible to the authenticated user.
     */
    public function paginate(
        User $user,
        array $filters = []
    ): LengthAwarePaginator {
        $query = CashReconciliation::query()
            ->with(['collector', 'reviewer']);

        /*
         * Enforce record visibility independently of supplied filters.
         */
        if (! $user->can('cash_reconciliations.view_all')) {
            $query->where('collector_id', $user->getKey());
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['collector_id'])) {
            $query->where('collector_id', $filters['collector_id']);
        }

        if (! empty($filters['period_start'])) {
            $query->whereDate(
                'period_start',
                '>=',
                $filters['period_start']
            );
        }

        if (! empty($filters['period_end'])) {
            $query->whereDate(
                'period_end',
                '<=',
                $filters['period_end']
            );
        }

        if (! empty($filters['from'])) {
            $query->whereDate(
                'created_at',
                '>=',
                $filters['from']
            );
        }

        if (! empty($filters['to'])) {
            $query->whereDate(
                'created_at',
                '<=',
                $filters['to']
            );
        }

        /*
         * Whitelist sortable columns even if this method is called
         * without going through the Index Form Request.
         */
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
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage)
            ->withQueryString();
    }

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    */

    /**
     * Retrieve a reconciliation within the user's authorized data scope.
     *
     * The controller should also authorize viewing the record through
     * CashReconciliationPolicy.
     */
    public function findForUser(
        User $user,
        int|string $id
    ): CashReconciliation {
        $query = CashReconciliation::query()
            ->with(['collector', 'reviewer']);

        if (! $user->can('cash_reconciliations.view_all')) {
            $query->where('collector_id', $user->getKey());
        }

        return $query->findOrFail($id);
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    /**
     * Create a draft for the authenticated collector.
     *
     * Financial totals and workflow fields are assigned by the backend.
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

        return $reconciliation->load(['collector', 'reviewer']);
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    /**
     * Update a draft or returned reconciliation.
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
            $reconciliation = CashReconciliation::query()
                ->lockForUpdate()
                ->findOrFail($reconciliation->getKey());

            $this->assertCollectorOwns($user, $reconciliation);
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

                /*
                 * A changed actual amount invalidates a previously
                 * calculated difference. Recalculate during submission.
                 */
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

    /**
     * Submit a closing for finance review.
     *
     * Expected cash is calculated from completed cash payments recorded
     * by this collector during the reconciliation period.
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
            $reconciliation = CashReconciliation::query()
                ->lockForUpdate()
                ->findOrFail($reconciliation->getKey());

            $this->assertCollectorOwns($user, $reconciliation);
            $this->assertEditable($reconciliation);

            $this->validatePeriod(
                $reconciliation->period_start,
                $reconciliation->period_end
            );

            /*
             * Serialize submissions for the same collector. Locking only
             * the reconciliation row would not prevent two different
             * drafts from being submitted with overlapping periods.
             */
            User::query()
                ->whereKey($reconciliation->collector_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertNoOverlappingSubmittedPeriod(
                $reconciliation
            );

            $expectedCents = $this->moneyToCents(
                $this->calculateExpectedCash($reconciliation)
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

            /*
             * Clear any previous review when a returned reconciliation
             * is resubmitted.
             */
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

    /**
     * Review a submitted reconciliation.
     *
     * The controller must authorize the review through the policy.
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
            $reconciliation = CashReconciliation::query()
                ->lockForUpdate()
                ->findOrFail($reconciliation->getKey());

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

    /**
     * Cancel a draft or returned reconciliation.
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
            $reconciliation = CashReconciliation::query()
                ->lockForUpdate()
                ->findOrFail($reconciliation->getKey());

            $this->assertCollectorOwns($user, $reconciliation);
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
     * Calculate completed cash payments recorded by this collector
     * during the reconciliation period.
     *
     * This uses columns present in the supplied Payment model:
     * - processed_by
     * - payment_method
     * - status
     * - amount
     * - created_at
     *
     * Assumption: processed_by identifies the collector who recorded
     * the cash payment, and created_at is the intended payment date.
     */
    protected function calculateExpectedCash(
        CashReconciliation $reconciliation
    ): string {
        $total = Payment::query()
            ->where(
                'processed_by',
                $reconciliation->collector_id
            )
            ->where('payment_method', 'CASH')
            ->where('status', 'COMPLETED')
            ->whereBetween('created_at', [
                $reconciliation->period_start,
                $reconciliation->period_end,
            ])
            ->sum('amount');

        return $this->formatMoney(
            $this->moneyToCents($total)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Period Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Validate that both dates exist and the end is not before the start.
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
     * Prevent a collector from submitting overlapping reconciliation
     * periods.
     *
     * Draft and cancelled records do not reserve a period.
     * Returned records still reserve their period until resubmitted,
     * cancelled, or otherwise resolved.
     */
    protected function assertNoOverlappingSubmittedPeriod(
        CashReconciliation $reconciliation
    ): void {
        $overlapExists = CashReconciliation::query()
            ->where(
                'collector_id',
                $reconciliation->collector_id
            )
            ->where(
                'id',
                '<>',
                $reconciliation->getKey()
            )
            ->whereNotIn('status', [
                CashReconciliation::STATUS_DRAFT,
                CashReconciliation::STATUS_CANCELLED,
            ])
            ->where(
                'period_start',
                '<=',
                $reconciliation->period_end
            )
            ->where(
                'period_end',
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

    /**
     * Ensure the user has the required permission.
     */
    protected function assertPermission(
        User $user,
        string $permission
    ): void {
        if (! $user->can($permission)) {
            abort(403, 'You are not authorized to perform this action.');
        }
    }

    /**
     * Ensure that the reconciliation belongs to the authenticated collector.
     */
    protected function assertCollectorOwns(
        User $user,
        CashReconciliation $reconciliation
    ): void {
        if (
            (string) $reconciliation->collector_id
            !== (string) $user->getKey()
        ) {
            abort(
                403,
                'You are not authorized to modify this reconciliation.'
            );
        }
    }

    /**
     * Ensure that the reconciliation is editable.
     */
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
    |
    | Use integer cents for arithmetic and comparisons.
    | This avoids floating-point arithmetic for financial decisions
    | and does not require the BCMath PHP extension.
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
