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
    /**
     * List records visible to the authenticated user.
     */
    public function paginate(
        User $user,
        array $filters = []
    ): LengthAwarePaginator {
        $query = CashReconciliation::query()
            ->with(['collector', 'reviewer']);

        if (! $user->can('cash-reconciliations.view-all')) {
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
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Retrieve a record while enforcing record visibility.
     */
    public function findForUser(
        User $user,
        int|string $id
    ): CashReconciliation {
        $query = CashReconciliation::query()
            ->with(['collector', 'reviewer']);

        if (! $user->can('cash-reconciliations.view-all')) {
            $query->where('collector_id', $user->getKey());
        }

        return $query->findOrFail($id);
    }

    /**
     * Create a draft for the authenticated collector.
     */
    public function createDraft(
        User $user,
        array $data
    ): CashReconciliation {
        $this->validatePeriod(
            $data['period_start'],
            $data['period_end']
        );

        return CashReconciliation::create([
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
    }

    /**
     * Update a draft or returned reconciliation.
     */
    public function updateDraft(
        User $user,
        CashReconciliation $reconciliation,
        array $data
    ): CashReconciliation {
        return DB::transaction(function () use (
            $user,
            $reconciliation,
            $data
        ) {
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
                    $data['actual_amount'];
            }

            if (array_key_exists('collector_notes', $data)) {
                $reconciliation->collector_notes =
                    $data['collector_notes'];
            }

            $reconciliation->save();

            return $reconciliation->fresh(['collector', 'reviewer']);
        });
    }

    /**
     * Submit the closing for Finance review.
     *
     * Expected cash and the difference are always calculated server-side.
     */
    public function submit(
        User $user,
        CashReconciliation $reconciliation,
        array $data
    ): CashReconciliation {
        return DB::transaction(function () use (
            $user,
            $reconciliation,
            $data
        ) {
            $reconciliation = CashReconciliation::query()
                ->lockForUpdate()
                ->findOrFail($reconciliation->getKey());

            $this->assertCollectorOwns($user, $reconciliation);
            $this->assertEditable($reconciliation);

            $this->validatePeriod(
                $reconciliation->period_start,
                $reconciliation->period_end
            );

            $this->assertNoOverlappingSubmittedPeriod(
                $reconciliation
            );

            $expected = $this->calculateExpectedCash(
                $reconciliation
            );

            $actual = bcadd(
                (string) $data['actual_amount'],
                '0.00',
                2
            );

            $difference = bcsub($actual, $expected, 2);

            $reconciliation->expected_amount = $expected;
            $reconciliation->actual_amount = $actual;
            $reconciliation->difference_amount = $difference;

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

    /**
     * Finance Officer reviews a submitted closing.
     */
    public function review(
        User $user,
        CashReconciliation $reconciliation,
        array $data
    ): CashReconciliation {
        return DB::transaction(function () use (
            $user,
            $reconciliation,
            $data
        ) {
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

            if (
                $reconciliation->status
                !== CashReconciliation::STATUS_PENDING_REVIEW
                && $reconciliation->status
                !== CashReconciliation::STATUS_DISCREPANCY
            ) {
                throw ValidationException::withMessages([
                    'status' =>
                        'Only pending-review or discrepancy reconciliations can be reviewed.',
                ]);
            }

            $decision = $data['decision'];

            $difference = (string) $reconciliation->difference_amount;

            if (
                $decision === CashReconciliation::STATUS_RECONCILED
                && bccomp($difference, '0.00', 2) !== 0
            ) {
                throw ValidationException::withMessages([
                    'decision' =>
                        'A reconciliation with a cash difference cannot be finalized as reconciled.',
                ]);
            }

            if (
                $decision === CashReconciliation::STATUS_DISCREPANCY
                && bccomp($difference, '0.00', 2) === 0
            ) {
                throw ValidationException::withMessages([
                    'decision' =>
                        'A balanced closing cannot be flagged as a cash discrepancy.',
                ]);
            }

            $reconciliation->reviewed_by = $user->getKey();
            $reconciliation->reviewed_at = now();
            $reconciliation->review_notes =
                $data['review_notes'] ?? null;

            $reconciliation->status = match ($decision) {
                'RECONCILED' =>
                    CashReconciliation::STATUS_RECONCILED,

                'DISCREPANCY' =>
                    CashReconciliation::STATUS_DISCREPANCY,

                'RETURNED' =>
                    CashReconciliation::STATUS_RETURNED,
            };

            $reconciliation->save();

            return $reconciliation->fresh(['collector', 'reviewer']);
        });
    }

    /**
     * Cancel a draft or returned closing.
     */
    public function cancel(
        User $user,
        CashReconciliation $reconciliation
    ): CashReconciliation {
        return DB::transaction(function () use (
            $user,
            $reconciliation
        ) {
            $reconciliation = CashReconciliation::query()
                ->lockForUpdate()
                ->findOrFail($reconciliation->getKey());

            $this->assertCollectorOwns($user, $reconciliation);

            if (
                ! in_array(
                    $reconciliation->status,
                    [
                        CashReconciliation::STATUS_DRAFT,
                        CashReconciliation::STATUS_RETURNED,
                    ],
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'status' =>
                        'Only draft or returned reconciliations can be cancelled.',
                ]);
            }

            $reconciliation->status =
                CashReconciliation::STATUS_CANCELLED;

            $reconciliation->save();

            return $reconciliation->fresh(['collector', 'reviewer']);
        });
    }

    /**
     * Calculate the collector's completed cash payments during the period.
     *
     * IMPORTANT: Verify these column names against your Payment model.
     */
    protected function calculateExpectedCash(
        CashReconciliation $reconciliation
    ): string {
        $amount = Payment::query()
            ->where(
                'received_by',
                $reconciliation->collector_id
            )
            ->where('payment_method', 'CASH')
            ->where('status', 'COMPLETED')
            ->whereBetween('payment_date', [
                $reconciliation->period_start,
                $reconciliation->period_end,
            ])
            ->sum('amount');

        return bcadd((string) $amount, '0.00', 2);
    }

    /**
     * Validate the closing time range.
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
     * Prevent a payment period from being included in multiple closings.
     *
     * Draft and cancelled records do not reserve a period.
     */
    protected function assertNoOverlappingSubmittedPeriod(
        CashReconciliation $reconciliation
    ): void {
        $overlapExists = CashReconciliation::query()
            ->where(
                'collector_id',
                $reconciliation->collector_id
            )
            ->where('id', '<>', $reconciliation->getKey())
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

    /**
     * A collector may only edit their own closing.
     */
    protected function assertCollectorOwns(
        User $user,
        CashReconciliation $reconciliation
    ): void {
        if (
            (string) $reconciliation->collector_id
            !== (string) $user->getKey()
        ) {
            throw ValidationException::withMessages([
                'reconciliation' =>
                    'You are not authorized to modify this reconciliation.',
            ]);
        }
    }

    /**
     * Only drafts and returned records can be edited or resubmitted.
     */
    protected function assertEditable(
        CashReconciliation $reconciliation
    ): void {
        if (
            ! in_array(
                $reconciliation->status,
                [
                    CashReconciliation::STATUS_DRAFT,
                    CashReconciliation::STATUS_RETURNED,
                ],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'status' =>
                    'Only draft or returned reconciliations can be edited or submitted.',
            ]);
        }
    }
}