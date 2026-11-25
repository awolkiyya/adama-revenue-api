<?php

declare(strict_types=1);

namespace App\Modules\Revenue\Services;

use App\Models\TariffRule;
use App\Models\TariffVersion;
use App\Services\DocumentSequenceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TariffVersionService
{
    private const VERSION_UNIQUE_CONSTRAINT =
        'tariff_versions_year_version_unique';

    private const BUSINESS_TIMEZONE = 'Africa/Addis_Ababa';

    private const ACTIVE_LOCK_KEY =
        'municipal-revenue:tariff-version:active';

    public function __construct(
        private readonly DocumentSequenceService $documentSequenceService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Read Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Paginate tariff versions using optional filters.
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = TariffVersion::query()
            ->withCount('tariffRules');

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $pattern = '%' . $search . '%';

            $query->where(function (Builder $builder) use ($pattern): void {
                $builder
                    ->where('name', 'ILIKE', $pattern)
                    ->orWhere('description', 'ILIKE', $pattern)
                    ->orWhereRaw('CAST(year AS TEXT) ILIKE ?', [$pattern])
                    ->orWhereRaw('CAST(version AS TEXT) ILIKE ?', [$pattern]);
            });
        }

        if (
            array_key_exists('year', $filters)
            && $filters['year'] !== ''
            && $filters['year'] !== null
        ) {
            $query->where('year', (int) $filters['year']);
        }

        if (
            array_key_exists('is_active', $filters)
            && $filters['is_active'] !== ''
            && $filters['is_active'] !== null
        ) {
            $isActive = filter_var(
                $filters['is_active'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );

            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }
        }

        $allowedSorts = [
            'year',
            'version',
            'name',
            'effective_from',
            'created_at',
        ];

        $sortBy = (string) ($filters['sort_by'] ?? 'year');

        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'year';
        }

        $sortDirection = strtolower(
            (string) ($filters['sort_direction'] ?? 'desc')
        );

        if (!in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        $query
            ->orderBy($sortBy, $sortDirection)
            ->orderBy('version', 'desc');

        $perPage = filter_var(
            $filters['per_page'] ?? 20,
            FILTER_VALIDATE_INT
        );

        if ($perPage === false || $perPage < 1) {
            $perPage = 20;
        }

        return $query->paginate(min($perPage, 100));
    }

    /**
     * Return the active tariff summary for the dashboard.
     */
    public function summary(): array
    {
        $activeVersion = TariffVersion::query()
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->first();

        if ($activeVersion === null) {
            return ['message' => null];
        }

        return [
            'message' => sprintf(
                '%s is the currently active tariff — all new assessments use its pricing rules.',
                $activeVersion->year
            ),
        ];
    }

    /**
     * Find a tariff version by ID.
     */
    public function find(string $id): TariffVersion
    {
        return TariffVersion::query()
            ->withCount('tariffRules')
            ->findOrFail($id);
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    /**
     * Create a tariff version with a server-generated version number.
     *
     * The first currently effective version becomes active if no active
     * version exists.
     *
     * If the new version is activated, its effective period must not
     * overlap another non-deleted tariff version.
     */
    public function create(array $data): TariffVersion
    {
        try {
            return DB::transaction(function () use ($data): TariffVersion {
                $year = (int) $data['year'];

                $this->lockYear($year);

                /*
                 * Serialize active-tariff transitions before checking
                 * whether an active version already exists.
                 */
                $this->lockActiveTariff();

                $nextVersion = $this->nextVersionNumber($year);

                $effectiveFrom = $data['effective_from'] ?? null;
                $effectiveTo = $data['effective_to'] ?? null;

                $requestedActive = $this->toBoolean(
                    $data['is_active'] ?? false
                );

                $hasActiveVersion = TariffVersion::query()
                    ->where('is_active', true)
                    ->exists();

                $shouldActivate = $requestedActive
                    || (
                        !$hasActiveVersion
                        && $this->isCurrentlyEffective(
                            $effectiveFrom,
                            $effectiveTo
                        )
                    );

                if ($shouldActivate) {
                    $this->validateActivationPeriod(
                        $effectiveFrom,
                        $effectiveTo
                    );

                    /*
                     * Close the previous active tariff before checking
                     * for overlapping effective periods.
                     */
                    $this->closePreviousActiveTariff(
                        $this->normalizeDate($effectiveFrom)
                    );

                    $this->assertNoOverlappingPeriods(
                        $effectiveFrom,
                        $effectiveTo
                    );
                }

                unset(
                    $data['id'],
                    $data['year'],
                    $data['version'],
                    $data['is_active'],
                    $data['created_at'],
                    $data['updated_at'],
                    $data['deleted_at']
                );

                $tariffVersion = TariffVersion::query()->create(
                    array_merge($data, [
                        'year' => $year,
                        'version' => $nextVersion,
                        'is_active' => $shouldActivate,
                    ])
                );

                return $tariffVersion->loadCount('tariffRules');
            }, 3);
        } catch (QueryException $exception) {
            $this->rethrowVersionConflict($exception);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate
    |--------------------------------------------------------------------------
    */

    /**
     * Duplicate a tariff version into a new inactive version.
     *
     * Copies non-deleted tariff rules and their non-deleted formula
     * variables. Every copied tariff rule receives a newly generated code.
     *
     * Does not copy assessments, invoices, payments, or approval history.
     */
    public function duplicate(string $id, array $data): TariffVersion
    {
        try {
            return DB::transaction(function () use ($id, $data): TariffVersion {
                $source = TariffVersion::query()
                    ->with('tariffRules.formulaVariables')
                    ->findOrFail($id);

                $year = (int) $data['year'];

                $this->lockYear($year);

                $nextVersion = $this->nextVersionNumber($year);

                /*
                 * A duplicate is always a draft.
                 *
                 * Its effective period is not activated or used to
                 * change another tariff's effective period.
                 */
                $newVersion = TariffVersion::query()->create([
                    'year' => $year,
                    'version' => $nextVersion,
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'effective_from' => $data['effective_from'],
                    'effective_to' => $data['effective_to'] ?? null,
                    'is_active' => false,
                ]);

                $userId = auth()->id();

                foreach ($source->tariffRules as $sourceRule) {
                    $newRuleCode = $this->documentSequenceService->generate(
                        'tariff_rule'
                    );

                    $newRule = TariffRule::query()->create([
                        'tariff_version_id' => $newVersion->id,
                        'service_id' => $sourceRule->service_id,
                        'code' => $newRuleCode,
                        'name' => $sourceRule->name,
                        'description' => $sourceRule->description,
                        'calculation_type' => $sourceRule->calculation_type,
                        'base_field_id' => $sourceRule->base_field_id,
                        'measurement_unit_id' => $sourceRule->measurement_unit_id,
                        'priority' => $sourceRule->priority,
                        'execution_order' => $sourceRule->execution_order,
                        'min_value' => $sourceRule->min_value,
                        'max_value' => $sourceRule->max_value,
                        'amount' => $sourceRule->amount,
                        'percentage' => $sourceRule->percentage,
                        'minimum_amount' => $sourceRule->minimum_amount,
                        'maximum_amount' => $sourceRule->maximum_amount,
                        'formula' => $sourceRule->formula,
                        'conditions' => $sourceRule->conditions,
                        'rounding_rule' => $sourceRule->rounding_rule,
                        'is_active' => $sourceRule->is_active,
                        'created_by' => $userId,
                        'updated_by' => null,
                    ]);

                    foreach ($sourceRule->formulaVariables as $sourceVariable) {
                        $newRule->formulaVariables()->create([
                            'code' => $sourceVariable->code,
                            'variable_name' => $sourceVariable->variable_name,
                            'label' => $sourceVariable->label,
                            'source_type' => $sourceVariable->source_type,
                            'base_field_id' => $sourceVariable->base_field_id,
                            'default_value' => $sourceVariable->default_value,
                            'data_type' => $sourceVariable->data_type,
                            'is_required' => $sourceVariable->is_required,
                            'sort_order' => $sourceVariable->sort_order,
                        ]);
                    }
                }

                return $newVersion
                    ->refresh()
                    ->loadCount('tariffRules');
            }, 3);
        } catch (QueryException $exception) {
            $this->rethrowVersionConflict($exception);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    /**
     * Update editable tariff metadata.
     *
     * Year and version are immutable.
     *
     * If is_active is omitted, the existing activation state is preserved.
     *
     * Updating an active tariff's effective period is subject to the same
     * overlap and date validation rules as activation.
     */
    public function update(string $id, array $data): TariffVersion
    {
        return DB::transaction(function () use ($id, $data): TariffVersion {
            /*
             * Acquire the advisory lock before the row lock.
             * All activation-related operations use the same lock order.
             */
            $this->lockActiveTariff();

            $tariffVersion = TariffVersion::query()
                ->lockForUpdate()
                ->findOrFail($id);

            $activationWasProvided = array_key_exists('is_active', $data);

            $requestedActive = $activationWasProvided
                ? $this->toBoolean($data['is_active'])
                : (bool) $tariffVersion->is_active;

            unset(
                $data['id'],
                $data['year'],
                $data['version'],
                $data['created_at'],
                $data['updated_at'],
                $data['deleted_at']
            );

            $effectiveFrom = array_key_exists('effective_from', $data)
                ? $data['effective_from']
                : $tariffVersion->effective_from;

            $effectiveTo = array_key_exists('effective_to', $data)
                ? $data['effective_to']
                : $tariffVersion->effective_to;

            if ($requestedActive) {
                $this->validateActivationPeriod(
                    $effectiveFrom,
                    $effectiveTo
                );

                $this->closePreviousActiveTariff(
                    $this->normalizeDate($effectiveFrom),
                    $tariffVersion->id
                );

                $this->assertNoOverlappingPeriods(
                    $effectiveFrom,
                    $effectiveTo,
                    $tariffVersion->id
                );
            }

            unset($data['is_active']);

            /*
             * Deactivate any other selected tariff when this version
             * is being activated.
             */
            if ($requestedActive) {
                TariffVersion::query()
                    ->where('is_active', true)
                    ->where('id', '!=', $tariffVersion->id)
                    ->update(['is_active' => false]);
            }

            $data['is_active'] = $requestedActive;

            $tariffVersion->update($data);

            return $tariffVersion
                ->refresh()
                ->loadCount('tariffRules');
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Activate
    |--------------------------------------------------------------------------
    */

    /**
     * Activate a tariff version.
     *
     * The previously active tariff is closed on the day before the
     * new tariff's effective_from date.
     *
     * Activation is rejected if another non-deleted tariff overlaps
     * the requested effective period.
     */
    public function activate(string $id): TariffVersion
    {
        return DB::transaction(function () use ($id): TariffVersion {
            $this->lockActiveTariff();

            $tariffVersion = TariffVersion::query()
                ->lockForUpdate()
                ->findOrFail($id);

            $effectiveFrom = $tariffVersion->effective_from;
            $effectiveTo = $tariffVersion->effective_to;

            $this->validateActivationPeriod(
                $effectiveFrom,
                $effectiveTo
            );

            /*
             * Close the previously active tariff.
             */
            $this->closePreviousActiveTariff(
                $this->normalizeDate($effectiveFrom),
                $tariffVersion->id
            );

            /*
             * Fail rather than silently changing the effective dates
             * of unrelated historical or draft versions.
             */
            $this->assertNoOverlappingPeriods(
                $effectiveFrom,
                $effectiveTo,
                $tariffVersion->id
            );

            /*
             * There must be only one selected active tariff.
             */
            TariffVersion::query()
                ->where('is_active', true)
                ->where('id', '!=', $tariffVersion->id)
                ->update(['is_active' => false]);

            $tariffVersion->update([
                'is_active' => true,
            ]);

            return $tariffVersion
                ->refresh()
                ->loadCount('tariffRules');
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    /**
     * Soft-delete an inactive tariff version.
     */
    public function delete(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $this->lockActiveTariff();

            $tariffVersion = TariffVersion::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($tariffVersion->is_active) {
                throw ValidationException::withMessages([
                    'tariff' => [
                        'An active tariff version cannot be deleted.',
                    ],
                ]);
            }

            return (bool) $tariffVersion->delete();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Restore
    |--------------------------------------------------------------------------
    */

    /**
     * Restore a soft-deleted tariff version as inactive.
     *
     * Restoration does not activate the tariff or alter another
     * tariff's effective period.
     */
    public function restore(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $tariffVersion = TariffVersion::withTrashed()
                ->lockForUpdate()
                ->findOrFail($id);

            if (!$tariffVersion->trashed()) {
                return true;
            }

            $tariffVersion->is_active = false;

            return (bool) $tariffVersion->restore();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Effective Period Management
    |--------------------------------------------------------------------------
    */

    /**
     * Close the previously active tariff when a new tariff is activated.
     *
     * The previous tariff's effective_to becomes the day before the
     * incoming tariff's effective_from date.
     *
     * If the previous tariff starts on or after the incoming tariff's
     * start date, the transition is rejected because the periods cannot
     * be represented as consecutive non-overlapping date ranges.
     */
    private function closePreviousActiveTariff(
        ?string $newEffectiveFrom,
        ?string $excludeId = null
    ): void {
        if ($newEffectiveFrom === null) {
            throw ValidationException::withMessages([
                'effective_from' => [
                    'A valid effective start date is required.',
                ],
            ]);
        }

        $previousActiveQuery = TariffVersion::query()
            ->where('is_active', true);

        if ($excludeId !== null) {
            $previousActiveQuery->where('id', '!=', $excludeId);
        }

        $previousActive = $previousActiveQuery
            ->lockForUpdate()
            ->first();

        if ($previousActive === null) {
            return;
        }

        $previousStart = $this->normalizeDate(
            $previousActive->effective_from
        );

        $previousEnd = $this->normalizeDate(
            $previousActive->effective_to
        );

        /*
         * A new tariff cannot replace an active tariff retroactively
         * when the previous tariff starts on or after the new one.
         */
        if (
            $previousStart !== null
            && $previousStart >= $newEffectiveFrom
        ) {
            throw ValidationException::withMessages([
                'effective_from' => [
                    'The new tariff must start after the previous active tariff starts. A retroactive activation is not permitted.',
                ],
            ]);
        }

        /*
         * If the previous tariff already ended before the new tariff
         * starts, only its selected active state needs to change.
         */
        if (
            $previousEnd !== null
            && $previousEnd < $newEffectiveFrom
        ) {
            $previousActive->update([
                'is_active' => false,
            ]);

            return;
        }

        $newEndDate = $this->previousDay($newEffectiveFrom);

        if (
            $previousStart !== null
            && $newEndDate < $previousStart
        ) {
            throw ValidationException::withMessages([
                'effective_from' => [
                    'The new tariff effective date would create an invalid effective period for the previous tariff.',
                ],
            ]);
        }

        $previousActive->update([
            'effective_to' => $newEndDate,
            'is_active' => false,
        ]);
    }

    /**
     * Reject effective periods that overlap another non-deleted tariff.
     *
     * Both effective_from and effective_to are inclusive.
     *
     * Soft-deleted tariff versions are excluded.
     */
    private function assertNoOverlappingPeriods(
        mixed $effectiveFrom,
        mixed $effectiveTo,
        ?string $excludeId = null
    ): void {
        $startDate = $this->normalizeDate($effectiveFrom);
        $endDate = $this->normalizeDate($effectiveTo);

        if ($startDate === null) {
            throw ValidationException::withMessages([
                'effective_from' => [
                    'A valid effective start date is required.',
                ],
            ]);
        }

        $query = TariffVersion::query()
            ->whereDate('effective_from', '<=', $endDate ?? '9999-12-31')
            ->where(function (Builder $builder) use ($startDate): void {
                $builder
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $startDate);
            });

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        $overlappingVersion = $query
            ->orderBy('effective_from')
            ->orderBy('version')
            ->first();

        if ($overlappingVersion !== null) {
            throw ValidationException::withMessages([
                'effective_from' => [
                    sprintf(
                        'The requested effective period overlaps tariff %s, version %s (%s to %s). Correct the conflicting effective period before activating this tariff.',
                        $overlappingVersion->year,
                        $overlappingVersion->version,
                        $this->normalizeDate(
                            $overlappingVersion->effective_from
                        ) ?? 'unknown',
                        $this->normalizeDate(
                            $overlappingVersion->effective_to
                        ) ?? 'open-ended'
                    ),
                ],
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Date Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Validate whether a tariff can be activated today.
     *
     * The effective_from and effective_to dates are inclusive.
     */
    private function validateActivationPeriod(
        mixed $effectiveFrom,
        mixed $effectiveTo
    ): void {
        $today = $this->today();

        $startDate = $this->normalizeDate($effectiveFrom);
        $endDate = $this->normalizeDate($effectiveTo);

        if ($startDate === null) {
            throw ValidationException::withMessages([
                'effective_from' => [
                    'A valid effective start date is required to activate a tariff.',
                ],
            ]);
        }

        if ($endDate !== null && $endDate < $startDate) {
            throw ValidationException::withMessages([
                'effective_to' => [
                    'The effective end date cannot be earlier than the effective start date.',
                ],
            ]);
        }

        if ($startDate > $today) {
            throw ValidationException::withMessages([
                'effective_from' => [
                    'A future-effective tariff cannot be activated before its effective date.',
                ],
            ]);
        }

        if ($endDate !== null && $endDate < $today) {
            throw ValidationException::withMessages([
                'effective_to' => [
                    'An expired tariff version cannot be activated.',
                ],
            ]);
        }
    }

    /**
     * Determine whether a date range includes today.
     *
     * The effective_to date is inclusive.
     */
    private function isCurrentlyEffective(
        mixed $effectiveFrom,
        mixed $effectiveTo
    ): bool {
        $today = $this->today();

        $startDate = $this->normalizeDate($effectiveFrom);
        $endDate = $this->normalizeDate($effectiveTo);

        if ($startDate === null || $startDate > $today) {
            return false;
        }

        if ($endDate !== null && $endDate < $today) {
            return false;
        }

        return true;
    }

    /**
     * Normalize a date value to YYYY-MM-DD.
     */
    private function normalizeDate(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        $dateString = substr(trim((string) $date), 0, 10);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            return null;
        }

        $parsedDate = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $dateString,
            new \DateTimeZone(self::BUSINESS_TIMEZONE)
        );

        if (
            $parsedDate === false
            || $parsedDate->format('Y-m-d') !== $dateString
        ) {
            return null;
        }

        return $dateString;
    }

    /**
     * Return the day before a YYYY-MM-DD date.
     */
    private function previousDay(string $date): string
    {
        $dateObject = new \DateTimeImmutable(
            $date,
            new \DateTimeZone(self::BUSINESS_TIMEZONE)
        );

        return $dateObject
            ->modify('-1 day')
            ->format('Y-m-d');
    }

    /**
     * Get today's date in the municipal business timezone.
     */
    private function today(): string
    {
        return now(self::BUSINESS_TIMEZONE)->toDateString();
    }

    /**
     * Normalize boolean input safely.
     */
    private function toBoolean(mixed $value): bool
    {
        return filter_var(
            $value,
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL Concurrency Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Serialize version allocation for a year.
     *
     * Must be called inside a database transaction.
     */
    private function lockYear(int $year): void
    {
        DB::select(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            ['municipal-revenue:tariff-version:year:' . $year]
        );
    }

    /**
     * Serialize active-tariff and effective-period transitions.
     *
     * Must be called inside a database transaction.
     */
    private function lockActiveTariff(): void
    {
        DB::select(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            [self::ACTIVE_LOCK_KEY]
        );
    }

    /**
     * Generate the next version number for a year.
     *
     * Include soft-deleted rows because their version numbers remain
     * reserved by the database unique constraint.
     *
     * The caller must acquire the target year's advisory lock first.
     */
    private function nextVersionNumber(int $year): int
    {
        $latestVersion = TariffVersion::withTrashed()
            ->where('year', $year)
            ->max('version');

        return ((int) ($latestVersion ?? 0)) + 1;
    }

    /*
    |--------------------------------------------------------------------------
    | Database Exception Handling
    |--------------------------------------------------------------------------
    */

    /**
     * Translate only the known year/version unique constraint violation.
     */
    private function rethrowVersionConflict(QueryException $exception): never
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $message = $exception->getMessage();

        if (
            $sqlState === '23505'
            && str_contains($message, self::VERSION_UNIQUE_CONSTRAINT)
        ) {
            throw ValidationException::withMessages([
                'version' => [
                    'A tariff version with this year and version number already exists. Please retry the operation.',
                ],
            ]);
        }

        throw $exception;
    }
}
