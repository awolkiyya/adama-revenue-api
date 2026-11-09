<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AssessmentService;
use App\Models\TariffRule;
use App\Services\Calculations\FormulaCalculationEngine;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TariffCalculator
{
    /**
     * Create the tariff calculator.
     */
    public function __construct(
        private readonly FormulaCalculationEngine $formulaEngine,
    ) {
    }

    /**
     * Calculate the amount for one assessment service.
     *
     * Kept for backward compatibility with AssessmentCalculationService.
     */
    public function calculate(
        TariffRule $rule,
        AssessmentService $assessmentService,
    ): TariffCalculationResult {
        $assessmentService->loadMissing([
            'values',
        ]);

        $values = $this->buildValueMap(
            $assessmentService,
        );

        $result = $this->calculateWithValues(
            rule: $rule,
            values: $values,
        );

        $resultMetadata = $result->metadata ?? [];

        $resultMetadata['assessment_service_id'] =
            $assessmentService->id;

        if ($result->isSuccessful()) {
            return TariffCalculationResult::success(
                amount: $result->amount,
                metadata: $resultMetadata,
            );
        }

        return TariffCalculationResult::failed(
            error: $result->error ?? 'Tariff calculation failed.',
            metadata: $resultMetadata,
        );
    }

    /**
     * Calculate a tariff for a direct revenue-service collection.
     *
     * Direct Collection has already resolved the tariff version,
     * tariff rule and normalized input values.
     */
    public function calculateForRevenueService(
        TariffRule $rule,
        array $values,
    ): TariffCalculationResult {
        return $this->calculateWithValues(
            rule: $rule,
            values: $values,
        );
    }

    /**
     * Generic tariff calculation entry point.
     *
     * Supported calculation types:
     *
     * FIXED
     * PERCENTAGE
     * PER_UNIT
     * RANGE
     * FORMULA
     */
    public function calculateWithValues(
        TariffRule $rule,
        array $values,
    ): TariffCalculationResult {
        $calculationType = strtoupper(
            trim((string) $rule->calculation_type),
        );

        try {
            Log::debug(
                'Starting tariff calculation.',
                [
                    'tariff_rule_id' => $rule->id,
                    'tariff_version_id' => $rule->tariff_version_id,
                    'calculation_type' => $calculationType,
                    'input_value_count' => count($values),
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Calculate Principal
            |--------------------------------------------------------------------------
            */

            $amount = match ($calculationType) {
                'FIXED' => $this->calculateFixed($rule),

                'PERCENTAGE' => $this->calculatePercentage(
                    $rule,
                    $values,
                ),

                'PER_UNIT' => $this->calculatePerUnit(
                    $rule,
                    $values,
                ),

                'RANGE' => $this->calculateRange(
                    $rule,
                    $values,
                ),

                'FORMULA' => $this->calculateFormula(
                    $rule,
                    $values,
                ),

                default => throw new RuntimeException(
                    sprintf(
                        'Unsupported calculation type: %s',
                        $rule->calculation_type,
                    ),
                ),
            };

            $this->validateFiniteAmount(
                $amount,
                'Tariff calculation produced an invalid amount.',
            );

            /*
            |--------------------------------------------------------------------------
            | Apply Minimum / Maximum
            |--------------------------------------------------------------------------
            */

            $amount = $this->applyLimits(
                $amount,
                $rule,
            );

            /*
            |--------------------------------------------------------------------------
            | Apply Rounding
            |--------------------------------------------------------------------------
            */

            $amount = $this->applyRounding(
                $amount,
                $rule,
            );

            /*
            |--------------------------------------------------------------------------
            | Final Validation
            |--------------------------------------------------------------------------
            */

            $this->validateFiniteAmount(
                $amount,
                'Tariff calculation produced an invalid final amount.',
            );

            if ($amount < 0) {
                throw new RuntimeException(
                    'Calculated tariff amount cannot be negative.',
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Build Calculation Metadata
            |--------------------------------------------------------------------------
            |
            | Preserve the existing metadata and expose the applied
            | quantity and unit price for PER_UNIT calculations.
            |
            */

            $metadata = [
                'tariff_rule_id' => $rule->id,

                'tariff_version_id' => $rule->tariff_version_id,

                'calculation_type' => $rule->calculation_type,

                'base_field_id' => $rule->base_field_id,

                'measurement_unit_id' => $rule->measurement_unit_id,

                'configured_amount' => $rule->amount,

                'percentage' => $rule->percentage,

                'minimum_amount' => $rule->minimum_amount,

                'maximum_amount' => $rule->maximum_amount,

                'rounding_rule' => $rule->rounding_rule,

                'formula' => $rule->formula,

                'inputs' => $values,
            ];

            /*
            |--------------------------------------------------------------------------
            | PER_UNIT Breakdown
            |--------------------------------------------------------------------------
            |
            | PER_UNIT calculation:
            |
            | amount = quantity × configured unit rate
            |
            | The rule's configured amount is the applied unit rate.
            |
            */

            if ($calculationType === 'PER_UNIT') {
                $quantity = $this->requireNumericValue(
                    $values,
                    (string) $rule->base_field_id,
                );

                $metadata['quantity'] = $quantity;

                $metadata['unit_price'] = (float) $rule->amount;

                $metadata['unit'] = null;
            }

            /*
            |--------------------------------------------------------------------------
            | Successful Calculation Log
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Tariff calculation completed successfully.',
                [
                    'tariff_rule_id' => $rule->id,

                    'tariff_version_id' => $rule->tariff_version_id,

                    'calculation_type' => $calculationType,

                    'calculated_amount' => $amount,

                    'quantity' => $metadata['quantity'] ?? null,

                    'unit_price' => $metadata['unit_price'] ?? null,
                ],
            );

            return TariffCalculationResult::success(
                amount: $amount,
                metadata: $metadata,
            );
        } catch (Throwable $e) {
            Log::error(
                'Tariff calculation failed.',
                [
                    'tariff_rule_id' => $rule->id,

                    'tariff_version_id' => $rule->tariff_version_id,

                    'calculation_type' => $calculationType,

                    'input_value_count' => count($values),

                    'exception_class' => $e::class,

                    'exception_message' => $e->getMessage(),
                ],
            );

            return TariffCalculationResult::failed(
                error: $e->getMessage(),
                metadata: [
                    'tariff_version_id' => $rule->tariff_version_id,

                    'tariff_rule_id' => $rule->id,

                    'calculation_type' => $rule->calculation_type,

                    'base_field_id' => $rule->base_field_id,

                    'inputs' => $values,
                ],
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FIXED
    |--------------------------------------------------------------------------
    */

    private function calculateFixed(
        TariffRule $rule,
    ): float {
        if ($rule->amount === null) {
            throw new RuntimeException(
                'FIXED tariff requires amount.',
            );
        }

        return (float) $rule->amount;
    }

    /*
    |--------------------------------------------------------------------------
    | PERCENTAGE
    |--------------------------------------------------------------------------
    */

    private function calculatePercentage(
        TariffRule $rule,
        array $values,
    ): float {
        if (
            $rule->base_field_id === null
            || trim((string) $rule->base_field_id) === ''
        ) {
            throw new RuntimeException(
                'PERCENTAGE tariff requires base_field_id.',
            );
        }

        if ($rule->percentage === null) {
            throw new RuntimeException(
                'PERCENTAGE tariff requires percentage.',
            );
        }

        $base = $this->requireNumericValue(
            $values,
            (string) $rule->base_field_id,
        );

        return $base * ((float) $rule->percentage / 100);
    }

    /*
    |--------------------------------------------------------------------------
    | PER UNIT
    |--------------------------------------------------------------------------
    */

    private function calculatePerUnit(
        TariffRule $rule,
        array $values,
    ): float {
        if (
            $rule->base_field_id === null
            || trim((string) $rule->base_field_id) === ''
        ) {
            throw new RuntimeException(
                'PER_UNIT tariff requires base_field_id.',
            );
        }

        if ($rule->amount === null) {
            throw new RuntimeException(
                'PER_UNIT tariff requires amount.',
            );
        }

        $quantity = $this->requireNumericValue(
            $values,
            (string) $rule->base_field_id,
        );

        return $quantity * (float) $rule->amount;
    }

    /*
    |--------------------------------------------------------------------------
    | RANGE
    |--------------------------------------------------------------------------
    |
    | TariffResolver selects the matching RANGE rule.
    |
    */

    private function calculateRange(
        TariffRule $rule,
        array $values,
    ): float {
        if (
            $rule->base_field_id === null
            || trim((string) $rule->base_field_id) === ''
        ) {
            throw new RuntimeException(
                'RANGE tariff requires base_field_id.',
            );
        }

        if ($rule->amount === null) {
            throw new RuntimeException(
                'RANGE tariff requires amount.',
            );
        }

        $value = $this->requireNumericValue(
            $values,
            (string) $rule->base_field_id,
        );

        if (
            $rule->min_value !== null
            && $value < (float) $rule->min_value
        ) {
            throw new RuntimeException(
                sprintf(
                    'Value %s is below the configured tariff range minimum of %s.',
                    $value,
                    $rule->min_value,
                ),
            );
        }

        if (
            $rule->max_value !== null
            && $value > (float) $rule->max_value
        ) {
            throw new RuntimeException(
                sprintf(
                    'Value %s exceeds the configured tariff range maximum of %s.',
                    $value,
                    $rule->max_value,
                ),
            );
        }

        return (float) $rule->amount;
    }

    /*
    |--------------------------------------------------------------------------
    | FORMULA
    |--------------------------------------------------------------------------
    */

    private function calculateFormula(
        TariffRule $rule,
        array $values,
    ): float {
        if (
            $rule->formula === null
            || trim((string) $rule->formula) === ''
        ) {
            throw new RuntimeException(
                'FORMULA tariff requires formula.',
            );
        }

        Log::debug(
            'Delegating FORMULA tariff calculation to formula engine.',
            [
                'tariff_rule_id' => $rule->id,
                'formula' => $rule->formula,
            ],
        );

        $amount = $this->formulaEngine->calculate(
            rule: $rule,
            values: $values,
        );

        $this->validateFiniteAmount(
            $amount,
            sprintf(
                'Formula calculation for tariff rule %s produced an invalid amount.',
                $rule->id,
            ),
        );

        Log::debug(
            'FORMULA tariff calculation completed.',
            [
                'tariff_rule_id' => $rule->id,
                'amount' => $amount,
            ],
        );

        return $amount;
    }

    /*
    |--------------------------------------------------------------------------
    | BUILD ASSESSMENT VALUE MAP
    |--------------------------------------------------------------------------
    |
    | Converts AssessmentServiceValue records into:
    |
    | BaseField UUID => normalized value
    |
    */

    private function buildValueMap(
        AssessmentService $assessmentService,
    ): array {
        $values = [];

        foreach ($assessmentService->values ?? [] as $assessmentValue) {
            /*
            |--------------------------------------------------------------------------
            | Direct field_id support
            |--------------------------------------------------------------------------
            */

            if (! empty($assessmentValue->field_id)) {
                $values[$assessmentValue->field_id] =
                    $this->normalizeValue(
                        $assessmentValue->value,
                    );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Current schema: revenue_service_field_id
            |--------------------------------------------------------------------------
            */

            if (empty($assessmentValue->revenue_service_field_id)) {
                continue;
            }

            $revenueServiceField =
                $assessmentValue->revenueServiceField;

            if (! $revenueServiceField) {
                $revenueServiceField =
                    \App\Models\RevenueServiceField::query()
                        ->with('baseField')
                        ->find(
                            $assessmentValue->revenue_service_field_id,
                        );
            }

            if (! $revenueServiceField) {
                continue;
            }

            $baseFieldId = $revenueServiceField->base_field_id ?? null;

            if (empty($baseFieldId)) {
                continue;
            }

            $values[$baseFieldId] =
                $this->normalizeValue(
                    $assessmentValue->value,
                );
        }

        return $values;
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE VALUE
    |--------------------------------------------------------------------------
    */

    private function normalizeValue(
        mixed $value,
    ): mixed {
        if ($value === null || is_bool($value)) {
            return $value;
        }

        if (
            is_int($value)
            || is_float($value)
            || (
                is_string($value)
                && is_numeric(trim($value))
            )
        ) {
            return (float) $value;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(
                json_encode($value),
                true,
            );
        }

        return trim((string) $value);
    }

    /*
    |--------------------------------------------------------------------------
    | REQUIRED NUMERIC VALUE
    |--------------------------------------------------------------------------
    */

    private function requireNumericValue(
        array $values,
        string $fieldId,
    ): float {
        $fieldId = trim($fieldId);

        if (
            $fieldId === ''
            || ! array_key_exists($fieldId, $values)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Required tariff field %s was not provided.',
                    $fieldId,
                ),
            );
        }

        $value = $values[$fieldId];

        if (
            $value === null
            || $value === ''
            || ! is_numeric($value)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Tariff field %s must contain a numeric value.',
                    $fieldId,
                ),
            );
        }

        $numericValue = (float) $value;

        if (! is_finite($numericValue)) {
            throw new RuntimeException(
                sprintf(
                    'Tariff field %s must contain a finite numeric value.',
                    $fieldId,
                ),
            );
        }

        return $numericValue;
    }

    /*
    |--------------------------------------------------------------------------
    | FINITE AMOUNT VALIDATION
    |--------------------------------------------------------------------------
    */

    private function validateFiniteAmount(
        float $amount,
        string $message,
    ): void {
        if (! is_finite($amount)) {
            throw new RuntimeException($message);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | MINIMUM / MAXIMUM
    |--------------------------------------------------------------------------
    */

    private function applyLimits(
        float $amount,
        TariffRule $rule,
    ): float {
        if ($rule->minimum_amount !== null) {
            $amount = max(
                $amount,
                (float) $rule->minimum_amount,
            );
        }

        if ($rule->maximum_amount !== null) {
            $amount = min(
                $amount,
                (float) $rule->maximum_amount,
            );
        }

        return $amount;
    }

    /*
    |--------------------------------------------------------------------------
    | ROUNDING
    |--------------------------------------------------------------------------
    */

    private function applyRounding(
        float $amount,
        TariffRule $rule,
    ): float {
        return match (
            strtoupper(trim((string) $rule->rounding_rule))
        ) {
            'ROUND_UP' => ceil($amount),

            'ROUND_DOWN' => floor($amount),

            'NEAREST' => round($amount),

            'NONE', '' => $amount,

            default => throw new RuntimeException(
                sprintf(
                    'Unsupported rounding rule: %s',
                    $rule->rounding_rule,
                ),
            ),
        };
    }
}
