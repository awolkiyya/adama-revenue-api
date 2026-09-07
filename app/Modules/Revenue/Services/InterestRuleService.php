<?php

namespace App\Modules\Revenue\Services;

use App\Models\InterestRule;
use App\Services\SystemLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class InterestRuleService
{
    /**
     * Audit module identifier.
     */
    private const MODULE = 'interest_rules';

    /**
     * Fields representing the actual interest-rule business state.
     *
     * Persistence metadata such as UUIDs, timestamps and audit-user
     * fields are intentionally excluded.
     */
    private const AUDIT_FIELDS = [
        'rate',
        'rate_period',
        'calculation_method',
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
    | LIST
    |--------------------------------------------------------------------------
    */

    /**
     * Paginate interest rules.
     */
    public function paginate(
        int $page = 1,
        int $perPage = 15,
        ?string $search = null,
        ?bool $isActive = null,
        ?string $ratePeriod = null,
        ?string $calculationMethod = null,
        ?string $calculationBasis = null,
        ?string $sortBy = 'effective_from',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator {
        $query = InterestRule::query();

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (
            $search !== null
            && trim($search) !== ''
        ) {
            $search = trim($search);

            $query->where(function (
                Builder $q
            ) use ($search) {
                $q->where(
                    DB::raw('CAST(rate AS TEXT)'),
                    'ILIKE',
                    "%{$search}%"
                )
                    ->orWhere(
                        'rate_period',
                        'ILIKE',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'calculation_method',
                        'ILIKE',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'calculation_basis',
                        'ILIKE',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'legal_reference',
                        'ILIKE',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'description',
                        'ILIKE',
                        "%{$search}%"
                    )
                    ->orWhere(
                        DB::raw(
                            "TO_CHAR(effective_from, 'YYYY-MM-DD')"
                        ),
                        'ILIKE',
                        "%{$search}%"
                    )
                    ->orWhere(
                        DB::raw(
                            "TO_CHAR(effective_to, 'YYYY-MM-DD')"
                        ),
                        'ILIKE',
                        "%{$search}%"
                    );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        if ($isActive !== null) {
            $query->where(
                'is_active',
                $isActive
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Rate Period
        |--------------------------------------------------------------------------
        */

        if ($ratePeriod !== null) {
            $query->where(
                'rate_period',
                $ratePeriod
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Calculation Method
        |--------------------------------------------------------------------------
        */

        if ($calculationMethod !== null) {
            $query->where(
                'calculation_method',
                $calculationMethod
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Calculation Basis
        |--------------------------------------------------------------------------
        */

        if ($calculationBasis !== null) {
            $query->where(
                'calculation_basis',
                $calculationBasis
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Sorting
        |--------------------------------------------------------------------------
        */

        $allowedSorts = [
            'rate',
            'rate_period',
            'calculation_method',
            'calculation_basis',
            'effective_from',
            'effective_to',
            'is_active',
            'created_at',
            'updated_at',
        ];

        if (! in_array(
            $sortBy,
            $allowedSorts,
            true
        )) {
            $sortBy = 'effective_from';
        }

        $sortDirection =
            strtolower($sortDirection) === 'asc'
                ? 'asc'
                : 'desc';

        $query
            ->orderBy(
                $sortBy,
                $sortDirection
            )
            ->orderByDesc('created_at');

        return $query->paginate(
            perPage: $perPage,
            page: $page
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FIND
    |--------------------------------------------------------------------------
    */

    /**
     * Find an interest rule by UUID.
     */
    public function find(
        string $id
    ): InterestRule {
        return InterestRule::query()
            ->findOrFail($id);
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */

    /**
     * Create an interest rule.
     */
    public function create(
        array $data,
        ?string $userId = null
    ): InterestRule {
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

            $this->validateBusinessRules(
                $data
            );

            /*
            |--------------------------------------------------------------------------
            | Effective Period
            |--------------------------------------------------------------------------
            */

            $this->validateEffectivePeriod(
                effectiveFrom: $data['effective_from'],
                effectiveTo: $data['effective_to'] ?? null,
                isActive: $data['is_active'] ?? true
            );

            /*
            |--------------------------------------------------------------------------
            | Audit Ownership
            |--------------------------------------------------------------------------
            */

            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;

            /*
            |--------------------------------------------------------------------------
            | Create
            |--------------------------------------------------------------------------
            */

            try {
                $interestRule = InterestRule::create(
                    $data
                );
            } catch (Throwable $exception) {
                $this->handleDatabaseConstraint(
                    $exception
                );

                throw $exception;
            }

            /*
            |--------------------------------------------------------------------------
            | Refresh
            |--------------------------------------------------------------------------
            */

            $interestRule->refresh();

            /*
            |--------------------------------------------------------------------------
            | Audit CREATE
            |--------------------------------------------------------------------------
            */

            $this->systemLogService->created(
                resource: $interestRule,
                module: self::MODULE,
                description: 'Interest rule created successfully.',
                newValues: $this->getAuditValues(
                    $interestRule
                ),
                metadata: [
                    'operation' => 'create',
                    'rate_period' =>
                        $interestRule->rate_period,
                    'calculation_method' =>
                        $interestRule->calculation_method,
                    'calculation_basis' =>
                        $interestRule->calculation_basis,
                ],
            );

            return $interestRule;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    /**
     * Update an existing interest rule.
     *
     * The supplied data may be partial, but validation is performed
     * against the complete resulting business state.
     */
    public function update(
        InterestRule $interestRule,
        array $data,
        ?string $userId = null
    ): InterestRule {
        return DB::transaction(function () use (
            $interestRule,
            $data,
            $userId
        ) {
            /*
            |--------------------------------------------------------------------------
            | Lock Current Record
            |--------------------------------------------------------------------------
            */

            $interestRule = InterestRule::query()
                ->whereKey(
                    $interestRule->getKey()
                )
                ->lockForUpdate()
                ->firstOrFail();

            /*
            |--------------------------------------------------------------------------
            | Old Business State
            |--------------------------------------------------------------------------
            */

            $oldValues = $this->getAuditValues(
                $interestRule
            );

            /*
            |--------------------------------------------------------------------------
            | Build Complete Candidate State
            |--------------------------------------------------------------------------
            */

            $candidate = array_merge(
                $oldValues,
                $data
            );

            /*
            |--------------------------------------------------------------------------
            | Normalize
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
            | Effective Period
            |--------------------------------------------------------------------------
            */

            $this->validateEffectivePeriod(
                effectiveFrom:
                    $candidate['effective_from'],

                effectiveTo:
                    $candidate['effective_to'] ?? null,

                isActive:
                    $candidate['is_active'] ?? true,

                ignoreId:
                    $interestRule->id
            );

            /*
            |--------------------------------------------------------------------------
            | Prepare Update
            |--------------------------------------------------------------------------
            |
            | Only canonical business fields may be updated.
            |
            | created_by is intentionally never modified.
            |
            */

            $updateData = [];

            foreach (
                self::AUDIT_FIELDS as $field
            ) {
                if (
                    array_key_exists(
                        $field,
                        $candidate
                    )
                ) {
                    $updateData[$field] =
                        $candidate[$field];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | System-Controlled Fields
            |--------------------------------------------------------------------------
            */

            $updateData['updated_by'] =
                $userId;

            /*
            |--------------------------------------------------------------------------
            | Update
            |--------------------------------------------------------------------------
            */

            try {
                $interestRule->fill(
                    $updateData
                );

                $interestRule->save();
            } catch (Throwable $exception) {
                $this->handleDatabaseConstraint(
                    $exception
                );

                throw $exception;
            }

            /*
            |--------------------------------------------------------------------------
            | Refresh
            |--------------------------------------------------------------------------
            */

            $interestRule->refresh();

            /*
            |--------------------------------------------------------------------------
            | New Business State
            |--------------------------------------------------------------------------
            */

            $newValues = $this->getAuditValues(
                $interestRule
            );

            /*
            |--------------------------------------------------------------------------
            | Detect Actual Business Changes
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
            | Do not create an audit entry when no business
            | configuration actually changed.
            |
            */

            if (
                $changedValues['old'] !== []
                || $changedValues['new'] !== []
            ) {
                $this->systemLogService->updated(
                    resource: $interestRule,
                    module: self::MODULE,
                    oldValues:
                        $changedValues['old'],
                    newValues:
                        $changedValues['new'],
                    description:
                        'Interest rule updated successfully.',
                    metadata: [
                        'operation' => 'update',
                    ],
                );
            }

            return $interestRule;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVATE
    |--------------------------------------------------------------------------
    */

    /**
     * Activate an inactive interest rule.
     */
    public function activate(
        InterestRule $interestRule,
        ?string $userId = null
    ): InterestRule {
        return DB::transaction(function () use (
            $interestRule,
            $userId
        ) {
            /*
            |--------------------------------------------------------------------------
            | Lock Current Record
            |--------------------------------------------------------------------------
            */

            $interestRule = InterestRule::query()
                ->whereKey(
                    $interestRule->getKey()
                )
                ->lockForUpdate()
                ->firstOrFail();

            /*
            |--------------------------------------------------------------------------
            | Already Active
            |--------------------------------------------------------------------------
            */

            if (
                $interestRule->is_active === true
            ) {
                return $interestRule;
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Existing Rule
            |--------------------------------------------------------------------------
            */

            $this->validateBusinessRules([
                ...$this->getAuditValues(
                    $interestRule
                ),
                'is_active' => true,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Validate Effective Period
            |--------------------------------------------------------------------------
            */

            $this->validateEffectivePeriod(
                effectiveFrom:
                    $interestRule
                        ->effective_from
                        ->format('Y-m-d'),

                effectiveTo:
                    $interestRule
                        ->effective_to
                        ?->format('Y-m-d'),

                isActive: true,

                ignoreId:
                    $interestRule->id
            );

            /*
            |--------------------------------------------------------------------------
            | Old State
            |--------------------------------------------------------------------------
            */

            $oldValues = $this->getAuditValues(
                $interestRule
            );

            /*
            |--------------------------------------------------------------------------
            | Activate
            |--------------------------------------------------------------------------
            */

            try {
                $interestRule->update([
                    'is_active' => true,
                    'updated_by' => $userId,
                ]);
            } catch (Throwable $exception) {
                $this->handleDatabaseConstraint(
                    $exception
                );

                throw $exception;
            }

            /*
            |--------------------------------------------------------------------------
            | Refresh
            |--------------------------------------------------------------------------
            */

            $interestRule->refresh();

            /*
            |--------------------------------------------------------------------------
            | New State
            |--------------------------------------------------------------------------
            */

            $newValues = $this->getAuditValues(
                $interestRule
            );

            /*
            |--------------------------------------------------------------------------
            | Audit ACTIVATE
            |--------------------------------------------------------------------------
            */

            $this->systemLogService->updated(
                resource: $interestRule,
                module: self::MODULE,
                oldValues: $oldValues,
                newValues: $newValues,
                description:
                    'Interest rule activated successfully.',
                metadata: [
                    'operation' => 'activate',
                    'previous_status' => false,
                    'new_status' => true,
                ],
            );

            return $interestRule;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | DEACTIVATE
    |--------------------------------------------------------------------------
    */

    /**
     * Deactivate an active interest rule.
     */
    public function deactivate(
        InterestRule $interestRule,
        ?string $userId = null
    ): InterestRule {
        return DB::transaction(function () use (
            $interestRule,
            $userId
        ) {
            /*
            |--------------------------------------------------------------------------
            | Lock Current Record
            |--------------------------------------------------------------------------
            */

            $interestRule = InterestRule::query()
                ->whereKey(
                    $interestRule->getKey()
                )
                ->lockForUpdate()
                ->firstOrFail();

            /*
            |--------------------------------------------------------------------------
            | Already Inactive
            |--------------------------------------------------------------------------
            */

            if (
                $interestRule->is_active === false
            ) {
                return $interestRule;
            }

            /*
            |--------------------------------------------------------------------------
            | Old State
            |--------------------------------------------------------------------------
            */

            $oldValues = $this->getAuditValues(
                $interestRule
            );

            /*
            |--------------------------------------------------------------------------
            | Deactivate
            |--------------------------------------------------------------------------
            */

            try {
                $interestRule->update([
                    'is_active' => false,
                    'updated_by' => $userId,
                ]);
            } catch (Throwable $exception) {
                $this->handleDatabaseConstraint(
                    $exception
                );

                throw $exception;
            }

            /*
            |--------------------------------------------------------------------------
            | Refresh
            |--------------------------------------------------------------------------
            */

            $interestRule->refresh();

            /*
            |--------------------------------------------------------------------------
            | New State
            |--------------------------------------------------------------------------
            */

            $newValues = $this->getAuditValues(
                $interestRule
            );

            /*
            |--------------------------------------------------------------------------
            | Audit DEACTIVATE
            |--------------------------------------------------------------------------
            */

            $this->systemLogService->updated(
                resource: $interestRule,
                module: self::MODULE,
                oldValues: $oldValues,
                newValues: $newValues,
                description:
                    'Interest rule deactivated successfully.',
                metadata: [
                    'operation' => 'deactivate',
                    'previous_status' => true,
                    'new_status' => false,
                ],
            );

            return $interestRule;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | CURRENT / APPLICABLE RULE
    |--------------------------------------------------------------------------
    */

    /**
     * Return the active interest rule applicable to
     * the supplied date.
     */
    public function getApplicableRule(
        ?string $date = null
    ): ?InterestRule {
        $date ??= now()->toDateString();

        return InterestRule::query()
            ->where(
                'is_active',
                true
            )
            ->whereDate(
                'effective_from',
                '<=',
                $date
            )
            ->where(function (
                Builder $query
            ) use ($date) {
                $query
                    ->whereNull(
                        'effective_to'
                    )
                    ->orWhereDate(
                        'effective_to',
                        '>=',
                        $date
                    );
            })
            ->orderByDesc(
                'effective_from'
            )
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | CURRENT ACTIVE RULE
    |--------------------------------------------------------------------------
    */

    /**
     * Return the interest rule currently
     * applicable to today's date.
     */
    public function getCurrentRule(): ?InterestRule
    {
        return $this->getApplicableRule(
            now()->toDateString()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */

    /**
     * Interest rules are financial/legal configuration.
     *
     * They must not be physically deleted because
     * historical calculations and audit trails may
     * depend on them.
     */
    public function delete(
        InterestRule $interestRule
    ): void {
        throw ValidationException::withMessages([
            'interest_rule' => [
                'Interest rules cannot be deleted. '
                . 'Deactivate or expire the rule instead.',
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize interest-rule input.
     */
    private function normalize(
        array $data
    ): array {
        /*
        |--------------------------------------------------------------------------
        | Active State
        |--------------------------------------------------------------------------
        */

        if (
            !array_key_exists(
                'is_active',
                $data
            )
        ) {
            $data['is_active'] = true;
        }

        $data['is_active'] =
            filter_var(
                $data['is_active'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            )
            ?? (bool) $data['is_active'];

        /*
        |--------------------------------------------------------------------------
        | Text Fields
        |--------------------------------------------------------------------------
        */

        if (
            array_key_exists(
                'legal_reference',
                $data
            )
        ) {
            $data['legal_reference'] =
                $data['legal_reference'] !== null
                    ? trim(
                        (string)
                            $data['legal_reference']
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
                        (string)
                            $data['description']
                    )
                    : null;
        }

        /*
        |--------------------------------------------------------------------------
        | Rate Period
        |--------------------------------------------------------------------------
        |
        | Preserve the configured period.
        |
        */

        if (
            array_key_exists(
                'rate_period',
                $data
            )
        ) {
            $data['rate_period'] =
                strtoupper(
                    trim(
                        (string)
                            $data['rate_period']
                    )
                );
        }

        /*
        |--------------------------------------------------------------------------
        | Calculation Method
        |--------------------------------------------------------------------------
        */

        if (
            array_key_exists(
                'calculation_method',
                $data
            )
        ) {
            $data['calculation_method'] =
                strtoupper(
                    trim(
                        (string)
                            $data['calculation_method']
                    )
                );
        }

        /*
        |--------------------------------------------------------------------------
        | Calculation Basis
        |--------------------------------------------------------------------------
        */

        if (
            array_key_exists(
                'calculation_basis',
                $data
            )
        ) {
            $data['calculation_basis'] =
                strtoupper(
                    trim(
                        (string)
                            $data['calculation_basis']
                    )
                );
        }

        /*
        |--------------------------------------------------------------------------
        | Remove Unsupported Fields
        |--------------------------------------------------------------------------
        |
        | These must never reach the model or audit payload.
        |
        */

        unset(
            $data['creator'],
            $data['updater']
        );

        return $data;
    }

    /*
    |--------------------------------------------------------------------------
    | BUSINESS VALIDATION
    |--------------------------------------------------------------------------
    */

    /**
     * Validate the complete interest-rule business state.
     */
    private function validateBusinessRules(
        array $data
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Rate
        |--------------------------------------------------------------------------
        */

        $rate = $data['rate'] ?? null;

        if (
            $rate === null
            || !is_numeric($rate)
        ) {
            throw ValidationException::withMessages([
                'rate' => [
                    'The interest rate must be a valid number.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Rate Bounds
        |--------------------------------------------------------------------------
        */

        if (
            bccomp(
                (string) $rate,
                '0',
                4
            ) < 0
        ) {
            throw ValidationException::withMessages([
                'rate' => [
                    'The interest rate cannot be negative.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Rate Period
        |--------------------------------------------------------------------------
        */

        $ratePeriod =
            $data['rate_period'] ?? null;

        if (!in_array(
            $ratePeriod,
            [
                InterestRule::RATE_PERIOD_YEAR,
                InterestRule::RATE_PERIOD_MONTH,
                InterestRule::RATE_PERIOD_DAY,
            ],
            true
        )) {
            throw ValidationException::withMessages([
                'rate_period' => [
                    'The selected interest rate period is invalid.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Calculation Method
        |--------------------------------------------------------------------------
        */

        $calculationMethod =
            $data['calculation_method'] ?? null;

        if (!in_array(
            $calculationMethod,
            [
                InterestRule::METHOD_SIMPLE,
                InterestRule::METHOD_COMPOUND,
            ],
            true
        )) {
            throw ValidationException::withMessages([
                'calculation_method' => [
                    'The selected interest calculation method is invalid.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Calculation Basis
        |--------------------------------------------------------------------------
        */

        $calculationBasis =
            $data['calculation_basis'] ?? null;

        if (!in_array(
            $calculationBasis,
            [
                InterestRule::BASIS_PRINCIPAL,
                InterestRule::BASIS_OUTSTANDING,
            ],
            true
        )) {
            throw ValidationException::withMessages([
                'calculation_basis' => [
                    'The selected interest calculation basis is invalid.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Effective From
        |--------------------------------------------------------------------------
        */

        if (
            empty(
                $data['effective_from'] ?? null
            )
        ) {
            throw ValidationException::withMessages([
                'effective_from' => [
                    'The effective start date is required.',
                ],
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | EFFECTIVE PERIOD VALIDATION
    |--------------------------------------------------------------------------
    */

    /**
     * Validate the effective period and prevent overlapping
     * active interest rules.
     *
     * PostgreSQL exclusion constraint remains the final
     * concurrency-safe protection.
     */
    protected function validateEffectivePeriod(
        string $effectiveFrom,
        ?string $effectiveTo,
        bool $isActive,
        ?string $ignoreId = null
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Validate Date Order
        |--------------------------------------------------------------------------
        */

        if (
            $effectiveTo !== null
            && $effectiveTo < $effectiveFrom
        ) {
            throw ValidationException::withMessages([
                'effective_to' => [
                    'The effective end date must be after or equal to '
                    . 'the effective start date.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Inactive Rules
        |--------------------------------------------------------------------------
        |
        | Inactive rules do not participate in overlap
        | validation.
        |
        */

        if (! $isActive) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Find Overlapping Active Rule
        |--------------------------------------------------------------------------
        |
        | Business periods are inclusive:
        |
        | Existing.start <= New.end
        | AND
        | Existing.end >= New.start
        |
        | NULL effective_to represents an open-ended period.
        |
        */

        $query = InterestRule::query()
            ->where(
                'is_active',
                true
            )
            ->where(
                'effective_from',
                '<=',
                $effectiveTo
                    ?? '9999-12-31'
            )
            ->where(function (
                Builder $query
            ) use (
                $effectiveFrom
            ) {
                $query
                    ->whereNull(
                        'effective_to'
                    )
                    ->orWhere(
                        'effective_to',
                        '>=',
                        $effectiveFrom
                    );
            });

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
        | Conflict
        |--------------------------------------------------------------------------
        */

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'effective_from' => [
                    'The selected effective period overlaps with '
                    . 'another active interest rule.',
                ],
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DATABASE CONSTRAINT HANDLING
    |--------------------------------------------------------------------------
    */

    /**
     * Convert known PostgreSQL constraint violations
     * into user-friendly validation errors.
     */
    protected function handleDatabaseConstraint(
        Throwable $exception
    ): void {
        $message =
            $exception->getMessage();

        /*
        |--------------------------------------------------------------------------
        | PostgreSQL Exclusion Constraint
        |--------------------------------------------------------------------------
        */

        if (
            str_contains(
                $message,
                'interest_rules_no_overlapping_periods'
            )
        ) {
            throw ValidationException::withMessages([
                'effective_from' => [
                    'The selected effective period overlaps with '
                    . 'another active interest rule.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Rate CHECK Constraint
        |--------------------------------------------------------------------------
        */

        if (
            str_contains(
                $message,
                'interest_rules_rate_non_negative'
            )
        ) {
            throw ValidationException::withMessages([
                'rate' => [
                    'The interest rate cannot be negative.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Rate Period CHECK Constraint
        |--------------------------------------------------------------------------
        */

        if (
            str_contains(
                $message,
                'interest_rules_rate_period_check'
            )
        ) {
            throw ValidationException::withMessages([
                'rate_period' => [
                    'The selected rate period is invalid.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Calculation Method CHECK Constraint
        |--------------------------------------------------------------------------
        */

        if (
            str_contains(
                $message,
                'interest_rules_calculation_method_check'
            )
        ) {
            throw ValidationException::withMessages([
                'calculation_method' => [
                    'The selected interest calculation method is invalid.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Calculation Basis CHECK Constraint
        |--------------------------------------------------------------------------
        */

        if (
            str_contains(
                $message,
                'interest_rules_calculation_basis_check'
            )
        ) {
            throw ValidationException::withMessages([
                'calculation_basis' => [
                    'The selected interest calculation basis is invalid.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Effective Period CHECK Constraint
        |--------------------------------------------------------------------------
        */

        if (
            str_contains(
                $message,
                'interest_rules_valid_effective_period'
            )
        ) {
            throw ValidationException::withMessages([
                'effective_to' => [
                    'The effective end date must be after or equal to '
                    . 'the effective start date.',
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
     * Extract business-relevant values for audit logging.
     */
    private function getAuditValues(
        InterestRule $interestRule
    ): array {
        return [
            'rate' =>
                $this->normalizeAuditNumber(
                    $interestRule->rate
                ),

            'rate_period' =>
                $interestRule->rate_period,

            'calculation_method' =>
                $interestRule->calculation_method,

            'calculation_basis' =>
                $interestRule->calculation_basis,

            'effective_from' =>
                $interestRule
                    ->effective_from
                    ?->format('Y-m-d'),

            'effective_to' =>
                $interestRule
                    ->effective_to
                    ?->format('Y-m-d'),

            'is_active' =>
                (bool)
                    $interestRule->is_active,

            'legal_reference' =>
                $interestRule->legal_reference,

            'description' =>
                $interestRule->description,
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
            || is_array($newValue)
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
     * Normalize decimal values before writing
     * them into the audit payload.
     *
     * This is only for audit representation.
     * Financial calculations must continue to use BCMath.
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