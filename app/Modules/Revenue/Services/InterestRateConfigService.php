<?php

namespace App\Modules\Revenue\Services;

use App\Models\InterestRateConfig;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class InterestRateConfigService
{
    /**
     * ============================================================
     * LIST
     * ============================================================
     */
    public function paginate(
        int $page = 1,
        int $perPage = 15,
        ?string $search = null,
        ?bool $isActive = null,
        ?string $rateType = null,
        ?string $sortBy = 'effective_from',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator {
        $query = InterestRateConfig::query();

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */
        if ($search !== null && trim($search) !== '') {
            $search = trim($search);

            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('source', 'ILIKE', "%{$search}%")
                    ->orWhere('description', 'ILIKE', "%{$search}%");
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Filters
        |--------------------------------------------------------------------------
        */
        if ($isActive !== null) {
            $query->where('is_active', $isActive);
        }

        if ($rateType !== null) {
            $query->where('rate_type', $rateType);
        }

        /*
        |--------------------------------------------------------------------------
        | Sorting
        |--------------------------------------------------------------------------
        */
        $allowedSorts = [
            'name',
            'rate',
            'rate_type',
            'effective_from',
            'effective_to',
            'is_active',
            'created_at',
        ];

        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'effective_from';
        }

        $sortDirection = strtolower($sortDirection) === 'asc'
            ? 'asc'
            : 'desc';

        $query->orderBy($sortBy, $sortDirection);

        return $query->paginate(
            perPage: $perPage,
            page: $page
        );
    }

    /**
     * ============================================================
     * FIND
     * ============================================================
     */
    public function find(string $id): InterestRateConfig
    {
        return InterestRateConfig::query()->findOrFail($id);
    }

    /**
     * ============================================================
     * CREATE
     * ============================================================
     */
    public function create(
        array $data,
        ?string $userId = null
    ): InterestRateConfig {
        return DB::transaction(function () use ($data, $userId) {

            /*
            |--------------------------------------------------------------------------
            | Prevent overlapping active periods
            |--------------------------------------------------------------------------
            */
            $this->validateEffectivePeriod(
                effectiveFrom: $data['effective_from'],
                effectiveTo: $data['effective_to'] ?? null,
                rateType: $data['rate_type'] ?? 'ANNUAL'
            );

            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;

            return InterestRateConfig::create($data);
        });
    }

    /**
     * ============================================================
     * UPDATE
     * ============================================================
     */
    public function update(
        InterestRateConfig $interestRateConfig,
        array $data,
        ?string $userId = null
    ): InterestRateConfig {
        return DB::transaction(function () use (
            $interestRateConfig,
            $data,
            $userId
        ) {

            $effectiveFrom = $data['effective_from']
                ?? $interestRateConfig->effective_from;

            $effectiveTo = array_key_exists('effective_to', $data)
                ? $data['effective_to']
                : $interestRateConfig->effective_to;

            $rateType = $data['rate_type']
                ?? $interestRateConfig->rate_type;

            /*
            |--------------------------------------------------------------------------
            | Prevent overlapping active periods
            |--------------------------------------------------------------------------
            */
            $this->validateEffectivePeriod(
                effectiveFrom: $effectiveFrom,
                effectiveTo: $effectiveTo,
                rateType: $rateType,
                ignoreId: $interestRateConfig->id
            );

            $data['updated_by'] = $userId;

            $interestRateConfig->update($data);

            return $interestRateConfig->fresh();
        });
    }

    /**
     * ============================================================
     * ACTIVATE
     * ============================================================
     */
    public function activate(
        InterestRateConfig $interestRateConfig,
        ?string $userId = null
    ): InterestRateConfig {
        return DB::transaction(function () use (
            $interestRateConfig,
            $userId
        ) {

            if ($interestRateConfig->is_active) {
                return $interestRateConfig;
            }

            $this->validateEffectivePeriod(
                effectiveFrom: $interestRateConfig->effective_from,
                effectiveTo: $interestRateConfig->effective_to,
                rateType: $interestRateConfig->rate_type,
                ignoreId: $interestRateConfig->id
            );

            $interestRateConfig->update([
                'is_active' => true,
                'updated_by' => $userId,
            ]);

            return $interestRateConfig->fresh();
        });
    }

    /**
     * ============================================================
     * DEACTIVATE
     * ============================================================
     */
    public function deactivate(
        InterestRateConfig $interestRateConfig,
        ?string $userId = null
    ): InterestRateConfig {
        $interestRateConfig->update([
            'is_active' => false,
            'updated_by' => $userId,
        ]);

        return $interestRateConfig->fresh();
    }

    /**
     * ============================================================
     * CURRENT RATE
     * ============================================================
     */
    public function getCurrentRate(
        ?string $date = null,
        ?string $rateType = 'ANNUAL'
    ): ?InterestRateConfig {
        $date ??= now()->toDateString();

        return InterestRateConfig::query()
            ->current($date)
            ->when(
                $rateType !== null,
                fn ($query) => $query->where('rate_type', $rateType)
            )
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * ============================================================
     * DELETE
     * ============================================================
     *
     * Intentionally not exposed by the controller.
     *
     * Interest rates are financial/legal configuration and should
     * remain available for historical auditing.
     */
    public function delete(
        InterestRateConfig $interestRateConfig
    ): void {
        throw ValidationException::withMessages([
            'interest_rate' => [
                'Interest rate configurations cannot be deleted. '
                . 'Deactivate or expire the configuration instead.'
            ],
        ]);
    }

    /**
     * ============================================================
     * VALIDATE EFFECTIVE PERIOD
     * ============================================================
     */
    protected function validateEffectivePeriod(
        string $effectiveFrom,
        ?string $effectiveTo,
        string $rateType,
        ?string $ignoreId = null
    ): void {
        if (
            $effectiveTo !== null &&
            $effectiveTo < $effectiveFrom
        ) {
            throw ValidationException::withMessages([
                'effective_to' => [
                    'The effective end date must be after or equal to '
                    . 'the effective start date.'
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Check overlapping active configurations
        |--------------------------------------------------------------------------
        */
        $query = InterestRateConfig::query()
            ->where('is_active', true)
            ->where('rate_type', $rateType)
            ->where('effective_from', '<=', $effectiveTo ?? '9999-12-31')
            ->where(function ($q) use ($effectiveFrom) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $effectiveFrom);
            });

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'effective_from' => [
                    'The selected effective period overlaps with '
                    . 'another active interest rate configuration.'
                ],
            ]);
        }
    }
}
