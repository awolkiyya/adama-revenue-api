<?php

namespace App\Services;

use Andegna\DateTimeFactory;
use App\Models\Assessment;
use App\Models\AssessmentService;
use App\Models\BaseField;
use App\Models\InterestRule;
use App\Models\PenaltyRule;
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
     *
     * Tariff version year:
     *
     *     Ethiopian calendar
     *
     * Tariff effective dates:
     *
     *     Gregorian database dates
     *
     * Example:
     *
     *     Gregorian: 2026-09-12
     *     Ethiopian:  2019-01-02
     *     Tariff year: 2019
     *
     * There is NO tariff approval workflow.
     *
     * A tariff version is eligible when:
     *
     *     - Ethiopian year matches
     *     - is_active = true
     *     - effective_from <= assessment date
     *     - effective_to is NULL or >= assessment date
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

        /*
         * Assessment dates are stored as Gregorian dates.
         */
        $date = Carbon::parse(
            $assessment->assessment_date
                ?? $assessment->created_at
        )->startOfDay();

        /*
         * Convert Gregorian date to Ethiopian year.
         */
        $ethiopianYear = $this->getEthiopianYear($date);

        /*
         * Resolve applicable tariff version.
         *
         * IMPORTANT:
         *
         * - year = Ethiopian year
         * - effective_from = Gregorian
         * - effective_to = Gregorian
         * - is_active = true
         * - NO approval check
         */
        $version = TariffVersion::query()
            ->where(
                'year',
                $ethiopianYear,
            )
            ->where(
                'is_active',
                true,
            )
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
            /*
             * Get all tariff versions belonging to this
             * Ethiopian tariff year for diagnostics.
             */
            $availableVersions = TariffVersion::query()
                ->where(
                    'year',
                    $ethiopianYear,
                )
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
                ]);

            if ($availableVersions->isEmpty()) {
                throw new RuntimeException(
                    sprintf(
                        'No tariff version exists for assessment date %s (Ethiopian year %d). Create a tariff version for Ethiopian year %d.',
                        $date->toDateString(),
                        $ethiopianYear,
                        $ethiopianYear,
                    )
                );
            }

            $diagnostics = $availableVersions
                ->map(function (TariffVersion $item) {
                    return sprintf(
                        'v%s (%s): effective %s to %s, active=%s',
                        $item->version,
                        $item->name,
                        $item->effective_from,
                        $item->effective_to ?? 'OPEN',
                        $item->is_active
                            ? 'YES'
                            : 'NO',
                    );
                })
                ->implode('; ');

            throw new RuntimeException(
                sprintf(
                    'No active tariff version found for %s (Ethiopian year %d). Available versions for Ethiopian year %d: %s',
                    $date->toDateString(),
                    $ethiopianYear,
                    $ethiopianYear,
                    $diagnostics,
                )
            );
        }

        return $version;
    }

    /**
     * ================================================================
     * GET ETHIOPIAN YEAR
     * ================================================================
     */
    private function getEthiopianYear(
        Carbon $date,
    ): int {
        $ethiopianDate = DateTimeFactory::fromDateTime(
            $date->toDateTime()
        );

        return (int) $ethiopianDate->getYear();
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

        /*
         * Build detailed diagnostics.
         */
        $diagnostics = $rules
            ->map(function (
                TariffRule $rule
            ) use (
                $assessmentService
            ) {
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
                    json_encode(
                        $conditions,
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                    ),
                    json_encode(
                        $conditionResults,
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                    ),
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
     * RESOLVE PENALTY RULE
     * ================================================================
     *
     * Penalty rules are global.
     *
     * They are NOT linked to a revenue service.
     *
     * Resolution is based on:
     *
     *     1. active status
     *     2. assessment date
     *     3. commencement strategy
     *
     * IMPORTANT:
     *
     * Your database intentionally permits:
     *
     *     FIXED_PAYMENT_DATE
     *     AGREEMENT_DATE
     *
     * to overlap.
     *
     * Therefore this method must not simply use:
     *
     *     ->first()
     *
     * across all active rules.
     *
     * The commencement strategy must be determined from the
     * assessment context.
     */
    public function resolvePenaltyRule(
        AssessmentService $assessmentService,
    ): PenaltyRule {
        $assessment = $assessmentService->assessment;

        if (!$assessment instanceof Assessment) {
            throw new RuntimeException(
                sprintf(
                    'Assessment service [%s] does not belong to a valid assessment.',
                    $assessmentService->id,
                )
            );
        }

        $assessmentDate = Carbon::parse(
            $assessment->assessment_date
                ?? $assessment->created_at
        )->startOfDay();

        /*
         * Load values because agreement-related configuration
         * may be stored on assessment service values.
         */
        $assessmentService->loadMissing([
            'values',
        ]);

        /*
         * Determine whether this assessment service has an
         * applicable agreement date.
         *
         * We intentionally inspect common agreement field names
         * rather than assuming a single BaseField UUID.
         */
        $hasAgreementDate = $this->hasAgreementDate(
            $assessmentService
        );

        /*
         * Build the candidate rule query.
         */
        $query = PenaltyRule::query()
            ->where(
                'is_active',
                true,
            )
            ->whereDate(
                'effective_from',
                '<=',
                $assessmentDate,
            )
            ->where(function ($query) use ($assessmentDate) {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate(
                        'effective_to',
                        '>=',
                        $assessmentDate,
                    );
            });

        /*
         * If an agreement date exists, use the agreement-date
         * penalty family.
         *
         * Otherwise use the municipality-wide payment-date family.
         */
        if ($hasAgreementDate) {
            $query->where(
                'start_type',
                PenaltyRule::START_TYPE_AGREEMENT_DATE,
            );
        } else {
            /*
             * IMPORTANT:
             *
             * The migration defines FIXED_PAYMENT_DATE.
             *
             * If your database/model is still using
             * FIXED_FISCAL_MONTH, normalize that separately.
             */
            $query->where(
                'start_type',
                'FIXED_PAYMENT_DATE',
            );
        }

        $rules = $query
            ->orderByDesc('effective_from')
            ->get();

        if ($rules->isEmpty()) {
            $startType = $hasAgreementDate
                ? PenaltyRule::START_TYPE_AGREEMENT_DATE
                : 'FIXED_PAYMENT_DATE';

            throw new RuntimeException(
                sprintf(
                    'No active penalty rule found for assessment service %s on %s using start type %s.',
                    $assessmentService->id,
                    $assessmentDate->toDateString(),
                    $startType,
                )
            );
        }

        /*
         * PostgreSQL already prevents overlapping ACTIVE rules
         * for the same start_type.
         *
         * Therefore normally only one rule can exist here.
         *
         * Keep this defensive check so application behaviour remains
         * deterministic even if database constraints are bypassed.
         */
        if ($rules->count() > 1) {
            $diagnostics = $rules
                ->map(function (PenaltyRule $rule) {
                    return sprintf(
                        '%s (%s): %s to %s, active=%s',
                        $rule->id,
                        $rule->name,
                        $rule->effective_from,
                        $rule->effective_to ?? 'OPEN',
                        $rule->is_active ? 'YES' : 'NO',
                    );
                })
                ->implode('; ');

            throw new RuntimeException(
                sprintf(
                    'Multiple active penalty rules matched assessment service %s on %s. Rules: %s',
                    $assessmentService->id,
                    $assessmentDate->toDateString(),
                    $diagnostics,
                )
            );
        }

        return $rules->first();
    }

    /**
     * ================================================================
     * RESOLVE INTEREST RULE
     * ================================================================
     *
     * Interest rules are global.
     *
     * They are not linked to a revenue service.
     *
     * The database prevents overlapping ACTIVE interest-rule
     * effective periods.
     *
     * Therefore the applicable rule is:
     *
     *     active
     *     AND
     *     effective on assessment date
     */
    public function resolveInterestRule(
        AssessmentService $assessmentService,
    ): InterestRule {
        $assessment = $assessmentService->assessment;

        if (!$assessment instanceof Assessment) {
            throw new RuntimeException(
                sprintf(
                    'Assessment service [%s] does not belong to a valid assessment.',
                    $assessmentService->id,
                )
            );
        }

        $assessmentDate = Carbon::parse(
            $assessment->assessment_date
                ?? $assessment->created_at
        )->startOfDay();

        $rules = InterestRule::query()
            ->where(
                'is_active',
                true,
            )
            ->whereDate(
                'effective_from',
                '<=',
                $assessmentDate,
            )
            ->where(function ($query) use ($assessmentDate) {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate(
                        'effective_to',
                        '>=',
                        $assessmentDate,
                    );
            })
            ->orderByDesc('effective_from')
            ->get();

        if ($rules->isEmpty()) {
            throw new RuntimeException(
                sprintf(
                    'No active interest rule found for assessment service %s on %s.',
                    $assessmentService->id,
                    $assessmentDate->toDateString(),
                )
            );
        }

        /*
         * The database exclusion constraint should guarantee
         * that only one active rule is effective on a date.
         *
         * Defensively reject ambiguity.
         */
        if ($rules->count() > 1) {
            $diagnostics = $rules
                ->map(function (InterestRule $rule) {
                    return sprintf(
                        '%s (%s%% %s): %s to %s, active=%s',
                        $rule->id,
                        $rule->rate,
                        $rule->rate_period,
                        $rule->effective_from,
                        $rule->effective_to ?? 'OPEN',
                        $rule->is_active ? 'YES' : 'NO',
                    );
                })
                ->implode('; ');

            throw new RuntimeException(
                sprintf(
                    'Multiple active interest rules matched assessment service %s on %s. Rules: %s',
                    $assessmentService->id,
                    $assessmentDate->toDateString(),
                    $diagnostics,
                )
            );
        }

        return $rules->first();
    }

    /**
     * ================================================================
     * AGREEMENT DATE DETECTION
     * ================================================================
     *
     * Looks for an agreement date in assessment service values.
     *
     * Supported field codes:
     *
     *     AGREEMENT_DATE
     *     AGREEMENT_SIGNING_DATE
     *     LIZZ_AGREEMENT_DATE
     *
     * This is intentionally based on field_code rather than a hard-coded
     * BaseField UUID.
     */
    private function hasAgreementDate(
        AssessmentService $assessmentService,
    ): bool {
        $assessmentService->loadMissing([
            'values',
        ]);

        $agreementCodes = [
            'AGREEMENT_DATE',
            'AGREEMENT_SIGNING_DATE',
            'LIZZ_AGREEMENT_DATE',
        ];

        foreach ($assessmentService->values as $value) {
            $fieldCode = strtoupper(
                trim(
                    (string) ($value->field_code ?? '')
                )
            );

            if (!in_array(
                $fieldCode,
                $agreementCodes,
                true
            )) {
                continue;
            }

            $rawValue = $value->value;

            if (
                $rawValue === null
                ||
                $rawValue === ''
            ) {
                continue;
            }

            /*
             * JSON date values may sometimes be represented as
             * arrays/objects. Only scalar values are relevant here.
             */
            if (
                is_array($rawValue)
                ||
                is_object($rawValue)
            ) {
                continue;
            }

            return trim(
                (string) $rawValue
            ) !== '';
        }

        return false;
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
         * All configured conditions must match.
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
         * RANGE rules additionally validate their base field.
         */
        if ($calculationType === 'RANGE') {
            return $this->matchesRange(
                $rule,
                $assessmentService,
            );
        }

        /*
         * Non-RANGE rules match once all conditions match.
         */
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

        /*
         * Minimum boundary.
         */
        if (
            $rule->min_value !== null
            &&
            $numericValue < (float) $rule->min_value
        ) {
            return false;
        }

        /*
         * Maximum boundary.
         */
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
                        json_encode(
                            $condition,
                            JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                        ),
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
                '=' => $this->valuesEqual(
                    $actual,
                    $expected,
                ),

                'not_equals',
                '!=' => !$this->valuesEqual(
                    $actual,
                    $expected,
                ),

                'greater_than',
                '>' => $this->compareNumeric(
                    $actual,
                    $expected,
                    '>',
                ),

                'greater_than_or_equal',
                '>=' => $this->compareNumeric(
                    $actual,
                    $expected,
                    '>=',
                ),

                'less_than',
                '<' => $this->compareNumeric(
                    $actual,
                    $expected,
                    '<',
                ),

                'less_than_or_equal',
                '<=' => $this->compareNumeric(
                    $actual,
                    $expected,
                    '<=',
                ),

                'in' => $this->valueIn(
                    $actual,
                    $expected,
                ),

                'not_in' => !$this->valueIn(
                    $actual,
                    $expected,
                ),

                'contains' => $this->contains(
                    $actual,
                    $expected,
                ),

                'not_contains' => !$this->contains(
                    $actual,
                    $expected,
                ),

                'starts_with' => $this->startsWith(
                    $actual,
                    $expected,
                ),

                'ends_with' => $this->endsWith(
                    $actual,
                    $expected,
                ),

                default => throw new RuntimeException(
                    sprintf(
                        'Unsupported tariff condition operator: %s',
                        $operator,
                    )
                ),
            };

            /*
             * All conditions use AND semantics.
             */
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
                    json_encode(
                        $condition,
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                    ),
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
     */
    private function resolveFieldReference(
        string $reference,
    ): array {
        $reference = trim($reference);

        $baseField = BaseField::query()
            ->where(
                'id',
                $reference,
            )
            ->first();

        if ($baseField) {
            return [
                'type' => 'base_field',
                'id' => $baseField->id,
                'code' => strtoupper(
                    trim(
                        (string) $baseField->code
                    )
                ),
            ];
        }

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
     * Resolution order:
     *
     * 1. revenue_service_field_id
     * 2. field_code
     */
    private function findAssessmentValue(
        AssessmentService $assessmentService,
        string $fieldReference,
    ): mixed {
        $reference = trim($fieldReference);

        /*
         * First try RevenueServiceField UUID.
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
                            (string) $value->revenue_service_field_id
                        ),
                        $reference,
                    ) === 0;
            }
        );

        if ($value) {
            return $value;
        }

        /*
         * If reference is a BaseField UUID, resolve it
         * to its field code.
         */
        $resolved = $this->resolveFieldReference(
            $reference
        );

        $code = $resolved['code'];

        /*
         * Match against stored field code.
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
                            (string) $value->field_code
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

        /*
         * Already decoded JSON / array.
         */
        if (is_array($conditions)) {
            /*
             * Single condition object.
             */
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

            /*
             * Array of conditions.
             */
            return array_values($conditions);
        }

        /*
         * JSON string.
         */
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

            /*
             * Single condition object.
             */
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

            /*
             * Array of conditions.
             */
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
            '>' => $actualValue > $expectedValue,

            '>=' => $actualValue >= $expectedValue,

            '<' => $actualValue < $expectedValue,

            '<=' => $actualValue <= $expectedValue,

            default => throw new RuntimeException(
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