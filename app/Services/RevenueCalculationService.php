<?php

namespace App\Services;

use App\Models\AssessmentService;
use App\Services\Calculations\DueDateResolver;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * ================================================================
 * REVENUE CALCULATION SERVICE
 * ================================================================
 *
 * Central orchestration layer for revenue calculations.
 *
 * This service coordinates:
 *
 *     AssessmentService
 *          ↓
 *     TariffResolver
 *          ↓
 *     TariffRule
 *          ↓
 *     TariffCalculator
 *          ↓
 *     PenaltyRule
 *          ↓
 *     InterestRule
 *          ↓
 *     DueDateResolver
 *          ↓
 *     Calculation Result
 *
 *
 * IMPORTANT
 * ----------------------------------------------------------------
 *
 * This service DOES:
 *
 * - resolve tariff version
 * - resolve tariff rule
 * - calculate principal amount
 * - resolve penalty rule
 * - resolve interest rule
 * - resolve due date
 * - build calculation metadata
 * - build calculation snapshots
 *
 *
 * This service DOES NOT:
 *
 * - create invoices
 * - issue invoices
 * - approve assessments
 * - reject assessments
 * - collect payments
 * - calculate accrued penalties
 * - calculate accrued interest
 * - calculate outstanding balances
 *
 *
 * Those responsibilities belong to their respective services.
 */
class RevenueCalculationService
{
    public function __construct(
        private readonly TariffResolver $resolver,
        private readonly TariffCalculator $calculator,
        private readonly DueDateResolver $dueDateResolver,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | CALCULATE ASSESSMENT SERVICE
    |--------------------------------------------------------------------------
    |
    | This is the shared calculation entry point currently compatible
    | with your existing TariffResolver and TariffCalculator.
    |
    | The actual Assessment lifecycle remains in:
    |
    |     AssessmentCalculationService
    |
    */

    public function calculateAssessmentService(
        AssessmentService $assessmentService,
    ): array {
        /*
        |--------------------------------------------------------------------------
        | Validate Service
        |--------------------------------------------------------------------------
        */

        if (! $assessmentService->exists) {
            throw new RuntimeException(
                'Assessment service must be persisted before calculation.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Load Required Relationships
        |--------------------------------------------------------------------------
        */

        $assessmentService->loadMissing([
            'assessment',
            'service',
            'values',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Validate Assessment
        |--------------------------------------------------------------------------
        */

        if (! $assessmentService->assessment) {
            throw new RuntimeException(
                sprintf(
                    'Assessment service [%s] does not belong to an assessment.',
                    $assessmentService->id,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Revenue Service
        |--------------------------------------------------------------------------
        */

        if (! $assessmentService->service) {
            throw new RuntimeException(
                sprintf(
                    'Revenue service is missing for assessment service [%s].',
                    $assessmentService->id,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Tariff Version
        |--------------------------------------------------------------------------
        */

        $version = $this->resolver->resolveVersion(
            $assessmentService,
        );

        if (! $version) {
            throw new RuntimeException(
                sprintf(
                    'Unable to resolve tariff version for assessment service [%s].',
                    $assessmentService->id,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Tariff Rule
        |--------------------------------------------------------------------------
        */

        $rule = $this->resolver->resolveRule(
            $version,
            $assessmentService,
        );

        if (! $rule) {
            throw new RuntimeException(
                sprintf(
                    'Unable to resolve tariff rule for assessment service [%s].',
                    $assessmentService->id,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Calculate Principal
        |--------------------------------------------------------------------------
        |
        | TariffCalculator is responsible only for the mathematical
        | calculation.
        |
        */

        $result = $this->calculator->calculate(
            $rule,
            $assessmentService,
        );

        /*
        |--------------------------------------------------------------------------
        | Validate Calculation Result
        |--------------------------------------------------------------------------
        */

        if (! $result->successful) {
            throw new RuntimeException(
                $result->error
                    ?? sprintf(
                        'Tariff calculation failed for assessment service [%s].',
                        $assessmentService->id,
                    )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Penalty Rule
        |--------------------------------------------------------------------------
        */

        $penaltyRule = $this->resolver->resolvePenaltyRule(
            $assessmentService,
        );

        if (! $penaltyRule) {
            throw new RuntimeException(
                sprintf(
                    'Unable to resolve penalty rule for assessment service [%s].',
                    $assessmentService->id,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Interest Rule
        |--------------------------------------------------------------------------
        |
        | Interest may be nullable according to the current
        | TariffResolver implementation.
        |
        */

        $interestRule = $this->resolver->resolveInterestRule(
            $assessmentService,
        );

        /*
        |--------------------------------------------------------------------------
        | Resolve Due Date
        |--------------------------------------------------------------------------
        */

        $dueDate = $this->dueDateResolver->resolve(
            $assessmentService,
            $penaltyRule,
        );

        /*
        |--------------------------------------------------------------------------
        | Normalize Calculation
        |--------------------------------------------------------------------------
        */

        $amount = $this->normalizeAmount(
            $result->amount,
        );

        $currencyCode =
            $result->currencyCode
            ?? $this->resolveCurrency($version)
            ?? 'ETB';

        /*
        |--------------------------------------------------------------------------
        | Build Calculation Metadata
        |--------------------------------------------------------------------------
        |
        | This metadata is suitable for:
        |
        |     assessment_services.calculation_metadata
        |
        | and later:
        |
        |     invoice_items.calculation_snapshot
        |
        */

        $metadata = [
            /*
            |--------------------------------------------------------------------------
            | Tariff
            |--------------------------------------------------------------------------
            */

            'tariff_version_id' =>
                $version->id,

            'tariff_rule_id' =>
                $rule->id,

            'calculation_type' =>
                $result->calculationType
                ?? $rule->calculation_type,

            /*
            |--------------------------------------------------------------------------
            | Financial Rules
            |--------------------------------------------------------------------------
            */

            'penalty_rule_id' =>
                $penaltyRule->id,

            'interest_rule_id' =>
                $interestRule?->id,

            /*
            |--------------------------------------------------------------------------
            | Due Date
            |--------------------------------------------------------------------------
            */

            'due_date' =>
                $this->normalizeDate(
                    $dueDate,
                ),

            /*
            |--------------------------------------------------------------------------
            | Principal
            |--------------------------------------------------------------------------
            */

            'principal_amount' =>
                $amount,

            'currency_code' =>
                $currencyCode,

            /*
            |--------------------------------------------------------------------------
            | Calculation Timestamp
            |--------------------------------------------------------------------------
            */

            'calculated_at' =>
                now()->toISOString(),
        ];

        /*
        |--------------------------------------------------------------------------
        | Merge Calculator Metadata
        |--------------------------------------------------------------------------
        |
        | TariffCalculator already returns useful information such as:
        |
        | - base_field_id
        | - measurement_unit_id
        | - configured_amount
        | - percentage
        | - minimum_amount
        | - maximum_amount
        | - rounding_rule
        | - formula
        | - inputs
        |
        | Preserve that metadata.
        |
        */

        if (
            is_array(
                $result->metadata ?? null
            )
        ) {
            $metadata = array_merge(
                $metadata,
                $result->metadata,
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Return Normalized Calculation
        |--------------------------------------------------------------------------
        */

        return [
            'amount' =>
                $amount,

            'currency_code' =>
                $currencyCode,

            'tariff_version_id' =>
                $version->id,

            'tariff_rule_id' =>
                $rule->id,

            'calculation_type' =>
                $result->calculationType
                ?? $rule->calculation_type,

            'penalty_rule_id' =>
                $penaltyRule->id,

            'interest_rule_id' =>
                $interestRule?->id,

            'due_date' =>
                $this->normalizeDate(
                    $dueDate,
                ),

            'metadata' =>
                $metadata,

            /*
            |--------------------------------------------------------------------------
            | Useful Object References
            |--------------------------------------------------------------------------
            |
            | These are useful internally when another service needs
            | access to the resolved objects.
            |
            */

            'tariff_version' =>
                $version,

            'tariff_rule' =>
                $rule,

            'penalty_rule' =>
                $penaltyRule,

            'interest_rule' =>
                $interestRule,

            'result' =>
                $result,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | BUILD CALCULATION SNAPSHOT
    |--------------------------------------------------------------------------
    |
    | Used when creating:
    |
    |     invoice_items.calculation_snapshot
    |
    | The snapshot is historical.
    |
    | Once an invoice is issued, the invoice item must not depend
    | on recalculating the current tariff configuration.
    |
    */

    public function buildCalculationSnapshot(
        array $calculation,
    ): array {
        return [
            /*
            |--------------------------------------------------------------------------
            | Principal
            |--------------------------------------------------------------------------
            */

            'amount' =>
                $this->normalizeAmount(
                    $calculation['amount'] ?? 0,
                ),

            /*
            |--------------------------------------------------------------------------
            | Currency
            |--------------------------------------------------------------------------
            */

            'currency_code' =>
                $calculation['currency_code']
                ?? 'ETB',

            /*
            |--------------------------------------------------------------------------
            | Tariff
            |--------------------------------------------------------------------------
            */

            'tariff_version_id' =>
                $calculation['tariff_version_id']
                ?? null,

            'tariff_rule_id' =>
                $calculation['tariff_rule_id']
                ?? null,

            'calculation_type' =>
                $calculation['calculation_type']
                ?? null,

            /*
            |--------------------------------------------------------------------------
            | Financial Rules
            |--------------------------------------------------------------------------
            */

            'penalty_rule_id' =>
                $calculation['penalty_rule_id']
                ?? null,

            'interest_rule_id' =>
                $calculation['interest_rule_id']
                ?? null,

            /*
            |--------------------------------------------------------------------------
            | Due Date
            |--------------------------------------------------------------------------
            */

            'due_date' =>
                $calculation['due_date']
                ?? null,

            /*
            |--------------------------------------------------------------------------
            | Calculation Metadata
            |--------------------------------------------------------------------------
            */

            'metadata' =>
                $calculation['metadata']
                ?? [],

            /*
            |--------------------------------------------------------------------------
            | Snapshot Timestamp
            |--------------------------------------------------------------------------
            */

            'calculated_at' =>
                now()->toISOString(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | BUILD INPUT SNAPSHOT
    |--------------------------------------------------------------------------
    |
    | This is separate from the calculation snapshot.
    |
    | input_snapshot:
    |
    |     "What did the taxpayer provide?"
    |
    | calculation_snapshot:
    |
    |     "How was the amount calculated?"
    |
    */

    public function buildInputSnapshot(
        AssessmentService $assessmentService,
    ): array {
        $assessmentService->loadMissing([
            'values.revenueServiceField.baseField',
        ]);

        $inputs = [];

        foreach (
            $assessmentService->values ?? []
            as $value
        ) {
            $field = $value->revenueServiceField;
            $baseField = $field?->baseField;

            $inputs[] = [
                /*
                |--------------------------------------------------------------------------
                | Field Identity
                |--------------------------------------------------------------------------
                */

                'revenue_service_field_id' =>
                    $value->revenue_service_field_id,

                'field_id' =>
                    $field?->base_field_id,

                'field_code' =>
                    $value->field_code
                    ?? $baseField?->code,

                /*
                |--------------------------------------------------------------------------
                | Field Labels
                |--------------------------------------------------------------------------
                */

                'field_label' =>
                    $value->field_label
                    ?? $baseField?->label
                    ?? $field?->label,

                /*
                |--------------------------------------------------------------------------
                | Field Configuration
                |--------------------------------------------------------------------------
                */

                'data_type' =>
                    $value->data_type
                    ?? $field?->data_type
                    ?? $baseField?->data_type,

                'input_type' =>
                    $value->input_type
                    ?? $field?->input_type
                    ?? $baseField?->input_type,

                /*
                |--------------------------------------------------------------------------
                | Actual Captured Value
                |--------------------------------------------------------------------------
                */

                'value' =>
                    $this->normalizeSnapshotValue(
                        $value->value,
                    ),

                /*
                |--------------------------------------------------------------------------
                | Display Value
                |--------------------------------------------------------------------------
                */

                'display_value' =>
                    $this->normalizeSnapshotValue(
                        $value->display_value
                        ?? null,
                    ),

                /*
                |--------------------------------------------------------------------------
                | Measurement Unit
                |--------------------------------------------------------------------------
                */

                'measurement_unit_id' =>
                    $value->measurement_unit_id
                    ?? null,

                /*
                |--------------------------------------------------------------------------
                | Ordering
                |--------------------------------------------------------------------------
                */

                'sort_order' =>
                    $value->sort_order
                    ?? null,
            ];
        }

        return [
            'assessment_service_id' =>
                $assessmentService->id,

            'service_id' =>
                $assessmentService->service_id,

            'inputs' =>
                $inputs,

            'captured_at' =>
                now()->toISOString(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | BUILD INVOICE ITEM SNAPSHOT
    |--------------------------------------------------------------------------
    |
    | Convenience method for InvoiceIssuanceService.
    |
    | Returns both immutable snapshots:
    |
    |     input_snapshot
    |     calculation_snapshot
    |
    */

    public function buildInvoiceItemSnapshots(
        AssessmentService $assessmentService,
        array $calculation,
    ): array {
        return [
            'input_snapshot' =>
                $this->buildInputSnapshot(
                    $assessmentService,
                ),

            'calculation_snapshot' =>
                $this->buildCalculationSnapshot(
                    $calculation,
                ),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | PRINCIPAL AMOUNT
    |--------------------------------------------------------------------------
    |
    | The tariff calculation result is the ORIGINAL PRINCIPAL.
    |
    | It does NOT include:
    |
    | - accrued penalty
    | - accrued interest
    | - payment fees
    | - outstanding balance
    |
    */

    public function principalAmount(
        array $calculation,
    ): float {
        return $this->normalizeAmount(
            $calculation['amount'] ?? 0,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CURRENCY
    |--------------------------------------------------------------------------
    */

    private function resolveCurrency(
        object $version,
    ): ?string {
        $currency = $version->currency_code ?? null;

        if (
            $currency === null
            ||
            trim((string) $currency) === ''
        ) {
            return null;
        }

        return strtoupper(
            trim((string) $currency)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE AMOUNT
    |--------------------------------------------------------------------------
    |
    | Database:
    |
    |     decimal(18,4)
    |
    | Therefore calculation amounts are normalized to four decimal
    | places before persistence.
    |
    */

    private function normalizeAmount(
        mixed $amount,
    ): float {
        if (
            $amount === null
            ||
            $amount === ''
        ) {
            return 0.0;
        }

        if (! is_numeric($amount)) {
            throw new RuntimeException(
                'Revenue calculation returned a non-numeric amount.'
            );
        }

        $amount = (float) $amount;

        if (! is_finite($amount)) {
            throw new RuntimeException(
                'Revenue calculation returned an invalid amount.'
            );
        }

        if ($amount < 0) {
            throw new RuntimeException(
                'Revenue calculation cannot produce a negative principal amount.'
            );
        }

        return round(
            $amount,
            4,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE DATE
    |--------------------------------------------------------------------------
    */

    private function normalizeDate(
        mixed $date,
    ): ?string {
        if ($date === null) {
            return null;
        }

        if ($date instanceof Carbon) {
            return $date->toDateString();
        }

        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        if (is_string($date)) {
            $date = trim($date);

            if ($date === '') {
                return null;
            }

            try {
                return Carbon::parse(
                    $date,
                )->toDateString();

            } catch (\Throwable $e) {
                throw new RuntimeException(
                    sprintf(
                        'Invalid revenue calculation due date: %s',
                        $date,
                    ),
                    previous: $e,
                );
            }
        }

        throw new RuntimeException(
            'Revenue calculation returned an invalid due date.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE SNAPSHOT VALUE
    |--------------------------------------------------------------------------
    |
    | JSON-safe historical snapshot values.
    |
    */

    private function normalizeSnapshotValue(
        mixed $value,
    ): mixed {
        if ($value === null) {
            return null;
        }

        if (
            is_scalar($value)
            ||
            is_array($value)
        ) {
            return $value;
        }

        if ($value instanceof \JsonSerializable) {
            return $value->jsonSerialize();
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(
                'Y-m-d H:i:s',
            );
        }

        return json_decode(
            json_encode(
                $value,
                JSON_THROW_ON_ERROR,
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}