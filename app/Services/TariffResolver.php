<?php

declare(strict_types=1);

namespace App\Services;

use Andegna\DateTimeFactory;
use App\Models\Assessment;
use App\Models\AssessmentService;
use App\Models\BaseField;
use App\Models\InterestRule;
use App\Models\PenaltyRule;
use App\Models\RevenueService;
use App\Models\RevenueServiceField;
use App\Models\TariffRule;
use App\Models\TariffVersion;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class TariffResolver
{
    /*
    |--------------------------------------------------------------------------
    | Constants
    |--------------------------------------------------------------------------
    */

    private const AGREEMENT_DATE_CODES = [
        'AGREEMENT_DATE',
        'AGREEMENT_SIGNING_DATE',
        'LIZZ_AGREEMENT_DATE',
    ];

    private const FIXED_PAYMENT_DATE = 'FIXED_PAYMENT_DATE';

    /*
    |--------------------------------------------------------------------------
    | RESOLVE TARIFF VERSION FOR ASSESSMENT
    |--------------------------------------------------------------------------
    */

    public function resolve(
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
        )->startOfDay();

        return $this->resolveVersionForDate(
            date: $date,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE TARIFF VERSION FOR DIRECT COLLECTION
    |--------------------------------------------------------------------------
    */

    public function resolveVersionForRevenueService(
        RevenueService $revenueService,
        Carbon|string|null $date = null,
    ): TariffVersion {
        if (!$revenueService->exists) {
            throw new RuntimeException(
                'The supplied revenue service does not exist.',
            );
        }

        $collectionDate = $date === null
            ? now()
            : (
                $date instanceof Carbon
                    ? $date
                    : Carbon::parse($date)
            );

        return $this->resolveVersionForDate(
            $collectionDate,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE TARIFF VERSION FOR DATE
    |--------------------------------------------------------------------------
    */

    public function resolveVersionForDate(
        Carbon|string $date,
    ): TariffVersion {
        $date = $date instanceof Carbon
            ? $date->copy()->startOfDay()
            : Carbon::parse($date)->startOfDay();

        $ethiopianYear = $this->getEthiopianYear(
            $date,
        );

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
            ->where(function ($query) use ($date): void {
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
                        'No tariff version exists for date %s (Ethiopian year %d). Create a tariff version for Ethiopian year %d.',
                        $date->toDateString(),
                        $ethiopianYear,
                        $ethiopianYear,
                    )
                );
            }

            $diagnostics = $availableVersions
                ->map(function (TariffVersion $item): string {
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

    /*
    |--------------------------------------------------------------------------
    | GET ETHIOPIAN YEAR
    |--------------------------------------------------------------------------
    */

    private function getEthiopianYear(
        Carbon $date,
    ): int {
        $ethiopianDate = DateTimeFactory::fromDateTime(
            $date->toDateTime(),
        );

        return (int) $ethiopianDate->getYear();
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE TARIFF RULE FOR ASSESSMENT
    |--------------------------------------------------------------------------
    */

    public function resolveRule(
        TariffVersion $version,
        AssessmentService $assessmentService,
    ): TariffRule {
        $assessmentService->loadMissing([
            'values',
        ]);

        $values = $this->buildAssessmentValueMap(
            $assessmentService,
        );

        return $this->resolveRuleForValues(
            version: $version,
            serviceId: (string) $assessmentService->service_id,
            values: $values,
            diagnosticsContext: sprintf(
                'assessment service %s',
                $assessmentService->id,
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE TARIFF RULE FOR DIRECT COLLECTION
    |--------------------------------------------------------------------------
    */

    public function resolveRuleForRevenueService(
        TariffVersion $version,
        string $serviceId,
        array $values,
    ): TariffRule {
        $serviceId = trim($serviceId);

        if ($serviceId === '') {
            throw new RuntimeException(
                'A revenue service ID is required to resolve a tariff rule.',
            );
        }

        return $this->resolveRuleForValues(
            version: $version,
            serviceId: $serviceId,
            values: $this->normalizeValueMap($values),
            diagnosticsContext: sprintf(
                'revenue service %s',
                $serviceId,
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | BUILD DIRECT COLLECTION VALUE MAP
    |--------------------------------------------------------------------------
    */

    public function buildRevenueServiceValueMap(
        RevenueService $revenueService,
        array $inputs,
    ): array {
        $inputs = $this->normalizeValueMap(
            $inputs,
        );

        /*
        |--------------------------------------------------------------------------
        | Preserve submitted values
        |--------------------------------------------------------------------------
        |
        | Canonical frontend format:
        |
        | RevenueServiceField UUID => value
        |
        */

        $values = $inputs;

        $fields = $this->getRevenueServiceFields(
            $revenueService,
        );

        if ($fields->isEmpty()) {
            return $values;
        }

        foreach ($fields as $field) {
            $fieldId = trim(
                (string) $field->id
            );

            if ($fieldId === '') {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve Submitted Value
            |--------------------------------------------------------------------------
            */

            [$hasValue, $value] =
                $this->resolveDirectInputValue(
                    field: $field,
                    inputs: $inputs,
                );

            if (!$hasValue) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | RevenueServiceField UUID
            |--------------------------------------------------------------------------
            */

            $values[$fieldId] = $value;

            /*
            |--------------------------------------------------------------------------
            | RevenueServiceField Code
            |--------------------------------------------------------------------------
            */

            if (!empty($field->field_code)) {
                $fieldCode = strtoupper(
                    trim(
                        (string) $field->field_code
                    )
                );

                if ($fieldCode !== '') {
                    $values[$fieldCode] = $value;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve BaseField
            |--------------------------------------------------------------------------
            */

            $baseField = $field->baseField;

            if (
                !$baseField
                &&
                !empty($field->base_field_id)
            ) {
                $baseField = BaseField::query()
                    ->find(
                        $field->base_field_id,
                    );
            }

            if (!$baseField) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | BaseField UUID
            |--------------------------------------------------------------------------
            */

            $baseFieldId = trim(
                (string) $baseField->id
            );

            if ($baseFieldId !== '') {
                $values[$baseFieldId] = $value;
            }

            /*
            |--------------------------------------------------------------------------
            | BaseField Code
            |--------------------------------------------------------------------------
            */

            if (!empty($baseField->code)) {
                $baseFieldCode = strtoupper(
                    trim(
                        (string) $baseField->code
                    )
                );

                if ($baseFieldCode !== '') {
                    $values[$baseFieldCode] = $value;
                }
            }
        }

        return $values;
    }

    /*
    |--------------------------------------------------------------------------
    | GET REVENUE SERVICE FIELDS
    |--------------------------------------------------------------------------
    */

    private function getRevenueServiceFields(
        RevenueService $revenueService,
    ) {
        if (
            method_exists(
                $revenueService,
                'fields',
            )
        ) {
            return $revenueService
                ->fields()
                ->with('baseField')
                ->get();
        }

        return RevenueServiceField::query()
            ->with('baseField')
            ->where(
                'service_id',
                $revenueService->id,
            )
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE DIRECT INPUT VALUE
    |--------------------------------------------------------------------------
    */

    private function resolveDirectInputValue(
        RevenueServiceField $field,
        array $inputs,
    ): array {
        $fieldId = trim(
            (string) $field->id
        );

        /*
        |--------------------------------------------------------------------------
        | RevenueServiceField UUID
        |--------------------------------------------------------------------------
        */

        if (
            array_key_exists(
                $fieldId,
                $inputs,
            )
        ) {
            return [
                true,
                $inputs[$fieldId],
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | RevenueServiceField Code
        |--------------------------------------------------------------------------
        */

        if (!empty($field->field_code)) {
            $fieldCode = trim(
                (string) $field->field_code
            );

            if (
                array_key_exists(
                    $fieldCode,
                    $inputs,
                )
            ) {
                return [
                    true,
                    $inputs[$fieldCode],
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Case-Insensitive Lookup
            |--------------------------------------------------------------------------
            */

            $normalizedFieldCode = strtoupper(
                $fieldCode,
            );

            foreach (
                $inputs as $key => $value
            ) {
                if (!is_string($key)) {
                    continue;
                }

                if (
                    strtoupper(
                        trim($key)
                    )
                    ===
                    $normalizedFieldCode
                ) {
                    return [
                        true,
                        $value,
                    ];
                }
            }
        }

        return [
            false,
            null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | GENERIC TARIFF RULE RESOLUTION
    |--------------------------------------------------------------------------
    */

    private function resolveRuleForValues(
        TariffVersion $version,
        string $serviceId,
        array $values,
        string $diagnosticsContext,
    ): TariffRule {
        $rules = TariffRule::query()
            ->where(
                'tariff_version_id',
                $version->id,
            )
            ->where(
                'service_id',
                $serviceId,
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
                    $serviceId,
                    $version->id,
                )
            );
        }

        foreach ($rules as $rule) {
            if (
                $this->ruleMatchesValues(
                    rule: $rule,
                    values: $values,
                )
            ) {
                return $rule;
            }
        }

        $diagnostics = $rules
            ->map(function (
                TariffRule $rule,
            ) use (
                $values
            ): string {
                $conditions = $this->normalizeConditions(
                    $rule->conditions,
                );

                $conditionResults = [];

                foreach ($conditions as $condition) {
                    try {
                        $fieldReference =
                            $this->getConditionFieldReference(
                                $condition,
                            );

                        $resolvedField =
                            $this->resolveFieldReference(
                                $fieldReference,
                            );

                        $actualValue =
                            $this->findValue(
                                values: $values,
                                fieldReference: $fieldReference,
                                resolvedField: $resolvedField,
                            );

                        $conditionResults[] = [
                            'field_reference' =>
                                $fieldReference,

                            'resolved_code' =>
                                $resolvedField['code'] ?? null,

                            'resolved_type' =>
                                $resolvedField['type'] ?? null,

                            'operator' =>
                                $condition['operator'] ?? null,

                            'expected' =>
                                $condition['value'] ?? null,

                            'actual' =>
                                $actualValue,

                            'field_found' =>
                                $actualValue !== null,
                        ];
                    } catch (\Throwable $e) {
                        $conditionResults[] = [
                            'error' =>
                                $e->getMessage(),

                            'condition' =>
                                $condition,
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
                        | JSON_UNESCAPED_SLASHES,
                    ),
                    json_encode(
                        $conditionResults,
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES,
                    ),
                );
            })
            ->implode('; ');

        throw new RuntimeException(
            sprintf(
                'No matching tariff rule found for %s in tariff version %s. Rules checked: %s',
                $diagnosticsContext,
                $version->id,
                $diagnostics,
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE PENALTY RULE FOR ASSESSMENT
    |--------------------------------------------------------------------------
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
                ?? $assessment->created_at,
        )->startOfDay();

        $assessmentService->loadMissing([
            'values',
        ]);

        $hasAgreementDate = $this->hasAgreementDate(
            $assessmentService,
        );

        return $this->resolvePenaltyRuleForDate(
            date: $assessmentDate,
            hasAgreementDate: $hasAgreementDate,
            context: sprintf(
                'assessment service %s',
                $assessmentService->id,
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE PENALTY RULE FOR DIRECT COLLECTION
    |--------------------------------------------------------------------------
    */

    public function resolvePenaltyRuleForRevenueService(
        Carbon|string $date,
        array $values = [],
        ?string $context = null,
    ): PenaltyRule {
        $date = $date instanceof Carbon
            ? $date->copy()->startOfDay()
            : Carbon::parse($date)->startOfDay();

        $values = $this->normalizeValueMap(
            $values,
        );

        $hasAgreementDate = $this->hasAgreementDateInValues(
            $values,
        );

        return $this->resolvePenaltyRuleForDate(
            date: $date,
            hasAgreementDate: $hasAgreementDate,
            context: $context
                ?? 'direct collection',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE PENALTY RULE FOR DATE
    |--------------------------------------------------------------------------
    */

    public function resolvePenaltyRuleForDate(
        Carbon|string $date,
        bool $hasAgreementDate = false,
        ?string $context = null,
    ): PenaltyRule {
        $date = $date instanceof Carbon
            ? $date->copy()->startOfDay()
            : Carbon::parse($date)->startOfDay();

        return $this->resolvePenaltyRuleUsingContext(
            date: $date,
            hasAgreementDate: $hasAgreementDate,
            context: $context
                ?? sprintf(
                    'date %s',
                    $date->toDateString(),
                ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PENALTY RULE INTERNAL RESOLUTION
    |--------------------------------------------------------------------------
    */

    private function resolvePenaltyRuleUsingContext(
        Carbon $date,
        bool $hasAgreementDate,
        string $context,
    ): PenaltyRule {
        $query = PenaltyRule::query()
            ->where(
                'is_active',
                true,
            )
            ->whereDate(
                'effective_from',
                '<=',
                $date,
            )
            ->where(function ($query) use ($date): void {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate(
                        'effective_to',
                        '>=',
                        $date,
                    );
            });

        $startType = $hasAgreementDate
            ? PenaltyRule::START_TYPE_AGREEMENT_DATE
            : self::FIXED_PAYMENT_DATE;

        $query->where(
            'start_type',
            $startType,
        );

        $rules = $query
            ->orderByDesc('effective_from')
            ->get();

        if ($rules->isEmpty()) {
            throw new RuntimeException(
                sprintf(
                    'No active penalty rule found for %s on %s using start type %s.',
                    $context,
                    $date->toDateString(),
                    $startType,
                )
            );
        }

        if ($rules->count() > 1) {
            $diagnostics = $rules
                ->map(function (PenaltyRule $rule): string {
                    return sprintf(
                        '%s (%s): %s to %s, active=%s',
                        $rule->id,
                        $rule->name,
                        $rule->effective_from,
                        $rule->effective_to ?? 'OPEN',
                        $rule->is_active
                            ? 'YES'
                            : 'NO',
                    );
                })
                ->implode('; ');

            throw new RuntimeException(
                sprintf(
                    'Multiple active penalty rules matched %s on %s. Rules: %s',
                    $context,
                    $date->toDateString(),
                    $diagnostics,
                )
            );
        }

        return $rules->first();
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE INTEREST RULE FOR ASSESSMENT
    |--------------------------------------------------------------------------
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
                ?? $assessment->created_at,
        )->startOfDay();

        return $this->resolveInterestRuleForDate(
            date: $assessmentDate,
            context: sprintf(
                'assessment service %s',
                $assessmentService->id,
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE INTEREST RULE FOR DIRECT COLLECTION
    |--------------------------------------------------------------------------
    */

    public function resolveInterestRuleForRevenueService(
        Carbon|string $date,
        ?string $context = null,
    ): InterestRule {
        return $this->resolveInterestRuleForDate(
            date: $date,
            context: $context
                ?? 'direct collection',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE INTEREST RULE FOR DATE
    |--------------------------------------------------------------------------
    */

    public function resolveInterestRuleForDate(
        Carbon|string $date,
        ?string $context = null,
    ): InterestRule {
        $date = $date instanceof Carbon
            ? $date->copy()->startOfDay()
            : Carbon::parse($date)->startOfDay();

        $rules = InterestRule::query()
            ->where(
                'is_active',
                true,
            )
            ->whereDate(
                'effective_from',
                '<=',
                $date,
            )
            ->where(function ($query) use ($date): void {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate(
                        'effective_to',
                        '>=',
                        $date,
                    );
            })
            ->orderByDesc('effective_from')
            ->get();

        if ($rules->isEmpty()) {
            throw new RuntimeException(
                sprintf(
                    'No active interest rule found for %s on %s.',
                    $context
                        ?? 'the supplied date',
                    $date->toDateString(),
                )
            );
        }

        if ($rules->count() > 1) {
            $diagnostics = $rules
                ->map(function (InterestRule $rule): string {
                    return sprintf(
                        '%s (%s%% %s): %s to %s, active=%s',
                        $rule->id,
                        $rule->rate,
                        $rule->rate_period,
                        $rule->effective_from,
                        $rule->effective_to ?? 'OPEN',
                        $rule->is_active
                            ? 'YES'
                            : 'NO',
                    );
                })
                ->implode('; ');

            throw new RuntimeException(
                sprintf(
                    'Multiple active interest rules matched %s on %s. Rules: %s',
                    $context
                        ?? 'the supplied date',
                    $date->toDateString(),
                    $diagnostics,
                )
            );
        }

        return $rules->first();
    }

    /*
    |--------------------------------------------------------------------------
    | HAS AGREEMENT DATE - ASSESSMENT
    |--------------------------------------------------------------------------
    */

    private function hasAgreementDate(
        AssessmentService $assessmentService,
    ): bool {
        $assessmentService->loadMissing([
            'values',
        ]);

        foreach (
            $assessmentService->values ?? []
            as $value
        ) {
            /*
            |--------------------------------------------------------------------------
            | Persisted Field Code
            |--------------------------------------------------------------------------
            */

            $fieldCode = strtoupper(
                trim(
                    (string) (
                        $value->field_code
                        ?? ''
                    )
                )
            );

            if (
                in_array(
                    $fieldCode,
                    self::AGREEMENT_DATE_CODES,
                    true,
                )
            ) {
                if (
                    $this->hasUsableScalarValue(
                        $value->value,
                    )
                ) {
                    return true;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | RevenueServiceField / BaseField
            |--------------------------------------------------------------------------
            */

            $revenueServiceField =
                $value->revenueServiceField
                ?? null;

            if (!$revenueServiceField) {
                $revenueServiceField =
                    RevenueServiceField::query()
                        ->with('baseField')
                        ->find(
                            $value->revenue_service_field_id
                                ?? null,
                        );
            }

            $baseField =
                $revenueServiceField?->baseField;

            if (!$baseField) {
                continue;
            }

            $baseFieldCode = strtoupper(
                trim(
                    (string) $baseField->code
                )
            );

            if (
                in_array(
                    $baseFieldCode,
                    self::AGREEMENT_DATE_CODES,
                    true,
                )
                &&
                $this->hasUsableScalarValue(
                    $value->value,
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | HAS AGREEMENT DATE - DIRECT COLLECTION
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | The Direct Collection value map intentionally contains multiple
    | identifier types:
    |
    |     RevenueServiceField UUID
    |     RevenueServiceField code
    |     BaseField UUID
    |     BaseField code
    |
    | Therefore we MUST NOT send every array key into:
    |
    |     BaseField.id
    |
    | because field codes are not UUIDs.
    |
    */

    private function hasAgreementDateInValues(
        array $values,
    ): bool {
        /*
        |--------------------------------------------------------------------------
        | 1. Check field codes directly
        |--------------------------------------------------------------------------
        */

        foreach ($values as $key => $value) {
            if (
                !is_string($key)
                ||
                !$this->hasUsableScalarValue($value)
            ) {
                continue;
            }

            $normalizedKey = strtoupper(
                trim($key)
            );

            if (
                in_array(
                    $normalizedKey,
                    self::AGREEMENT_DATE_CODES,
                    true,
                )
            ) {
                return true;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Collect ONLY UUID keys
        |--------------------------------------------------------------------------
        |
        | Never send codes such as:
        |
        |     TESSOO
        |     TIRIIPPII
        |     HALKAN
        |
        | into BaseField.id.
        |
        */

        $baseFieldIds = [];

        foreach ($values as $key => $value) {
            if (
                !is_string($key)
                ||
                !$this->hasUsableScalarValue($value)
            ) {
                continue;
            }

            $key = trim($key);

            if (
                !Str::isUuid($key)
            ) {
                continue;
            }

            $baseFieldIds[] = $key;
        }

        /*
        |--------------------------------------------------------------------------
        | No UUID values
        |--------------------------------------------------------------------------
        */

        if (empty($baseFieldIds)) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Remove Duplicate UUIDs
        |--------------------------------------------------------------------------
        */

        $baseFieldIds = array_values(
            array_unique(
                $baseFieldIds,
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Find Matching Base Fields
        |--------------------------------------------------------------------------
        */

        $baseFields = BaseField::query()
            ->whereIn(
                'id',
                $baseFieldIds,
            )
            ->get([
                'id',
                'code',
            ]);

        /*
        |--------------------------------------------------------------------------
        | Check Agreement Date BaseField
        |--------------------------------------------------------------------------
        */

        foreach ($baseFields as $baseField) {
            $code = strtoupper(
                trim(
                    (string) $baseField->code
                )
            );

            if (
                !in_array(
                    $code,
                    self::AGREEMENT_DATE_CODES,
                    true,
                )
            ) {
                continue;
            }

            $baseFieldId = (string) $baseField->id;

            if (
                array_key_exists(
                    $baseFieldId,
                    $values,
                )
                &&
                $this->hasUsableScalarValue(
                    $values[$baseFieldId],
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | USABLE SCALAR VALUE
    |--------------------------------------------------------------------------
    */

    private function hasUsableScalarValue(
        mixed $value,
    ): bool {
        if (
            $value === null
            ||
            $value === ''
        ) {
            return false;
        }

        if (
            is_array($value)
            ||
            is_object($value)
        ) {
            return false;
        }

        return trim(
            (string) $value
        ) !== '';
    }

    /*
    |--------------------------------------------------------------------------
    | RULE MATCHING - ASSESSMENT COMPATIBILITY
    |--------------------------------------------------------------------------
    */

    private function ruleMatches(
        TariffRule $rule,
        AssessmentService $assessmentService,
    ): bool {
        $assessmentService->loadMissing([
            'values',
        ]);

        $values = $this->buildAssessmentValueMap(
            $assessmentService,
        );

        return $this->ruleMatchesValues(
            rule: $rule,
            values: $values,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | GENERIC RULE MATCHING
    |--------------------------------------------------------------------------
    */

    private function ruleMatchesValues(
        TariffRule $rule,
        array $values,
    ): bool {
        $calculationType = strtoupper(
            trim(
                (string) $rule->calculation_type
            )
        );

        $conditions = $this->normalizeConditions(
            $rule->conditions,
        );

        if (!empty($conditions)) {
            if (
                !$this->matchesConditionsValues(
                    conditions: $conditions,
                    values: $values,
                )
            ) {
                return false;
            }
        }

        if (
            $calculationType === 'RANGE'
        ) {
            return $this->matchesRangeValues(
                rule: $rule,
                values: $values,
            );
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | RANGE MATCHING - ASSESSMENT COMPATIBILITY
    |--------------------------------------------------------------------------
    */

    private function matchesRange(
        TariffRule $rule,
        AssessmentService $assessmentService,
    ): bool {
        $assessmentService->loadMissing([
            'values',
        ]);

        $values = $this->buildAssessmentValueMap(
            $assessmentService,
        );

        return $this->matchesRangeValues(
            rule: $rule,
            values: $values,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | GENERIC RANGE MATCHING
    |--------------------------------------------------------------------------
    */

    private function matchesRangeValues(
        TariffRule $rule,
        array $values,
    ): bool {
        if (
            $rule->base_field_id === null
            ||
            trim(
                (string) $rule->base_field_id
            ) === ''
        ) {
            throw new RuntimeException(
                sprintf(
                    'Range tariff rule %s does not define a base field.',
                    $rule->id,
                )
            );
        }

        $fieldReference =
            (string) $rule->base_field_id;

        $resolvedField =
            $this->resolveFieldReference(
                $fieldReference,
            );

        $value = $this->findValue(
            values: $values,
            fieldReference: $fieldReference,
            resolvedField: $resolvedField,
        );

        if ($value === null) {
            return false;
        }

        $numericValue = $this->numericValue(
            $value,
        );

        if ($numericValue === null) {
            return false;
        }

        if (
            $rule->min_value !== null
            &&
            $numericValue <
                (float) $rule->min_value
        ) {
            return false;
        }

        if (
            $rule->max_value !== null
            &&
            $numericValue >
                (float) $rule->max_value
        ) {
            return false;
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | MATCH CONDITIONS - ASSESSMENT COMPATIBILITY
    |--------------------------------------------------------------------------
    */

    private function matchesConditions(
        array $conditions,
        AssessmentService $assessmentService,
    ): bool {
        $assessmentService->loadMissing([
            'values',
        ]);

        $values = $this->buildAssessmentValueMap(
            $assessmentService,
        );

        return $this->matchesConditionsValues(
            conditions: $conditions,
            values: $values,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | GENERIC CONDITION MATCHING
    |--------------------------------------------------------------------------
    */

    private function matchesConditionsValues(
        array $conditions,
        array $values,
    ): bool {
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                throw new RuntimeException(
                    'Invalid tariff rule condition.',
                );
            }

            $fieldReference =
                $this->getConditionFieldReference(
                    $condition,
                );

            $operator = strtolower(
                trim(
                    (string) (
                        $condition['operator']
                        ?? ''
                    )
                )
            );

            if (
                trim($fieldReference) === ''
                ||
                $operator === ''
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Invalid tariff rule condition: %s',
                        json_encode(
                            $condition,
                            JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES,
                        ),
                    )
                );
            }

            $resolvedField =
                $this->resolveFieldReference(
                    $fieldReference,
                );

            $actual = $this->findValue(
                values: $values,
                fieldReference: $fieldReference,
                resolvedField: $resolvedField,
            );

            if ($actual === null) {
                return false;
            }

            $actual =
                $this->normalizeComparableValue(
                    $actual,
                );

            $expected =
                $this->normalizeComparableValue(
                    $condition['value'] ?? null,
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

                default => throw new RuntimeException(
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

    /*
    |--------------------------------------------------------------------------
    | GET CONDITION FIELD REFERENCE
    |--------------------------------------------------------------------------
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
                        | JSON_UNESCAPED_SLASHES,
                    ),
                )
            );
        }

        return trim(
            (string) $reference
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE FIELD REFERENCE
    |--------------------------------------------------------------------------
    |
    | Supported:
    |
    |     BaseField UUID
    |     BaseField code
    |
    | IMPORTANT:
    |
    | Only UUID values are queried against BaseField.id.
    |
    */

    private function resolveFieldReference(
        string $reference,
    ): array {
        $reference = trim(
            $reference,
        );

        if ($reference === '') {
            return [
                'type' => 'unknown',
                'id' => null,
                'code' => null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | BaseField UUID
        |--------------------------------------------------------------------------
        */

        if (Str::isUuid($reference)) {
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

            /*
            |--------------------------------------------------------------------------
            | UUID but BaseField does not exist
            |--------------------------------------------------------------------------
            */

            return [
                'type' => 'base_field',
                'id' => $reference,
                'code' => null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | BaseField Code
        |--------------------------------------------------------------------------
        */

        return [
            'type' => 'code',
            'id' => null,
            'code' => strtoupper(
                $reference,
            ),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | FIND VALUE
    |--------------------------------------------------------------------------
    */

    private function findValue(
        array $values,
        string $fieldReference,
        array $resolvedField,
    ): mixed {
        $fieldReference = trim(
            $fieldReference,
        );

        /*
        |--------------------------------------------------------------------------
        | Exact Reference
        |--------------------------------------------------------------------------
        */

        if (
            array_key_exists(
                $fieldReference,
                $values,
            )
        ) {
            return $values[
                $fieldReference
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | BaseField UUID
        |--------------------------------------------------------------------------
        */

        $fieldId =
            $resolvedField['id']
            ?? null;

        if (
            $fieldId !== null
            &&
            array_key_exists(
                $fieldId,
                $values,
            )
        ) {
            return $values[
                $fieldId
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Field Code
        |--------------------------------------------------------------------------
        */

        $code =
            $resolvedField['code']
            ?? null;

        if (
            $code !== null
            &&
            array_key_exists(
                $code,
                $values,
            )
        ) {
            return $values[
                $code
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Case-Insensitive Code Lookup
        |--------------------------------------------------------------------------
        */

        if ($code !== null) {
            foreach (
                $values as $key => $value
            ) {
                if (
                    is_string($key)
                    &&
                    strtoupper(
                        trim($key)
                    )
                    ===
                    strtoupper(
                        trim($code)
                    )
                ) {
                    return $value;
                }
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | FIND ASSESSMENT VALUE
    |--------------------------------------------------------------------------
    */

    private function findAssessmentValue(
        AssessmentService $assessmentService,
        string $fieldReference,
    ): mixed {
        $assessmentService->loadMissing([
            'values',
        ]);

        $reference = trim(
            $fieldReference,
        );

        /*
        |--------------------------------------------------------------------------
        | RevenueServiceField UUID
        |--------------------------------------------------------------------------
        */

        $value = $assessmentService
            ->values
            ->first(
                function ($value) use ($reference): bool {
                    return
                        !empty(
                            $value->revenue_service_field_id
                        )
                        &&
                        strcasecmp(
                            trim(
                                (string) $value
                                    ->revenue_service_field_id
                            ),
                            $reference,
                        ) === 0;
                }
            );

        if ($value) {
            return $value;
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve BaseField
        |--------------------------------------------------------------------------
        */

        $resolved =
            $this->resolveFieldReference(
                $reference,
            );

        $code =
            $resolved['code']
            ?? null;

        if ($code === null) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Field Code
        |--------------------------------------------------------------------------
        */

        return $assessmentService
            ->values
            ->first(
                function ($value) use ($code): bool {
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

    /*
    |--------------------------------------------------------------------------
    | BUILD ASSESSMENT VALUE MAP
    |--------------------------------------------------------------------------
    */

    private function buildAssessmentValueMap(
        AssessmentService $assessmentService,
    ): array {
        $assessmentService->loadMissing([
            'values',
        ]);

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
                    $assessmentValue->value;

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | RevenueServiceField
            |--------------------------------------------------------------------------
            */

            if (
                empty(
                    $assessmentValue
                        ->revenue_service_field_id
                )
            ) {
                continue;
            }

            $revenueServiceField =
                $assessmentValue
                    ->revenueServiceField
                    ?? null;

            if (!$revenueServiceField) {
                $revenueServiceField =
                    RevenueServiceField::query()
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
            | BaseField ID
            |--------------------------------------------------------------------------
            */

            $baseFieldId =
                $revenueServiceField
                    ->base_field_id
                    ?? null;

            if (
                empty($baseFieldId)
            ) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | BaseField UUID
            |--------------------------------------------------------------------------
            */

            $values[$baseFieldId] =
                $assessmentValue->value;

            /*
            |--------------------------------------------------------------------------
            | BaseField Code
            |--------------------------------------------------------------------------
            */

            $baseField =
                $revenueServiceField
                    ->baseField
                    ?? null;

            if (
                $baseField
                &&
                !empty($baseField->code)
            ) {
                $values[
                    strtoupper(
                        trim(
                            (string) $baseField->code
                        )
                    )
                ] =
                    $assessmentValue->value;
            }
        }

        return $values;
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE VALUE MAP
    |--------------------------------------------------------------------------
    */

    private function normalizeValueMap(
        array $values,
    ): array {
        $normalized = [];

        foreach (
            $values as $key => $value
        ) {
            if (is_string($key)) {
                $key = trim($key);
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE CONDITIONS
    |--------------------------------------------------------------------------
    */

    private function normalizeConditions(
        mixed $conditions,
    ): array {
        if ($conditions === null) {
            return [];
        }

        if (is_array($conditions)) {
            /*
             * Single condition object.
             */
            if (
                isset(
                    $conditions['operator']
                )
                &&
                (
                    isset(
                        $conditions['field']
                    )
                    ||
                    isset(
                        $conditions['fieldId']
                    )
                    ||
                    isset(
                        $conditions['field_id']
                    )
                    ||
                    isset(
                        $conditions['field_code']
                    )
                )
            ) {
                return [
                    $conditions,
                ];
            }

            /*
             * Condition list.
             */
            return array_values(
                $conditions,
            );
        }

        if (is_string($conditions)) {
            $conditions = trim(
                $conditions,
            );

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
                    'Tariff rule conditions contain invalid JSON.',
                );
            }

            if ($decoded === null) {
                return [];
            }

            /*
             * Single condition.
             */
            if (
                is_array($decoded)
                &&
                isset(
                    $decoded['operator']
                )
                &&
                (
                    isset(
                        $decoded['field']
                    )
                    ||
                    isset(
                        $decoded['fieldId']
                    )
                    ||
                    isset(
                        $decoded['field_id']
                    )
                    ||
                    isset(
                        $decoded['field_code']
                    )
                )
            ) {
                return [
                    $decoded,
                ];
            }

            /*
             * Condition list.
             */
            if (is_array($decoded)) {
                return array_values(
                    $decoded,
                );
            }
        }

        throw new RuntimeException(
            'Tariff rule conditions must be an array or valid JSON.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | NUMERIC VALUE
    |--------------------------------------------------------------------------
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
                (string) $value,
            );

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE COMPARABLE VALUE
    |--------------------------------------------------------------------------
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

    /*
    |--------------------------------------------------------------------------
    | VALUES EQUAL
    |--------------------------------------------------------------------------
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

    /*
    |--------------------------------------------------------------------------
    | NUMERIC COMPARISON
    |--------------------------------------------------------------------------
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

            default => throw new RuntimeException(
                "Unsupported numeric comparison operator [{$operator}].",
            ),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | VALUE IN
    |--------------------------------------------------------------------------
    */

    private function valueIn(
        mixed $actual,
        mixed $expected,
    ): bool {
        if (!is_array($expected)) {
            return false;
        }

        foreach (
            $expected as $item
        ) {
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

    /*
    |--------------------------------------------------------------------------
    | CONTAINS
    |--------------------------------------------------------------------------
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
            foreach (
                $actual as $item
            ) {
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

    /*
    |--------------------------------------------------------------------------
    | STARTS WITH
    |--------------------------------------------------------------------------
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

    /*
    |--------------------------------------------------------------------------
    | ENDS WITH
    |--------------------------------------------------------------------------
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