<?php

namespace App\Modules\Revenue\Services;

use App\Models\RevenueService;
use App\Models\ServiceAccessRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ServiceAccessRuleService
{
    /**
     * Get all access rules.
     */
    public function all(array $filters = [])
    {
        return ServiceAccessRule::query()

            ->with([
                'service',
                'sector',
            ])

            ->when(
                isset($filters['service_id']),
                fn ($q) =>
                    $q->where(
                        'service_id',
                        $filters['service_id']
                    )
            )

            ->when(
                isset($filters['sector_id']),
                fn ($q) =>
                    $q->where(
                        'sector_id',
                        $filters['sector_id']
                    )
            )

            ->when(
                isset($filters['is_active']),
                fn ($q) =>
                    $q->where(
                        'is_active',
                        filter_var(
                            $filters['is_active'],
                            FILTER_VALIDATE_BOOLEAN
                        )
                    )
            )

            ->latest()

            ->paginate();
    }

    /**
     * Get access rules summary.
     */
    public function summary(
        array $filters = []
    ): array {
        $query = ServiceAccessRule::query();

        $query
            ->when(
                isset($filters['service_id']),
                fn ($q) =>
                    $q->where(
                        'service_id',
                        $filters['service_id']
                    )
            )

            ->when(
                isset($filters['sector_id']),
                fn ($q) =>
                    $q->where(
                        'sector_id',
                        $filters['sector_id']
                    )
            )

            ->when(
                isset($filters['is_active']),
                fn ($q) =>
                    $q->where(
                        'is_active',
                        filter_var(
                            $filters['is_active'],
                            FILTER_VALIDATE_BOOLEAN
                        )
                    )
            );

        return [
            'total' =>
                (clone $query)->count(),

            'sectors' =>
                (clone $query)
                    ->distinct('sector_id')
                    ->count('sector_id'),

            'active' =>
                (clone $query)
                    ->where(
                        'is_active',
                        true
                    )
                    ->count(),

            'inactive' =>
                (clone $query)
                    ->where(
                        'is_active',
                        false
                    )
                    ->count(),
        ];
    }

    /**
     * Find a single access rule.
     */
    public function find(
        ServiceAccessRule $rule
    ): ServiceAccessRule {
        return $rule->load([
            'service',
            'sector',
        ]);
    }

    /**
     * Update a single access rule.
     */
    public function update(
        ServiceAccessRule $rule,
        array $data
    ): ServiceAccessRule {
        return DB::transaction(function () use (
            $rule,
            $data
        ) {
            $updateData = [];

            if (array_key_exists('sector_id', $data)) {
                $updateData['sector_id'] =
                    $data['sector_id'];
            }

            if (array_key_exists('is_active', $data)) {
                $updateData['is_active'] =
                    $data['is_active'];
            }

            $updateData['updated_by'] =
                auth()->id();

            $rule->update(
                $updateData
            );

            return $rule->load([
                'service',
                'sector',
            ]);
        });
    }

    /**
     * Synchronize all sector access rules for a revenue service.
     *
     * The service is taken from the route:
     *
     * PUT /revenue/services/{service}/access-rules
     *
     * Expected data:
     *
     * [
     *     'sectors' => [
     *         [
     *             'sectorId' => 'uuid',
     *             'sectorName' => 'Finance',
     *             'isActive' => true,
     *         ],
     *         [
     *             'sectorId' => 'uuid',
     *             'sectorName' => 'Revenue',
     *             'isActive' => false,
     *         ],
     *     ],
     * ]
     */
    public function sync(
        RevenueService $service,
        array $data
    ): Collection {
        return DB::transaction(function () use (
            $service,
            $data
        ) {
            /*
            |--------------------------------------------------------------------------
            | Validate payload
            |--------------------------------------------------------------------------
            */

            $sectors = $data['sectors'] ?? [];

            if (empty($sectors)) {
                throw new InvalidArgumentException(
                    'At least one sector access rule is required.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Current authenticated user
            |--------------------------------------------------------------------------
            */

            $userId = auth()->id();

            /*
            |--------------------------------------------------------------------------
            | Synchronize rules
            |--------------------------------------------------------------------------
            */

            $rules = collect();

            foreach ($sectors as $sector) {
                $sectorId = $sector['sectorId'];

                $isActive = filter_var(
                    $sector['isActive'],
                    FILTER_VALIDATE_BOOLEAN
                );

                /*
                |--------------------------------------------------------------------------
                | Find existing rule, including soft-deleted rules
                |--------------------------------------------------------------------------
                */

                $rule = ServiceAccessRule::withTrashed()
                    ->where(
                        'service_id',
                        $service->id
                    )
                    ->where(
                        'sector_id',
                        $sectorId
                    )
                    ->first();

                /*
                |--------------------------------------------------------------------------
                | Create new rule
                |--------------------------------------------------------------------------
                */

                if (!$rule) {
                    $rule = ServiceAccessRule::create([
                        'service_id' => $service->id,
                        'sector_id' => $sectorId,
                        'is_active' => $isActive,
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]);
                } else {
                    /*
                    |--------------------------------------------------------------------------
                    | Restore previously soft-deleted rule
                    |--------------------------------------------------------------------------
                    */

                    if ($rule->trashed()) {
                        $rule->restore();
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Update existing rule
                    |--------------------------------------------------------------------------
                    */

                    $rule->update([
                        'is_active' => $isActive,
                        'updated_by' => $userId,
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Load relationships and add to result
                |--------------------------------------------------------------------------
                */

                $rules->push(
                    $rule->load([
                        'service',
                        'sector',
                    ])
                );
            }

            return $rules;
        });
    }
}