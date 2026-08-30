<?php

namespace App\Modules\Administrative\Services;

use App\Models\Sector;
use Illuminate\Support\Facades\DB;

class SectorService
{
    /**
     * =========================================================
     * GET PAGINATED SECTORS
     * =========================================================
     */
    public function getList(array $filters, int $perPage = 10)
    {
        return Sector::query()
            ->with('cluster')

            // Search
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->whereRaw(
                        'LOWER(name) LIKE ?',
                        ["%" . mb_strtolower($search) . "%"]
                    )
                    ->orWhereRaw(
                        'LOWER(code) LIKE ?',
                        ["%" . mb_strtolower($search) . "%"]
                    );
                });
            })

            // Filter by cluster
            ->when($filters['cluster_id'] ?? null, function ($q, $clusterId) {
                $q->where('cluster_id', $clusterId);
            })

            // Filter active
            ->when(isset($filters['is_active']), function ($q) use ($filters) {
                $q->where(
                    'is_active',
                    filter_var(
                        $filters['is_active'],
                        FILTER_VALIDATE_BOOLEAN
                    )
                );
            })

            ->latest()
            ->paginate($perPage);
    }

    /**
     * =========================================================
     * CREATE SECTOR
     * =========================================================
     */
    public function create(array $data)
    {
        return Sector::create($data)
            ->load('cluster');
    }

    /**
     * =========================================================
     * UPDATE SECTOR
     * =========================================================
     */
    public function update(
        Sector $sector,
        array $data
    ) {
        $sector->update($data);

        return $sector->fresh()
            ->load('cluster');
    }

    /**
     * =========================================================
     * DELETE SECTOR
     * =========================================================
     *
     * Deletes the sector inside a database transaction.
     *
     * IMPORTANT:
     *
     * Dependency checks should be added here once the actual
     * Sector relationships are confirmed.
     *
     * The controller should NOT contain deletion business logic.
     */
    public function delete(
        Sector $sector
    ): void {
        DB::transaction(function () use ($sector) {

            /*
             * -------------------------------------------------
             * Dependency checks
             * -------------------------------------------------
             *
             * Add checks here for records that reference this
             * sector before deleting it.
             *
             * Example:
             *
             * if ($sector->users()->exists()) {
             *     throw new \RuntimeException(
             *         'Sector cannot be deleted because users are assigned to it.'
             *     );
             * }
             */

            /*
             * -------------------------------------------------
             * Delete
             * -------------------------------------------------
             */
            $sector->delete();
        });
    }
}
