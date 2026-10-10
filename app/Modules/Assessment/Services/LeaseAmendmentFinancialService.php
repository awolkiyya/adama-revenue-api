<?php

namespace App\Modules\Assessment\Services;

use App\Enums\PaymentScheduleStatus;
use App\Models\Assessment;
use App\Models\PaymentSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaseAmendmentFinancialService
{
    private const CURRENCY_DECIMAL_PLACES = 4;

    private const CLOSURE_REASON =
        'Closed due to an approved lease amendment.';

    /**
     * Validate due installments and close eligible future schedules.
     *
     * Business rules:
     * - Installments due today or earlier must be fully paid.
     * - Future installments may be cancelled if they have no partial
     *   payments and no linked invoice items.
     * - Fully paid future installments remain unchanged.
     * - Existing agreement-defined due dates are never modified.
     *
     * This method must run inside the database transaction that applies
     * the lease amendment. The calling service should lock the original
     * assessment before invoking this method.
     *
     * This service handles payment schedules only. It does not cancel
     * invoices or modify payment records.
     *
     * @return array{
     *     due_date_cutoff: string,
     *     validated_schedule_count: int,
     *     closed_schedule_count: int,
     *     already_paid_future_schedule_count: int,
     *     closed_schedule_ids: array<int, string>
     * }
     *
     * @throws ValidationException
     */
    public function validateAndCloseFutureSchedules(
        Assessment $assessment
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException(
                'Lease amendment financial processing must run inside ' .
                'the amendment application database transaction.'
            );
        }

        if (! $assessment->exists || $assessment->getKey() === null) {
            throw ValidationException::withMessages([
                'assessment' => [
                    'The original assessment does not exist.',
                ],
            ]);
        }

        $timezone = config(
            'app.timezone',
            'Africa/Addis_Ababa'
        );

        /*
         * Today's date is the cutoff.
         *
         * A schedule due today is considered due and must be settled.
         * Future schedules are those whose due dates are after today.
         */
        $today = CarbonImmutable::today($timezone);

        /*
         * Lock schedules associated with services belonging to this
         * assessment. The parent assessment should already be locked
         * by the calling LeaseAmendmentService.
         */
        $schedules = PaymentSchedule::query()
            ->whereHas(
                'assessmentService',
                fn ($query) => $query->where(
                    'assessment_id',
                    $assessment->getKey()
                )
            )
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /*
         * An assessment without schedules has no scheduled obligations
         * for this service to validate or close.
         *
         * The amendment workflow must independently ensure that the
         * target assessment/service is eligible for lease amendment.
         */
        if ($schedules->isEmpty()) {
            return [
                'due_date_cutoff' => $today->toDateString(),
                'validated_schedule_count' => 0,
                'closed_schedule_count' => 0,
                'already_paid_future_schedule_count' => 0,
                'closed_schedule_ids' => [],
            ];
        }

        /*
         * Phase 1:
         *
         * Reject any outstanding balance on non-cancelled schedules
         * due today or earlier.
         */
        $this->assertDueObligationsSettled(
            $schedules,
            $today
        );

        /*
         * Phase 2:
         *
         * Identify future schedules that have not already been cancelled.
         * Validate all candidates before modifying any schedule.
         */
        $futureSchedules = $schedules->filter(
            fn (PaymentSchedule $schedule): bool =>
                ! $schedule->isCancelled()
                && $this->isFutureSchedule(
                    $schedule,
                    $today
                )
        );

        $this->assertFutureSchedulesCanBeClosed(
            $futureSchedules
        );

        /*
         * Phase 3:
         *
         * Cancel eligible unpaid future schedules.
         *
         * Fully paid schedules are deliberately retained to preserve
         * historical payment information.
         */
        $closedScheduleIds = [];

        foreach ($futureSchedules as $schedule) {
            if ($this->isFullyPaid($schedule)) {
                continue;
            }

            $schedule->status = PaymentScheduleStatus::CANCELLED;

            $schedule->notes = $this->appendNote(
                $schedule->notes,
                self::CLOSURE_REASON
            );

            $schedule->save();

            $closedScheduleIds[] = (string) $schedule->getKey();
        }

        /*
         * Count schedules that were due today or earlier and validated.
         * Cancelled schedules are excluded.
         */
        $validatedScheduleCount = $schedules->filter(
            fn (PaymentSchedule $schedule): bool =>
                ! $schedule->isCancelled()
                && $this->isDueOnOrBefore(
                    $schedule,
                    $today
                )
        )->count();

        /*
         * Count future schedules that were already fully paid and
         * therefore left unchanged.
         */
        $paidFutureScheduleCount = $futureSchedules->filter(
            fn (PaymentSchedule $schedule): bool =>
                $this->isFullyPaid($schedule)
        )->count();

        return [
            'due_date_cutoff' => $today->toDateString(),

            'validated_schedule_count' =>
                $validatedScheduleCount,

            'closed_schedule_count' =>
                count($closedScheduleIds),

            'already_paid_future_schedule_count' =>
                $paidFutureScheduleCount,

            'closed_schedule_ids' =>
                $closedScheduleIds,
        ];
    }

    /**
     * Reject non-cancelled schedules due today or earlier that have
     * an outstanding balance.
     */
    private function assertDueObligationsSettled(
        Collection $schedules,
        CarbonImmutable $today
    ): void {
        $unsettled = [];

        foreach ($schedules as $schedule) {
            if ($schedule->isCancelled()) {
                continue;
            }

            if (! $this->isDueOnOrBefore($schedule, $today)) {
                continue;
            }

            $remainingUnits = $this->remainingAmountUnits(
                $schedule
            );

            if ($remainingUnits <= 0) {
                continue;
            }

            $unsettled[] = [
                'schedule_id' =>
                    (string) $schedule->getKey(),

                'installment_number' =>
                    $schedule->installment_number,

                'due_date' =>
                    $schedule->due_date?->toDateString(),

                'amount_due' =>
                    (string) $schedule->amount_due,

                'amount_paid' =>
                    (string) $schedule->amount_paid,

                'remaining_amount' =>
                    $this->formatUnits($remainingUnits),
            ];
        }

        if ($unsettled !== []) {
            throw ValidationException::withMessages([
                'payment_schedules' => [
                    'The lease amendment cannot be applied because ' .
                    'one or more installments due today or earlier ' .
                    'remain outstanding. Settle all due installments ' .
                    'before applying the amendment.',
                ],

                'outstanding_installments' => [
                    json_encode(
                        $unsettled,
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                    ),
                ],
            ]);
        }
    }

    /**
     * Ensure future schedules can be closed safely.
     *
     * Fully paid schedules are retained.
     * Partially paid schedules block the amendment.
     * Schedules linked to invoice items block automatic closure.
     */
    private function assertFutureSchedulesCanBeClosed(
        Collection $futureSchedules
    ): void {
        $blockingSchedules = [];

        foreach ($futureSchedules as $schedule) {
            if ($this->isFullyPaid($schedule)) {
                continue;
            }

            /*
             * A partial payment requires an explicit financial decision.
             */
            if ($this->hasAnyPayment($schedule)) {
                $blockingSchedules[] = [
                    'schedule_id' =>
                        (string) $schedule->getKey(),

                    'installment_number' =>
                        $schedule->installment_number,

                    'reason' =>
                        'The future installment is partially paid. ' .
                        'Its outstanding balance requires an explicit ' .
                        'financial decision before closure.',
                ];

                continue;
            }

            /*
             * Avoid leaving invoice items linked to a cancelled schedule.
             * Resolve linked financial records through the existing
             * invoice workflow before retrying the amendment.
             */
            if ($schedule->invoiceItems()->exists()) {
                $blockingSchedules[] = [
                    'schedule_id' =>
                        (string) $schedule->getKey(),

                    'installment_number' =>
                        $schedule->installment_number,

                    'reason' =>
                        'An invoice item is linked to this schedule. ' .
                        'Resolve the linked financial record through ' .
                        'the existing invoice workflow before closure.',
                ];
            }
        }

        if ($blockingSchedules !== []) {
            throw ValidationException::withMessages([
                'future_payment_schedules' => [
                    'One or more future installments cannot be closed ' .
                    'automatically. Resolve the listed financial records ' .
                    'before applying the lease amendment.',
                ],

                'blocking_schedules' => [
                    json_encode(
                        $blockingSchedules,
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                    ),
                ],
            ]);
        }
    }

    /**
     * Determine whether a schedule's existing due date is after today.
     */
    private function isFutureSchedule(
        PaymentSchedule $schedule,
        CarbonImmutable $today
    ): bool {
        return $this->getScheduleDueDate($schedule)
            ->greaterThan($today);
    }

    /**
     * Determine whether a schedule's existing due date is today
     * or earlier.
     */
    private function isDueOnOrBefore(
        PaymentSchedule $schedule,
        CarbonImmutable $today
    ): bool {
        return $this->getScheduleDueDate($schedule)
            ->lessThanOrEqualTo($today);
    }

    /**
     * Return the validated due date already stored on the schedule.
     *
     * This method does not generate or modify due dates.
     */
    private function getScheduleDueDate(
        PaymentSchedule $schedule
    ): CarbonImmutable {
        if ($schedule->due_date === null) {
            throw ValidationException::withMessages([
                'payment_schedules' => [
                    'A payment schedule has no due date. Its payment ' .
                    'obligation cannot be classified safely.',
                ],
            ]);
        }

        $timezone = config(
            'app.timezone',
            'Africa/Addis_Ababa'
        );

        return CarbonImmutable::parse(
            $schedule->due_date,
            $timezone
        )->startOfDay();
    }

    /**
     * Determine whether the schedule is fully paid.
     */
    private function isFullyPaid(
        PaymentSchedule $schedule
    ): bool {
        return $this->remainingAmountUnits($schedule) <= 0;
    }

    /**
     * Determine whether any positive amount has been paid.
     */
    private function hasAnyPayment(
        PaymentSchedule $schedule
    ): bool {
        return $this->amountToUnits(
            $schedule->amount_paid
        ) > 0;
    }

    /**
     * Calculate the remaining balance in units of 0.0001.
     *
     * Integer arithmetic avoids binary floating-point errors.
     */
    private function remainingAmountUnits(
        PaymentSchedule $schedule
    ): int {
        $amountDue = $this->amountToUnits(
            $schedule->amount_due
        );

        $amountPaid = $this->amountToUnits(
            $schedule->amount_paid
        );

        return max($amountDue - $amountPaid, 0);
    }

    /**
     * Convert a decimal monetary value into integer units.
     *
     * Examples:
     * 100.2500 => 1002500
     * 0.0001   => 1
     * 0.1      => 1000
     *
     * Values with more than four decimal places are rounded to
     * four decimal places.
     */
    private function amountToUnits(mixed $amount): int
    {
        $value = trim((string) ($amount ?? '0'));

        if ($value === '') {
            $value = '0';
        }

        if (! preg_match(
            '/^([+-]?)(\d+)(?:\.(\d*))?$/',
            $value,
            $matches
        )) {
            throw new \UnexpectedValueException(
                'Invalid monetary decimal value.'
            );
        }

        $negative = ($matches[1] ?? '') === '-';
        $whole = $matches[2];
        $fraction = $matches[3] ?? '';

        $scale = self::CURRENCY_DECIMAL_PLACES;

        /*
         * Keep four fractional digits plus one rounding digit.
         * Right-padding preserves the decimal's actual place value.
         */
        $fraction = str_pad(
            $fraction,
            $scale + 1,
            '0',
            STR_PAD_RIGHT
        );

        $keptFraction = substr($fraction, 0, $scale);
        $roundingDigit = (int) $fraction[$scale];

        $units = ((int) $whole * (10 ** $scale))
            + (int) $keptFraction;

        if ($roundingDigit >= 5) {
            $units++;
        }

        return $negative ? -$units : $units;
    }

    /**
     * Convert integer monetary units into a decimal string.
     */
    private function formatUnits(int $units): string
    {
        $negative = $units < 0;
        $absolute = abs($units);

        $factor = 10 ** self::CURRENCY_DECIMAL_PLACES;

        $whole = intdiv($absolute, $factor);
        $fraction = $absolute % $factor;

        $formatted = $whole . '.' . str_pad(
            (string) $fraction,
            self::CURRENCY_DECIMAL_PLACES,
            '0',
            STR_PAD_LEFT
        );

        return $negative ? '-' . $formatted : $formatted;
    }

    /**
     * Append a closure reason without discarding existing notes.
     */
    private function appendNote(
        ?string $existing,
        string $newNote
    ): string {
        $existing = trim((string) $existing);

        if ($existing === '') {
            return $newNote;
        }

        if (str_contains($existing, $newNote)) {
            return $existing;
        }

        return $existing . PHP_EOL . $newNote;
    }
}