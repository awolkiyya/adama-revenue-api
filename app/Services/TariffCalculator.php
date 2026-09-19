<?php

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
     * IMPORTANT:
     * -------------------------------------------------------------
     * This class ONLY calculates.
     *
     * It does NOT:
     * - update assessment status
     * - approve assessment
     * - reject assessment
     * - save database records
     * - choose tariff versions
     * - choose tariff rules
     *
     * Those responsibilities belong to other services.
     *
     * Calculation strategy:
     *
     *     FIXED       → internal fixed calculation
     *     PERCENTAGE  → percentage calculation
     *     PER_UNIT    → per-unit calculation
     *     RANGE       → range calculation
     *     FORMULA     → FormulaCalculationEngine
     */
    public function calculate(
        TariffRule $rule,
        AssessmentService $assessmentService,
    ): TariffCalculationResult {

        $calculationType = strtoupper(
            trim(
                (string) $rule->calculation_type
            )
        );

        try {

            Log::debug(
                'Starting tariff calculation.',
                [
                    'tariff_rule_id' =>
                        $rule->id,

                    'tariff_version_id' =>
                        $rule->tariff_version_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'calculation_type' =>
                        $calculationType,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Ensure assessment values are loaded
            |--------------------------------------------------------------------------
            */

            $assessmentService->loadMissing([
                'values',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Build Assessment Input Map
            |--------------------------------------------------------------------------
            |
            | The map is keyed by BaseField UUID.
            |
            | Example:
            |
            | [
            |     "23388827-cd82-4881-a812-c881f9774d1e" => 105,
            |     "5c37894c-ebc7-49aa-9e3c-7917e765c286" => "RESIDENTIAL",
            | ]
            |
            */

            $values = $this->buildValueMap(
                $assessmentService
            );

            Log::debug(
                'Assessment values prepared for tariff calculation.',
                [
                    'tariff_rule_id' =>
                        $rule->id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'input_value_count' =>
                        count($values),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Calculate
            |--------------------------------------------------------------------------
            */

            $amount = match ($calculationType) {

                'FIXED' =>
                    $this->calculateFixed(
                        $rule
                    ),

                'PERCENTAGE' =>
                    $this->calculatePercentage(
                        $rule,
                        $values
                    ),

                'PER_UNIT' =>
                    $this->calculatePerUnit(
                        $rule,
                        $values
                    ),

                'RANGE' =>
                    $this->calculateRange(
                        $rule,
                        $values
                    ),

                'FORMULA' =>
                    $this->calculateFormula(
                        $rule,
                        $values
                    ),

                default =>
                    throw new RuntimeException(
                        sprintf(
                            'Unsupported calculation type: %s',
                            $rule->calculation_type
                        )
                    ),
            };

            /*
            |--------------------------------------------------------------------------
            | Validate Result
            |--------------------------------------------------------------------------
            */

            if (!is_finite($amount)) {
                throw new RuntimeException(
                    'Tariff calculation produced an invalid amount.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Apply Minimum / Maximum
            |--------------------------------------------------------------------------
            */

            $amount = $this->applyLimits(
                $amount,
                $rule
            );

            /*
            |--------------------------------------------------------------------------
            | Apply Rounding
            |--------------------------------------------------------------------------
            */

            $amount = $this->applyRounding(
                $amount,
                $rule
            );

            /*
            |--------------------------------------------------------------------------
            | Final Validation
            |--------------------------------------------------------------------------
            */

            if (!is_finite($amount)) {
                throw new RuntimeException(
                    'Tariff calculation produced an invalid final amount.'
                );
            }

            if ($amount < 0) {
                throw new RuntimeException(
                    'Calculated tariff amount cannot be negative.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Successful Result
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Tariff calculation completed successfully.',
                [
                    'tariff_rule_id' =>
                        $rule->id,

                    'tariff_version_id' =>
                        $rule->tariff_version_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'calculation_type' =>
                        $calculationType,

                    'calculated_amount' =>
                        $amount,
                ]
            );

            return TariffCalculationResult::success(
                amount: $amount,

                metadata: [

                    'tariff_rule_id' =>
                        $rule->id,

                    'tariff_version_id' =>
                        $rule->tariff_version_id,

                    'calculation_type' =>
                        $rule->calculation_type,

                    'base_field_id' =>
                        $rule->base_field_id,

                    'measurement_unit_id' =>
                        $rule->measurement_unit_id,

                    'configured_amount' =>
                        $rule->amount,

                    'percentage' =>
                        $rule->percentage,

                    'minimum_amount' =>
                        $rule->minimum_amount,

                    'maximum_amount' =>
                        $rule->maximum_amount,

                    'rounding_rule' =>
                        $rule->rounding_rule,

                    'formula' =>
                        $rule->formula,

                    /*
                     * Keep the existing metadata behavior.
                     *
                     * NOTE:
                     * If these values contain sensitive taxpayer data,
                     * consider removing them from metadata and storing
                     * only field IDs/counts.
                     */
                    'inputs' =>
                        $values,
                ],
            );

        } catch (Throwable $e) {

            Log::error(
                'Tariff calculation failed.',
                [
                    'tariff_rule_id' =>
                        $rule->id,

                    'tariff_version_id' =>
                        $rule->tariff_version_id,

                    'assessment_service_id' =>
                        $assessmentService->id,

                    'calculation_type' =>
                        $calculationType,

                    'exception_class' =>
                        $e::class,

                    'exception_message' =>
                        $e->getMessage(),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Failed Result
            |--------------------------------------------------------------------------
            |
            | TariffCalculationResult::failed() accepts:
            |
            |     failed(string $error, array $metadata = [])
            |
            */

            return TariffCalculationResult::failed(
                error: $e->getMessage(),

                metadata: [

                    'tariff_version_id' =>
                        $rule->tariff_version_id,

                    'tariff_rule_id' =>
                        $rule->id,

                    'calculation_type' =>
                        $rule->calculation_type,

                    'base_field_id' =>
                        $rule->base_field_id,

                    'assessment_service_id' =>
                        $assessmentService->id,
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
                'FIXED tariff requires amount.'
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
            ||
            trim(
                (string) $rule->base_field_id
            ) === ''
        ) {
            throw new RuntimeException(
                'PERCENTAGE tariff requires base_field_id.'
            );
        }

        if ($rule->percentage === null) {
            throw new RuntimeException(
                'PERCENTAGE tariff requires percentage.'
            );
        }

        $base = $this->requireNumericValue(
            $values,
            $rule->base_field_id
        );

        return $base *
            ((float) $rule->percentage / 100);
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
            ||
            trim(
                (string) $rule->base_field_id
            ) === ''
        ) {
            throw new RuntimeException(
                'PER_UNIT tariff requires base_field_id.'
            );
        }

        if ($rule->amount === null) {
            throw new RuntimeException(
                'PER_UNIT tariff requires amount.'
            );
        }

        $quantity = $this->requireNumericValue(
            $values,
            $rule->base_field_id
        );

        return $quantity *
            (float) $rule->amount;
    }

    /*
    |--------------------------------------------------------------------------
    | RANGE
    |--------------------------------------------------------------------------
    |
    | TariffResolver is responsible for selecting the matching
    | RANGE rule.
    |
    | This method validates that the selected rule accepts the
    | assessment value and returns its configured amount.
    */

    private function calculateRange(
        TariffRule $rule,
        array $values,
    ): float {

        if (
            $rule->base_field_id === null
            ||
            trim(
                (string) $rule->base_field_id
            ) === ''
        ) {
            throw new RuntimeException(
                'RANGE tariff requires base_field_id.'
            );
        }

        if ($rule->amount === null) {
            throw new RuntimeException(
                'RANGE tariff requires amount.'
            );
        }

        $value = $this->requireNumericValue(
            $values,
            $rule->base_field_id
        );

        /*
        |--------------------------------------------------------------------------
        | Minimum
        |--------------------------------------------------------------------------
        */

        if (
            $rule->min_value !== null
            &&
            $value < (float) $rule->min_value
        ) {
            throw new RuntimeException(
                sprintf(
                    'Value %s is below the configured tariff range minimum of %s.',
                    $value,
                    $rule->min_value
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Maximum
        |--------------------------------------------------------------------------
        */

        if (
            $rule->max_value !== null
            &&
            $value > (float) $rule->max_value
        ) {
            throw new RuntimeException(
                sprintf(
                    'Value %s exceeds the configured tariff range maximum of %s.',
                    $value,
                    $rule->max_value
                )
            );
        }

        return (float) $rule->amount;
    }

    /*
    |--------------------------------------------------------------------------
    | FORMULA
    |--------------------------------------------------------------------------
    |
    | Delegates FORMULA evaluation to FormulaCalculationEngine.
    |
    | IMPORTANT:
    |
    | Never use eval().
    |
    | Example:
    |
    |     LAND_AREA * RATE * LIZZ_PERIOD
    |
    | Formula variables:
    |
    |     LAND_AREA   → BASE_FIELD
    |     RATE        → CONSTANT = 3.70
    |     LIZZ_PERIOD → BASE_FIELD
    |
    */

    private function calculateFormula(
        TariffRule $rule,
        array $values,
    ): float {

        if (
            $rule->formula === null
            ||
            trim(
                (string) $rule->formula
            ) === ''
        ) {
            throw new RuntimeException(
                'FORMULA tariff requires formula.'
            );
        }

        Log::debug(
            'Delegating FORMULA tariff calculation to formula engine.',
            [
                'tariff_rule_id' =>
                    $rule->id,

                'formula' =>
                    $rule->formula,
            ]
        );

        $amount = $this->formulaEngine->calculate(
            rule: $rule,
            values: $values,
        );

        if (!is_finite($amount)) {
            throw new RuntimeException(
                sprintf(
                    'Formula calculation for tariff rule %s produced an invalid amount.',
                    $rule->id
                )
            );
        }

        Log::debug(
            'FORMULA tariff calculation completed.',
            [
                'tariff_rule_id' =>
                    $rule->id,

                'amount' =>
                    $amount,
            ]
        );

        return $amount;
    }

    /*
    |--------------------------------------------------------------------------
    | BUILD VALUE MAP
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | AssessmentServiceValue currently stores:
    |
    |     revenue_service_field_id
    |
    | while TariffRule / TariffFormulaVariable stores:
    |
    |     base_field_id
    |
    | Therefore:
    |
    |     assessment value
    |          ↓
    |     revenue service field
    |          ↓
    |     base field
    |          ↓
    |     base field ID
    |
    | Example:
    |
    |     Assessment value:
    |
    |     revenue_service_field_id =
    |     019ff640-101e-708a-9c85-132b78c8b59c
    |
    |     RevenueServiceField:
    |
    |     base_field_id =
    |     23388827-cd82-4881-a812-c881f9774d1e
    |
    |     BaseField:
    |
    |     code = LAND_AREA
    |
    | Result:
    |
    |     [
    |         '23388827-cd82-4881-a812-c881f9774d1e' => 500
    |     ]
    */

    private function buildValueMap(
        AssessmentService $assessmentService,
    ): array {

        $values = [];

        foreach (
            $assessmentService->values ?? []
            as $assessmentValue
        ) {

            /*
            |--------------------------------------------------------------------------
            | Direct field_id support
            |--------------------------------------------------------------------------
            */

            if (
                !empty(
                    $assessmentValue->field_id
                )
            ) {

                $values[
                    $assessmentValue->field_id
                ] =
                    $this->normalizeValue(
                        $assessmentValue->value
                    );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Current schema:
            |
            | revenue_service_field_id
            |--------------------------------------------------------------------------
            */

            if (
                empty(
                    $assessmentValue->revenue_service_field_id
                )
            ) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve RevenueServiceField
            |--------------------------------------------------------------------------
            */

            $revenueServiceField =
                $assessmentValue->revenueServiceField;

            if (!$revenueServiceField) {

                $revenueServiceField =
                    \App\Models\RevenueServiceField::query()
                        ->with('baseField')
                        ->find(
                            $assessmentValue
                                ->revenue_service_field_id
                        );
            }

            if (!$revenueServiceField) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve BaseField ID
            |--------------------------------------------------------------------------
            */

            $baseFieldId =
                $revenueServiceField->base_field_id
                ?? null;

            if (
                empty($baseFieldId)
            ) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Store by BaseField UUID
            |--------------------------------------------------------------------------
            */

            $values[$baseFieldId] =
                $this->normalizeValue(
                    $assessmentValue->value
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

        if ($value === null) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Boolean
        |--------------------------------------------------------------------------
        */

        if (is_bool($value)) {
            return $value;
        }

        /*
        |--------------------------------------------------------------------------
        | Numeric
        |--------------------------------------------------------------------------
        */

        if (
            is_int($value)
            ||
            is_float($value)
            ||
            (
                is_string($value)
                &&
                is_numeric(
                    trim($value)
                )
            )
        ) {
            return (float) $value;
        }

        /*
        |--------------------------------------------------------------------------
        | Array / JSON
        |--------------------------------------------------------------------------
        */

        if (is_array($value)) {
            return $value;
        }

        /*
        |--------------------------------------------------------------------------
        | Object
        |--------------------------------------------------------------------------
        */

        if (is_object($value)) {

            return json_decode(
                json_encode($value),
                true,
            );
        }

        /*
        |--------------------------------------------------------------------------
        | String
        |--------------------------------------------------------------------------
        */

        return trim(
            (string) $value
        );
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

        $fieldId =
            trim($fieldId);

        if (
            $fieldId === ''
            ||
            !array_key_exists(
                $fieldId,
                $values
            )
        ) {
            throw new RuntimeException(
                sprintf(
                    'Required assessment field %s was not provided.',
                    $fieldId
                )
            );
        }

        $value =
            $values[$fieldId];

        if (
            $value === null
            ||
            $value === ''
            ||
            !is_numeric($value)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Assessment field %s must contain a numeric value.',
                    $fieldId
                )
            );
        }

        return (float) $value;
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

        if (
            $rule->minimum_amount !== null
        ) {

            $amount = max(
                $amount,
                (float) $rule->minimum_amount
            );
        }

        if (
            $rule->maximum_amount !== null
        ) {

            $amount = min(
                $amount,
                (float) $rule->maximum_amount
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
            strtoupper(
                trim(
                    (string) $rule->rounding_rule
                )
            )
        ) {

            'ROUND_UP' =>
                ceil($amount),

            'ROUND_DOWN' =>
                floor($amount),

            'NEAREST' =>
                round($amount),

            'NONE',
            '' =>
                $amount,

            default =>
                throw new RuntimeException(
                    sprintf(
                        'Unsupported rounding rule: %s',
                        $rule->rounding_rule
                    )
                ),
        };
    }
}
