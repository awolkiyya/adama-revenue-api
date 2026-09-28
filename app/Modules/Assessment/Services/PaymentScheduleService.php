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
    | LIZZ CONFIGURATION
    |--------------------------------------------------------------------------
    |
    | Payment schedules are applicable to revenue codes that have an active
    | RevenueCodePaymentScheduleRule.
    |
    | NEW LIZZ
    |
    |     computed_amount
    |         ↓
    |     optional first installment
    |         ↓
    |     annual installments
    |
    | EXISTING LIZZ
    |
    |     computed_amount
    |         = original / historical obligation
    |
    |     paid_amount
    |         = amount already paid historically
    |
    |     remaining_amount
    |         = historical outstanding balance
    |
    |     PAYMENT_COMPLETION_YEARS
    |         = ORIGINAL / TOTAL CONTRACTUAL TERM
    |
    |     remaining_payment_years
    |         = original term - elapsed contractual years
    |
    |     scheduling_principal
    |         = remaining_amount
    |
    | IMPORTANT:
    |
    | PAYMENT_COMPLETION_YEARS must NOT be overwritten or interpreted as the
    | remaining term for Existing LIZZ.
    |
    | Example:
    |
    |     agreement_date       = 2017-09-11
    |     balance_as_of_date   = 2022-09-11
    |     original term        = 60 years
    |     elapsed years        = 5
    |     remaining years      = 55
    |
    | Therefore:
    |
    |     PAYMENT_COMPLETION_YEARS = 60
    |     remaining_payment_years  = 55
    |
    |--------------------------------------------------------------------------
    | FIRST INSTALLMENT RULE
    |--------------------------------------------------------------------------
    |
    | The current revenue-code payment schedule rule contains:
    |
    |     first_installment_percentage
    |
    | This rule applies only when:
    |
    |     1. schedule type is NEW_LIZZ
    |     2. FIRST_INSTALLMENT_REQUIRED is true
    |
    | When the rule is actually applied, the generated first schedule stores:
    |
    |     installment_number = 1
    |     rule_percentage    = configured percentage
    |     amount_due         = calculated first installment amount
    |
    | Example:
    |
    |     principal = 1,900,000
    |     percentage = 10%
    |
    |     installment #1:
    |         amount_due      = 190,000
    |         rule_percentage = 10.00
    |
    |     remaining installments:
    |         rule_percentage = NULL
    |
    | EXISTING LIZZ:
    |
    |     installment #1
    |         rule_percentage = NULL
    |
    | because installment #1 only represents its position in the schedule.
    |
    | rule_percentage is a historical snapshot. It must not be recalculated
    | later from the current RevenueCodePaymentScheduleRule.
    |
    */

    private const FIRST_INSTALLMENT_REQUIRED_FIELD =
        'FIRST_INSTALLMENT_REQUIRED';

    private const PAYMENT_COMPLETION_YEARS_FIELD =
        'PAYMENT_COMPLETION_YEARS';

    /*
    |--------------------------------------------------------------------------
    | EXISTING LIZZ AGREEMENT FIELD
    |--------------------------------------------------------------------------
    */

    private const LIZZ_AGREEMENT_DATE_FIELD =
        'AGREEMENT_DATE';

    /*
    |--------------------------------------------------------------------------
    | SCHEDULE TYPES
    |--------------------------------------------------------------------------
    */

    private const SCHEDULE_TYPE_NEW_LIZZ =
        'NEW_LIZZ';

    private const SCHEDULE_TYPE_EXISTING_LIZZ =
        'EXISTING_LIZZ';

    /*
    |--------------------------------------------------------------------------
    | CREATE FOR ASSESSMENT
    |--------------------------------------------------------------------------
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
                        'services.service.revenueCode.paymentScheduleRule',
                        'services.values.revenueServiceField.baseField',
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

                    $schedules =
                        new Collection();

                    foreach (
                        $assessment->services
                        as $assessmentService
                    ) {
                        $revenueCode =
                            $this->resolveRevenueCode(
                                $assessmentService
                            );

                        $paymentScheduleRule =
                            $this->resolvePaymentScheduleRule(
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

                                'payment_schedule_enabled' =>
                                    $paymentScheduleRule !== null,
                            ]
                        );

                        if (! $paymentScheduleRule) {
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

                            'first_schedule_id' =>
                                $schedules->first()?->id,

                            'last_schedule_id' =>
                                $schedules->last()?->id,
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
                    $lockedAssessmentService =
                        AssessmentServiceModel::query()
                            ->whereKey($assessmentService->id)
                            ->lockForUpdate()
                            ->first();

                    if (! $lockedAssessmentService) {
                        throw ValidationException::withMessages([
                            'assessment_service' => [
                                'The assessment service could not be found.',
                            ],
                        ]);
                    }

                    return $this->createForAssessmentServiceInternal(
                        $lockedAssessmentService
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
            'service.revenueCode.paymentScheduleRule',
            'values.revenueServiceField.baseField',
        ]);

        $revenueCode =
            $this->resolveRevenueCode(
                $assessmentService
            );

        /*
        |--------------------------------------------------------------------------
        | APPLICABILITY
        |--------------------------------------------------------------------------
        */

        $paymentScheduleRule =
            $this->resolvePaymentScheduleRule(
                $assessmentService
            );

        if (! $paymentScheduleRule) {
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
        | VALIDATE
        |--------------------------------------------------------------------------
        */

        $this->validateAssessmentServiceForScheduling(
            $assessmentService
        );

        /*
        |--------------------------------------------------------------------------
        | IDEMPOTENCY
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

                    'first_schedule_id' =>
                        $existingSchedules->first()?->id,

                    'last_schedule_id' =>
                        $existingSchedules->last()?->id,
                ]
            );

            return $existingSchedules;
        }

        /*
        |--------------------------------------------------------------------------
        | RESOLVE CONFIGURATION
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

                'schedule_type' =>
                    $configuration['schedule_type'],

                'principal_amount' =>
                    $configuration['principal_amount'],

                'computed_amount' =>
                    $configuration['computed_amount'],

                'paid_amount' =>
                    $configuration['paid_amount'],

                'remaining_amount' =>
                    $configuration['remaining_amount'],

                'agreement_date' =>
                    $configuration['agreement_date']
                        ?->toDateString(),

                'balance_as_of_date' =>
                    $configuration['balance_as_of_date'],

                'base_due_date' =>
                    $configuration['base_due_date']
                        ->toDateString(),

                'first_installment_required' =>
                    $configuration['first_installment_required'],

                'first_installment_percentage' =>
                    $configuration['first_installment_percentage'],

                'payment_completion_years' =>
                    $configuration['payment_completion_years'],

                'remaining_payment_years' =>
                    $configuration['remaining_payment_years'],

                'elapsed_payment_years' =>
                    $configuration['elapsed_payment_years'],
            ]
        );

        return $this->buildPaymentSchedule(
            $assessmentService,
            $configuration
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE PAYMENT SCHEDULE CONFIGURATION
    |--------------------------------------------------------------------------
    */

    private function resolvePaymentScheduleConfiguration(
        AssessmentServiceModel $assessmentService
    ): array {
        $rule =
            $this->resolvePaymentScheduleRule(
                $assessmentService
            );

        if (! $rule) {
            throw ValidationException::withMessages([
                'payment_schedule' => [
                    'This revenue code does not have an active payment schedule rule.',
                ],
            ]);
        }

        $computedAmount =
            $this->normalizeMoney(
                $assessmentService->computed_amount
            );

        if ($computedAmount <= 0) {
            throw ValidationException::withMessages([
                'computed_amount' => [
                    'The LIZZ computed amount must be greater than zero.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Existing vs New LIZZ
        |--------------------------------------------------------------------------
        */

        $isExistingLizz =
            $assessmentService->remaining_amount !== null;

        if ($isExistingLizz) {
            return $this->resolveExistingLizzConfiguration(
                $assessmentService,
                $rule,
                $computedAmount
            );
        }

        return $this->resolveNewLizzConfiguration(
            $assessmentService,
            $rule,
            $computedAmount
        );
    }

    /*
    |--------------------------------------------------------------------------
    | NEW LIZZ CONFIGURATION
    |--------------------------------------------------------------------------
    */

    private function resolveNewLizzConfiguration(
        AssessmentServiceModel $assessmentService,
        RevenueCodePaymentScheduleRule $rule,
        float $computedAmount
    ): array {
        $firstInstallmentRequired =
            $this->resolveBooleanField(
                $assessmentService,
                self::FIRST_INSTALLMENT_REQUIRED_FIELD
            );

        $paymentCompletionYears =
            $this->resolvePositiveIntegerField(
                $assessmentService,
                self::PAYMENT_COMPLETION_YEARS_FIELD
            );

        $percentage =
            $this->resolveFirstInstallmentPercentage(
                $rule,
                $firstInstallmentRequired
            );

        if (! $assessmentService->due_date) {
            throw ValidationException::withMessages([
                'due_date' => [
                    'The new LIZZ assessment service must have a resolved due date before payment schedules can be created.',
                ],
            ]);
        }

        $baseDueDate =
            Carbon::parse(
                $assessmentService->due_date
            )->startOfDay();

        Log::info(
            'New LIZZ payment schedule configuration resolved.',
            [
                'assessment_id' =>
                    $assessmentService->assessment_id,

                'assessment_service_id' =>
                    $assessmentService->id,

                'computed_amount' =>
                    $computedAmount,

                'first_installment_required' =>
                    $firstInstallmentRequired,

                'first_installment_percentage' =>
                    $percentage,

                'payment_completion_years' =>
                    $paymentCompletionYears,

                'elapsed_payment_years' =>
                    0,

                'remaining_payment_years' =>
                    $paymentCompletionYears,

                'base_due_date' =>
                    $baseDueDate->toDateString(),
            ]
        );

        return [
            'schedule_type' =>
                self::SCHEDULE_TYPE_NEW_LIZZ,

            'principal_amount' =>
                $computedAmount,

            'computed_amount' =>
                $computedAmount,

            'paid_amount' =>
                $this->normalizeMoney(
                    $assessmentService->paid_amount
                ),

            'remaining_amount' =>
                null,

            'balance_as_of_date' =>
                null,

            'agreement_date' =>
                null,

            'base_due_date' =>
                $baseDueDate,

            'first_installment_required' =>
                $firstInstallmentRequired,

            /*
            |--------------------------------------------------------------------------
            | This is configuration.
            |
            | It will be copied to payment_schedules.rule_percentage only
            | when the rule is actually applied.
            |--------------------------------------------------------------------------
            */

            'first_installment_percentage' =>
                $percentage,

            'payment_completion_years' =>
                $paymentCompletionYears,

            'remaining_payment_years' =>
                $paymentCompletionYears,

            'elapsed_payment_years' =>
                0,

            /*
            |--------------------------------------------------------------------------
            | Configuration reference only.
            |
            | Not persisted into payment_schedules.
            |--------------------------------------------------------------------------
            */

            'payment_schedule_rule_id' =>
                $rule->id,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | EXISTING LIZZ CONFIGURATION
    |--------------------------------------------------------------------------
    */

    private function resolveExistingLizzConfiguration(
        AssessmentServiceModel $assessmentService,
        RevenueCodePaymentScheduleRule $rule,
        float $computedAmount
    ): array {
        $paidAmount =
            $this->normalizeMoney(
                $assessmentService->paid_amount
            );

        $remainingAmount =
            $this->normalizeMoney(
                $assessmentService->remaining_amount
            );

        if ($paidAmount < 0) {
            throw ValidationException::withMessages([
                'paid_amount' => [
                    'The historical paid amount cannot be negative.',
                ],
            ]);
        }

        if ($remainingAmount < 0) {
            throw ValidationException::withMessages([
                'remaining_amount' => [
                    'The historical remaining amount cannot be negative.',
                ],
            ]);
        }

        if (! $assessmentService->balance_as_of_date) {
            throw ValidationException::withMessages([
                'balance_as_of_date' => [
                    'The balance-as-of date is required for an existing LIZZ agreement.',
                ],
            ]);
        }

        $balanceAsOfDate =
            Carbon::parse(
                $assessmentService->balance_as_of_date
            )->startOfDay();

        /*
        |--------------------------------------------------------------------------
        | Historical reconciliation
        |--------------------------------------------------------------------------
        */

        $historicalTotal =
            $this->normalizeMoney(
                $paidAmount +
                $remainingAmount
            );

        if ($historicalTotal > $computedAmount) {
            Log::error(
                'Existing LIZZ historical balance exceeds original computed amount.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'computed_amount' =>
                        $computedAmount,

                    'paid_amount' =>
                        $paidAmount,

                    'remaining_amount' =>
                        $remainingAmount,

                    'historical_total' =>
                        $historicalTotal,

                    'balance_as_of_date' =>
                        $balanceAsOfDate->toDateString(),
                ]
            );

            throw ValidationException::withMessages([
                'remaining_amount' => [
                    'The existing LIZZ paid amount plus remaining amount cannot exceed the original computed amount.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | RESOLVE AGREEMENT DATE
        |--------------------------------------------------------------------------
        */

        $agreementDate =
            $this->resolveLizzAgreementDate(
                $assessmentService
            );

        if ($agreementDate->gt($balanceAsOfDate)) {
            throw ValidationException::withMessages([
                'balance_as_of_date' => [
                    'The balance-as-of date cannot be earlier than the LIZZ agreement date.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | ORIGINAL / TOTAL CONTRACTUAL TERM
        |--------------------------------------------------------------------------
        */

        $paymentCompletionYears =
            $this->resolvePositiveIntegerField(
                $assessmentService,
                self::PAYMENT_COMPLETION_YEARS_FIELD
            );

        /*
        |--------------------------------------------------------------------------
        | ELAPSED CONTRACTUAL YEARS
        |--------------------------------------------------------------------------
        */

        $elapsedPaymentYears =
            $this->calculateElapsedPaymentYears(
                $agreementDate,
                $balanceAsOfDate
            );

        /*
        |--------------------------------------------------------------------------
        | REMAINING FUTURE PAYMENT YEARS
        |--------------------------------------------------------------------------
        */

        $remainingPaymentYears =
            $this->calculateRemainingPaymentYears(
                $paymentCompletionYears,
                $elapsedPaymentYears
            );

        /*
        |--------------------------------------------------------------------------
        | CONTRACTUAL TERM COMPLETED
        |--------------------------------------------------------------------------
        */

        if ($remainingPaymentYears <= 0) {
            Log::info(
                'Existing LIZZ contractual term has fully elapsed.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'agreement_date' =>
                        $agreementDate->toDateString(),

                    'balance_as_of_date' =>
                        $balanceAsOfDate->toDateString(),

                    'payment_completion_years' =>
                        $paymentCompletionYears,

                    'elapsed_payment_years' =>
                        $elapsedPaymentYears,

                    'remaining_payment_years' =>
                        $remainingPaymentYears,

                    'remaining_amount' =>
                        $remainingAmount,
                ]
            );

            if ($remainingAmount > 0) {
                throw ValidationException::withMessages([
                    'payment_schedule' => [
                        'The Existing LIZZ agreement has reached the end of its contractual payment term while an outstanding balance still remains. The balance requires a separate authorized resolution before a new payment schedule can be created.',
                    ],
                ]);
            }

            return [
                'schedule_type' =>
                    self::SCHEDULE_TYPE_EXISTING_LIZZ,

                'principal_amount' =>
                    0.0,

                'computed_amount' =>
                    $computedAmount,

                'paid_amount' =>
                    $paidAmount,

                'remaining_amount' =>
                    $remainingAmount,

                'balance_as_of_date' =>
                    $balanceAsOfDate->toDateString(),

                'agreement_date' =>
                    $agreementDate,

                'base_due_date' =>
                    $balanceAsOfDate,

                /*
                |--------------------------------------------------------------------------
                | Existing LIZZ never applies the new first-installment rule.
                |--------------------------------------------------------------------------
                */

                'first_installment_required' =>
                    false,

                'first_installment_percentage' =>
                    0.0,

                'payment_completion_years' =>
                    $paymentCompletionYears,

                'elapsed_payment_years' =>
                    $elapsedPaymentYears,

                'remaining_payment_years' =>
                    0,

                'payment_schedule_rule_id' =>
                    $rule->id,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | RESOLVE NEXT APPLICABLE ANNIVERSARY
        |--------------------------------------------------------------------------
        */

        $baseDueDate =
            $this->resolveExistingLizzBaseDueDate(
                $agreementDate,
                $balanceAsOfDate
            );

        Log::info(
            'Existing LIZZ payment schedule configuration resolved.',
            [
                'assessment_id' =>
                    $assessmentService->assessment_id,

                'assessment_service_id' =>
                    $assessmentService->id,

                'computed_amount' =>
                    $computedAmount,

                'historical_paid_amount' =>
                    $paidAmount,

                'historical_remaining_amount' =>
                    $remainingAmount,

                'agreement_date' =>
                    $agreementDate->toDateString(),

                'balance_as_of_date' =>
                    $balanceAsOfDate->toDateString(),

                'payment_completion_years' =>
                    $paymentCompletionYears,

                'elapsed_payment_years' =>
                    $elapsedPaymentYears,

                'remaining_payment_years' =>
                    $remainingPaymentYears,

                'resolved_base_due_date' =>
                    $baseDueDate->toDateString(),

                'first_installment_applied' =>
                    false,

                'first_installment_percentage' =>
                    0.0,
            ]
        );

        return [
            'schedule_type' =>
                self::SCHEDULE_TYPE_EXISTING_LIZZ,

            /*
            |--------------------------------------------------------------------------
            | Existing LIZZ schedules ONLY historical outstanding balance.
            |--------------------------------------------------------------------------
            */

            'principal_amount' =>
                $remainingAmount,

            'computed_amount' =>
                $computedAmount,

            'paid_amount' =>
                $paidAmount,

            'remaining_amount' =>
                $remainingAmount,

            'balance_as_of_date' =>
                $balanceAsOfDate->toDateString(),

            'agreement_date' =>
                $agreementDate,

            'base_due_date' =>
                $baseDueDate,

            /*
            |--------------------------------------------------------------------------
            | Existing LIZZ never receives a new first installment rule.
            |--------------------------------------------------------------------------
            */

            'first_installment_required' =>
                false,

            'first_installment_percentage' =>
                0.0,

            /*
            |--------------------------------------------------------------------------
            | Original contractual value.
            |--------------------------------------------------------------------------
            */

            'payment_completion_years' =>
                $paymentCompletionYears,

            /*
            |--------------------------------------------------------------------------
            | Derived actual future schedule period.
            |--------------------------------------------------------------------------
            */

            'elapsed_payment_years' =>
                $elapsedPaymentYears,

            'remaining_payment_years' =>
                $remainingPaymentYears,

            'payment_schedule_rule_id' =>
                $rule->id,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | CALCULATE ELAPSED PAYMENT YEARS
    |--------------------------------------------------------------------------
    */

    private function calculateElapsedPaymentYears(
        Carbon $agreementDate,
        Carbon $balanceAsOfDate
    ): int {
        $agreementDate =
            $agreementDate
                ->copy()
                ->startOfDay();

        $balanceAsOfDate =
            $balanceAsOfDate
                ->copy()
                ->startOfDay();

        if ($balanceAsOfDate->lt($agreementDate)) {
            throw ValidationException::withMessages([
                'balance_as_of_date' => [
                    'The balance-as-of date cannot be earlier than the agreement date.',
                ],
            ]);
        }

        return (int) $agreementDate->diffInYears(
            $balanceAsOfDate
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CALCULATE REMAINING PAYMENT YEARS
    |--------------------------------------------------------------------------
    */

    private function calculateRemainingPaymentYears(
        int $originalPaymentCompletionYears,
        int $elapsedPaymentYears
    ): int {
        if ($originalPaymentCompletionYears <= 0) {
            throw ValidationException::withMessages([
                self::PAYMENT_COMPLETION_YEARS_FIELD => [
                    'The original LIZZ payment completion period must be greater than zero.',
                ],
            ]);
        }

        if ($elapsedPaymentYears < 0) {
            throw ValidationException::withMessages([
                'payment_schedule' => [
                    'Elapsed LIZZ payment years cannot be negative.',
                ],
            ]);
        }

        return max(
            0,
            $originalPaymentCompletionYears -
            $elapsedPaymentYears
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE EXISTING LIZZ AGREEMENT DATE
    |--------------------------------------------------------------------------
    */

    private function resolveLizzAgreementDate(
        AssessmentServiceModel $assessmentService
    ): Carbon {
        $value =
            $this->resolveFieldValue(
                $assessmentService,
                self::LIZZ_AGREEMENT_DATE_FIELD
            );

        if (
            $value === null ||
            trim((string) $value) === ''
        ) {
            Log::error(
                'Existing LIZZ agreement date is missing.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'field_code' =>
                        self::LIZZ_AGREEMENT_DATE_FIELD,
                ]
            );

            throw ValidationException::withMessages([
                self::LIZZ_AGREEMENT_DATE_FIELD => [
                    'The LIZZ agreement date is required for an existing LIZZ payment schedule.',
                ],
            ]);
        }

        try {
            return Carbon::parse(
                (string) $value
            )->startOfDay();
        } catch (Throwable $exception) {
            Log::error(
                'Existing LIZZ agreement date could not be parsed.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'field_code' =>
                        self::LIZZ_AGREEMENT_DATE_FIELD,

                    'value' =>
                        $value,

                    'exception_class' =>
                        $exception::class,

                    'exception_message' =>
                        $exception->getMessage(),
                ]
            );

            throw ValidationException::withMessages([
                self::LIZZ_AGREEMENT_DATE_FIELD => [
                    'The LIZZ agreement date must be a valid date.',
                ],
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE EXISTING LIZZ BASE DUE DATE
    |--------------------------------------------------------------------------
    */

    private function resolveExistingLizzBaseDueDate(
        Carbon $agreementDate,
        Carbon $balanceAsOfDate
    ): Carbon {
        $agreementDate =
            $agreementDate
                ->copy()
                ->startOfDay();

        $balanceAsOfDate =
            $balanceAsOfDate
                ->copy()
                ->startOfDay();

        if ($balanceAsOfDate->lt($agreementDate)) {
            throw ValidationException::withMessages([
                'balance_as_of_date' => [
                    'The balance-as-of date cannot be earlier than the agreement date.',
                ],
            ]);
        }

        $elapsedYears =
            $agreementDate->diffInYears(
                $balanceAsOfDate
            );

        $candidate =
            $agreementDate
                ->copy()
                ->addYears($elapsedYears);

        /*
        |--------------------------------------------------------------------------
        | If the anniversary has already passed the balance cutoff,
        | move to the next anniversary.
        |--------------------------------------------------------------------------
        */

        if ($candidate->lt($balanceAsOfDate)) {
            $candidate->addYear();
        }

        if ($candidate->lt($balanceAsOfDate)) {
            throw ValidationException::withMessages([
                'due_date' => [
                    'The next applicable Existing LIZZ payment due date could not be resolved.',
                ],
            ]);
        }

        return $candidate;
    }

    /*
    |--------------------------------------------------------------------------
    | BUILD PAYMENT SCHEDULE
    |--------------------------------------------------------------------------
    */

    private function buildPaymentSchedule(
        AssessmentServiceModel $assessmentService,
        array $configuration
    ): Collection {
        $scheduleType =
            $configuration['schedule_type'];

        $principal =
            $this->normalizeMoney(
                $configuration['principal_amount']
            );

        $firstInstallmentRequired =
            (bool) $configuration['first_installment_required'];

        $firstInstallmentPercentage =
            (float) $configuration['first_installment_percentage'];

        $originalCompletionYears =
            (int) $configuration['payment_completion_years'];

        $remainingPaymentYears =
            (int) $configuration['remaining_payment_years'];

        $baseDueDate =
            $configuration['base_due_date'];

        if (! $baseDueDate instanceof Carbon) {
            throw ValidationException::withMessages([
                'due_date' => [
                    'The LIZZ payment schedule base due date could not be resolved.',
                ],
            ]);
        }

        if ($principal < 0) {
            throw ValidationException::withMessages([
                'payment_schedule' => [
                    'The LIZZ payment schedule principal cannot be negative.',
                ],
            ]);
        }

        if ($originalCompletionYears <= 0) {
            throw ValidationException::withMessages([
                self::PAYMENT_COMPLETION_YEARS_FIELD => [
                    'The original LIZZ payment completion period must be greater than zero.',
                ],
            ]);
        }

        if ($remainingPaymentYears < 0) {
            throw ValidationException::withMessages([
                'payment_schedule' => [
                    'The remaining LIZZ payment period cannot be negative.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | EXISTING LIZZ WITH NO REMAINING BALANCE
        |--------------------------------------------------------------------------
        */

        if (
            $scheduleType ===
            self::SCHEDULE_TYPE_EXISTING_LIZZ &&
            $principal <= 0
        ) {
            Log::info(
                'Existing LIZZ has no remaining balance. No payment schedules created.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'computed_amount' =>
                        $configuration['computed_amount'],

                    'paid_amount' =>
                        $configuration['paid_amount'],

                    'remaining_amount' =>
                        $configuration['remaining_amount'],

                    'agreement_date' =>
                        $configuration['agreement_date']
                            ?->toDateString(),

                    'balance_as_of_date' =>
                        $configuration['balance_as_of_date'],

                    'payment_completion_years' =>
                        $originalCompletionYears,

                    'elapsed_payment_years' =>
                        $configuration['elapsed_payment_years'],

                    'remaining_payment_years' =>
                        $remainingPaymentYears,
                ]
            );

            return new Collection();
        }

        if ($principal <= 0) {
            throw ValidationException::withMessages([
                'payment_schedule' => [
                    'The LIZZ payment schedule principal amount must be greater than zero.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | EXISTING LIZZ MUST NEVER RECEIVE FIRST-INSTALLMENT RULE
        |--------------------------------------------------------------------------
        */

        if (
            $scheduleType ===
            self::SCHEDULE_TYPE_EXISTING_LIZZ
        ) {
            $firstInstallmentRequired = false;
            $firstInstallmentPercentage = 0.0;
        }

        Log::info(
            'Building LIZZ payment schedule.',
            [
                'assessment_id' =>
                    $assessmentService->assessment_id,

                'assessment_service_id' =>
                    $assessmentService->id,

                'schedule_type' =>
                    $scheduleType,

                'principal_amount' =>
                    $principal,

                'computed_amount' =>
                    $configuration['computed_amount'],

                'paid_amount' =>
                    $configuration['paid_amount'],

                'remaining_amount' =>
                    $configuration['remaining_amount'],

                'agreement_date' =>
                    $configuration['agreement_date']
                        ?->toDateString(),

                'balance_as_of_date' =>
                    $configuration['balance_as_of_date'],

                'base_due_date' =>
                    $baseDueDate->toDateString(),

                'payment_completion_years' =>
                    $originalCompletionYears,

                'elapsed_payment_years' =>
                    $configuration['elapsed_payment_years'],

                'remaining_payment_years' =>
                    $remainingPaymentYears,

                'first_installment_required' =>
                    $firstInstallmentRequired,

                'first_installment_percentage' =>
                    $firstInstallmentPercentage,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | FIRST INSTALLMENT
        |--------------------------------------------------------------------------
        */

        $firstInstallmentAmount = 0.0;

        if (
            $scheduleType ===
            self::SCHEDULE_TYPE_NEW_LIZZ &&
            $firstInstallmentRequired
        ) {
            $firstInstallmentAmount =
                $this->calculatePercentageAmount(
                    $principal,
                    $firstInstallmentPercentage
                );

            if ($firstInstallmentAmount <= 0) {
                throw ValidationException::withMessages([
                    'first_installment_percentage' => [
                        'The LIZZ first installment amount must be greater than zero when the first installment is required.',
                    ],
                ]);
            }

            if ($firstInstallmentAmount >= $principal) {
                $firstInstallmentAmount =
                    $principal;
            }

            Log::info(
                'New LIZZ first installment calculated.',
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
        }

        /*
        |--------------------------------------------------------------------------
        | REMAINING BALANCE
        |--------------------------------------------------------------------------
        */

        $remainingBalance =
            $this->normalizeMoney(
                $principal -
                $firstInstallmentAmount
            );

        /*
        |--------------------------------------------------------------------------
        | CREATE SCHEDULES
        |--------------------------------------------------------------------------
        */

        $schedules =
            new Collection();

        $installmentNumber = 1;

        /*
        |--------------------------------------------------------------------------
        | NEW LIZZ FIRST INSTALLMENT
        |--------------------------------------------------------------------------
        |
        | This is the ONLY place where rule_percentage is persisted.
        |
        | The persisted value is a historical snapshot of the rule that
        | actually produced this schedule amount.
        |--------------------------------------------------------------------------
        */

        if (
            $scheduleType ===
            self::SCHEDULE_TYPE_NEW_LIZZ &&
            $firstInstallmentRequired
        ) {
            $firstSchedule =
                PaymentSchedule::query()->create([
                    'assessment_service_id' =>
                        $assessmentService->id,

                    'installment_number' =>
                        $installmentNumber,

                    /*
                    |--------------------------------------------------------------------------
                    | RULE SNAPSHOT
                    |--------------------------------------------------------------------------
                    |
                    | Example:
                    |
                    |     first_installment_percentage = 10.00
                    |
                    |     rule_percentage = 10.00
                    |
                    | This value is historical metadata.
                    |--------------------------------------------------------------------------
                    */

                    'rule_percentage' =>
                        $firstInstallmentPercentage,

                    'due_date' =>
                        $baseDueDate,

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
                'New LIZZ first payment schedule created with rule snapshot.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'payment_schedule_id' =>
                        $firstSchedule->id,

                    'installment_number' =>
                        $installmentNumber,

                    'rule_percentage' =>
                        $firstInstallmentPercentage,

                    'amount_due' =>
                        $firstInstallmentAmount,

                    'due_date' =>
                        $baseDueDate->toDateString(),

                    'status' =>
                        PaymentScheduleStatus::PENDING->value,
                ]
            );

            $installmentNumber++;
        }

        /*
        |--------------------------------------------------------------------------
        | NOTHING REMAINING
        |--------------------------------------------------------------------------
        */

        if ($remainingBalance <= 0) {
            $this->assertScheduleTotal(
                $schedules,
                $principal,
                $assessmentService,
                $scheduleType
            );

            return $schedules;
        }

        /*
        |--------------------------------------------------------------------------
        | DETERMINE ANNUAL INSTALLMENTS
        |--------------------------------------------------------------------------
        |
        | NEW LIZZ:
        |
        |     original term = future term
        |
        | EXISTING LIZZ:
        |
        |     original term = contractual total
        |     remaining term = original term - elapsed term
        |--------------------------------------------------------------------------
        */

        if ($remainingPaymentYears <= 0) {
            throw ValidationException::withMessages([
                'payment_schedule' => [
                    'The LIZZ payment schedule has no remaining payment years.',
                ],
            ]);
        }

        $annualAmount =
            $this->normalizeMoney(
                $remainingBalance /
                $remainingPaymentYears
            );

        if ($annualAmount <= 0) {
            throw ValidationException::withMessages([
                'payment_schedule' => [
                    'The calculated annual LIZZ payment amount must be greater than zero.',
                ],
            ]);
        }

        $scheduledRemainingTotal = 0.0;

        /*
        |--------------------------------------------------------------------------
        | ANNUAL SCHEDULES
        |--------------------------------------------------------------------------
        */

        for (
            $year = 1;
            $year <= $remainingPaymentYears;
            $year++
        ) {
            /*
            |--------------------------------------------------------------------------
            | EXISTING LIZZ
            |--------------------------------------------------------------------------
            |
            | The first annual schedule is due on the resolved base date.
            |
            | Example:
            |
            |     agreement      = 2017-09-11
            |     balance cutoff = 2022-09-11
            |     base due date  = 2022-09-11
            |
            |     #1 = 2022-09-11
            |     #2 = 2023-09-11
            |     ...
            |--------------------------------------------------------------------------
            */

            $dueDate =
                $baseDueDate
                    ->copy()
                    ->addYears(
                        $year - 1
                    );

            /*
            |--------------------------------------------------------------------------
            | NEW LIZZ WITH FIRST INSTALLMENT
            |--------------------------------------------------------------------------
            |
            | The first percentage installment is already due at base date.
            |
            | Therefore annual installment #1 starts one year later.
            |--------------------------------------------------------------------------
            */

            if (
                $scheduleType ===
                self::SCHEDULE_TYPE_NEW_LIZZ &&
                $firstInstallmentRequired
            ) {
                $dueDate =
                    $baseDueDate
                        ->copy()
                        ->addYears(
                            $year
                        );
            }

            /*
            |--------------------------------------------------------------------------
            | FINAL INSTALLMENT ABSORBS ROUNDING DIFFERENCE
            |--------------------------------------------------------------------------
            */

            if ($year === $remainingPaymentYears) {
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

                        'schedule_type' =>
                            $scheduleType,

                        'annual_installment_number' =>
                            $year,

                        'installment_number' =>
                            $installmentNumber,

                        'remaining_payment_years' =>
                            $remainingPaymentYears,

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
                            'The calculated LIZZ payment amount for installment %d must be greater than zero.',
                            $year
                        ),
                    ],
                ]);
            }

            $schedule =
                PaymentSchedule::query()->create([
                    'assessment_service_id' =>
                        $assessmentService->id,

                    'installment_number' =>
                        $installmentNumber,

                    /*
                    |--------------------------------------------------------------------------
                    | IMPORTANT:
                    |
                    | Annual installments do NOT receive the first-installment
                    | percentage snapshot.
                    |
                    | NULL means no percentage rule was applied to this row.
                    |
                    | This is also correct for Existing LIZZ installment #1.
                    |--------------------------------------------------------------------------
                    */

                    'rule_percentage' =>
                        null,

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
                            $scheduleType,
                            $year,
                            $remainingPaymentYears,
                            $originalCompletionYears,
                            $configuration['elapsed_payment_years'],
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

            /*
            |--------------------------------------------------------------------------
            | PRODUCTION-SAFE LOGGING
            |--------------------------------------------------------------------------
            */

            if (
                $year === 1 ||
                $year === $remainingPaymentYears
            ) {
                Log::info(
                    'LIZZ annual payment schedule created.',
                    [
                        'assessment_id' =>
                            $assessmentService->assessment_id,

                        'assessment_service_id' =>
                            $assessmentService->id,

                        'payment_schedule_id' =>
                            $schedule->id,

                        'schedule_type' =>
                            $scheduleType,

                        'installment_number' =>
                            $installmentNumber,

                        'annual_installment_number' =>
                            $year,

                        'remaining_payment_years' =>
                            $remainingPaymentYears,

                        'due_date' =>
                            $dueDate->toDateString(),

                        'amount_due' =>
                            $amountDue,

                        'rule_percentage' =>
                            null,

                        'status' =>
                            PaymentScheduleStatus::PENDING->value,
                    ]
                );
            }

            $installmentNumber++;
        }

        /*
        |--------------------------------------------------------------------------
        | RECONCILE REMAINING BALANCE
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

                    'schedule_type' =>
                        $scheduleType,

                    'principal_amount' =>
                        $principal,

                    'first_installment_amount' =>
                        $firstInstallmentAmount,

                    'remaining_balance' =>
                        $remainingBalance,

                    'remaining_payment_years' =>
                        $remainingPaymentYears,

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
        | RECONCILE COMPLETE PRINCIPAL
        |--------------------------------------------------------------------------
        */

        $this->assertScheduleTotal(
            $schedules,
            $principal,
            $assessmentService,
            $scheduleType
        );

        /*
        |--------------------------------------------------------------------------
        | FINAL SUMMARY
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

        Log::info(
            'LIZZ payment schedule build completed.',
            [
                'assessment_id' =>
                    $assessmentService->assessment_id,

                'assessment_service_id' =>
                    $assessmentService->id,

                'schedule_type' =>
                    $scheduleType,

                'computed_amount' =>
                    $configuration['computed_amount'],

                'historical_paid_amount' =>
                    $configuration['paid_amount'],

                'historical_remaining_amount' =>
                    $configuration['remaining_amount'],

                'scheduling_principal' =>
                    $principal,

                'agreement_date' =>
                    $configuration['agreement_date']
                        ?->toDateString(),

                'balance_as_of_date' =>
                    $configuration['balance_as_of_date'],

                'base_due_date' =>
                    $baseDueDate->toDateString(),

                'first_installment_required' =>
                    $firstInstallmentRequired,

                'first_installment_percentage' =>
                    $firstInstallmentPercentage,

                'first_installment_amount' =>
                    $firstInstallmentAmount,

                'remaining_balance_scheduled' =>
                    $remainingBalance,

                'payment_completion_years' =>
                    $originalCompletionYears,

                'elapsed_payment_years' =>
                    $configuration['elapsed_payment_years'],

                'remaining_payment_years' =>
                    $remainingPaymentYears,

                'annual_remaining_amount' =>
                    $annualAmount,

                'scheduled_remaining_total' =>
                    $scheduledRemainingTotal,

                'total_scheduled_amount' =>
                    $totalScheduled,

                'schedule_count' =>
                    $schedules->count(),

                'first_schedule_id' =>
                    $schedules->first()?->id,

                'last_schedule_id' =>
                    $schedules->last()?->id,
            ]
        );

        return $schedules;
    }

    /*
    |--------------------------------------------------------------------------
    | ASSERT SCHEDULE TOTAL
    |--------------------------------------------------------------------------
    */

    private function assertScheduleTotal(
        Collection $schedules,
        float $principal,
        AssessmentServiceModel $assessmentService,
        string $scheduleType
    ): void {
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

        $difference =
            $this->normalizeMoney(
                $principal -
                $totalScheduled
            );

        if ($difference !== 0.0) {
            Log::error(
                'LIZZ total payment schedule does not reconcile with scheduling principal.',
                [
                    'assessment_id' =>
                        $assessmentService->assessment_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'schedule_type' =>
                        $scheduleType,

                    'scheduling_principal' =>
                        $principal,

                    'total_scheduled_amount' =>
                        $totalScheduled,

                    'difference' =>
                        $difference,

                    'schedule_count' =>
                        $schedules->count(),
                ]
            );

            throw ValidationException::withMessages([
                'payment_schedule' => [
                    'The generated LIZZ payment schedules do not reconcile with the scheduling principal.',
                ],
            ]);
        }
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

        $rule =
            $assessmentService
                ->service
                ?->revenueCode
                ?->paymentScheduleRule;

        if (! $rule || ! $rule->is_enabled) {
            return null;
        }

        return $rule;
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE FIRST INSTALLMENT PERCENTAGE
    |--------------------------------------------------------------------------
    */

    private function resolveFirstInstallmentPercentage(
        RevenueCodePaymentScheduleRule $rule,
        bool $firstInstallmentRequired
    ): float {
        /*
        |--------------------------------------------------------------------------
        | No first installment means no percentage is applied.
        |--------------------------------------------------------------------------
        */

        if (! $firstInstallmentRequired) {
            return 0.0;
        }

        $percentage =
            $rule->first_installment_percentage;

        if (
            $percentage === null ||
            ! is_numeric($percentage)
        ) {
            throw ValidationException::withMessages([
                'first_installment_percentage' => [
                    'The active payment schedule rule must define a valid first installment percentage.',
                ],
            ]);
        }

        $percentage =
            (float) $percentage;

        if (
            ! is_finite($percentage) ||
            $percentage <= 0 ||
            $percentage > 100
        ) {
            throw ValidationException::withMessages([
                'first_installment_percentage' => [
                    'The first installment percentage must be greater than 0 and less than or equal to 100.',
                ],
            ]);
        }

        return $percentage;
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
            ! is_numeric($value)
        ) {
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
            ! is_finite($number) ||
            $number <= 0 ||
            floor($number) !== $number
        ) {
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

                        if (! $baseField) {
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
    */

    private function resolveRevenueCode(
        AssessmentServiceModel $assessmentService
    ): string {
        $assessmentService->loadMissing([
            'service.revenueCode',
        ]);

        $service =
            $assessmentService->service;

        if (! $service) {
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

        if (! $revenueCode) {
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

        $required =
            $this->resolvePaymentScheduleRule(
                $assessmentService
            ) !== null;

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
        $paymentScheduleRule =
            $this->resolvePaymentScheduleRule(
                $assessmentService
            );

        if (! $paymentScheduleRule) {
            return new Collection();
        }

        $existingSchedules =
            $this->getSchedules(
                $assessmentService
            );

        if ($existingSchedules->isNotEmpty()) {
            return $existingSchedules;
        }

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
        $assessmentService->loadMissing([
            'assessment',
            'service.revenueCode.paymentScheduleRule',
            'values.revenueServiceField.baseField',
        ]);

        $paymentScheduleRule =
            $this->resolvePaymentScheduleRule(
                $assessmentService
            );

        if (! $paymentScheduleRule) {
            return;
        }

        if (! $assessmentService->assessment) {
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

        $configuration =
            $this->resolvePaymentScheduleConfiguration(
                $assessmentService
            );

        /*
        |--------------------------------------------------------------------------
        | Existing LIZZ may have zero balance.
        |--------------------------------------------------------------------------
        */

        if (
            $configuration['schedule_type'] !==
            self::SCHEDULE_TYPE_EXISTING_LIZZ &&
            $configuration['principal_amount'] <= 0
        ) {
            throw ValidationException::withMessages([
                'payment_schedule' => [
                    'The payment schedule principal amount must be greater than zero.',
                ],
            ]);
        }

        if (
            ! $configuration['base_due_date'] instanceof Carbon
        ) {
            throw ValidationException::withMessages([
                'due_date' => [
                    'The payment schedule base due date could not be resolved.',
                ],
            ]);
        }

        Log::info(
            'Payment schedule validation completed successfully.',
            [
                'assessment_id' =>
                    $assessmentService->assessment_id,

                'assessment_service_id' =>
                    $assessmentService->id,

                'schedule_type' =>
                    $configuration['schedule_type'],

                'scheduling_principal' =>
                    $configuration['principal_amount'],

                'payment_completion_years' =>
                    $configuration['payment_completion_years'],

                'elapsed_payment_years' =>
                    $configuration['elapsed_payment_years'],

                'remaining_payment_years' =>
                    $configuration['remaining_payment_years'],

                'first_installment_required' =>
                    $configuration['first_installment_required'],

                'first_installment_percentage' =>
                    $configuration['first_installment_percentage'],

                'base_due_date' =>
                    $configuration['base_due_date']
                        ->toDateString(),
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

        if (! $assessment) {
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

        $paymentScheduleRule =
            $this->resolvePaymentScheduleRule(
                $assessmentService
            );

        if (! $paymentScheduleRule) {
            return;
        }

        $computedAmount =
            $this->normalizeMoney(
                $assessmentService->computed_amount
            );

        if ($computedAmount <= 0) {
            throw ValidationException::withMessages([
                'computed_amount' => [
                    'A payment schedule requires a positive computed amount.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | EXISTING LIZZ
        |--------------------------------------------------------------------------
        */

        if (
            $assessmentService->remaining_amount !== null
        ) {
            $this->validateExistingLizzAssessmentService(
                $assessmentService,
                $computedAmount
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | NEW LIZZ
        |--------------------------------------------------------------------------
        */

        if (! $assessmentService->isCompleted()) {
            throw ValidationException::withMessages([
                'assessment_service' => [
                    'A payment schedule cannot be created because the new LIZZ assessment service calculation is not completed.',
                ],
            ]);
        }

        if (! $assessmentService->due_date) {
            throw ValidationException::withMessages([
                'due_date' => [
                    'A new LIZZ payment schedule requires a resolved due date.',
                ],
            ]);
        }

        Log::info(
            'New LIZZ assessment service passed payment scheduling validation.',
            [
                'assessment_id' =>
                    $assessment->id,

                'assessment_service_id' =>
                    $assessmentService->id,

                'computed_amount' =>
                    $computedAmount,

                'due_date' =>
                    $assessmentService
                        ->due_date
                        ->toDateString(),
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE EXISTING LIZZ
    |--------------------------------------------------------------------------
    */

    private function validateExistingLizzAssessmentService(
        AssessmentServiceModel $assessmentService,
        float $computedAmount
    ): void {
        $paidAmount =
            $this->normalizeMoney(
                $assessmentService->paid_amount
            );

        $remainingAmount =
            $this->normalizeMoney(
                $assessmentService->remaining_amount
            );

        if ($paidAmount < 0) {
            throw ValidationException::withMessages([
                'paid_amount' => [
                    'The historical paid amount cannot be negative.',
                ],
            ]);
        }

        if ($remainingAmount < 0) {
            throw ValidationException::withMessages([
                'remaining_amount' => [
                    'The historical remaining amount cannot be negative.',
                ],
            ]);
        }

        if (! $assessmentService->balance_as_of_date) {
            throw ValidationException::withMessages([
                'balance_as_of_date' => [
                    'The balance-as-of date is required for an existing LIZZ agreement.',
                ],
            ]);
        }

        $balanceAsOfDate =
            Carbon::parse(
                $assessmentService->balance_as_of_date
            )->startOfDay();

        $historicalTotal =
            $this->normalizeMoney(
                $paidAmount +
                $remainingAmount
            );

        if ($historicalTotal > $computedAmount) {
            throw ValidationException::withMessages([
                'remaining_amount' => [
                    'The historical paid amount plus remaining amount cannot exceed the original computed amount.',
                ],
            ]);
        }

        $agreementDate =
            $this->resolveLizzAgreementDate(
                $assessmentService
            );

        if ($agreementDate->gt($balanceAsOfDate)) {
            throw ValidationException::withMessages([
                'balance_as_of_date' => [
                    'The balance-as-of date cannot be earlier than the LIZZ agreement date.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | ORIGINAL CONTRACTUAL PERIOD
        |--------------------------------------------------------------------------
        */

        $paymentCompletionYears =
            $this->resolvePositiveIntegerField(
                $assessmentService,
                self::PAYMENT_COMPLETION_YEARS_FIELD
            );

        /*
        |--------------------------------------------------------------------------
        | DERIVED ELAPSED PERIOD
        |--------------------------------------------------------------------------
        */

        $elapsedPaymentYears =
            $this->calculateElapsedPaymentYears(
                $agreementDate,
                $balanceAsOfDate
            );

        /*
        |--------------------------------------------------------------------------
        | DERIVED REMAINING PERIOD
        |--------------------------------------------------------------------------
        */

        $remainingPaymentYears =
            $this->calculateRemainingPaymentYears(
                $paymentCompletionYears,
                $elapsedPaymentYears
            );

        if (
            $remainingPaymentYears <= 0 &&
            $remainingAmount > 0
        ) {
            throw ValidationException::withMessages([
                'payment_schedule' => [
                    'The Existing LIZZ contractual payment term has elapsed while an outstanding balance remains.',
                ],
            ]);
        }

        Log::info(
            'Existing LIZZ assessment service passed payment scheduling validation.',
            [
                'assessment_id' =>
                    $assessmentService->assessment_id,

                'assessment_service_id' =>
                    $assessmentService->id,

                'computed_amount' =>
                    $computedAmount,

                'paid_amount' =>
                    $paidAmount,

                'remaining_amount' =>
                    $remainingAmount,

                'agreement_date' =>
                    $agreementDate->toDateString(),

                'balance_as_of_date' =>
                    $balanceAsOfDate->toDateString(),

                'payment_completion_years' =>
                    $paymentCompletionYears,

                'elapsed_payment_years' =>
                    $elapsedPaymentYears,

                'remaining_payment_years' =>
                    $remainingPaymentYears,
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
                    $lockedAssessmentService =
                        AssessmentServiceModel::query()
                            ->whereKey($assessmentService->id)
                            ->lockForUpdate()
                            ->first();

                    if (! $lockedAssessmentService) {
                        throw ValidationException::withMessages([
                            'assessment_service' => [
                                'The assessment service could not be found.',
                            ],
                        ]);
                    }

                    $lockedAssessmentService->loadMissing([
                        'assessment',
                        'service.revenueCode.paymentScheduleRule',
                        'values.revenueServiceField.baseField',
                    ]);

                    $revenueCode =
                        $this->resolveRevenueCode(
                            $lockedAssessmentService
                        );

                    $paymentScheduleRule =
                        $this->resolvePaymentScheduleRule(
                            $lockedAssessmentService
                        );

                    if (! $paymentScheduleRule) {
                        Log::info(
                            'Payment schedule rebuild skipped for non-scheduled revenue code.',
                            [
                                'assessment_id' =>
                                    $lockedAssessmentService->assessment_id,

                                'assessment_service_id' =>
                                    $lockedAssessmentService->id,

                                'revenue_code' =>
                                    $revenueCode,
                            ]
                        );

                        return new Collection();
                    }

                    $this->validateAssessmentServiceForScheduling(
                        $lockedAssessmentService
                    );

                    $existingSchedules =
                        $this->getSchedules(
                            $lockedAssessmentService
                        );

                    /*
                    |--------------------------------------------------------------------------
                    | NEVER REBUILD AFTER ACTUAL PAYMENTS
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
                        throw ValidationException::withMessages([
                            'payment_schedule' => [
                                'A payment schedule with recorded payments cannot be rebuilt.',
                            ],
                        ]);
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | DELETE UNPAID SCHEDULES
                    |--------------------------------------------------------------------------
                    */

                    $deleted =
                        PaymentSchedule::query()
                            ->where(
                                'assessment_service_id',
                                $lockedAssessmentService->id
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
                                $lockedAssessmentService->assessment_id,

                            'assessment_service_id' =>
                                $lockedAssessmentService->id,

                            'deleted_count' =>
                                $deleted,
                        ]
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | REBUILD FROM CURRENT CONFIGURATION
                    |--------------------------------------------------------------------------
                    |
                    | Newly generated schedules receive fresh rule snapshots.
                    |--------------------------------------------------------------------------
                    */

                    return $this->createForAssessmentServiceInternal(
                        $lockedAssessmentService
                    );
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
        return $this->normalizeMoney(
            $principal *
            ($percentage / 100)
        );
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
            'New LIZZ first installment: %.2f%% of principal %.4f. Remaining balance: %.4f.',
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
        string $scheduleType,
        int $year,
        int $remainingPaymentYears,
        int $originalPaymentCompletionYears,
        int $elapsedPaymentYears,
        float $principal,
        float $firstInstallmentAmount,
        float $remainingBalance,
        float $amountDue
    ): string {
        if (
            $scheduleType ===
            self::SCHEDULE_TYPE_EXISTING_LIZZ
        ) {
            return sprintf(
                'Existing LIZZ annual installment %d of %d remaining years. Original contractual term: %d years. Elapsed contractual years: %d. Historical remaining balance: %.4f. Annual amount: %.4f.',
                $year,
                $remainingPaymentYears,
                $originalPaymentCompletionYears,
                $elapsedPaymentYears,
                $remainingBalance,
                $amountDue
            );
        }

        return sprintf(
            'New LIZZ annual installment %d of %d. Original payment term: %d years. Principal: %.4f. Initial installment: %.4f. Remaining balance: %.4f. Annual amount: %.4f.',
            $year,
            $remainingPaymentYears,
            $originalPaymentCompletionYears,
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

        if (! is_numeric($amount)) {
            return 0.0;
        }

        $normalized =
            (float) $amount;

        if (! is_finite($normalized)) {
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