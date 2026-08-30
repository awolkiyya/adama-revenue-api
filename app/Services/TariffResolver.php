<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentService;
use App\Models\BaseField;
use App\Models\TariffRule;
use App\Models\TariffVersion;
use Carbon\Carbon;
use RuntimeException;

class TariffResolver
{
    /**
     * ================================================================
     * RESOLVE TARIFF VERSION
     * ================================================================
     */
    public function resolveVersion(
        AssessmentService $assessmentService,
    ): TariffVersion {

        $assessment = $assessmentService->assessment;

        if (!$assessment instanceof Assessment) {
            throw new RuntimeException(
                sprintf(
                    'Assessment service [%s] does not belong to a valid assessment.',
                    $assessmentService->id,
                )
            );
        }

        $date = Carbon::parse(
            $assessment->assessment_date
                ?? $assessment->created_at
        );

        $version = TariffVersion::query()
            ->where('is_active', true)
            ->where('is_approved', true)
            ->whereDate(
                'effective_from',
                '<=',
                $date,
            )
            ->where(function ($query) use ($date) {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate(
                        'effective_to',
                        '>=',
                        $date,
                    );
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();

        if (!$version) {

            $availableVersions = TariffVersion::query()
                ->where('year', $date->year)
                ->orderByDesc('effective_from')
                ->orderByDesc('version')
                ->get([
                    'id',
                    'year',
                    'version',
                    'name',
                    'effective_from',
                    'effective_to',
                    'is_active',
                    'is_approved',
                ]);

            if ($availableVersions->isEmpty()) {
                throw new RuntimeException(
                    sprintf(
                        'No tariff version exists for assessment date %s. Create a tariff version for year %d.',
                        $date->toDateString(),
                        $date->year,
                    )
                );
            }

            $diagnostics = $availableVersions
                ->map(function ($item) {
                    return sprintf(
                        'v%s (%s): effective %s to %s, active=%s, approved=%s',
                        $item->version,
                        $item->name,
                        $item->effective_from,
                        $item->effective_to ?? 'OPEN',
                        $item->is_active ? 'YES' : 'NO',
                        $item->is_approved ? 'YES' : 'NO',
                    );
                })
                ->implode('; ');

            throw new RuntimeException(
                sprintf(
                    'No active approved tariff version found for %s. Available versions for %d: %s',
                    $date->toDateString(),
                    $date->year,
                    $diagnostics,
                )
            );
        }

        return $version;
    }

    /**
     * ================================================================
     * RESOLVE TARIFF RULE
     * ================================================================
     */
    public function resolveRule(
        TariffVersion $version,
        AssessmentService $assessmentService,
    ): TariffRule {

        $assessmentService->loadMissing([
            'values',
        ]);

        $rules = TariffRule::query()
            ->where(
                'tariff_version_id',
                $version->id,
            )
            ->where(
                'service_id',
                $assessmentService->service_id,
            )
            ->where(
                'is_active',
                true,
            )
            ->orderBy('priority')
            ->orderBy('execution_order')
            ->get();

        if ($rules->isEmpty()) {
            throw new RuntimeException(
                sprintf(
                    'No active tariff rule found for service %s in tariff version %s.',
                    $assessmentService->service_id,
                    $version->id,
                )
            );
        }

        foreach ($rules as $rule) {

            if (
                $this->ruleMatches(
                    $rule,
                    $assessmentService,
                )
            ) {
                return $rule;
            }
        }

        $diagnostics = $rules
            ->map(function (TariffRule $rule) use ($assessmentService) {

                $conditions = $this->normalizeConditions(
                    $rule->conditions
                );

                $conditionResults = [];

                foreach ($conditions as $condition) {

                    try {

                        $fieldReference = $this->getConditionFieldReference(
                            $condition
                        );

                        $resolvedField = $this->resolveFieldReference(
                            $fieldReference
                        );

                        $assessmentValue = $this->findAssessmentValue(
                            $assessmentService,
                            $fieldReference,
                        );

                        $conditionResults[] = [
                            'field_reference' => $fieldReference,
                            'resolved_code' => $resolvedField['code'] ?? null,
                            'resolved_type' => $resolvedField['type'] ?? null,
                            'operator' => $condition['operator'] ?? null,
                            'expected' => $condition['value'] ?? null,
                            'actual' => $assessmentValue
                                ? $assessmentValue->value
                                : null,
                            'field_found' => $assessmentValue !== null,
                        ];

                    } catch (\Throwable $e) {

                        $conditionResults[] = [
                            'error' => $e->getMessage(),
                            'condition' => $condition,
                        ];
                    }
                }

                return sprintf(
                    'rule=%s type=%s base_field=%s min=%s max=%s conditions=%s condition_results=%s',
                    $rule->id,
                    $rule->calculation_type,
                    $rule->base_field_id ?? 'NULL',
                    $rule->min_value ?? 'NULL',
                    $rule->max_value ?? 'NULL',
                    json_encode($conditions),
                    json_encode($conditionResults),
                );
            })
            ->implode('; ');

        throw new RuntimeException(
            sprintf(
                'No matching tariff rule found for service %s in tariff version %s. Rules checked: %s',
                $assessmentService->service_id,
                $version->id,
                $diagnostics,
            )
        );
    }

    /**
     * ================================================================
     * RULE MATCHING
     * ================================================================
     */
    private function ruleMatches(
        TariffRule $rule,
        AssessmentService $assessmentService,
    ): bool {

        $calculationType = strtoupper(
            trim(
                (string) $rule->calculation_type
            )
        );

        $conditions = $this->normalizeConditions(
            $rule->conditions
        );

        /*
         * Every condition must match.
         */
        if (!empty($conditions)) {

            if (!$this->matchesConditions(
                $conditions,
                $assessmentService,
            )) {
                return false;
            }
        }

        /*
         * RANGE requires range matching.
         */
        if ($calculationType === 'RANGE') {

            return $this->matchesRange(
                $rule,
                $assessmentService,
            );
        }

        return true;
    }

    /**
     * ================================================================
     * RANGE MATCHING
     * ================================================================
     */
    private function matchesRange(
        TariffRule $rule,
        AssessmentService $assessmentService,
    ): bool {

        if (
            $rule->base_field_id === null
            ||
            trim((string) $rule->base_field_id) === ''
        ) {

            throw new RuntimeException(
                sprintf(
                    'Range tariff rule %s does not define a base field.',
                    $rule->id,
                )
            );
        }

        /*
         * Resolve base_field_id.
         *
         * Example:
         *
         * 23388827... -> LAND_AREA
         */
        $resolvedField = $this->resolveFieldReference(
            $rule->base_field_id
        );

        $value = $this->findAssessmentValue(
            $assessmentService,
            $rule->base_field_id,
        );

        if (!$value) {
            return false;
        }

        $numericValue = $this->numericValue(
            $value->value
        );

        if ($numericValue === null) {
            return false;
        }

        if (
            $rule->min_value !== null
            &&
            $numericValue < (float) $rule->min_value
        ) {
            return false;
        }

        if (
            $rule->max_value !== null
            &&
            $numericValue > (float) $rule->max_value
        ) {
            return false;
        }

        return true;
    }

    /**
     * ================================================================
     * MATCH CONDITIONS
     * ================================================================
     */
    private function matchesConditions(
        array $conditions,
        AssessmentService $assessmentService,
    ): bool {

        foreach ($conditions as $condition) {

            if (!is_array($condition)) {
                throw new RuntimeException(
                    'Invalid tariff rule condition.'
                );
            }

            $fieldReference = $this->getConditionFieldReference(
                $condition
            );

            $operator = strtolower(
                trim(
                    (string) ($condition['operator'] ?? '')
                )
            );

            if (
                trim((string) $fieldReference) === ''
                ||
                $operator === ''
            ) {

                throw new RuntimeException(
                    sprintf(
                        'Invalid tariff rule condition: %s',
                        json_encode($condition),
                    )
                );
            }

            $assessmentValue = $this->findAssessmentValue(
                $assessmentService,
                $fieldReference,
            );

            if (!$assessmentValue) {
                return false;
            }

            $actual = $this->normalizeComparableValue(
                $assessmentValue->value
            );

            $expected = $this->normalizeComparableValue(
                $condition['value'] ?? null
            );

            $matches = match ($operator) {

                'equals',
                '=' =>
                    $this->valuesEqual(
                        $actual,
                        $expected,
                    ),

                'not_equals',
                '!=' =>
                    !$this->valuesEqual(
                        $actual,
                        $expected,
                    ),

                'greater_than',
                '>' =>
                    $this->compareNumeric(
                        $actual,
                        $expected,
                        '>',
                    ),

                'greater_than_or_equal',
                '>=' =>
                    $this->compareNumeric(
                        $actual,
                        $expected,
                        '>=',
                    ),

                'less_than',
                '<' =>
                    $this->compareNumeric(
                        $actual,
                        $expected,
                        '<',
                    ),

                'less_than_or_equal',
                '<=' =>
                    $this->compareNumeric(
                        $actual,
                        $expected,
                        '<=',
                    ),

                'in' =>
                    $this->valueIn(
                        $actual,
                        $expected,
                    ),

                'not_in' =>
                    !$this->valueIn(
                        $actual,
                        $expected,
                    ),

                'contains' =>
                    $this->contains(
                        $actual,
                        $expected,
                    ),

                'not_contains' =>
                    !$this->contains(
                        $actual,
                        $expected,
                    ),

                'starts_with' =>
                    $this->startsWith(
                        $actual,
                        $expected,
                    ),

                'ends_with' =>
                    $this->endsWith(
                        $actual,
                        $expected,
                    ),

                default =>
                    throw new RuntimeException(
                        sprintf(
                            'Unsupported tariff condition operator: %s',
                            $operator,
                        )
                    ),
            };

            if (!$matches) {
                return false;
            }
        }

        return true;
    }

    /**
     * ================================================================
     * GET CONDITION FIELD REFERENCE
     * ================================================================
     *
     * Supports:
     *
     * fieldId
     * field_id
     * field
     * field_code
     */
    private function getConditionFieldReference(
        array $condition,
    ): string {

        $reference =
            $condition['fieldId']
            ?? $condition['field_id']
            ?? $condition['field']
            ?? $condition['field_code']
            ?? null;

        if ($reference === null) {

            throw new RuntimeException(
                sprintf(
                    'Tariff condition does not contain a field reference: %s',
                    json_encode($condition),
                )
            );
        }

        return trim(
            (string) $reference
        );
    }

    /**
     * ================================================================
     * RESOLVE FIELD REFERENCE
     * ================================================================
     *
     * Determines what a field reference represents.
     *
     * Possible references:
     *
     * 1. BaseField UUID
     * 2. RevenueServiceField UUID
     * 3. field code
     *
     * Example:
     *
     * 23388827... -> BaseField -> LAND_AREA
     */
    private function resolveFieldReference(
        string $reference,
    ): array {

        $reference = trim($reference);

        /*
         * BaseField ID
         */
        $baseField = BaseField::query()
            ->where('id', $reference)
            ->first();

        if ($baseField) {

            return [
                'type' => 'base_field',
                'id' => $baseField->id,
                'code' => strtoupper(
                    trim(
                        $baseField->code
                    )
                ),
            ];
        }

        /*
         * Otherwise treat it as a code.
         */
        return [
            'type' => 'code',
            'id' => null,
            'code' => strtoupper($reference),
        ];
    }

    /**
     * ================================================================
     * FIND ASSESSMENT VALUE
     * ================================================================
     *
     * Matching order:
     *
     * 1. revenue_service_field_id
     * 2. BaseField ID -> field_code
     * 3. field_code
     */
    private function findAssessmentValue(
        AssessmentService $assessmentService,
        string $fieldReference,
    ): mixed {

        $reference = trim($fieldReference);

        /*
         * Direct ID comparison first.
         *
         * This supports:
         *
         * revenue_service_field_id
         */
        $value = $assessmentService->values->first(
            function ($value) use ($reference) {

                return
                    !empty(
                        $value->revenue_service_field_id
                    )
                    &&
                    strcasecmp(
                        trim(
                            (string)
                            $value->revenue_service_field_id
                        ),
                        $reference,
                    ) === 0;
            }
        );

        if ($value) {
            return $value;
        }

        /*
         * Resolve BaseField UUID.
         */
        $resolved = $this->resolveFieldReference(
            $reference
        );

        $code = $resolved['code'];

        /*
         * Match field_code.
         */
        return $assessmentService->values->first(
            function ($value) use ($code) {

                return
                    !empty(
                        $value->field_code
                    )
                    &&
                    strcasecmp(
                        trim(
                            (string)
                            $value->field_code
                        ),
                        $code,
                    ) === 0;
            }
        );
    }

    /**
     * ================================================================
     * NORMALIZE CONDITIONS
     * ================================================================
     */
    private function normalizeConditions(
        mixed $conditions,
    ): array {

        if ($conditions === null) {
            return [];
        }

        if (is_array($conditions)) {

            if (
                isset($conditions['operator'])
                &&
                (
                    isset($conditions['field'])
                    ||
                    isset($conditions['fieldId'])
                    ||
                    isset($conditions['field_id'])
                    ||
                    isset($conditions['field_code'])
                )
            ) {

                return [
                    $conditions,
                ];
            }

            return array_values($conditions);
        }

        if (is_string($conditions)) {

            $conditions = trim($conditions);

            if ($conditions === '') {
                return [];
            }

            $decoded = json_decode(
                $conditions,
                true,
            );

            if (
                json_last_error()
                !==
                JSON_ERROR_NONE
            ) {

                throw new RuntimeException(
                    'Tariff rule conditions contain invalid JSON.'
                );
            }

            if ($decoded === null) {
                return [];
            }

            if (
                is_array($decoded)
                &&
                isset($decoded['operator'])
                &&
                (
                    isset($decoded['field'])
                    ||
                    isset($decoded['fieldId'])
                    ||
                    isset($decoded['field_id'])
                    ||
                    isset($decoded['field_code'])
                )
            ) {

                return [
                    $decoded,
                ];
            }

            if (is_array($decoded)) {
                return array_values($decoded);
            }
        }

        throw new RuntimeException(
            'Tariff rule conditions must be an array or valid JSON.'
        );
    }

    /**
     * ================================================================
     * NUMERIC VALUE
     * ================================================================
     */
    private function numericValue(
        mixed $value,
    ): ?float {

        if (
            $value === null
            ||
            $value === ''
        ) {
            return null;
        }

        if (
            is_int($value)
            ||
            is_float($value)
            ||
            is_string($value)
        ) {

            $value = trim(
                (string) $value
            );

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * ================================================================
     * NORMALIZE COMPARABLE VALUE
     * ================================================================
     */
    private function normalizeComparableValue(
        mixed $value,
    ): mixed {

        if ($value === null) {
            return null;
        }

        if (
            is_bool($value)
            ||
            is_int($value)
            ||
            is_float($value)
        ) {
            return $value;
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_object($value)) {

            $value = json_decode(
                json_encode($value),
                true,
            );
        }

        if (is_array($value)) {
            return $value;
        }

        return $value;
    }

    /**
     * ================================================================
     * VALUES EQUAL
     * ================================================================
     */
    private function valuesEqual(
        mixed $actual,
        mixed $expected,
    ): bool {

        if (
            is_numeric($actual)
            &&
            is_numeric($expected)
        ) {

            return
                (float) $actual
                ===
                (float) $expected;
        }

        if (
            is_bool($actual)
            ||
            is_bool($expected)
        ) {

            return
                (bool) $actual
                ===
                (bool) $expected;
        }

        if (
            is_array($actual)
            ||
            is_array($expected)
        ) {

            return $actual == $expected;
        }

        return
            strtolower(
                trim(
                    (string) $actual
                )
            )
            ===
            strtolower(
                trim(
                    (string) $expected
                )
            );
    }

    /**
     * ================================================================
     * NUMERIC COMPARISON
     * ================================================================
     */
    private function compareNumeric(
        mixed $actual,
        mixed $expected,
        string $operator,
    ): bool {

        if (
            !is_numeric($actual)
            ||
            !is_numeric($expected)
        ) {
            return false;
        }

        $actualValue = (float) $actual;
        $expectedValue = (float) $expected;

        return match ($operator) {

            '>' =>
                $actualValue > $expectedValue,

            '>=' =>
                $actualValue >= $expectedValue,

            '<' =>
                $actualValue < $expectedValue,

            '<=' =>
                $actualValue <= $expectedValue,

            default =>
                throw new RuntimeException(
                    "Unsupported numeric comparison operator [{$operator}]."
                ),
        };
    }

    /**
     * ================================================================
     * VALUE IN ARRAY
     * ================================================================
     */
    private function valueIn(
        mixed $actual,
        mixed $expected,
    ): bool {

        if (!is_array($expected)) {
            return false;
        }

        foreach ($expected as $item) {

            if (
                $this->valuesEqual(
                    $actual,
                    $item,
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * ================================================================
     * CONTAINS
     * ================================================================
     */
    private function contains(
        mixed $actual,
        mixed $expected,
    ): bool {

        if (
            $actual === null
            ||
            $expected === null
        ) {
            return false;
        }

        if (is_array($actual)) {

            foreach ($actual as $item) {

                if (
                    $this->contains(
                        $item,
                        $expected,
                    )
                ) {
                    return true;
                }
            }

            return false;
        }

        return str_contains(
            strtolower(
                trim(
                    (string) $actual
                )
            ),
            strtolower(
                trim(
                    (string) $expected
                )
            ),
        );
    }

    /**
     * ================================================================
     * STARTS WITH
     * ================================================================
     */
    private function startsWith(
        mixed $actual,
        mixed $expected,
    ): bool {

        if (
            $actual === null
            ||
            $expected === null
        ) {
            return false;
        }

        return str_starts_with(
            strtolower(
                trim(
                    (string) $actual
                )
            ),
            strtolower(
                trim(
                    (string) $expected
                )
            ),
        );
    }

    /**
     * ================================================================
     * ENDS WITH
     * ================================================================
     */
    private function endsWith(
        mixed $actual,
        mixed $expected,
    ): bool {

        if (
            $actual === null
            ||
            $expected === null
        ) {
            return false;
        }

        return str_ends_with(
            strtolower(
                trim(
                    (string) $actual
                )
            ),
            strtolower(
                trim(
                    (string) $expected
                )
            ),
        );
    }
}
