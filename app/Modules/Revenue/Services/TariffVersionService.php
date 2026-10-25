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
     * If no active tariff exists, the first non-future-effective version
     * becomes active. Future-effective tariffs remain inactive.
     */
    public function create(array $data): TariffVersion
    {
        try {
            return DB::transaction(function () use ($data): TariffVersion {
                $year = (int) $data['year'];

                $this->lockYear($year);

                $nextVersion = $this->nextVersionNumber($year);

                $effectiveFrom = $data['effective_from'] ?? null;
                $isFutureEffective = $this->isFutureDate($effectiveFrom);

                $requestedActive = filter_var(
                    $data['is_active'] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                );

                if ($requestedActive && $isFutureEffective) {
                    throw ValidationException::withMessages([
                        'effective_from' => [
                            'A future-effective tariff cannot be activated before its effective date.',
                        ],
                    ]);
                }

                $this->lockActiveTariff();

                $hasActiveVersion = TariffVersion::query()
                    ->where('is_active', true)
                    ->exists();

                $shouldActivate = $requestedActive
                    || (!$hasActiveVersion && !$isFutureEffective);

                unset(
                    $data['id'],
                    $data['year'],
                    $data['version'],
                    $data['is_active'],
                    $data['created_at'],
                    $data['updated_at'],
                    $data['deleted_at']
                );

                if ($shouldActivate) {
                    TariffVersion::query()
                        ->where('is_active', true)
                        ->update(['is_active' => false]);
                }

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

                /*
                 * Serialize allocation for the requested target year.
                 */
                $this->lockYear($year);

                $nextVersion = $this->nextVersionNumber($year);

                /*
                 * Create a new inactive tariff version.
                 * Generated values cannot be overridden by client input.
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
                    /*
                     * Generate a unique code for the copied rule.
                     *
                     * Do not reuse $sourceRule->code: tariff rule codes
                     * are globally unique in the database.
                     */
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

                    /*
                     * Copy formula-variable configuration while allowing
                     * the new rule to own its own variable records.
                     */
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
     * Year and version are immutable through this method.
     */
    public function update(string $id, array $data): TariffVersion
    {
        return DB::transaction(function () use ($id, $data): TariffVersion {
            $requestedActive = filter_var(
                $data['is_active'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            if ($requestedActive) {
                $this->lockActiveTariff();
            }

            $tariffVersion = TariffVersion::query()
                ->lockForUpdate()
                ->findOrFail($id);

            unset(
                $data['id'],
                $data['year'],
                $data['version'],
                $data['created_at'],
                $data['updated_at'],
                $data['deleted_at']
            );

            if ($requestedActive) {
                $effectiveFrom = $data['effective_from']
                    ?? $tariffVersion->effective_from;

                if ($this->isFutureDate($effectiveFrom)) {
                    throw ValidationException::withMessages([
                        'effective_from' => [
                            'A future-effective tariff cannot be activated before its effective date.',
                        ],
                    ]);
                }

                TariffVersion::query()
                    ->where('is_active', true)
                    ->where('id', '!=', $tariffVersion->id)
                    ->update(['is_active' => false]);
            }

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
     * Activate a tariff version and deactivate the previously active version.
     */
    public function activate(string $id): TariffVersion
    {
        return DB::transaction(function () use ($id): TariffVersion {
            $this->lockActiveTariff();

            $tariffVersion = TariffVersion::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($this->isFutureDate($tariffVersion->effective_from)) {
                throw ValidationException::withMessages([
                    'effective_from' => [
                        'Cannot activate a tariff version before its effective date.',
                    ],
                ]);
            }

            TariffVersion::query()
                ->where('is_active', true)
                ->where('id', '!=', $tariffVersion->id)
                ->update(['is_active' => false]);

            $tariffVersion->update(['is_active' => true]);

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

            /*
             * Keep restored tariffs inactive. A separate activation request
             * is required if the restored tariff should become active.
             */
            $tariffVersion->is_active = false;

            return (bool) $tariffVersion->restore();
        }, 3);
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
     * Serialize active-tariff changes.
     *
     * Must be called inside a database transaction.
     */
    private function lockActiveTariff(): void
    {
        DB::select(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            ['municipal-revenue:tariff-version:active']
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

    /**
     * Determine whether a date is later than today's date.
     */
    private function isFutureDate(mixed $date): bool
    {
        if ($date === null || $date === '') {
            return false;
        }

        $dateString = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : substr((string) $date, 0, 10);

        return $dateString > today()->toDateString();
    }

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
