<?php

namespace App\Modules\Revenue\Services;

use App\Models\ServiceAccessRule;
use Illuminate\Support\Facades\DB;


class ServiceAccessRuleService
{

    /**
     * Get all access rules
     */
    public function all(array $filters = [])
    {

        return ServiceAccessRule::query()

            ->with([
                'service',
                'sector',
                'role',
            ])


            ->when(
                isset($filters['sector_id']),
                fn($q) =>
                    $q->where(
                        'sector_id',
                        $filters['sector_id']
                    )
            )


            ->when(
                isset($filters['role_id']),
                fn($q) =>
                    $q->where(
                        'role_id',
                        $filters['role_id']
                    )
            )


            ->when(
                isset($filters['is_active']),
                fn($q) =>
                    $q->where(
                        'is_active',
                        $filters['is_active']
                    )
            )


            ->latest()

            ->paginate();

    }


    /**
 * Access rules summary
 */
public function summary(
    array $filters = []
): array
{

    $query = ServiceAccessRule::query();



    $query
        ->when(
            isset($filters['sector_id']),
            fn($q) =>
                $q->where(
                    'sector_id',
                    $filters['sector_id']
                )
        )

        ->when(
            isset($filters['role_id']),
            fn($q) =>
                $q->where(
                    'role_id',
                    $filters['role_id']
                )
        )

        ->when(
            isset($filters['is_active']),
            fn($q) =>
                $q->where(
                    'is_active',
                    $filters['is_active']
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
                ->where('is_active', true)
                ->count(),


        'inactive' =>
            (clone $query)
                ->where('is_active', false)
                ->count(),

    ];

}





    /**
     * Find single rule
     */
    public function find(
        ServiceAccessRule $rule
    )
    {

        return $rule->load([
            'service',
            'sector',
            'role',
        ]);

    }





    /**
     * Create rule
     */
    public function create(array $data)
    {

        return DB::transaction(function () use ($data) {


            $data['created_by'] =
                auth()->id();


            $data['updated_by'] =
                auth()->id();



            $rule = ServiceAccessRule::create(
                $data
            );


            return $rule->load([
                'service',
                'sector',
                'role',
            ]);

        });

    }





    /**
     * Update rule
     */
    public function update(
        ServiceAccessRule $rule,
        array $data
    )
    {

        return DB::transaction(function () use (
            $rule,
            $data
        ) {


            $data['updated_by'] =
                auth()->id();



            $rule->update(
                $data
            );


            return $rule->load([
                'service',
                'sector',
                'role',
            ]);

        });

    }





    /**
     * Delete rule
     */
    public function delete(
        ServiceAccessRule $rule
    )
    {

        return $rule->delete();

    }





    /**
     * Check if role has action permission
     *
     * Example:
     *
     * Building Permit
     * Construction Officer
     * Can APPROVE?
     */
    public function check(
        string $serviceId,
        string $sectorId,
        string $roleId,
        string $action
    ): bool
    {

        return ServiceAccessRule::query()

            ->where('service_id', $serviceId)

            ->where('sector_id', $sectorId)

            ->where('role_id', $roleId)

            ->where('is_active', true)

            ->whereJsonContains(
                'actions',
                $action
            )

            ->exists();

    }


}