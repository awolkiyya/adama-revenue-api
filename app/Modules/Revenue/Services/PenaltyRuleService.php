<?php

namespace App\Modules\Revenue\Services;

use App\Models\PenaltyRule;
use App\Services\SystemLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PenaltyRuleService
{
    /**
     * Audit module identifier.
     *
     * Keep this consistent with the Revenue module's
     * existing system-log conventions.
     */
    private const MODULE = 'penalty_rules';

    /**
     * Fields that represent the actual penalty-rule business state.
     *
     * Timestamps, UUIDs and audit-user fields are intentionally
     * excluded from business change comparisons.
     */
    private const AUDIT_FIELDS = [
        'name',
        'initial_rate',
        'increment_rate',
        'maximum_rate',
        'start_type',
        'start_fiscal_month',
        'increment_period',
        'calculation_basis',
        'effective_from',
        'effective_to',
        'is_active',
        'legal_reference',
        'description',
    ];

    public function __construct(
        private readonly SystemLogService $systemLogService
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */

    /**
     * Create a penalty rule.
     *
     * Business mutation and audit logging are executed inside
     * the same database transaction.
     */
    public function create(
        array $data,
        string $userId
    ): PenaltyRule {
        return DB::transaction(function () use (
            $data,
            $userId
        ) {
            /*
            |--------------------------------------------------------------------------
            | Normalize
            |--------------------------------------------------------------------------
            */

            $data = $this->normalize($data);

            /*
            |--------------------------------------------------------------------------
            | Business Validation
            |--------------------------------------------------------------------------
            */

            $this->validateBusinessRules($data);

            /*
            |--------------------------------------------------------------------------
            | Ownership
            |--------------------------------------------------------------------------
            */

            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;

            /*
            |--------------------------------------------------------------------------
            | Overlap Validation
            |--------------------------------------------------------------------------
            |
            | Only active rules participate.
            |
            | Same start_type:
            |     overlapping = prohibited
            |
            | Different start_type:
            |     overlapping = allowed
            |
            */

            if ($data['is_active'] === true) {
                $this->ensureNoOverlappingActiveRule($data);
            }

            /*
            |--------------------------------------------------------------------------
            | Create
            |--------------------------------------------------------------------------
            */

            $rule = PenaltyRule::create($data);

            /*
            |--------------------------------------------------------------------------
            | Refresh
            |--------------------------------------------------------------------------
            */

            $rule->refresh();

            /*
            |--------------------------------------------------------------------------
            | Audit CREATE
            |--------------------------------------------------------------------------
            */

            $this->systemLogService->created(
                resource: $rule,
                module: self::MODULE,
                description: 'Penalty rule created successfully.',
                newValues: $this->getAuditValues($rule),
                metadata: [
                    'operation' => 'create',
                    'start_type' => $rule->start_type,
                    'calculation_basis' => $rule->calculation_basis,
                ],
            );

            return $rule;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    /**
     * Update an existing penalty rule.
     *
     * PATCH-style input is supported. Business validation is
     * performed against the complete resulting state.
     */
    public function update(
        PenaltyRule $penaltyRule,
        array $data,
        string $userId
    ): PenaltyRule {
        return DB::transaction(function () use (
            $penaltyRule,
            $data,
            $userId
        ) {
            /*
            |--------------------------------------------------------------------------
            | Lock Current Record
            |--------------------------------------------------------------------------
            |
            | Prevent concurrent modifications from operating on
            | stale state.
            |
            */

            $penaltyRule = PenaltyRule::query()
                ->whereKey($penaltyRule->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
            |--------------------------------------------------------------------------
            | Capture Old Business State
            |--------------------------------------------------------------------------
            */

            $oldValues = $this->getAuditValues(
                $penaltyRule
            );

            /*
            |--------------------------------------------------------------------------
            | Build Complete Candidate State
            |--------------------------------------------------------------------------
            */

            $candidate = array_merge(
                $this->getAuditValues($penaltyRule),
                $data
            );

            /*
            |--------------------------------------------------------------------------
            | Normalize Candidate
            |--------------------------------------------------------------------------
            */

            $candidate = $this->normalize(
                $candidate
            );

            /*
            |--------------------------------------------------------------------------
            | Business Validation
            |--------------------------------------------------------------------------
            */

            $this->validateBusinessRules(
                $candidate
            );

            /*
            |--------------------------------------------------------------------------
            | Overlap Validation
            |--------------------------------------------------------------------------
            */

            if ($candidate['is_active'] === true) {
                $this->ensureNoOverlappingActiveRule(
                    $candidate,
                    $penaltyRule->id
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Prepare Update
            |--------------------------------------------------------------------------
            |
            | Only permitted business fields are accepted from the
            | candidate. created_by is never modified.
            |
            */

            $updateData = [];

            foreach (self::AUDIT_FIELDS as $field) {
                if (array_key_exists($field, $candidate)) {
                    $updateData[$field] =
                        $candidate[$field];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | System-Controlled Fields
            |--------------------------------------------------------------------------
            */

            $updateData['updated_by'] = $userId;

            /*
            |--------------------------------------------------------------------------
            | Update
            |--------------------------------------------------------------------------
            */

            $penaltyRule->fill(
                $updateData
            );

            $penaltyRule->save();

            /*
            |--------------------------------------------------------------------------
            | Refresh
            |--------------------------------------------------------------------------
            */

            $penaltyRule->refresh();

            /*
            |--------------------------------------------------------------------------
            | New Business State
            |--------------------------------------------------------------------------
            */

            $newValues = $this->getAuditValues(
                $penaltyRule
            );

            /*
            |--------------------------------------------------------------------------
            | Detect Actual Changes
            |--------------------------------------------------------------------------
            */

            $changedValues =
                $this->getChangedAuditValues(
                    $oldValues,
                    $newValues
                );

            /*
            |--------------------------------------------------------------------------
            | Audit UPDATE
            |--------------------------------------------------------------------------
            |
            | Do not create an audit entry when the business
            | configuration did not actually change.
            |
            */

            if (
                $changedValues['old'] !== []
                || $changedValues['new'] !== []
            ) {
                $this->systemLogService->updated(
                    resource: $penaltyRule,
                    module: self::MODULE,
                    oldValues: $changedValues['old'],
                    newValues: $changedValues['new'],
                    description: 'Penalty rule updated successfully.',
                    metadata: [
                        'operation' => 'update',
                    ],
                );
            }

            return $penaltyRule;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVATE
    |--------------------------------------------------------------------------
    */

    /**
     * Activate an inactive penalty rule.
     *
     * Activation is a state change and therefore uses the same
     * SystemLogService::updated() mechanism as other configuration
     * changes.
     */
    public function activate(
        PenaltyRule $penaltyRule,
        string $userId
    ): PenaltyRule {
        return DB::transaction(function () use (
            $penaltyRule,
            $userId
        ) {
            /*
            |--------------------------------------------------------------------------
            | Lock Current Record
            |--------------------------------------------------------------------------
            */

            $penaltyRule = PenaltyRule::query()
                ->whereKey($penaltyRule->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
            |--------------------------------------------------------------------------
            | Already Active
            |--------------------------------------------------------------------------
            */

            if ($penaltyRule->is_active === true) {
                return $penaltyRule;
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Rule
            |--------------------------------------------------------------------------
            */

            $this->validateActivation(
                $penaltyRule
            );

            /*
            |--------------------------------------------------------------------------
            | Overlap Validation
            |--------------------------------------------------------------------------
            */

            $this->ensureNoOverlappingActiveRule(
                [
                    'start_type' =>
                        $penaltyRule->start_type,

                    'effective_from' =>
                        $penaltyRule->effective_from,

                    'effective_to' =>
                        $penaltyRule->effective_to,

                    'is_active' => true,
                ],
                $penaltyRule->id
            );

            /*
            |--------------------------------------------------------------------------
            | Old State
            |--------------------------------------------------------------------------
            */

            $oldValues = $this->getAuditValues(
                $penaltyRule
            );

            /*
            |--------------------------------------------------------------------------
            | Activate
            |--------------------------------------------------------------------------
            */

            $penaltyRule->update([
                'is_active' => true,
                'updated_by' => $userId,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Refresh
            |--------------------------------------------------------------------------
            */

            $penaltyRule->refresh();

            /*
            |--------------------------------------------------------------------------
            | New State
            |--------------------------------------------------------------------------
            */

            $newValues = $this->getAuditValues(
                $penaltyRule
            );

            /*
            |--------------------------------------------------------------------------
            | Audit ACTIVATE
            |--------------------------------------------------------------------------
            */

            $this->systemLogService->updated(
                resource: $penaltyRule,
                module: self::MODULE,
                oldValues: $oldValues,
                newValues: $newValues,
                description: 'Penalty rule activated successfully.',
                metadata: [
                    'operation' => 'activate',
                    'previous_status' => false,
                    'new_status' => true,
                ],
            );

            return $penaltyRule;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | DEACTIVATE
    |--------------------------------------------------------------------------
    */

    /**
     * Deactivate an active penalty rule.
     */
    public function deactivate(
        PenaltyRule $penaltyRule,
        string $userId
    ): PenaltyRule {
        return DB::transaction(function () use (
            $penaltyRule,
            $userId
        ) {
            /*
            |--------------------------------------------------------------------------
            | Lock Current Record
            |--------------------------------------------------------------------------
            */

            $penaltyRule = PenaltyRule::query()
                ->whereKey($penaltyRule->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
            |--------------------------------------------------------------------------
            | Already Inactive
            |--------------------------------------------------------------------------
            */

            if ($penaltyRule->is_active === false) {
                return $penaltyRule;
            }

            /*
            |--------------------------------------------------------------------------
            | Old State
            |--------------------------------------------------------------------------
            */

            $oldValues = $this->getAuditValues(
                $penaltyRule
            );

            /*
            |--------------------------------------------------------------------------
            | Deactivate
            |--------------------------------------------------------------------------
            */

            $penaltyRule->update([
                'is_active' => false,
                'updated_by' => $userId,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Refresh
            |--------------------------------------------------------------------------
            */

            $penaltyRule->refresh();

            /*
            |--------------------------------------------------------------------------
            | New State
            |--------------------------------------------------------------------------
            */

            $newValues = $this->getAuditValues(
                $penaltyRule
            );

            /*
            |--------------------------------------------------------------------------
            | Audit DEACTIVATE
            |--------------------------------------------------------------------------
            */

            $this->systemLogService->updated(
                resource: $penaltyRule,
                module: self::MODULE,
                oldValues: $oldValues,
                newValues: $newValues,
                description: 'Penalty rule deactivated successfully.',
                metadata: [
                    'operation' => 'deactivate',
                    'previous_status' => true,
                    'new_status' => false,
                ],
            );

            return $penaltyRule;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize penalty-rule input.
     *
     * The application currently supports monthly increments only.
     */
    private function normalize(
        array $data
    ): array {
        /*
        |--------------------------------------------------------------------------
        | Increment Period
        |--------------------------------------------------------------------------
        */

        $data['increment_period'] =
            PenaltyRule::INCREMENT_PERIOD_MONTH;

        /*
        |--------------------------------------------------------------------------
        | Active State
        |--------------------------------------------------------------------------
        */

        if (! array_key_exists(
            'is_active',
            $data
        )) {
            $data['is_active'] = true;
        }

        $data['is_active'] =
            filter_var(
                $data['is_active'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) ?? (bool) $data['is_active'];

        /*
        |--------------------------------------------------------------------------
        | Start Type
        |--------------------------------------------------------------------------
        */

        if (
            ($data['start_type'] ?? null) ===
            PenaltyRule::START_TYPE_AGREEMENT_DATE
        ) {
            /*
             * Agreement-date rules never use a fiscal month.
             */
            $data['start_fiscal_month'] = null;
        }

        /*
        |--------------------------------------------------------------------------
        | Fixed Fiscal Month
        |--------------------------------------------------------------------------
        */

        if (
            ($data['start_type'] ?? null) ===
            PenaltyRule::START_TYPE_FIXED_FISCAL_MONTH
            &&
            isset($data['start_fiscal_month'])
        ) {
            $data['start_fiscal_month'] =
                (int) $data['start_fiscal_month'];
        }

        /*
        |--------------------------------------------------------------------------
        | Text Fields
        |--------------------------------------------------------------------------
        */

        if (
            array_key_exists(
                'name',
                $data
            )
        ) {
            $data['name'] =
                trim((string) $data['name']);
        }

        if (
            array_key_exists(
                'legal_reference',
                $data
            )
        ) {
            $data['legal_reference'] =
                $data['legal_reference'] !== null
                    ? trim(
                        (string) $data['legal_reference']
                    )
                    : null;
        }

        if (
            array_key_exists(
                'description',
                $data
            )
        ) {
            $data['description'] =
                $data['description'] !== null
                    ? trim(
                        (string) $data['description']
                    )
                    : null;
        }

        return $data;
    }

    /*
    |--------------------------------------------------------------------------
    | BUSINESS VALIDATION
    |--------------------------------------------------------------------------
    */

    /**
     * Validate the complete penalty-rule business state.
     */
    private function validateBusinessRules(
        array $data
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Rates
        |--------------------------------------------------------------------------
        */

        $initialRate =
            $data['initial_rate'] ?? null;

        $incrementRate =
            $data['increment_rate'] ?? null;

        $maximumRate =
            $data['maximum_rate'] ?? null;

        if (
            $initialRate === null
            || ! is_numeric($initialRate)
        ) {
            throw ValidationException::withMessages([
                'initial_rate' =>
                    'The initial penalty rate must be a valid number.',
            ]);
        }

        if (
            $incrementRate === null
            || ! is_numeric($incrementRate)
        ) {
            throw ValidationException::withMessages([
                'increment_rate' =>
                    'The increment penalty rate must be a valid number.',
            ]);
        }

        if (
            $maximumRate === null
            || ! is_numeric($maximumRate)
        ) {
            throw ValidationException::withMessages([
                'maximum_rate' =>
                    'The maximum penalty rate must be a valid number.',
            ]);
        }

        if (
            (float) $initialRate < 0
            || (float) $initialRate > 100
        ) {
            throw ValidationException::withMessages([
                'initial_rate' =>
                    'The initial penalty rate must be between 0 and 100%.',
            ]);
        }

        if (
            (float) $incrementRate < 0
            || (float) $incrementRate > 100
        ) {
            throw ValidationException::withMessages([
                'increment_rate' =>
                    'The increment penalty rate must be between 0 and 100%.',
            ]);
        }

        if (
            (float) $maximumRate < 0
            || (float) $maximumRate > 100
        ) {
            throw ValidationException::withMessages([
                'maximum_rate' =>
                    'The maximum penalty rate must be between 0 and 100%.',
            ]);
        }

        if (
            (float) $maximumRate <
            (float) $initialRate
        ) {
            throw ValidationException::withMessages([
                'maximum_rate' =>
                    'The maximum penalty rate must be greater than or equal to the initial penalty rate.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Start Type
        |--------------------------------------------------------------------------
        */

        $startType =
            $data['start_type'] ?? null;

        if (! in_array(
            $startType,
            [
                PenaltyRule::START_TYPE_FIXED_FISCAL_MONTH,
                PenaltyRule::START_TYPE_AGREEMENT_DATE,
            ],
            true
        )) {
            throw ValidationException::withMessages([
                'start_type' =>
                    'The selected penalty commencement type is invalid.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Fixed Fiscal Month
        |--------------------------------------------------------------------------
        */

        if (
            $startType ===
            PenaltyRule::START_TYPE_FIXED_FISCAL_MONTH
        ) {
            $fiscalMonth =
                $data['start_fiscal_month'] ?? null;

            if (
                $fiscalMonth === null
                || ! is_numeric($fiscalMonth)
                || (int) $fiscalMonth < 1
                || (int) $fiscalMonth > 13
            ) {
                throw ValidationException::withMessages([
                    'start_fiscal_month' =>
                        'The fiscal month must be between 1 and 13 when the penalty start type is FIXED_FISCAL_MONTH.',
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Agreement Date
        |--------------------------------------------------------------------------
        */

        if (
            $startType ===
            PenaltyRule::START_TYPE_AGREEMENT_DATE
            &&
            ($data['start_fiscal_month'] ?? null) !== null
        ) {
            throw ValidationException::withMessages([
                'start_fiscal_month' =>
                    'The fiscal month must be empty when the penalty start type is AGREEMENT_DATE.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Increment Period
        |--------------------------------------------------------------------------
        */

        if (
            ($data['increment_period'] ?? null) !==
            PenaltyRule::INCREMENT_PERIOD_MONTH
        ) {
            throw ValidationException::withMessages([
                'increment_period' =>
                    'The penalty increment period must be MONTH.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Calculation Basis
        |--------------------------------------------------------------------------
        */

        $calculationBasis =
            $data['calculation_basis'] ?? null;

        if (! in_array(
            $calculationBasis,
            [
                PenaltyRule::CALCULATION_BASIS_PRINCIPAL,
                PenaltyRule::CALCULATION_BASIS_OUTSTANDING,
            ],
            true
        )) {
            throw ValidationException::withMessages([
                'calculation_basis' =>
                    'The selected penalty calculation basis is invalid.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Effective Dates
        |--------------------------------------------------------------------------
        */

        $effectiveFrom =
            $data['effective_from'] ?? null;

        $effectiveTo =
            $data['effective_to'] ?? null;

        if ($effectiveFrom === null) {
            throw ValidationException::withMessages([
                'effective_from' =>
                    'The effective start date is required.',
            ]);
        }

        if (
            $effectiveTo !== null
            && $effectiveTo < $effectiveFrom
        ) {
            throw ValidationException::withMessages([
                'effective_to' =>
                    'The effective end date must be on or after the effective start date.',
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVATION VALIDATION
    |--------------------------------------------------------------------------
    */

    /**
     * Validate an existing inactive rule before activation.
     */
    private function validateActivation(
        PenaltyRule $penaltyRule
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Effective Period
        |--------------------------------------------------------------------------
        */

        if (
            $penaltyRule->effective_to !== null
            &&
            $penaltyRule->effective_to
                ->lt($penaltyRule->effective_from)
        ) {
            throw ValidationException::withMessages([
                'effective_to' =>
                    'The penalty rule has an invalid effective period.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Start Type
        |--------------------------------------------------------------------------
        */

        if (! in_array(
            $penaltyRule->start_type,
            [
                PenaltyRule::START_TYPE_FIXED_FISCAL_MONTH,
                PenaltyRule::START_TYPE_AGREEMENT_DATE,
            ],
            true
        )) {
            throw ValidationException::withMessages([
                'start_type' =>
                    'The penalty rule has an invalid commencement type.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Fixed Fiscal Month
        |--------------------------------------------------------------------------
        */

        if (
            $penaltyRule->start_type ===
            PenaltyRule::START_TYPE_FIXED_FISCAL_MONTH
            &&
            (
                $penaltyRule->start_fiscal_month === null
                ||
                $penaltyRule->start_fiscal_month < 1
                ||
                $penaltyRule->start_fiscal_month > 13
            )
        ) {
            throw ValidationException::withMessages([
                'start_fiscal_month' =>
                    'The fiscal month must be between 1 and 13 for FIXED_FISCAL_MONTH.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Agreement Date
        |--------------------------------------------------------------------------
        */

        if (
            $penaltyRule->start_type ===
            PenaltyRule::START_TYPE_AGREEMENT_DATE
            &&
            $penaltyRule->start_fiscal_month !== null
        ) {
            throw ValidationException::withMessages([
                'start_fiscal_month' =>
                    'The fiscal month must be empty for AGREEMENT_DATE.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Increment Period
        |--------------------------------------------------------------------------
        */

        if (
            $penaltyRule->increment_period !==
            PenaltyRule::INCREMENT_PERIOD_MONTH
        ) {
            throw ValidationException::withMessages([
                'increment_period' =>
                    'The penalty increment period must be MONTH.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Calculation Basis
        |--------------------------------------------------------------------------
        */

        if (! in_array(
            $penaltyRule->calculation_basis,
            [
                PenaltyRule::CALCULATION_BASIS_PRINCIPAL,
                PenaltyRule::CALCULATION_BASIS_OUTSTANDING,
            ],
            true
        )) {
            throw ValidationException::withMessages([
                'calculation_basis' =>
                    'The penalty rule has an invalid calculation basis.',
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | OVERLAP VALIDATION
    |--------------------------------------------------------------------------
    */

    /**
     * Prevent overlapping ACTIVE penalty rules of the same
     * start_type.
     *
     * Different start types are intentionally allowed to overlap.
     *
     * Example allowed:
     *
     * FIXED_FISCAL_MONTH
     * 2025-09-11 → NULL
     *
     * AGREEMENT_DATE
     * 2026-09-04 → NULL
     *
     * Example rejected:
     *
     * AGREEMENT_DATE
     * 2025-09-11 → NULL
     *
     * AGREEMENT_DATE
     * 2026-09-04 → NULL
     *
     * The PostgreSQL exclusion constraint remains the final
     * concurrency-safe protection.
     */
    private function ensureNoOverlappingActiveRule(
        array $data,
        ?string $ignoreId = null
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Active Only
        |--------------------------------------------------------------------------
        */

        if (
            ($data['is_active'] ?? true) !== true
        ) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Required Values
        |--------------------------------------------------------------------------
        */

        $startType =
            $data['start_type'] ?? null;

        $effectiveFrom =
            $data['effective_from'] ?? null;

        $effectiveTo =
            $data['effective_to'] ?? null;

        if (
            $startType === null
            || $effectiveFrom === null
        ) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Same Start Type
        |--------------------------------------------------------------------------
        */

        $query = PenaltyRule::query()
            ->where(
                'is_active',
                true
            )
            ->where(
                'start_type',
                $startType
            );

        /*
        |--------------------------------------------------------------------------
        | Ignore Current Rule
        |--------------------------------------------------------------------------
        */

        if ($ignoreId !== null) {
            $query->where(
                'id',
                '!=',
                $ignoreId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Existing Start <= New End
        |--------------------------------------------------------------------------
        */

        if ($effectiveTo !== null) {
            $query->whereDate(
                'effective_from',
                '<=',
                $effectiveTo
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Existing End >= New Start
        |--------------------------------------------------------------------------
        |
        | NULL effective_to represents an open-ended period.
        |
        */

        $query->where(function (
            Builder $query
        ) use (
            $effectiveFrom
        ) {
            $query
                ->whereNull(
                    'effective_to'
                )
                ->orWhereDate(
                    'effective_to',
                    '>=',
                    $effectiveFrom
                );
        });

        /*
        |--------------------------------------------------------------------------
        | Conflict
        |--------------------------------------------------------------------------
        */

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'effective_from' => [
                    sprintf(
                        'Another active penalty rule with the same commencement type (%s) already exists for an overlapping effective period.',
                        $startType
                    ),
                ],
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | AUDIT VALUES
    |--------------------------------------------------------------------------
    */

    /**
     * Extract only business-relevant values for audit logging.
     *
     * We intentionally do not include:
     *
     * - id
     * - created_at
     * - updated_at
     * - created_by
     * - updated_by
     *
     * Those are persistence metadata rather than configuration
     * changes.
     */
    private function getAuditValues(
        PenaltyRule $penaltyRule
    ): array {
        return [
            'name' =>
                $penaltyRule->name,

            'initial_rate' =>
                $this->normalizeAuditNumber(
                    $penaltyRule->initial_rate
                ),

            'increment_rate' =>
                $this->normalizeAuditNumber(
                    $penaltyRule->increment_rate
                ),

            'maximum_rate' =>
                $this->normalizeAuditNumber(
                    $penaltyRule->maximum_rate
                ),

            'start_type' =>
                $penaltyRule->start_type,

            'start_fiscal_month' =>
                $penaltyRule->start_fiscal_month,

            'increment_period' =>
                $penaltyRule->increment_period,

            'calculation_basis' =>
                $penaltyRule->calculation_basis,

            'effective_from' =>
                $penaltyRule->effective_from?->format(
                    'Y-m-d'
                ),

            'effective_to' =>
                $penaltyRule->effective_to?->format(
                    'Y-m-d'
                ),

            'is_active' =>
                $penaltyRule->is_active,

            'legal_reference' =>
                $penaltyRule->legal_reference,

            'description' =>
                $penaltyRule->description,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | CHANGED AUDIT VALUES
    |--------------------------------------------------------------------------
    */

    /**
     * Return only fields whose business values changed.
     */
    private function getChangedAuditValues(
        array $oldValues,
        array $newValues
    ): array {
        $oldChanged = [];
        $newChanged = [];

        $keys = array_unique(
            array_merge(
                array_keys($oldValues),
                array_keys($newValues)
            )
        );

        foreach ($keys as $key) {
            $oldValue =
                $oldValues[$key] ?? null;

            $newValue =
                $newValues[$key] ?? null;

            if (
                ! $this->auditValuesEqual(
                    $oldValue,
                    $newValue
                )
            ) {
                $oldChanged[$key] =
                    $oldValue;

                $newChanged[$key] =
                    $newValue;
            }
        }

        return [
            'old' => $oldChanged,
            'new' => $newChanged,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | AUDIT VALUE COMPARISON
    |--------------------------------------------------------------------------
    */

    /**
     * Compare audit values safely.
     */
    private function auditValuesEqual(
        mixed $oldValue,
        mixed $newValue
    ): bool {
        if (
            is_array($oldValue)
            ||
            is_array($newValue)
        ) {
            return $oldValue === $newValue;
        }

        return $oldValue === $newValue;
    }

    /*
    |--------------------------------------------------------------------------
    | AUDIT NUMBER NORMALIZATION
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize decimal values before writing them into the
     * audit payload.
     *
     * Laravel's decimal cast may return a string such as:
     *
     * "5.0000"
     *
     * Keeping the representation consistent prevents false
     * audit changes caused only by decimal formatting.
     */
    private function normalizeAuditNumber(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        return number_format(
            (float) $value,
            4,
            '.',
            ''
        );
    }
}
