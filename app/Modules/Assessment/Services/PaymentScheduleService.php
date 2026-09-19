<?php

namespace App\Modules\Assessment\Services;

use App\Enums\PaymentScheduleStatus;
use App\Models\Assessment;
use App\Models\AssessmentService as AssessmentServiceModel;
use App\Models\PaymentSchedule;
use App\Models\RevenueCodePaymentScheduleRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentScheduleService
{
    /*
    |--------------------------------------------------------------------------
    | Scheduled Revenue Code Configuration
    |--------------------------------------------------------------------------
    |
    | Payment schedules are currently applicable only to LIZZ.
    |
    | RevenueService does not contain a "code" column.
    |
    | Actual relationship:
    |
    | assessment_services.service_id
    |     ↓
    | revenue_services.id
    |     ↓
    | revenue_services.revenue_code_id
    |     ↓
    | revenue_codes.id
    |     ↓
    | revenue_codes.code
    |
    | Current LIZZ revenue code:
    |
    |     1731
    |
    | Keep this decision centralized so the approval workflow and other
    | application services do not need to know which revenue services
    | require payment scheduling.
    |
    */

    private const FIRST_INSTALLMENT_REQUIRED_FIELD =
        'FIRST_INSTALLMENT_REQUIRED';

    private const PAYMENT_COMPLETION_YEARS_FIELD =
        'PAYMENT_COMPLETION_YEARS';

    /*
    |--------------------------------------------------------------------------
    | CREATE FOR ASSESSMENT
    |--------------------------------------------------------------------------
    |
    | Creates payment schedules for all AssessmentService records that
    | require scheduling.
    |
    | This service ONLY manages payment schedules.
    |
    | It does not:
    |
    | - create invoices
    | - issue invoices
    | - process payments
    | - allocate payments
    | - generate receipts
    | - calculate tariffs
    | - calculate penalties
    | - calculate interest
    | - approve assessments
    |
    */

    public function createForAssessment(
        Assessment $assessment
    ): Collection {
        Log::info(
            'Payment schedule creation started for assessment.',
            [
                'assessment_id' =>
                    $assessment->id,

                'assessment_status' =>
                    $assessment->status,
            ]
        );

        try {
            return DB::transaction(
                function () use ($assessment): Collection {
                    $assessment->loadMissing([
                        'services.service.revenueCode',
                    ]);

                    $this->validateAssessmentForScheduling(
                        $assessment
                    );

                    Log::info(
                        'Assessment validated for payment scheduling.',
                        [
                            'assessment_id' =>
                                $assessment->id,

                            'assessment_status' =>
                                $assessment->status,

                            'service_count' =>
                                $assessment->services->count(),
                        ]
                    );

                    $schedules = new Collection();

                    foreach ($assessment->services as $assessmentService) {
                        $revenueCode =
                            $this->resolveRevenueCode(
                                $assessmentService
                            );

                        Log::info(
                            'Evaluating assessment service for payment scheduling.',
                            [
                                'assessment_id' =>
                                    $assessment->id,

                                'assessment_service_id' =>
                                    $assessmentService->id,

                                'service_id' =>
                                    $assessmentService->service_id,

                                'revenue_code' =>
                                    $revenueCode,
                            ]
                        );

                        $paymentScheduleRule = $this->resolvePaymentScheduleRule($assessmentService);

                        if (!$paymentScheduleRule) {
                            Log::info(
                                'Payment scheduling skipped for assessment service.',
                                [
                                    'assessment_id' =>
                                        $assessment->id,

                                    'assessment_service_id' =>
                                        $assessmentService->id,

                                    'service_id' =>
                                        $assessmentService->service_id,

                                    'revenue_code' =>
                                        $revenueCode,

                                    'reason' =>
                                        'revenue_code_does_not_require_payment_schedule',
                                ]
                            );

                            continue;
                        }

                        $created =
                            $this->createForAssessmentServiceInternal(
                                $assessmentService
                            );

                        foreach ($created as $schedule) {
                            $schedules->push($schedule);
                        }
                    }

                    Log::info(
                        'Payment schedule creation completed for assessment.',
                        [
                            'assessment_id' =>
                                $assessment->id,

                            'schedule_count' =>
                                $schedules->count(),

                            'schedule_ids' =>
                                $schedules
                                    ->pluck('id')
                                    ->values()
                                    ->all(),
                        ]
                    );

                    return $schedules;
                }
            );
        } catch (Throwable $exception) {
            $this->logError(
                'Payment schedule creation failed for assessment.',
                [
                    'assessment_id' =>
                        $assessment->id,

                    'assessment_status' =>
                        $assessment->status,
                ],
                $exception
            );

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE FOR ASSESSMENT SERVICE
    |--------------------------------------------------------------------------
    |
    | Creates payment schedules for one AssessmentService.
    |
    | The operation is idempotent:
    |
    | Existing schedules are returned instead of being duplicated.
    |
    */

    public function createForAssessmentService(
        AssessmentServiceModel $assessmentService
    ): Collection {
        Log::info(
            'Payment schedule creation started for assessment service.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'assessment_id' =>
                    $assessmentService->assessment_id,

                'service_id' =>
                    $assessmentService->service_id,
            ]
        );

        try {
            return DB::transaction(
                function () use ($assessmentService): Collection {
                    return $this->createForAssessmentServiceInternal(
                        $assessmentService
                    );
                }
            );
        } catch (Throwable $exception) {
            $this->logError(
                'Payment schedule creation failed for assessment service.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'service_id' =>
                        $assessmentService->service_id,
                ],
                $exception
            );

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | INTERNAL CREATE
    |--------------------------------------------------------------------------
    */

    private function createForAssessmentServiceInternal(
        AssessmentServiceModel $assessmentService
    ): Collection {
        $assessmentService->loadMissing([
            'assessment',
            'service.revenueCode',
            'values.revenueServiceField.baseField',
        ]);

        $revenueCode =
            $this->resolveRevenueCode(
                $assessmentService
            );

        Log::info(
            'Assessment service relationships loaded.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'assessment_id' =>
                    $assessmentService->assessment_id,

                'service_id' =>
                    $assessmentService->service_id,

                'revenue_code' =>
                    $revenueCode,

                'status' =>
                    $assessmentService->status,

                'computed_amount' =>
                    $assessmentService->computed_amount,

                'due_date' =>
                    $assessmentService->due_date?->toDateString(),
            ]
        );

        $this->validateAssessmentServiceForScheduling(
            $assessmentService
        );

        /*
        |--------------------------------------------------------------------------
        | Applicability
        |--------------------------------------------------------------------------
        */

        $paymentScheduleRule = $this->resolvePaymentScheduleRule($assessmentService);

        if (!$paymentScheduleRule) {
            Log::info(
                'Payment schedule creation skipped because revenue code does not require scheduling.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'revenue_code' =>
                        $revenueCode,
                ]
            );

            return new Collection();
        }

        /*
        |--------------------------------------------------------------------------
        | Idempotency
        |--------------------------------------------------------------------------
        */

        $existingSchedules =
            $this->getSchedules(
                $assessmentService
            );

        if ($existingSchedules->isNotEmpty()) {
            Log::warning(
                'Existing payment schedules found. Creation skipped to preserve idempotency.',
                [
                    'assessment_service_id' =>
                        $assessmentService->id,

                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'revenue_code' =>
                        $revenueCode,

                    'existing_schedule_count' =>
                        $existingSchedules->count(),

                    'existing_schedule_ids' =>
                        $existingSchedules
                            ->pluck('id')
                            ->values()
                            ->all(),
                ]
            );

            return $existingSchedules;
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve LIZZ Configuration
        |--------------------------------------------------------------------------
        */

        $configuration =
            $this->resolvePaymentScheduleConfiguration(
                $assessmentService
            );

        Log::info(
            'LIZZ payment configuration resolved.',
            [
                'assessment_id' =>
                    $assessmentService->assessment_id,

                'assessment_service_id' =>
                    $assessmentService->id,

                'revenue_code' =>
                    $revenueCode,

                'principal_amount' =>
                    $configuration['principal_amount'],

                'first_installment_required' =>
                    $configuration['first_installment_required'],

                'first_installment_percentage' =>
                    $configuration['first_installment_percentage'],

                'payment_completion_years' =>
                    $configuration['payment_completion_years'],
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Build Schedule
        |--------------------------------------------------------------------------
        */

        return $this->buildPaymentSchedule(
            $assessmentService,
            $configuration
        );
    }

    /*
    |--------------------------------------------------------------------------
    | BUILD LIZZ SCHEDULE
    |--------------------------------------------------------------------------
    |
    | LIZZ payment scheduling rule:
    |
    | If FIRST_INSTALLMENT_REQUIRED = true:
    |
    |   Schedule #1:
    |       configured percentage of principal
    |       due on AssessmentService.due_date
    |
    |   Schedules #2 ... #N+1:
    |       remaining balance divided across PAYMENT_COMPLETION_YEARS
    |       one payment schedule per year
    |
    | If FIRST_INSTALLMENT_REQUIRED = false:
    |
    |   Schedules #1 ... #N:
    |       full principal divided across PAYMENT_COMPLETION_YEARS
    |       one payment schedule per year
    |
    | Example:
    |
    |   Principal = 128,205
    |   First installment = 20%
    |   First installment = 25,641
    |   Remaining balance = 102,564
    |   Completion years = 3
    |
    |   Schedule #1 = 25,641 on base due date
    |   Schedule #2 = 34,188 after 1 year
    |   Schedule #3 = 34,188 after 2 years
    |   Schedule #4 = 34,188 after 3 years
    |
    | The final annual installment absorbs any rounding difference.
    |
    */

    private function buildPaymentSchedule(
        AssessmentServiceModel $assessmentService,
        array $configuration
    ): Collection {
        $principal =
            $configuration['principal_amount'];

        $firstInstallmentRequired =
            $configuration['first_installment_required'];

        $firstInstallmentPercentage =
            $configuration['first_installment_percentage'];

        $completionYears =
            $configuration['payment_completion_years'];

        if (!$assessmentService->due_date) {
            throw ValidationException::withMessages([
                'due_date' => [
                    'The LIZZ assessment service must have a resolved due date before a payment schedule can be created.',
                ],
            ]);
        }

        if ($completionYears <= 0) {
            throw ValidationException::withMessages([
                'PAYMENT_COMPLETION_YEARS' => [
                    'The LIZZ payment completion period must be greater than zero.',
                ],
            ]);
        }

        $assessmentDueDate =
            Carbon::parse(
                $assessmentService->due_date
            );

        Log::info(
            'Building LIZZ annual payment schedule.',
            [
                'assessment_id' =>
                    $assessmentService->assessment_id,

                'assessment_service_id' =>
                    $assessmentService->id,

                'principal_amount' =>
                    $principal,

                'first_installment_required' =>
                    $firstInstallmentRequired,

                'first_installment_percentage' =>
                    $firstInstallmentPercentage,

                'payment_completion_years' =>
                    $completionYears,

                'base_due_date' =>
                    $assessmentDueDate->toDateString(),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Determine Initial Installment
        |--------------------------------------------------------------------------
        */

        $firstInstallmentAmount = 0.0;

        if ($firstInstallmentRequired) {
            $firstInstallmentAmount =
                $this->calculatePercentageAmount(
                    $principal,
                    $firstInstallmentPercentage
                );

            if ($firstInstallmentAmount <= 0) {
                Log::warning(
                    'LIZZ first installment calculation produced a non-positive amount.',
                    [
                        'assessment_id' =>
                            $assessmentService->assessment_id,

                        'assessment_service_id' =>
                            $assessmentService->id,

                        'principal_amount' =>
                            $principal,

                        'percentage' =>
                            $firstInstallmentPercentage,

                        'calculated_amount' =>
                            $firstInstallmentAmount,
                    ]
                );

                throw ValidationException::withMessages([
                    'lizz_first_installment_percentage' => [
                        'The LIZZ first installment amount must be greater than zero when the first installment is required.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Defensive protection against a first installment greater than
            | the principal.
            |--------------------------------------------------------------------------
            */

            if ($firstInstallmentAmount >= $principal) {
                Log::info(
                    'LIZZ first installment equals or exceeds principal. Principal will be fully scheduled as the first installment.',
                    [
                        'assessment_id' =>
                            $assessmentService->assessment_id,

                        'assessment_service_id' =>
                            $assessmentService->id,

                        'principal_amount' =>
                            $principal,

                        'calculated_first_installment' =>
                            $firstInstallmentAmount,
                    ]
                );

                $firstInstallmentAmount =
                    $principal;
            }

            Log::info(
                'LIZZ first installment calculated.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'principal_amount' =>
                        $principal,

                    'percentage' =>
                        $firstInstallmentPercentage,

                    'first_installment_amount' =>
                        $firstInstallmentAmount,
                ]
            );
        } else {
            Log::info(
                'LIZZ first installment is not required.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'principal_amount' =>
                        $principal,
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Remaining Balance
        |--------------------------------------------------------------------------
        */

        $remainingBalance =
            $this->normalizeMoney(
                $principal - $firstInstallmentAmount
            );

        Log::info(
            'LIZZ remaining balance calculated.',
            [
                'assessment_id' =>
                    $assessmentService->assessment_id,

                'assessment_service_id' =>
                    $assessmentService->id,

                'principal_amount' =>
                    $principal,

                'first_installment_amount' =>
                    $firstInstallmentAmount,

                'remaining_balance' =>
                    $remainingBalance,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Create Collection
        |--------------------------------------------------------------------------
        */

        $schedules =
            new Collection();

        $installmentNumber = 1;

        /*
        |--------------------------------------------------------------------------
        | Initial Installment
        |--------------------------------------------------------------------------
        */

        if ($firstInstallmentRequired) {
            $firstSchedule =
                PaymentSchedule::query()->create([
                    'assessment_service_id' =>
                        $assessmentService->id,

                    'installment_number' =>
                        $installmentNumber,

                    'due_date' =>
                        $assessmentDueDate,

                    'amount_due' =>
                        $firstInstallmentAmount,

                    'amount_paid' =>
                        0,

                    'status' =>
                        PaymentScheduleStatus::PENDING->value,

                    'paid_at' =>
                        null,

                    'notes' =>
                        $this->buildFirstInstallmentNotes(
                            $firstInstallmentPercentage,
                            $principal,
                            $remainingBalance
                        ),
                ]);

            $schedules->push(
                $firstSchedule
            );

            Log::info(
                'LIZZ first payment schedule created.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'payment_schedule_id' =>
                        $firstSchedule->id,

                    'installment_number' =>
                        $installmentNumber,

                    'due_date' =>
                        $assessmentDueDate->toDateString(),

                    'amount_due' =>
                        $firstInstallmentAmount,

                    'status' =>
                        PaymentScheduleStatus::PENDING->value,
                ]
            );

            $installmentNumber++;
        }

        /*
        |--------------------------------------------------------------------------
        | No Remaining Balance
        |--------------------------------------------------------------------------
        */

        if ($remainingBalance <= 0) {
            Log::info(
                'LIZZ has no remaining balance after initial installment.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'principal_amount' =>
                        $principal,

                    'first_installment_amount' =>
                        $firstInstallmentAmount,

                    'schedule_count' =>
                        $schedules->count(),
                ]
            );

            return $schedules;
        }

        /*
        |--------------------------------------------------------------------------
        | Annual Amount
        |--------------------------------------------------------------------------
        |
        | The remaining balance is distributed equally across the configured
        | number of payment-completion years.
        |
        | Example:
        |
        |   Remaining = 102,564
        |   Years    = 3
        |
        |   Annual amount = 102,564 / 3 = 34,188
        |
        */

        $annualAmount =
            $this->normalizeMoney(
                $remainingBalance / $completionYears
            );

        if ($annualAmount <= 0) {
            throw ValidationException::withMessages([
                'payment_completion_years' => [
                    'The calculated annual LIZZ payment amount must be greater than zero.',
                ],
            ]);
        }

        Log::info(
            'LIZZ annual payment amount calculated.',
            [
                'assessment_id' =>
                    $assessmentService->assessment_id,

                'assessment_service_id' =>
                    $assessmentService->id,

                'remaining_balance' =>
                    $remainingBalance,

                'payment_completion_years' =>
                    $completionYears,

                'annual_amount' =>
                    $annualAmount,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Create One Payment Schedule Per Year
        |--------------------------------------------------------------------------
        */

        $scheduledRemainingTotal = 0.0;

        for (
            $year = 1;
            $year <= $completionYears;
            $year++
        ) {
            /*
            |--------------------------------------------------------------------------
            | Annual Due Date
            |--------------------------------------------------------------------------
            |
            | Year 1 = base date + 1 year
            | Year 2 = base date + 2 years
            | ...
            | Year N = base date + N years
            |
            */

            $dueDate =
                $assessmentDueDate
                    ->copy()
                    ->addYears($year);

            /*
            |--------------------------------------------------------------------------
            | Annual Amount
            |--------------------------------------------------------------------------
            |
            | All years use the calculated annual amount except the final
            | year, which receives the exact remaining amount after all
            | previous annual schedules have been calculated.
            |
            | This guarantees exact reconciliation despite decimal rounding.
            |
            */

            if ($year === $completionYears) {
                $amountDue =
                    $this->normalizeMoney(
                        $remainingBalance -
                        $scheduledRemainingTotal
                    );
            } else {
                $amountDue =
                    $annualAmount;
            }

            if ($amountDue <= 0) {
                Log::error(
                    'Calculated LIZZ annual payment amount is not positive.',
                    [
                        'assessment_id' =>
                            $assessmentService->assessment_id,

                        'assessment_service_id' =>
                            $assessmentService->id,

                        'completion_year' =>
                            $year,

                        'completion_years' =>
                            $completionYears,

                        'remaining_balance' =>
                            $remainingBalance,

                        'scheduled_remaining_total' =>
                            $scheduledRemainingTotal,

                        'calculated_amount' =>
                            $amountDue,
                    ]
                );

                throw ValidationException::withMessages([
                    'payment_schedule' => [
                        sprintf(
                            'The calculated LIZZ payment amount for year %d must be greater than zero.',
                            $year
                        ),
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Create Annual Schedule
            |--------------------------------------------------------------------------
            */

            $schedule =
                PaymentSchedule::query()->create([
                    'assessment_service_id' =>
                        $assessmentService->id,

                    'installment_number' =>
                        $installmentNumber,

                    'due_date' =>
                        $dueDate,

                    'amount_due' =>
                        $amountDue,

                    'amount_paid' =>
                        0,

                    'status' =>
                        PaymentScheduleStatus::PENDING->value,

                    'paid_at' =>
                        null,

                    'notes' =>
                        $this->buildAnnualInstallmentNotes(
                            $year,
                            $completionYears,
                            $principal,
                            $firstInstallmentAmount,
                            $remainingBalance,
                            $amountDue
                        ),
                ]);

            $schedules->push(
                $schedule
            );

            $scheduledRemainingTotal =
                $this->normalizeMoney(
                    $scheduledRemainingTotal +
                    $amountDue
                );

            Log::info(
                'LIZZ annual payment schedule created.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'payment_schedule_id' =>
                        $schedule->id,

                    'installment_number' =>
                        $installmentNumber,

                    'completion_year' =>
                        $year,

                    'completion_years' =>
                        $completionYears,

                    'due_date' =>
                        $dueDate->toDateString(),

                    'amount_due' =>
                        $amountDue,

                    'scheduled_remaining_total' =>
                        $scheduledRemainingTotal,

                    'remaining_balance' =>
                        $remainingBalance,

                    'status' =>
                        PaymentScheduleStatus::PENDING->value,
                ]
            );

            $installmentNumber++;
        }

        /*
        |--------------------------------------------------------------------------
        | Final Balance Integrity Check
        |--------------------------------------------------------------------------
        */

        $scheduledRemainingDifference =
            $this->normalizeMoney(
                $remainingBalance -
                $scheduledRemainingTotal
            );

        if ($scheduledRemainingDifference !== 0.0) {
            Log::error(
                'LIZZ payment schedule balance mismatch detected.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'principal_amount' =>
                        $principal,

                    'first_installment_amount' =>
                        $firstInstallmentAmount,

                    'remaining_balance' =>
                        $remainingBalance,

                    'scheduled_remaining_total' =>
                        $scheduledRemainingTotal,

                    'difference' =>
                        $scheduledRemainingDifference,
                ]
            );

            throw ValidationException::withMessages([
                'payment_schedule' => [
                    'The generated LIZZ payment schedules do not reconcile with the remaining balance.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Total Schedule Integrity Check
        |--------------------------------------------------------------------------
        */

        $totalScheduled =
            $this->normalizeMoney(
                $schedules->sum(
                    function (
                        PaymentSchedule $schedule
                    ): float {
                        return $this->normalizeMoney(
                            $schedule->amount_due
                        );
                    }
                )
            );

        $totalDifference =
            $this->normalizeMoney(
                $principal -
                $totalScheduled
            );

        if ($totalDifference !== 0.0) {
            Log::error(
                'LIZZ total payment schedule does not reconcile with principal.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'principal_amount' =>
                        $principal,

                    'total_scheduled_amount' =>
                        $totalScheduled,

                    'difference' =>
                        $totalDifference,

                    'schedule_count' =>
                        $schedules->count(),
                ]
            );

            throw ValidationException::withMessages([
                'payment_schedule' => [
                    'The generated LIZZ payment schedules do not reconcile with the principal amount.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Final Summary
        |--------------------------------------------------------------------------
        */

        Log::info(
            'LIZZ annual payment schedule build completed.',
            [
                'assessment_id' =>
                    $assessmentService->assessment_id,

                'assessment_service_id' =>
                    $assessmentService->id,

                'principal_amount' =>
                    $principal,

                'first_installment_amount' =>
                    $firstInstallmentAmount,

                'remaining_balance' =>
                    $remainingBalance,

                'payment_completion_years' =>
                    $completionYears,

                'annual_remaining_amount' =>
                    $annualAmount,

                'scheduled_remaining_total' =>
                    $scheduledRemainingTotal,

                'total_scheduled_amount' =>
                    $totalScheduled,

                'schedule_count' =>
                    $schedules->count(),

                'schedule_ids' =>
                    $schedules
                        ->pluck('id')
                        ->values()
                        ->all(),
            ]
        );

        return $schedules;
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE PAYMENT SCHEDULE RULE
    |--------------------------------------------------------------------------
    */

    private function resolvePaymentScheduleRule(
        AssessmentServiceModel $assessmentService
    ): ?RevenueCodePaymentScheduleRule {
        $assessmentService->loadMissing([
            'service.revenueCode.paymentScheduleRule',
        ]);

        $rule = $assessmentService->service?->revenueCode?->paymentScheduleRule;

        if (!$rule || !$rule->is_enabled) {
            return null;
        }

        if ($rule->first_installment_percentage === null) {
            Log::warning('Payment schedule rule has no first installment percentage.', [
                'assessment_service_id' => $assessmentService->id,
                'assessment_id' => $assessmentService->assessment_id,
                'revenue_code' => $assessmentService->service?->revenueCode?->code,
            ]);
        }

        return $rule;
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE PAYMENT SCHEDULE CONFIGURATION
    |--------------------------------------------------------------------------
    */

    private function resolvePaymentScheduleConfiguration(
        AssessmentServiceModel $assessmentService
    ): array {
        $principal = $this->normalizeMoney($assessmentService->computed_amount);

        if ($principal <= 0) {
            throw ValidationException::withMessages([
                'computed_amount' => ['The payment schedule principal amount must be greater than zero.'],
            ]);
        }

        $rule = $this->resolvePaymentScheduleRule($assessmentService);

        if (!$rule) {
            throw ValidationException::withMessages([
                'payment_schedule' => ['This revenue code does not have an active payment schedule rule.'],
            ]);
        }

        $firstInstallmentRequired = $this->resolveBooleanField(
            $assessmentService,
            self::FIRST_INSTALLMENT_REQUIRED_FIELD
        );

        $paymentCompletionYears = $this->resolvePositiveIntegerField(
            $assessmentService,
            self::PAYMENT_COMPLETION_YEARS_FIELD
        );

        $percentage = $rule->first_installment_percentage;

        if ($firstInstallmentRequired) {
            if ($percentage === null || !is_numeric($percentage)) {
                throw ValidationException::withMessages([
                    'first_installment_percentage' => ['The active payment schedule rule must define a valid first installment percentage.'],
                ]);
            }
            $percentage = (float) $percentage;
            if (!is_finite($percentage) || $percentage <= 0 || $percentage > 100) {
                throw ValidationException::withMessages([
                    'first_installment_percentage' => ['The first installment percentage must be greater than 0 and less than or equal to 100.'],
                ]);
            }
        } else {
            $percentage = 0.0;
        }

        Log::info('Payment schedule configuration resolved.', [
            'assessment_id' => $assessmentService->assessment_id,
            'assessment_service_id' => $assessmentService->id,
            'revenue_code' => $assessmentService->service?->revenueCode?->code,
            'payment_schedule_rule_id' => $rule->id,
            'first_installment_required' => $firstInstallmentRequired,
            'first_installment_percentage' => $percentage,
            'payment_completion_years' => $paymentCompletionYears,
            'percentage_source' => 'revenue_code_payment_schedule_rules',
        ]);

        return [
            'principal_amount' => $principal,
            'first_installment_required' => $firstInstallmentRequired,
            'first_installment_percentage' => $percentage,
            'payment_completion_years' => $paymentCompletionYears,
            'payment_schedule_rule_id' => $rule->id,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE BOOLEAN FIELD
    |--------------------------------------------------------------------------
    */

    private function resolveBooleanField(
        AssessmentServiceModel $assessmentService,
        string $fieldCode
    ): bool {
        $value =
            $this->resolveFieldValue(
                $assessmentService,
                $fieldCode
            );

        if (
            $value === null ||
            trim((string) $value) === ''
        ) {
            Log::warning(
                'Required LIZZ boolean field is missing.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'field_code' =>
                        $fieldCode,
                ]
            );

            throw ValidationException::withMessages([
                $fieldCode => [
                    sprintf(
                        'The %s value is required for LIZZ payment scheduling.',
                        $fieldCode
                    ),
                ],
            ]);
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized =
            strtolower(
                trim((string) $value)
            );

        return match ($normalized) {
            '1',
            'true',
            'yes',
            'y',
            'on' => true,

            '0',
            'false',
            'no',
            'n',
            'off' => false,

            default => throw ValidationException::withMessages([
                $fieldCode => [
                    sprintf(
                        'The %s value must be true or false.',
                        $fieldCode
                    ),
                ],
            ]),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE POSITIVE INTEGER FIELD
    |--------------------------------------------------------------------------
    */

    private function resolvePositiveIntegerField(
        AssessmentServiceModel $assessmentService,
        string $fieldCode
    ): int {
        $value =
            $this->resolveFieldValue(
                $assessmentService,
                $fieldCode
            );

        if (
            $value === null ||
            trim((string) $value) === '' ||
            !is_numeric($value)
        ) {
            Log::warning(
                'Required LIZZ integer field is invalid.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'field_code' =>
                        $fieldCode,

                    'value_present' =>
                        $value !== null &&
                        trim((string) $value) !== '',
                ]
            );

            throw ValidationException::withMessages([
                $fieldCode => [
                    sprintf(
                        'The %s value must be a positive whole number.',
                        $fieldCode
                    ),
                ],
            ]);
        }

        $number =
            (float) $value;

        if (
            !is_finite($number) ||
            $number <= 0 ||
            floor($number) !== $number
        ) {
            Log::warning(
                'LIZZ integer field failed validation.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'field_code' =>
                        $fieldCode,

                    'value' =>
                        $value,
                ]
            );

            throw ValidationException::withMessages([
                $fieldCode => [
                    sprintf(
                        'The %s value must be a positive whole number.',
                        $fieldCode
                    ),
                ],
            ]);
        }

        return (int) $number;
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE FIELD VALUE
    |--------------------------------------------------------------------------
    |
    | Canonical relationship:
    |
    | AssessmentService
    |   -> values()
    |   -> revenueServiceField
    |   -> baseField
    |   -> code
    |
    */

    private function resolveFieldValue(
        AssessmentServiceModel $assessmentService,
        string $fieldCode
    ): mixed {
        $normalizedFieldCode =
            strtoupper(
                trim($fieldCode)
            );

        $value =
            $assessmentService
                ->values
                ->first(
                    function ($serviceValue) use (
                        $normalizedFieldCode
                    ): bool {
                        $baseField =
                            $serviceValue
                                ->revenueServiceField
                                ?->baseField;

                        if (!$baseField) {
                            return false;
                        }

                        return strtoupper(
                            trim(
                                (string) $baseField->code
                            )
                        ) === $normalizedFieldCode;
                    }
                );

        return $value?->value;
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE REVENUE CODE
    |--------------------------------------------------------------------------
    |
    | Correct service identity:
    |
    | assessment_services.service_id
    |          ↓
    | revenue_services.id
    |          ↓
    | revenue_services.revenue_code_id
    |          ↓
    | revenue_codes.id
    |          ↓
    | revenue_codes.code
    |
    | RevenueService does NOT have a "code" column.
    |
    */

    private function resolveRevenueCode(
        AssessmentServiceModel $assessmentService
    ): string {
        $assessmentService->loadMissing([
            'service.revenueCode',
        ]);

        $service =
            $assessmentService->service;

        if (!$service) {
            Log::error(
                'Revenue service relationship could not be resolved for assessment service.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'service_id' =>
                        $assessmentService->service_id,
                ]
            );

            throw ValidationException::withMessages([
                'service_id' => [
                    'The revenue service associated with this assessment service could not be found.',
                ],
            ]);
        }

        $revenueCode =
            $service->revenueCode;

        if (!$revenueCode) {
            Log::error(
                'Revenue service has no associated revenue code.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'service_id' =>
                        $service->id,

                    'revenue_code_id' =>
                        $service->revenue_code_id,
                ]
            );

            throw ValidationException::withMessages([
                'service_id' => [
                    'The revenue service does not have an associated revenue code.',
                ],
            ]);
        }

        $code =
            strtoupper(
                trim(
                    (string) $revenueCode->code
                )
            );

        if ($code === '') {
            Log::error(
                'Associated revenue code has no valid code.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'service_id' =>
                        $service->id,

                    'revenue_code_id' =>
                        $revenueCode->id,
                ]
            );

            throw ValidationException::withMessages([
                'service_id' => [
                    'The associated revenue code does not have a valid code.',
                ],
            ]);
        }

        return $code;
    }

    /*
    |--------------------------------------------------------------------------
    | IS REQUIRED
    |--------------------------------------------------------------------------
    */

    public function isRequired(
        AssessmentServiceModel $assessmentService
    ): bool {
        $revenueCode =
            $this->resolveRevenueCode(
                $assessmentService
            );

        $required = $this->resolvePaymentScheduleRule($assessmentService) !== null;

        Log::info(
            'Payment scheduling applicability resolved.',
            [
                'assessment_service_id' =>
                    $assessmentService->id,

                'assessment_id' =>
                    $assessmentService->assessment_id,

                'service_id' =>
                    $assessmentService->service_id,

                'revenue_code' =>
                    $revenueCode,

                'payment_schedule_required' =>
                    $required,
            ]
        );

        return $required;
    }

    /*
    |--------------------------------------------------------------------------
    | GET OR CREATE
    |--------------------------------------------------------------------------
    */

    public function getOrCreateForAssessmentService(
        AssessmentServiceModel $assessmentService
    ): Collection {
        $revenueCode =
            $this->resolveRevenueCode(
                $assessmentService
            );

        $paymentScheduleRule = $this->resolvePaymentScheduleRule($assessmentService);

        if (!$paymentScheduleRule) {
            Log::info(
                'Get-or-create payment schedule skipped for non-scheduled revenue code.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'revenue_code' =>
                        $revenueCode,
                ]
            );

            return new Collection();
        }

        $existingSchedules =
            $this->getSchedules(
                $assessmentService
            );

        if ($existingSchedules->isNotEmpty()) {
            Log::info(
                'Existing payment schedules returned by get-or-create operation.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'revenue_code' =>
                        $revenueCode,

                    'schedule_count' =>
                        $existingSchedules->count(),

                    'schedule_ids' =>
                        $existingSchedules
                            ->pluck('id')
                            ->values()
                            ->all(),
                ]
            );

            return $existingSchedules;
        }

        Log::info(
            'No payment schedules found. Creating schedules.',
            [
                'assessment_id' =>
                    $assessmentService->assessment_id,

                'assessment_service_id' =>
                    $assessmentService->id,

                'revenue_code' =>
                    $revenueCode,
            ]
        );

        return $this->createForAssessmentService(
            $assessmentService
        );
    }

    /*
    |--------------------------------------------------------------------------
    | GET SCHEDULES
    |--------------------------------------------------------------------------
    */

    private function getSchedules(
        AssessmentServiceModel $assessmentService
    ): Collection {
        return PaymentSchedule::query()
            ->where(
                'assessment_service_id',
                $assessmentService->id
            )
            ->orderBy(
                'installment_number'
            )
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE SCHEDULE
    |--------------------------------------------------------------------------
    */

    public function validateSchedule(
        AssessmentServiceModel $assessmentService
    ): void {
        Log::info(
            'Payment schedule validation started.',
            [
                'assessment_id' =>
                    $assessmentService->assessment_id,

                'assessment_service_id' =>
                    $assessmentService->id,
            ]
        );

        $assessmentService->loadMissing([
            'assessment',
            'service.revenueCode',
            'values.revenueServiceField.baseField',
        ]);

        $revenueCode =
            $this->resolveRevenueCode(
                $assessmentService
            );

        $paymentScheduleRule = $this->resolvePaymentScheduleRule($assessmentService);

        if (!$paymentScheduleRule) {
            Log::info(
                'Payment schedule validation skipped because revenue code does not require scheduling.',
                [
                    'assessment_service_id' =>
                        $assessmentService->id,

                    'revenue_code' =>
                        $revenueCode,
                ]
            );

            return;
        }

        if (!$assessmentService->assessment) {
            throw ValidationException::withMessages([
                'assessment_service' => [
                    'The assessment service does not have a valid assessment.',
                ],
            ]);
        }

        if (
            $assessmentService->assessment->status !==
            'APPROVED'
        ) {
            throw ValidationException::withMessages([
                'status' => [
                    'The assessment must be approved before its payment schedule is active.',
                ],
            ]);
        }

        if (!$assessmentService->isCompleted()) {
            throw ValidationException::withMessages([
                'assessment_service' => [
                    'The assessment service calculation must be completed before payment scheduling.',
                ],
            ]);
        }

        if (
            $this->normalizeMoney(
                $assessmentService->computed_amount
            ) <= 0
        ) {
            throw ValidationException::withMessages([
                'computed_amount' => [
                    'The computed amount must be greater than zero.',
                ],
            ]);
        }

        if (!$assessmentService->due_date) {
            throw ValidationException::withMessages([
                'due_date' => [
                    'The assessment service must have a resolved due date.',
                ],
            ]);
        }

        $this->resolvePaymentScheduleConfiguration(
            $assessmentService
        );

        Log::info(
            'Payment schedule validation completed successfully.',
            [
                'assessment_id' =>
                    $assessmentService->assessment_id,

                'assessment_service_id' =>
                    $assessmentService->id,

                'revenue_code' =>
                    $revenueCode,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE ASSESSMENT
    |--------------------------------------------------------------------------
    */

    private function validateAssessmentForScheduling(
        Assessment $assessment
    ): void {
        if ($assessment->status !== 'APPROVED') {
            Log::warning(
                'Assessment failed payment scheduling validation.',
                [
                    'assessment_id' =>
                        $assessment->id,

                    'assessment_status' =>
                        $assessment->status,

                    'required_status' =>
                        'APPROVED',
                ]
            );

            throw ValidationException::withMessages([
                'status' => [
                    'Payment schedules can only be created for approved assessments.',
                ],
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE ASSESSMENT SERVICE
    |--------------------------------------------------------------------------
    */

    private function validateAssessmentServiceForScheduling(
        AssessmentServiceModel $assessmentService
    ): void {
        $assessment =
            $assessmentService->assessment;

        if (!$assessment) {
            throw ValidationException::withMessages([
                'assessment_service' => [
                    'The assessment service is not associated with an assessment.',
                ],
            ]);
        }

        if ($assessment->status !== 'APPROVED') {
            throw ValidationException::withMessages([
                'status' => [
                    'Payment schedules can only be created after assessment approval.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Service identity must be valid before applicability is determined.
        |--------------------------------------------------------------------------
        */

        $revenueCode =
            $this->resolveRevenueCode(
                $assessmentService
            );

        $paymentScheduleRule = $this->resolvePaymentScheduleRule($assessmentService);

        if (!$paymentScheduleRule) {
            return;
        }

        if (!$assessmentService->isCompleted()) {
            throw ValidationException::withMessages([
                'assessment_service' => [
                    'A payment schedule cannot be created because the assessment service calculation is not completed.',
                ],
            ]);
        }

        $amount =
            $this->normalizeMoney(
                $assessmentService->computed_amount
            );

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'computed_amount' => [
                    'A payment schedule requires a positive computed amount.',
                ],
            ]);
        }

        if (!$assessmentService->due_date) {
            throw ValidationException::withMessages([
                'due_date' => [
                    'A payment schedule requires a resolved due date.',
                ],
            ]);
        }

        Log::info(
            'Assessment service passed payment scheduling validation.',
            [
                'assessment_id' =>
                    $assessment->id,

                'assessment_service_id' =>
                    $assessmentService->id,

                'revenue_code' =>
                    $revenueCode,

                'computed_amount' =>
                    $amount,

                'due_date' =>
                    $assessmentService->due_date->toDateString(),
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CANCEL SCHEDULE
    |--------------------------------------------------------------------------
    */

    public function cancelForAssessmentService(
        AssessmentServiceModel $assessmentService,
        ?string $reason = null
    ): int {
        $notes =
            $reason !== null &&
            trim($reason) !== ''
                ? trim($reason)
                : 'Payment schedule cancelled.';

        try {
            $updated =
                PaymentSchedule::query()
                    ->where(
                        'assessment_service_id',
                        $assessmentService->id
                    )
                    ->whereNotIn(
                        'status',
                        [
                            PaymentScheduleStatus::PAID->value,
                        ]
                    )
                    ->update([
                        'status' =>
                            PaymentScheduleStatus::CANCELLED->value,

                        'notes' =>
                            $notes,
                    ]);

            Log::info(
                'Payment schedules cancelled.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'cancelled_count' =>
                        $updated,

                    'reason_provided' =>
                        $reason !== null &&
                        trim($reason) !== '',
                ]
            );

            return $updated;
        } catch (Throwable $exception) {
            $this->logError(
                'Payment schedule cancellation failed.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,
                ],
                $exception
            );

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | REBUILD
    |--------------------------------------------------------------------------
    */

    public function rebuildForAssessmentService(
        AssessmentServiceModel $assessmentService
    ): Collection {
        Log::warning(
            'Payment schedule rebuild requested.',
            [
                'assessment_id' =>
                    $assessmentService->assessment_id,

                'assessment_service_id' =>
                    $assessmentService->id,
            ]
        );

        try {
            return DB::transaction(
                function () use ($assessmentService): Collection {
                    $assessmentService->loadMissing([
                        'assessment',
                        'service.revenueCode',
                        'values.revenueServiceField.baseField',
                    ]);

                    $revenueCode =
                        $this->resolveRevenueCode(
                            $assessmentService
                        );

                    if (
                        !$paymentScheduleRule
                    ) {
                        Log::info(
                            'Payment schedule rebuild skipped for non-scheduled revenue code.',
                            [
                                'assessment_id' =>
                                    $assessmentService->assessment_id,

                                'assessment_service_id' =>
                                    $assessmentService->id,

                                'revenue_code' =>
                                    $revenueCode,
                            ]
                        );

                        return new Collection();
                    }

                    $this->validateAssessmentServiceForScheduling(
                        $assessmentService
                    );

                    $existingSchedules =
                        $this->getSchedules(
                            $assessmentService
                        );

                    /*
                    |--------------------------------------------------------------------------
                    | Never rebuild after money has been recorded.
                    |--------------------------------------------------------------------------
                    */

                    $hasPayments =
                        $existingSchedules->contains(
                            function (
                                PaymentSchedule $schedule
                            ): bool {
                                return $this->normalizeMoney(
                                    $schedule->amount_paid
                                ) > 0;
                            }
                        );

                    if ($hasPayments) {
                        Log::warning(
                            'Payment schedule rebuild rejected because payments already exist.',
                            [
                                'assessment_id' =>
                                    $assessmentService->assessment_id,

                                'assessment_service_id' =>
                                    $assessmentService->id,

                                'revenue_code' =>
                                    $revenueCode,

                                'schedule_count' =>
                                    $existingSchedules->count(),
                            ]
                        );

                        throw ValidationException::withMessages([
                            'payment_schedule' => [
                                'A payment schedule with recorded payments cannot be rebuilt.',
                            ],
                        ]);
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Remove existing unpaid schedules.
                    |--------------------------------------------------------------------------
                    */

                    $deleted =
                        PaymentSchedule::query()
                            ->where(
                                'assessment_service_id',
                                $assessmentService->id
                            )
                            ->whereNotIn(
                                'status',
                                [
                                    PaymentScheduleStatus::PAID->value,
                                ]
                            )
                            ->delete();

                    Log::info(
                        'Existing unpaid payment schedules removed during rebuild.',
                        [
                            'assessment_id' =>
                                $assessmentService->assessment_id,

                            'assessment_service_id' =>
                                $assessmentService->id,

                            'deleted_count' =>
                                $deleted,
                        ]
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Re-create schedules using current configuration.
                    |--------------------------------------------------------------------------
                    */

                    $schedules =
                        $this->createForAssessmentServiceInternal(
                            $assessmentService
                        );

                    Log::info(
                        'Payment schedule rebuild completed.',
                        [
                            'assessment_id' =>
                                $assessmentService->assessment_id,

                            'assessment_service_id' =>
                                $assessmentService->id,

                            'new_schedule_count' =>
                                $schedules->count(),

                            'new_schedule_ids' =>
                                $schedules
                                    ->pluck('id')
                                    ->values()
                                    ->all(),
                        ]
                    );

                    return $schedules;
                }
            );
        } catch (Throwable $exception) {
            $this->logError(
                'Payment schedule rebuild failed.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,
                ],
                $exception
            );

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CALCULATE PERCENTAGE AMOUNT
    |--------------------------------------------------------------------------
    */

    private function calculatePercentageAmount(
        float $principal,
        float $percentage
    ): float {
        $amount =
            $this->normalizeMoney(
                $principal *
                ($percentage / 100)
            );

        Log::info(
            'LIZZ percentage amount calculated.',
            [
                'principal_amount' =>
                    $principal,

                'percentage' =>
                    $percentage,

                'calculated_amount' =>
                    $amount,
            ]
        );

        return $amount;
    }

    /*
    |--------------------------------------------------------------------------
    | FIRST INSTALLMENT NOTES
    |--------------------------------------------------------------------------
    */

    private function buildFirstInstallmentNotes(
        float $percentage,
        float $principal,
        float $remainingBalance
    ): string {
        return sprintf(
            'LIZZ first installment: %.2f%% of principal %.4f. Remaining balance: %.4f.',
            $percentage,
            $principal,
            $remainingBalance
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ANNUAL INSTALLMENT NOTES
    |--------------------------------------------------------------------------
    */

    private function buildAnnualInstallmentNotes(
        int $year,
        int $completionYears,
        float $principal,
        float $firstInstallmentAmount,
        float $remainingBalance,
        float $amountDue
    ): string {
        return sprintf(
            'LIZZ annual installment %d of %d. Principal: %.4f. Initial installment: %.4f. Remaining balance: %.4f. Annual amount: %.4f.',
            $year,
            $completionYears,
            $principal,
            $firstInstallmentAmount,
            $remainingBalance,
            $amountDue
        );
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE MONEY
    |--------------------------------------------------------------------------
    */

    private function normalizeMoney(
        mixed $amount
    ): float {
        if (
            $amount === null ||
            $amount === ''
        ) {
            return 0.0;
        }

        if (!is_numeric($amount)) {
            return 0.0;
        }

        $normalized =
            (float) $amount;

        if (!is_finite($normalized)) {
            return 0.0;
        }

        return round(
            $normalized,
            4
        );
    }

    /*
    |--------------------------------------------------------------------------
    | LOG ERROR
    |--------------------------------------------------------------------------
    */

    private function logError(
        string $message,
        array $context = [],
        ?Throwable $exception = null
    ): void {
        if ($exception) {
            $context =
                array_merge(
                    $context,
                    [
                        'exception_class' =>
                            $exception::class,

                        'exception_message' =>
                            $exception->getMessage(),

                        'exception_code' =>
                            $exception->getCode(),

                        'exception_file' =>
                            $exception->getFile(),

                        'exception_line' =>
                            $exception->getLine(),
                    ]
                );
        }

        Log::error(
            $message,
            $context
        );
    }
}