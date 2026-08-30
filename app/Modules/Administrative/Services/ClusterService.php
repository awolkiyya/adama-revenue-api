<?php

namespace App\Modules\Administrative\Services;

use App\Models\Cluster;
use Illuminate\Http\Request;


class ClusterService
{
     /**
     * Get paginated clusters.
     */
    public function getAll(Request $request)
    {
        $perPage = min($request->integer('per_page', 15), 100);

        return Cluster::query()
            ->with('city')

            // Search
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ILIKE', "%{$search}%")
                      ->orWhere('code', 'ILIKE', "%{$search}%")
                      ->orWhere('description', 'ILIKE', "%{$search}%");
                });
            })

            // Filter by city
            ->when($request->filled('city_id'), function ($query) use ($request) {
                $query->where('city_id', $request->city_id);
            })

            // Filter active/inactive
            ->when($request->filled('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            })

            // Sort
            ->orderBy(
                $request->input('sort_by', 'created_at'),
                $request->input('sort_order', 'desc')
            )

            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Get a single cluster.
     */
    public function find(string $id): Cluster
    {
        return Cluster::with('city')->findOrFail($id);
    }

    /**
     * Create a cluster.
     */
    public function create(array $data): Cluster
    {
        return Cluster::create($data);
    }

    /**
     * Update a cluster.
     */
    public function update(string $id, array $data): Cluster
    {
        $cluster = $this->find($id);

        $cluster->update($data);

        return $cluster->fresh();
    }

    /**
     * Delete a cluster.
     */
    public function delete(string $id): void
    {
        $cluster = $this->find($id);

        $cluster->delete();
    }
}